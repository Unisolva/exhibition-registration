<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Erp;

use App\Models\ErpConnection;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The bounded push run behind `leads:drain` (work order §8 "Drain command").
 * Odoo is contacted only when there is something to push. Each item is
 * searched by reference before any create, so a retry after an uncertain
 * outcome adopts the lead instead of creating a second one.
 */
class Drain
{
    public const LOCK_NAME = 'exreg_drain';

    public const STALE_AFTER_MINUTES = 5;

    public const MAX_ATTEMPTS = 5;

    /** Backoff after attempts 1 to 4: 1 min, 10 min, 1 h, 6 h. */
    public const BACKOFF_SECONDS = [60, 600, 3600, 21600];

    private string $runId;

    public function __construct(
        private readonly Settings $settings,
        private readonly ConnectorFactory $factory,
    ) {}

    /**
     * @return array{outcome: string, items: int, error_class: string|null}
     */
    public function run(int $maxItems, int $maxSeconds): array
    {
        $this->runId = (string) Str::ulid();
        $started = microtime(true);
        $runRow = DB::table('drain_runs')->insertGetId(['started_at' => now(), 'items_processed' => 0]);
        $result = ['outcome' => 'error', 'items' => 0, 'error_class' => null];

        try {
            $result = $this->execute($maxItems, $maxSeconds, $started);
        } catch (Throwable $e) {
            $result = ['outcome' => 'error', 'items' => $result['items'], 'error_class' => 'exception'];
            $this->log()->error('drain failed', ['exception' => get_class($e)]);
        } finally {
            DB::table('drain_runs')->where('id', $runRow)->update([
                'finished_at' => now(),
                'items_processed' => $result['items'],
                'outcome' => $result['outcome'],
                'error_class' => $result['error_class'],
            ]);
            $this->log()->info('drain finished', $result);
        }

        return $result;
    }

