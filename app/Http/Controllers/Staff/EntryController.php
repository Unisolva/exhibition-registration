<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Exhibition;
use App\Models\FieldDefinition;
use App\Models\Visitor;
use App\Registration\FormView;
use App\Registration\Normalizer;
use App\Registration\Submission;
use App\Support\Settings;
use App\Visitors\PackageService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Save & New (work order §7), for every role. Staff may enter visitors for
 * closed exhibitions and while public registration is off. Answers JSON to
 * the page script and plain redirects without JavaScript.
 */
class EntryController extends Controller
{
    public const SESSION_KEY = 'entry_exhibition_id';

    public const DUPLICATE_NOTE = 'This phone number is already registered at this exhibition.';

    public function __construct(
        private readonly Settings $settings,
        private readonly Normalizer $normalizer,
        private readonly FormView $formView,
        private readonly PackageService $packages,
    ) {}

    public function show(Request $request): Response
    {
        if ($request->filled('exhibition') && ($chosen = Exhibition::query()->find((int) $request->query('exhibition')))) {
            $request->session()->put(self::SESSION_KEY, $chosen->id);
        }

        $exhibition = $request->boolean('change') ? null : $this->chosenExhibition($request);
        if ($exhibition === null) {
            return response()->view('staff.entry.choose', [
                'exhibitions' => Exhibition::query()->orderByDesc('starts_on')->orderByDesc('id')->get(),
            ]);
        }

        return $this->form($exhibition, (string) Str::ulid());
    }

