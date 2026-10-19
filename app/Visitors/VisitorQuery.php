<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Visitors;

use App\Models\Exhibition;
use App\Models\User;
use App\Models\Visitor;
use App\Registration\Normalizer;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The visitor list and the export share this query (work order §7 "Visitor
 * list"): current visitors only, staff see only their own entries, filters
 * and sort fields come from allowlists.
 */
class VisitorQuery
{
    public const SORTS = [
        'newest' => ['created_at', 'desc'],
        'oldest' => ['created_at', 'asc'],
        'name' => ['name', 'asc'],
        'name_desc' => ['name', 'desc'],
    ];

    public const SOURCES = ['public', 'staff'];

    public const SYNC_STATES = ['never', 'queued', 'succeeded', 'failed', 'needs_review'];

    /**
     * Plain words for a sync state (M8 CO-17). The state is the one for the
     * reference connection (the active one, or Live), so "Sent to" names its kind.
     */
    public static function syncLabel(string $state, ?string $kind): string
    {
        return match ($state) {
            'queued' => 'Waiting',
            'succeeded' => 'Sent to '.strtoupper($kind ?? 'live'),
            'failed' => 'Failed',
            'needs_review' => 'Check in Odoo',
            default => 'Not sent',
        };
    }

    public function __construct(private readonly Settings $settings) {}

    /**
     * The filters the request asks for, reduced to allowed values.
     *
     * @return array{q: string, exhibition: int|null, occupation: string, region: string, source: string, sync: string, duplicate: bool, package: string, sort: string}
     */
    public function filters(Request $request): array
    {
        $string = fn (string $key) => is_string($request->query($key)) ? trim($request->query($key)) : '';

        return [
            'q' => mb_substr($string('q'), 0, 100),
            'exhibition' => ctype_digit($string('exhibition')) ? (int) $string('exhibition') : null,
            'occupation' => mb_substr($string('occupation'), 0, 100),
            'region' => mb_substr($string('region'), 0, 10),
            'source' => in_array($string('source'), self::SOURCES, true) ? $string('source') : '',
            'sync' => in_array($string('sync'), self::SYNC_STATES, true) ? $string('sync') : '',
            'duplicate' => $string('duplicate') === '1',
            // M9 CO-10: received or not received (only exhibitions that offer a package count as "not received").
            'package' => in_array($string('package'), ['received', 'not_received'], true) ? $string('package') : '',
            'sort' => array_key_exists($string('sort'), self::SORTS) ? $string('sort') : 'newest',
        ];
    }

    /**
     * @param  array{q: string, exhibition: int|null, occupation: string, region: string, source: string, sync: string, duplicate: bool, package: string, sort: string}  $filters
     * @return Builder<Visitor>
     */
    public function build(User $user, array $filters): Builder
    {
        $connectionId = self::referenceConnectionId();

        $query = Visitor::query()
            ->from('visitors')
            ->select('visitors.*')
            ->whereNull('visitors.trashed_at')
            ->whereNull('visitors.deleted_at')
            ->with('exhibition', 'enteredBy', 'packageReceivedBy');

        // The sync state shown is the one for the active connection, or Live when none is active (§16.6).
        $query->leftJoin('lead_syncs as sync', function ($join) use ($connectionId) {
            $join->on('sync.visitor_id', '=', 'visitors.id')->where('sync.connection_id', '=', $connectionId ?? 0);
        })->addSelect(DB::raw("COALESCE(sync.state, 'never') as sync_state"), 'sync.erp_lead_id as sync_erp_lead_id');

        if (! $user->isManager()) {
            $query->where('visitors.entered_by', $user->id)->where('visitors.source', 'staff');
        }

        if ($filters['exhibition'] !== null) {
            $query->where('visitors.exhibition_id', $filters['exhibition']);
        }
        if ($filters['occupation'] !== '') {
            $query->where('visitors.occupation', $filters['occupation']);
        }
        if ($filters['region'] !== '') {
            $query->where('visitors.region_code', $filters['region']);
        }
        if ($filters['source'] !== '') {
            $query->where('visitors.source', $filters['source']);
        }
        if ($filters['duplicate']) {
            $query->whereNotNull('visitors.possible_duplicate_of');
        }
        if ($filters['package'] === 'received') {
            $query->whereNotNull('visitors.package_received_at');
        } elseif ($filters['package'] === 'not_received') {
            $query->whereNull('visitors.package_received_at')
                ->whereIn('visitors.exhibition_id', Exhibition::query()->select('id')->where('offers_package', true));
        }
        if ($filters['sync'] !== '') {
            $filters['sync'] === 'never'
                ? $query->where(fn ($q) => $q->whereNull('sync.state')->orWhere('sync.state', 'never'))
                : $query->where('sync.state', $filters['sync']);
        }

        if ($filters['q'] !== '') {
            $this->search($query, $filters['q']);
        }

        [$column, $direction] = self::SORTS[$filters['sort']];

        return $query->orderBy('visitors.'.$column, $direction)->orderBy('visitors.id', $direction);
    }

    /**
     * Name prefix, or phone (normalised the same way as entry, matched on phone_key).
     *
     * @param  Builder<Visitor>  $query
     */
    private function search(Builder $query, string $q): void
    {
        $ascii = Normalizer::asciiDigits($q);
        if (preg_match('/^[+\d\s().-]+$/', $ascii) === 1 && strlen((string) preg_replace('/\D/', '', $ascii)) >= 3) {
            $query->where('visitors.phone_key', Normalizer::phoneKeyFor($q, (string) $this->settings->get('default_country')));

            return;
        }

        $query->where('visitors.name', 'like', addcslashes($q, '\\%_').'%');
    }

    /** The active connection, or the current Live connection when none is active. */
    public static function referenceConnectionId(): ?int
    {
        $active = DB::table('erp_connections')->where('is_active', true)->value('id');
        if ($active !== null) {
            return (int) $active;
        }

        $live = DB::table('erp_connections')->where('kind', 'live')->whereNull('archived_at')->value('id');

        return $live !== null ? (int) $live : null;
    }
}