    /**
     * @return array{outcome: string, items: int, error_class: string|null}
     */
    private function execute(int $maxItems, int $maxSeconds, float $started): array
    {
        // Step 1: switched off, or nothing to do (no Odoo call in either case).
        $connection = ErpConnection::active();
        if ($this->settings->get('erp_mode') !== 'odoo' || ! $this->settings->get('erp_push_enabled') || $connection === null) {
            return ['outcome' => 'skipped_disabled', 'items' => 0, 'error_class' => null];
        }
        if (! $this->hasWork($connection->id)) {
            return ['outcome' => 'ok', 'items' => 0, 'error_class' => null];
        }

        // Step 2: one drain at a time.
        if ((int) DB::selectOne('SELECT GET_LOCK(?, 0) AS got', [self::LOCK_NAME])->got !== 1) {
            return ['outcome' => 'skipped_locked', 'items' => 0, 'error_class' => null];
        }

        try {
            return $this->drainLocked($connection, $maxItems, $maxSeconds, $started);
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [self::LOCK_NAME]);
        }
    }

    /**
     * @return array{outcome: string, items: int, error_class: string|null}
     */
    private function drainLocked(ErpConnection $connection, int $maxItems, int $maxSeconds, float $started): array
    {
        $connector = $this->factory->for($connection);

        // The server version, re-read each run: a mismatch stops pushing.
        try {
            $version = $connector->serverVersion();
        } catch (ErpException $e) {
            return ['outcome' => 'error', 'items' => 0, 'error_class' => $e->errorClass];
        }
        $expected = (string) $this->settings->get('odoo_version');
        if ((string) (int) $version !== $expected) {
            $this->disablePush('version', ['server_version' => mb_substr($version, 0, 40), 'selector' => $expected]);

            return ['outcome' => 'stopped_version', 'items' => 0, 'error_class' => 'version'];
        }

        // Step 3: stale claims become retries; the next attempt searches before creating.
        DB::table('erp_push_items')->where('connection_id', $connection->id)->where('state', 'processing')
            ->where('claimed_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->update(['state' => 'failed_retry', 'next_attempt_at' => now(), 'error_class' => 'stale_claim',
                'error_message' => 'The previous attempt did not finish.', 'claim_id' => null, 'updated_at' => now()]);

        // Step 4: claim, call, apply.
        $processed = 0;
        $resolved = [];
        while ($processed < $maxItems && (microtime(true) - $started) < $maxSeconds) {
            $claim = $this->claim($connection->id);
            if ($claim === null) {
                return ['outcome' => 'ok', 'items' => $processed, 'error_class' => null];
            }
            [$item, $claimId] = $claim;

            $outcome = $this->process($item, $connector, $resolved);
            $this->apply($item, $claimId, $outcome);
            $processed++;

            if ($outcome['class'] === ErpException::AUTH) {
                $this->disablePush('auth');

                return ['outcome' => 'stopped_auth', 'items' => $processed, 'error_class' => ErpException::AUTH];
            }
        }

        return ['outcome' => $this->hasWork($connection->id) ? 'partial' : 'ok', 'items' => $processed, 'error_class' => null];
    }

    private function hasWork(int $connectionId): bool
    {
        return DB::table('erp_push_items')->where('connection_id', $connectionId)
            ->where(function ($q) {
                $q->where(fn ($q) => $q->whereIn('state', ['queued', 'failed_retry'])->where('next_attempt_at', '<=', now()))
                    ->orWhere(fn ($q) => $q->where('state', 'processing')->where('claimed_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES)));
            })->exists();
    }

    /**
     * §16.9: a non-locking SELECT of a candidate, then a conditional UPDATE on
     * its primary key. Holds no other row lock, so it cannot deadlock with the
     * §4 lock order; a lost race just moves on to the next candidate.
     *
     * @return array{0: object, 1: string}|null
     */
    private function claim(int $connectionId): ?array
    {
        for ($tries = 0; $tries < 5; $tries++) {
            $candidate = DB::table('erp_push_items')->where('connection_id', $connectionId)
                ->whereIn('state', ['queued', 'failed_retry'])->where('next_attempt_at', '<=', now())
                ->orderBy('next_attempt_at')->orderBy('id')->value('id');
            if ($candidate === null) {
                return null;
            }

            $claimId = (string) Str::ulid();
            $won = DB::table('erp_push_items')->where('id', $candidate)
                ->whereIn('state', ['queued', 'failed_retry'])->where('next_attempt_at', '<=', now())
                ->update([
                    'state' => 'processing', 'claim_id' => $claimId, 'claimed_at' => now(),
                    'attempts' => DB::raw('attempts + 1'), 'updated_at' => now(),
                ]);
            if ($won === 1) {
                return [DB::table('erp_push_items')->where('id', $candidate)->first(), $claimId];
            }
        }

        return null;
    }

    /**
     * Step 5: search by reference; one hit is adopted, more than one needs
     * review, none is created. No database transaction is open here.
     *
     * @param  array<string, int|null>  $resolved  per-run cache of country and state IDs
     * @return array{kind: string, class: string|null, message: string|null, lead_id: int|null}
     */
    private function process(object $item, ErpLeadConnector $connector, array &$resolved): array
    {
        $payload = json_decode($item->payload, true) ?: [];
        $ref = (string) ($payload['ext_ref'] ?? '');

        try {
            $hits = $connector->findByReference($ref);
            if (count($hits) === 1) {
                return ['kind' => 'success', 'class' => null, 'message' => null, 'lead_id' => (int) $hits[0]];
            }
            if (count($hits) > 1) {
                return ['kind' => 'ambiguous', 'class' => 'ambiguous', 'message' => 'More than one lead carries this reference.', 'lead_id' => null];
            }

            $leadId = $connector->createLead($this->resolve($payload, $connector, $resolved));

            return ['kind' => 'success', 'class' => null, 'message' => null, 'lead_id' => $leadId];
        } catch (ErpException $e) {
            $retry = $e->isTransient() && (int) $item->attempts < self::MAX_ATTEMPTS;

            return ['kind' => $retry ? 'retry' : 'final', 'class' => $e->errorClass, 'message' => $e->getMessage(), 'lead_id' => null];
        }
    }

    /**
     * Turn _country_code and _region_erp_code into country_id and state_id
     * (resolved once per run) and drop the internal keys. A region missing in
     * the ERP drops state_id and does not fail the item (§16.4).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, int|null>  $resolved
     * @return array<string, mixed>
     */
    private function resolve(array $payload, ErpLeadConnector $connector, array &$resolved): array
    {
        $country = $payload['_country_code'] ?? null;
        $region = $payload['_region_erp_code'] ?? null;
        foreach (array_keys($payload) as $key) {
            if ($key === 'ext_ref' || str_starts_with($key, '_')) {
                unset($payload[$key]);
            }
        }

        if (is_string($country) && $country !== '') {
            $countryKey = 'country:'.$country;
            if (! array_key_exists($countryKey, $resolved)) {
                $resolved[$countryKey] = $connector->resolveCountry($country);
            }
            if ($resolved[$countryKey] !== null) {
                $payload['country_id'] = $resolved[$countryKey];

                if (is_string($region) && $region !== '') {
                    $regionKey = 'state:'.$country.':'.$region;
                    if (! array_key_exists($regionKey, $resolved)) {
                        $resolved[$regionKey] = $connector->resolveRegion($country, $region);
                    }
                    if ($resolved[$regionKey] !== null) {
                        $payload['state_id'] = $resolved[$regionKey];
                    }
                }
            }
        }

        return $payload;
    }

    /**
     * Step 7: the outcome and its lead_syncs change, in one transaction in the
     * §4 lock order, only if the claim is still ours.
     *
     * @param  array{kind: string, class: string|null, message: string|null, lead_id: int|null}  $outcome
     */
    private function apply(object $item, string $claimId, array $outcome): void
    {
        DB::transaction(function () use ($item, $claimId, $outcome) {
            DB::table('visitors')->where('id', $item->visitor_id)->lockForUpdate()->value('id');
            DB::table('lead_syncs')->where('visitor_id', $item->visitor_id)->where('connection_id', $item->connection_id)->lockForUpdate()->first();
            $current = DB::table('erp_push_items')->where('id', $item->id)->lockForUpdate()->first();
            if ($current === null || $current->claim_id !== $claimId || $current->state !== 'processing') {
                return;
            }

            $now = now();
            $sync = DB::table('lead_syncs')->where('visitor_id', $item->visitor_id)->where('connection_id', $item->connection_id);
            $error = ['error_class' => $outcome['class'], 'error_message' => $outcome['message'] ? mb_substr($outcome['message'], 0, 255) : null];

            switch ($outcome['kind']) {
                case 'success':
                    DB::table('erp_push_items')->where('id', $item->id)->update([
                        'state' => 'succeeded', 'erp_lead_id' => $outcome['lead_id'], 'completed_at' => $now,
                        'error_class' => null, 'error_message' => null, 'updated_at' => $now,
                    ]);
                    $sync->update(['state' => 'succeeded', 'erp_lead_id' => $outcome['lead_id'], 'succeeded_at' => $now,
                        'active_item_id' => null, 'updated_at' => $now]);
                    break;

                case 'ambiguous':
                    DB::table('erp_push_items')->where('id', $item->id)->update([...$error, 'state' => 'needs_review', 'updated_at' => $now]);
                    $sync->update(['state' => 'needs_review', 'updated_at' => $now]);
                    break;

                case 'retry':
                    $delay = self::BACKOFF_SECONDS[min(max((int) $current->attempts - 1, 0), count(self::BACKOFF_SECONDS) - 1)];
                    DB::table('erp_push_items')->where('id', $item->id)->update([
                        ...$error, 'state' => 'failed_retry', 'next_attempt_at' => $now->copy()->addSeconds($delay), 'updated_at' => $now,
                    ]);
                    $sync->update(['state' => 'queued', 'updated_at' => $now]);
                    break;

                default: // final
                    DB::table('erp_push_items')->where('id', $item->id)->update([...$error, 'state' => 'failed_final', 'completed_at' => $now, 'updated_at' => $now]);
                    $sync->update(['state' => 'failed', 'active_item_id' => null, 'updated_at' => $now]);
            }
        });

        $this->log()->info('item processed', [
            'item_id' => $item->id, 'outcome' => $outcome['kind'], 'error_class' => $outcome['class'], 'attempt' => (int) $item->attempts,
        ]);
    }

    /**
     * @param  array<string, string>  $meta
     */
    private function disablePush(string $reason, array $meta = []): void
    {
        $this->settings->set('erp_push_enabled', false);
        Audit::record('push_disabled', outcome: 'automatic', meta: ['reason' => $reason, ...$meta]);
        $this->log()->warning('push disabled', ['reason' => $reason]);
    }

    private function log(): LoggerInterface
    {
        return Log::channel('drain')->withContext(['run_id' => $this->runId ?? null, 'request_id' => $this->runId ?? null]);
    }
}
