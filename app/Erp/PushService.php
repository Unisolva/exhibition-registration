<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Erp;

use App\Models\ErpBatch;
use App\Models\ErpConnection;
use App\Models\ErpPushItem;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Batch creation and the admin actions of the push state table (work order
 * §8). Every transaction locks in the §4 order: visitors (ascending id), then
 * their lead_syncs rows, then their push items.
 */
class PushService
{
    public const MAX_PER_BATCH = 200;

    public function __construct(private readonly PayloadBuilder $payloads) {}

    /**
     * How many of the selected visitors would be queued and how many skipped
     * (already succeeded or already queued on the active connection, trashed
     * or deleted). Read-only, for the confirm screen.
     *
     * @param  list<int>  $visitorIds
     * @return array{eligible: int, skipped: int}
     */
    public function preview(array $visitorIds, ErpConnection $connection): array
    {
        $current = Visitor::query()->whereIn('id', $visitorIds)->whereNull('trashed_at')->whereNull('deleted_at')->pluck('id');
        $blocked = DB::table('lead_syncs')->where('connection_id', $connection->id)->whereIn('visitor_id', $current)
            ->where(fn ($q) => $q->where('state', 'succeeded')->orWhereNotNull('active_item_id'))->count();
        $eligible = $current->count() - $blocked;

        return ['eligible' => $eligible, 'skipped' => count($visitorIds) - $eligible];
    }

