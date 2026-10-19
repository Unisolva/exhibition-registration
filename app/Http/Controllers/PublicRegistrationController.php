<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers;

use App\Http\Middleware\UsePublicLocale;
use App\Models\Exhibition;
use App\Models\FieldDefinition;
use App\Models\Visitor;
use App\Registration\FormView;
use App\Registration\Normalizer;
use App\Registration\PhoneLock;
use App\Registration\Submission;
use App\Support\QrCode;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The public QR form (work order §6). Visitors can submit, nothing more: no
 * response ever contains another visitor's data.
 */
class PublicRegistrationController extends Controller
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Normalizer $normalizer,
        private readonly FormView $formView,
    ) {}

    public function show(string $token): Response
    {
        $exhibition = $this->exhibitionOr404($token);

        if (! $this->accepting($exhibition)) {
            return $this->closed($exhibition, 200);
        }

        return $this->form($exhibition, (string) Str::ulid());
    }

    /**
     * Steps 1 (size, rate limits) and 2 (CSRF) run as middleware before this.
     */
    public function submit(Request $request, string $token): Response|RedirectResponse
    {
        $exhibition = $this->exhibitionOr404($token);
        $locale = app()->getLocale();
        $submission = $this->normalizer->normalize(
            $request->all(),
            FieldDefinition::activeInOrder(publicOnly: true),
            (string) $this->settings->get('default_country'),
            $locale,
        );

        $submissionToken = (string) $request->input('submission_token', '');
        if (! Str::isUlid($submissionToken)) {
            return $this->form($exhibition, (string) Str::ulid(), $submission->old, ['_form' => __('public.error.form_expired')], 422);
        }
        $submissionToken = strtoupper($submissionToken);

        // Step 3: a committed token answers from what was stored, even if the exhibition has closed since.
        if ($replay = $this->replay($exhibition, $submissionToken, $submission)) {
            return $replay;
        }

        // Step 4.
        if (! $this->accepting($exhibition)) {
            return $this->closed($exhibition, 403);
        }

        // Step 5: errors inline, values kept, same token.
        if (! $submission->isValid()) {
            return $this->form($exhibition, $submissionToken, $submission->old, $submission->errors, 422);
        }

        // M9 CO-7: one QR registration per phone number per exhibition. The named lock makes two
        // simultaneous submissions with the same number queue, so the second sees the first.
        $lock = PhoneLock::name($exhibition->id, $submission->phoneKey);
        if (! PhoneLock::acquire($lock)) {
            return $this->form($exhibition, $submissionToken, $submission->old, ['_form' => __('public.error.form_expired')], 429);
        }

        try {
            if (Visitor::earliestDuplicateId($exhibition->id, $submission->phoneKey) !== null) {
                // Nothing about the earlier visitor is shown.
                return $this->form($exhibition, $submissionToken, $submission->old,
                    ['phone' => __('public.error.phone_taken', ['exhibition' => $exhibition->name])], 422);
            }

            // Step 6. A unique-key violation re-enters step 3.
            try {
                $visitor = DB::transaction(fn () => Visitor::query()->create([
                    ...$submission->visitorAttributes(),
                    'exhibition_id' => $exhibition->id,
                    'source' => 'public',
                    'entered_by' => null,
                    'submission_token' => $submissionToken,
                    'payload_hash' => $submission->hash($exhibition->id),
                    'possible_duplicate_of' => null,
                    'locale' => $locale,
                    'request_id' => app('request_id'),
                ]));
            } catch (UniqueConstraintViolationException) {
                // The same token committed concurrently: answer as a replay.
                return $this->replay($exhibition, $submissionToken, $submission) ?? $this->conflict($exhibition);
            }
        } finally {
            PhoneLock::release($lock);
        }

        // Step 7. The thank-you page finds this visitor's package code in the session (M9 CO-6).
        $this->rememberVisitor($exhibition, $visitor->id);

        return $this->toThanks($exhibition);
    }

    /**
     * The exhibition's thanks text and, when it offers a welcome package, the
     * code and QR of the visitor registered in this session (M9 CO-6). A
     * session without one (expired, another browser) gets the generic thanks.
     */
    public function thanks(Request $request, string $token): Response
    {
        $exhibition = $this->exhibitionOr404($token);
        $visitor = null;
        if ($exhibition->offers_package && ($id = $request->session()->get(self::visitorKey($exhibition)))) {
            $visitor = Visitor::query()->current()->where('id', $id)->where('exhibition_id', $exhibition->id)
                ->where('source', 'public')->first(['id', 'package_code']);
        }

        return response()->view('public.thanks', [
            ...$this->page($exhibition),
            'packageCode' => $visitor?->package_code,
            'packageQr' => $visitor?->package_code ? QrCode::svg(self::packageUrl($visitor->package_code)) : null,
        ]);
    }

    /** Where the QR on the thank-you page points: the staff hand-over page for that code. */
    public static function packageUrl(string $code): string
    {
        return rtrim((string) config('app.url'), '/').'/staff/packages/'.$code;
    }

    private static function visitorKey(Exhibition $exhibition): string
    {
        return 'public_visitor.'.$exhibition->public_token;
    }

    private function rememberVisitor(Exhibition $exhibition, int $visitorId): void
    {
        request()->session()->put(self::visitorKey($exhibition), $visitorId);
    }

    /**
     * Rendered by GuardPublicSubmission when a rate limit is hit: the form
     * again, with the typed values and the same submission token.
     */
    public function rateLimited(Request $request, string $token): Response
    {
        UsePublicLocale::apply($request);
        $exhibition = $this->exhibitionOr404($token);
        $old = [];
        foreach (FieldDefinition::activeInOrder(publicOnly: true) as $field) {
            $value = $request->input($field->key);
            $old[$field->key] = is_string($value) ? $value : '';
        }
        $submissionToken = (string) $request->input('submission_token', '');

        return $this->form(
            $exhibition,
            Str::isUlid($submissionToken) ? strtoupper($submissionToken) : (string) Str::ulid(),
            $old,
            [],
            429,
            __('public.rate_limited'),
        );
    }

    private function exhibitionOr404(string $token): Exhibition
    {
        $exhibition = Exhibition::findByPublicToken($token);
        abort_if($exhibition === null, 404);

        return $exhibition;
    }

    private function accepting(Exhibition $exhibition): bool
    {
        return $exhibition->is_open && (bool) $this->settings->get('public_registration_enabled');
    }

    private function replay(Exhibition $exhibition, string $submissionToken, Submission $submission): Response|RedirectResponse|null
    {
        $committed = Visitor::query()->where('submission_token', $submissionToken)->first(['id', 'payload_hash', 'source']);
        if ($committed === null) {
            return null;
        }
        if (! hash_equals($committed->payload_hash, $submission->hash($exhibition->id))) {
            return $this->conflict($exhibition);
        }
        if ($committed->source === 'public') {
            $this->rememberVisitor($exhibition, $committed->id);
        }

        return $this->toThanks($exhibition);
    }

    private function toThanks(Exhibition $exhibition): RedirectResponse
    {
        return redirect()->route('public.thanks', ['token' => $exhibition->public_token], 303);
    }

    private function conflict(Exhibition $exhibition): Response
    {
        return response()->view('public.conflict', $this->page($exhibition), 409);
    }

    private function closed(Exhibition $exhibition, int $status): Response
    {
        return response()->view('public.closed', $this->page($exhibition), $status);
    }

    /**
     * @param  array<string, string>  $old
     * @param  array<string, string>  $errors
     */
    private function form(Exhibition $exhibition, string $submissionToken, array $old = [], array $errors = [], int $status = 200, ?string $notice = null): Response
    {
        $locale = app()->getLocale();

        return response()->view('public.form', [
            ...$this->page($exhibition),
            'fields' => $this->formView->fields($locale, publicOnly: true),
            'old' => $old,
            'fieldErrors' => $errors,
            'submissionToken' => $submissionToken,
            'notice' => $notice,
        ], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function page(Exhibition $exhibition): array
    {
        return ['exhibition' => $exhibition];
    }
}
