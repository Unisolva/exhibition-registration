<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers;

use App\Http\Middleware\UsePublicLocale;
use App\Models\Exhibition;
use App\Models\FieldDefinition;
use App\Models\Visitor;
use App\Registration\FormView;
use App\Registration\Normalizer;
use App\Registration\Submission;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The public QR form (work order §6). Visitors can submit, nothing more: no
 * response ever contains another visitor's data.
 */
class PublicRegistrationController extends Controller
{
    public const MIN_FILL_SECONDS = 3;

    public const HONEYPOT = 'website';

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
            FieldDefinition::activeInOrder(),
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

        // Step 4: spam traps fail silently.
        if ($this->looksLikeSpam($request, $exhibition)) {
            Log::info('spam_blocked', ['exhibition_id' => $exhibition->id]);

            return $this->toThanks($exhibition);
        }

        // Step 5.
        if (! $this->accepting($exhibition)) {
            return $this->closed($exhibition, 403);
        }

        // Step 6: errors inline, values kept, same token.
        if (! $submission->isValid()) {
            return $this->form($exhibition, $submissionToken, $submission->old, $submission->errors, 422);
        }

        // Step 7.
        try {
            DB::transaction(function () use ($exhibition, $submission, $submissionToken, $locale) {
                Visitor::query()->create([
                    ...$submission->visitorAttributes(),
                    'exhibition_id' => $exhibition->id,
                    'source' => 'public',
                    'entered_by' => null,
                    'submission_token' => $submissionToken,
                    'payload_hash' => $submission->hash($exhibition->id),
                    'possible_duplicate_of' => Visitor::earliestDuplicateId($exhibition->id, $submission->phoneKey),
                    'locale' => $locale,
                    'request_id' => app('request_id'),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // The same token committed concurrently: answer as a replay.
            return $this->replay($exhibition, $submissionToken, $submission) ?? $this->conflict($exhibition);
        }

        // Step 8.
        return $this->toThanks($exhibition);
    }

    public function thanks(string $token): Response
    {
        $exhibition = $this->exhibitionOr404($token);

        return response()->view('public.thanks', $this->page($exhibition));
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
        foreach (FieldDefinition::activeInOrder() as $field) {
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
        $committed = Visitor::query()->where('submission_token', $submissionToken)->first(['id', 'payload_hash']);
        if ($committed === null) {
            return null;
        }

        return hash_equals($committed->payload_hash, $submission->hash($exhibition->id))
            ? $this->toThanks($exhibition)
            : $this->conflict($exhibition);
    }

    private function looksLikeSpam(Request $request, Exhibition $exhibition): bool
    {
        if (trim((string) $request->input(self::HONEYPOT, '')) !== '') {
            return true;
        }

        $renderedAt = self::verifyRenderedAt((string) $request->input('rendered_at', ''), $exhibition);

        return $renderedAt === null || now()->getTimestamp() - $renderedAt < self::MIN_FILL_SECONDS;
    }

    public static function signRenderedAt(int $timestamp, Exhibition $exhibition): string
    {
        return $timestamp.'.'.hash_hmac('sha256', $timestamp.'|'.$exhibition->public_token, (string) config('app.key'));
    }

    private static function verifyRenderedAt(string $value, Exhibition $exhibition): ?int
    {
        if (preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $value, $m) !== 1) {
            return null;
        }

        return hash_equals(self::signRenderedAt((int) $m[1], $exhibition), $value) ? (int) $m[1] : null;
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
            'fields' => $this->formView->fields($locale),
            'old' => $old,
            'fieldErrors' => $errors,
            'submissionToken' => $submissionToken,
            'renderedAt' => self::signRenderedAt(now()->getTimestamp(), $exhibition),
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