    /**
     * Create a batch (§8 "Batch creation"). A replayed batch token returns the
     * existing batch. Returns null when nothing was eligible.
     *
     * @param  list<int>  $visitorIds
     * @return array{batch: ErpBatch|null, queued: int, skipped: int, replay: bool}
     */
    public function createBatch(User $user, ErpConnection $connection, array $visitorIds, string $batchToken): array
    {
        if ($existing = ErpBatch::query()->where('batch_token', $batchToken)->first()) {
            return ['batch' => $existing, 'queued' => 0, 'skipped' => 0, 'replay' => true];
        }

        $ids = array_values(array_unique(array_map('intval', $visitorIds)));
        sort($ids);

        try {
            return DB::transaction(function () use ($user, $connection, $ids, $batchToken) {
                $batch = ErpBatch::query()->create([
                    'batch_token' => $batchToken,
                    'created_by' => $user->id,
                    'connection_id' => $connection->id,
                    'field_keys' => $this->payloads->fieldKeys(),
                    'item_count' => 0,
                ]);

                $visitors = Visitor::query()->whereIn('id', $ids)->whereNull('trashed_at')->whereNull('deleted_at')
                    ->orderBy('id')->lockForUpdate()->get();

                $queued = 0;
                foreach ($visitors as $visitor) {
                    $sync = $this->lockSync($visitor->id, $connection->id, create: true);
                    if ($sync->state === 'succeeded' || $sync->active_item_id !== null) {
                        continue;
                    }

                    $itemId = DB::table('erp_push_items')->insertGetId([
                        'batch_id' => $batch->id,
                        'visitor_id' => $visitor->id,
                        'connection_id' => $connection->id,
                        'payload' => json_encode($this->payloads->build($visitor, $sync->ext_ref), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'state' => 'queued',
                        'attempts' => 0,
                        'next_attempt_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $this->updateSync($visitor->id, $connection->id, ['state' => 'queued', 'active_item_id' => $itemId]);
                    $queued++;
                }

                $skipped = count($ids) - $queued;
                if ($queued === 0) {
                    // Nothing to send: roll back the empty batch.
                    throw new NothingToPushException($skipped);
                }

                $batch->forceFill(['item_count' => $queued])->save();
                Audit::record('batch_created', $batch, meta: ['queued' => $queued, 'skipped' => $skipped, 'connection' => $connection->kind], userId: $user->id);

                return ['batch' => $batch, 'queued' => $queued, 'skipped' => $skipped, 'replay' => false];
            });
        } catch (NothingToPushException $e) {
            return ['batch' => null, 'queued' => 0, 'skipped' => $e->skipped, 'replay' => false];
        } catch (UniqueConstraintViolationException) {
            // A double click committed the same batch token first.
            return ['batch' => ErpBatch::query()->where('batch_token', $batchToken)->firstOrFail(), 'queued' => 0, 'skipped' => 0, 'replay' => true];
        }
    }

    /**
     * Cancel items still waiting (queued or failed_retry); a conditional update,
     * so a drain claim and a cancel racing on one item leave exactly one effective.
     *
     * @param  list<int>  $itemIds
     * @return int number cancelled
     */
    public function cancel(array $itemIds, User $user): int
    {
        $items = DB::table('erp_push_items')->whereIn('id', $itemIds)->whereIn('state', ['queued', 'failed_retry'])
            ->orderBy('visitor_id')->orderBy('id')->get(['id', 'visitor_id', 'connection_id']);

        $cancelled = 0;
        foreach ($items as $item) {
            $cancelled += DB::transaction(function () use ($item) {
                $this->lockVisitor($item->visitor_id);
                $this->lockSync($item->visitor_id, $item->connection_id);
                $done = DB::table('erp_push_items')->where('id', $item->id)->whereIn('state', ['queued', 'failed_retry'])
                    ->update(['state' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);
                if ($done === 0) {
                    return 0;
                }

                $earlierFailure = DB::table('erp_push_items')->where('visitor_id', $item->visitor_id)
                    ->where('connection_id', $item->connection_id)->where('state', 'failed_final')
                    ->where('id', '<', $item->id)->exists();
                $this->updateSync($item->visitor_id, $item->connection_id, [
                    'state' => $earlierFailure ? 'failed' : 'never',
                    'active_item_id' => null,
                ], onlyIfActiveItem: $item->id);

                return 1;
            });
        }

        if ($cancelled > 0) {
            Audit::record('items_cancelled', meta: ['count' => $cancelled], userId: $user->id);
        }

        return $cancelled;
    }

    /**
     * "Retry failed" (§8 state table): only from failed_final, only for a
     * visitor that is neither trashed nor deleted, whose sync row has no
     * active item and has not succeeded, on the active connection. The
     * payload is rebuilt from the current visitor row.
     */
    public function retry(int $itemId, User $user): void
    {
        $item = DB::table('erp_push_items')->where('id', $itemId)->first();
        if ($item === null || ! self::canRetry($item)) {
            throw new ConflictHttpException('This item cannot be retried.');
        }

        DB::transaction(function () use ($item, $user) {
            $visitor = $this->lockVisitor($item->visitor_id);
            $sync = $this->lockSync($item->visitor_id, $item->connection_id);
            $locked = DB::table('erp_push_items')->where('id', $item->id)->lockForUpdate()->first();
            $active = ErpConnection::active();

            if ($visitor->trashed_at !== null || $visitor->deleted_at !== null || $locked->state !== 'failed_final'
                || $sync->active_item_id !== null || $sync->state === 'succeeded' || $active?->id !== $item->connection_id) {
                throw new ConflictHttpException('This item cannot be retried.');
            }

            DB::table('erp_push_items')->where('id', $item->id)->update([
                'state' => 'queued', 'attempts' => 0, 'next_attempt_at' => now(), 'claim_id' => null, 'claimed_at' => null,
                'error_class' => null, 'error_message' => null, 'completed_at' => null, 'updated_at' => now(),
                'payload' => json_encode($this->payloads->build($visitor, $sync->ext_ref), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $this->updateSync($item->visitor_id, $item->connection_id, ['state' => 'queued', 'active_item_id' => $item->id]);

            Audit::record('item_retried', ErpPushItem::query()->find($item->id), userId: $user->id);
        });
    }

    /**
     * "Mark reviewed" (§8 state table), only from needs_review: either the lead
     * exists (its ID entered) or the item is sent again.
     */
    public function markReviewed(int $itemId, User $user, ?int $existingLeadId): void
    {
        $item = DB::table('erp_push_items')->where('id', $itemId)->first();
        if ($item === null || $item->state !== 'needs_review') {
            throw new ConflictHttpException('This item is not waiting for review.');
        }

        DB::transaction(function () use ($item, $user, $existingLeadId) {
            $this->lockVisitor($item->visitor_id);
            $this->lockSync($item->visitor_id, $item->connection_id);
            $locked = DB::table('erp_push_items')->where('id', $item->id)->lockForUpdate()->first();
            if ($locked->state !== 'needs_review') {
                throw new ConflictHttpException('This item is not waiting for review.');
            }

            if ($existingLeadId !== null) {
                DB::table('erp_push_items')->where('id', $item->id)->update([
                    'state' => 'succeeded', 'erp_lead_id' => $existingLeadId, 'completed_at' => now(), 'updated_at' => now(),
                ]);
                $this->updateSync($item->visitor_id, $item->connection_id, [
                    'state' => 'succeeded', 'erp_lead_id' => $existingLeadId, 'succeeded_at' => now(), 'active_item_id' => null,
                ]);
            } else {
                DB::table('erp_push_items')->where('id', $item->id)->update([
                    'state' => 'queued', 'attempts' => 0, 'next_attempt_at' => now(), 'claim_id' => null, 'claimed_at' => null,
                    'error_class' => null, 'error_message' => null, 'updated_at' => now(),
                ]);
                $this->updateSync($item->visitor_id, $item->connection_id, ['state' => 'queued']);
            }

            Audit::record('item_marked_reviewed', ErpPushItem::query()->find($item->id),
                meta: ['decision' => $existingLeadId !== null ? 'lead_exists' : 'send_again'], userId: $user->id);
        });
    }

    /** Whether the Retry button applies (the route re-checks under locks). */
    public static function canRetry(object $item): bool
    {
        if ($item->state !== 'failed_final' || ErpConnection::active()?->id !== $item->connection_id) {
            return false;
        }
        $visitor = DB::table('visitors')->where('id', $item->visitor_id)->first(['trashed_at', 'deleted_at']);
        $sync = DB::table('lead_syncs')->where('visitor_id', $item->visitor_id)->where('connection_id', $item->connection_id)->first();

        return $visitor !== null && $visitor->trashed_at === null && $visitor->deleted_at === null
            && $sync !== null && $sync->active_item_id === null && $sync->state !== 'succeeded';
    }

    // ---- locking helpers (§4 order) ---------------------------------------

    private function lockVisitor(int $visitorId): Visitor
    {
        return Visitor::query()->whereKey($visitorId)->lockForUpdate()->firstOrFail();
    }

    private function lockSync(int $visitorId, int $connectionId, bool $create = false): object
    {
        $query = fn () => DB::table('lead_syncs')->where('visitor_id', $visitorId)->where('connection_id', $connectionId)->lockForUpdate()->first();
        $sync = $query();
        if ($sync === null && $create) {
            // The visitor row is locked, so no one else can create this row meanwhile.
            DB::table('lead_syncs')->insert([
                'visitor_id' => $visitorId,
                'connection_id' => $connectionId,
                'ext_ref' => 'EXR-'.Str::ulid(),
                'state' => 'never',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $sync = $query();
        }
        if ($sync === null) {
            throw new ConflictHttpException('No sync record for this visitor and connection.');
        }

        return $sync;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function updateSync(int $visitorId, int $connectionId, array $values, ?int $onlyIfActiveItem = null): void
    {
        DB::table('lead_syncs')->where('visitor_id', $visitorId)->where('connection_id', $connectionId)
            ->when($onlyIfActiveItem !== null, fn ($q) => $q->where('active_item_id', $onlyIfActiveItem))
            ->update([...$values, 'updated_at' => now()]);
    }
}
