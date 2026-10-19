<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Visitors;

use App\Models\FieldDefinition;
use App\Models\User;
use App\Models\Visitor;
use App\Registration\Normalizer;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Edit, trash, restore and permanent deletion (work order §4, §7). Every
 * change locks in the §4 order (visitor row, then its lead_syncs rows, then
 * its push items) and checks the lock inside the transaction that writes, so
 * an edit and a batch creation serialise on the visitor row.
 */
class VisitorService
{
    public function __construct(
        private readonly Normalizer $normalizer,
        private readonly Settings $settings,
    ) {}

    /**
     * Apply an edit. Only active fields are read; stored values of inactive
     * fields stay. Returns the changes written to the edit log (empty when
     * nothing changed).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function edit(int $visitorId, array $input, User $user): array
    {
        return DB::transaction(function () use ($visitorId, $input, $user) {
            $visitor = $this->lockVisitor($visitorId);
            if ($visitor->trashed_at !== null) {
                throw new ConflictHttpException('This visitor is in the trash; restore it first.');
            }
            if ($cause = VisitorLock::causeFor($visitor->id, forUpdate: true)) {
                throw new VisitorLockedException($cause);
            }

            // Staff-only fields belong to staff entries only (M9 CO-2).
            $fields = FieldDefinition::activeInOrder(publicOnly: $visitor->source !== 'staff');
            $submission = $this->normalizer->normalize($input, $fields, (string) $this->settings->get('default_country'), 'en');
            if (! $submission->isValid()) {
                throw ValidationException::withMessages($submission->errors);
            }

            $changes = [];
            $attributes = [];
            $extra = $visitor->extra ?? [];
            foreach ($fields as $field) {
                [$old, $new, $columns] = match (true) {
                    $field->key === 'name' => [$visitor->name, $submission->name, ['name' => $submission->name]],
                    $field->key === 'phone' => [$visitor->phone_raw, $submission->phoneRaw, [
                        'phone_raw' => $submission->phoneRaw, 'phone_e164' => $submission->phoneE164,
                        'phone_key' => $submission->phoneKey, 'phone_valid' => $submission->phoneValid,
                    ]],
                    $field->key === 'email' => [$visitor->email, $submission->email, ['email' => $submission->email]],
                    $field->key === 'occupation' => [$visitor->occupation, $submission->occupation, ['occupation' => $submission->occupation]],
                    $field->type === 'region' => [$visitor->region_code, $submission->regionCode, [
                        'region_code' => $submission->regionCode, 'region_label' => $submission->regionLabel,
                    ]],
                    default => [$extra[$field->key] ?? null, $submission->extra[$field->key] ?? null, []],
                };

                if ($old === $new) {
                    continue;
                }
                $changes[$field->key] = [$old, $new];
                if ($columns === []) {
                    $extra[$field->key] = $new;
                } else {
                    $attributes = [...$attributes, ...$columns];
                }
            }

            if ($changes === []) {
                return [];
            }

            $oldPhoneKey = $visitor->phone_key;
            $visitor->forceFill([...$attributes, 'extra' => $extra, 'edited_at' => now()])->save();

            DB::table('visitor_edits')->insert([
                'visitor_id' => $visitor->id,
                'user_id' => $user->id,
                'at' => now(),
                'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            DuplicateFlags::recompute($visitor->exhibition_id, [$oldPhoneKey, $visitor->phone_key]);
            Audit::record('visitor_edited', $visitor, meta: ['fields' => array_keys($changes)], userId: $user->id);

            return $changes;
        });
    }

    /** Move to the trash (admins and super admins; never a locked visitor). */
    public function trash(int $visitorId, User $user): void
    {
        DB::transaction(function () use ($visitorId, $user) {
            $visitor = $this->lockVisitor($visitorId);
            if ($visitor->trashed_at !== null) {
                return;
            }
            if ($cause = VisitorLock::causeFor($visitor->id, forUpdate: true)) {
                throw new VisitorLockedException($cause);
            }

            $visitor->forceFill(['trashed_at' => now(), 'trashed_by' => $user->id])->save();
            DuplicateFlags::releaseReferencesTo($visitor->id);
            Audit::record('visitor_trashed', $visitor, userId: $user->id);
        });
    }

    public function restore(int $visitorId, User $user): void
    {
        DB::transaction(function () use ($visitorId, $user) {
            $visitor = $this->lockVisitor($visitorId);
            if ($visitor->trashed_at === null) {
                return;
            }

            $visitor->forceFill(['trashed_at' => null, 'trashed_by' => null])->save();
            DuplicateFlags::recompute($visitor->exhibition_id, [$visitor->phone_key]);
            Audit::record('visitor_restored', $visitor, userId: $user->id);
        });
    }

    /**
     * Permanent deletion (work order §4): the row stays, its personal fields
     * are blanked, its edit log is deleted and its push payloads emptied.
     * $user is null when done by housekeeping. $ignoreLiveLock is for
     * retention only; an item still in flight always blocks.
     */
    public function erase(int $visitorId, ?User $user, bool $ignoreLiveLock = false): void
    {
        DB::transaction(function () use ($visitorId, $user, $ignoreLiveLock) {
            $visitor = $this->lockVisitor($visitorId);
            $cause = VisitorLock::causeFor($visitor->id, forUpdate: true);
            if ($cause === VisitorLock::ACTIVE || ($cause === VisitorLock::LIVE && ! $ignoreLiveLock)) {
                throw new VisitorLockedException($cause);
            }
            DB::table('erp_push_items')->where('visitor_id', $visitor->id)->orderBy('id')->lockForUpdate()->pluck('id');

            $phoneKey = $visitor->phone_key;
            $visitor->forceFill([
                'name' => '', 'phone_raw' => '', 'phone_e164' => null, 'phone_key' => '', 'phone_valid' => false,
                'email' => null, 'occupation' => null, 'region_code' => null, 'region_label' => null,
                'extra' => null, 'possible_duplicate_of' => null,
                'deleted_at' => now(), 'deleted_by' => $user?->id,
            ])->save();

            DB::table('visitor_edits')->where('visitor_id', $visitor->id)->delete();
            DB::table('erp_push_items')->where('visitor_id', $visitor->id)->update(['payload' => '{}']);
            DuplicateFlags::releaseReferencesTo($visitor->id);
            DuplicateFlags::recompute($visitor->exhibition_id, [$phoneKey]);

            if ($user !== null) {
                Audit::record('visitor_deleted', $visitor, userId: $user->id);
            }
        });
    }

    private function lockVisitor(int $visitorId): Visitor
    {
        $visitor = Visitor::query()->whereKey($visitorId)->whereNull('deleted_at')->lockForUpdate()->first();
        if ($visitor === null) {
            throw new NotFoundHttpException;
        }

        return $visitor;
    }
}