    /** A fresh CSRF token for the page script after signing in again in another tab. */
    public function session(Request $request): JsonResponse
    {
        return response()->json(['csrf_token' => $request->session()->token()]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse|Response
    {
        $user = $request->user();
        $exhibition = Exhibition::query()->find((int) $request->input('exhibition_id'));
        if ($exhibition === null) {
            return $this->answer($request, 422, ['errors' => ['_form' => 'Choose an exhibition first.']], null, (string) Str::ulid());
        }

        $submission = $this->normalizer->normalize(
            $request->all(),
            FieldDefinition::activeInOrder(),
            (string) $this->settings->get('default_country'),
            'en',
        );

        $token = strtoupper((string) $request->input('submission_token', ''));
        if (! Str::isUlid($token)) {
            return $this->answer($request, 422, ['errors' => ['_form' => 'The form was reloaded. Press Save again.']], $exhibition, (string) Str::ulid(), $submission);
        }

        // §6 step 3, with the token bound to the user who committed it.
        if ($replay = $this->replay($request, $exhibition, $token, $submission)) {
            return $replay;
        }

        if (! $submission->isValid()) {
            return $this->answer($request, 422, ['errors' => $submission->errors], $exhibition, $token, $submission);
        }

        try {
            // "Package given" is recorded in the same transaction; it is not part of the replay check (M9 CO-10).
            $packageGiven = $exhibition->offers_package && $request->boolean('package_given');
            $visitor = DB::transaction(function () use ($submission, $exhibition, $user, $token, $packageGiven) {
                $visitor = Visitor::query()->create([
                    ...$submission->visitorAttributes(),
                    'exhibition_id' => $exhibition->id,
                    'source' => 'staff',
                    'entered_by' => $user->id,
                    'submission_token' => $token,
                    'payload_hash' => $submission->hash($exhibition->id),
                    'possible_duplicate_of' => Visitor::earliestDuplicateId($exhibition->id, $submission->phoneKey),
                    'locale' => 'en',
                    'request_id' => app('request_id'),
                ]);
                if ($packageGiven) {
                    $this->packages->markGiven($visitor, $user);
                }

                return $visitor;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->replay($request, $exhibition, $token, $submission)
                ?? $this->answer($request, 409, ['message' => 'This entry could not be saved. Press Save again.'], $exhibition, (string) Str::ulid(), $submission);
        }

        $request->session()->put(self::SESSION_KEY, $exhibition->id);

        return $this->answer($request, 201, ['status' => 'saved', ...$this->savedDetails($request, $visitor)], $exhibition, (string) Str::ulid());
    }

    /**
     * What the page shows after a save (M8 CO-4): the name, and whether the
     * phone is already registered here. Only managers get the link to the
     * earlier entry; staff never see the matching record (work order §7).
     *
     * @return array{possible_duplicate: bool, visitor_name: string, duplicate_url?: string}
     */
    private function savedDetails(Request $request, Visitor $visitor): array
    {
        $details = [
            'possible_duplicate' => $visitor->possible_duplicate_of !== null,
            'visitor_name' => (string) $visitor->name,
        ];
        if ($visitor->possible_duplicate_of !== null && $request->user()->isManager()) {
            $details['duplicate_url'] = route('visitors.show', $visitor->possible_duplicate_of);
        }

        return $details;
    }

    private function replay(Request $request, Exhibition $exhibition, string $token, Submission $submission): JsonResponse|RedirectResponse|Response|null
    {
        $committed = Visitor::query()->where('submission_token', $token)->first(['id', 'name', 'payload_hash', 'source', 'entered_by', 'possible_duplicate_of']);
        if ($committed === null) {
            return null;
        }

        $ownReplay = $committed->source === 'staff'
            && $committed->entered_by === $request->user()->id
            && hash_equals($committed->payload_hash, $submission->hash($exhibition->id));

        if ($ownReplay) {
            return $this->answer($request, 200, ['status' => 'replay', ...$this->savedDetails($request, $committed)], $exhibition, (string) Str::ulid());
        }

        // Never reveal anything about the committed entry.
        return $this->answer($request, 409, [
            'message' => 'This form was already saved with different details. Saving it again creates a new entry.',
        ], $exhibition, (string) Str::ulid(), $submission);
    }

    /**
     * JSON for the page script; for a plain form post, a redirect to a fresh
     * form after a save, or the form again with values and messages.
     *
     * @param  array<string, mixed>  $payload
     */
    private function answer(Request $request, int $status, array $payload, ?Exhibition $exhibition, string $nextToken, ?Submission $submission = null): JsonResponse|RedirectResponse|Response
    {
        if ($request->expectsJson()) {
            return response()->json([...$payload, 'next_token' => $nextToken], $status);
        }

        if (in_array($status, [200, 201], true)) {
            $saved = 'Saved '.$payload['visitor_name'].'.';

            return $payload['possible_duplicate']
                ? redirect()->route('staff.entry')->with('warning', $saved.' '.self::DUPLICATE_NOTE)
                : redirect()->route('staff.entry')->with('status', $saved);
        }

        if ($exhibition === null) {
            return redirect()->route('staff.entry', ['change' => 1])->with('status', $payload['errors']['_form'] ?? 'Choose an exhibition.');
        }

        // 422 keeps the token (the same entry, corrected); 409 offers a new one.
        $token = $status === 422 ? (string) $request->input('submission_token', $nextToken) : $nextToken;

        return $this->form($exhibition, Str::isUlid($token) ? strtoupper($token) : $nextToken, $submission?->old ?? [], $payload['errors'] ?? [], $status, $payload['message'] ?? null);
    }

    /**
     * @param  array<string, string>  $old
     * @param  array<string, string>  $errors
     */
    private function form(Exhibition $exhibition, string $token, array $old = [], array $errors = [], int $status = 200, ?string $notice = null): Response
    {
        return response()->view('staff.entry.form', [
            'exhibition' => $exhibition,
            'fields' => $this->formView->fields('en'),
            'old' => $old,
            'fieldErrors' => $errors,
            'submissionToken' => $token,
            'notice' => $notice,
        ], $status);
    }

    private function chosenExhibition(Request $request): ?Exhibition
    {
        $id = $request->session()->get(self::SESSION_KEY);

        return $id ? Exhibition::query()->find($id) : null;
    }
}
