<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Support;

use App\Models\User;
use App\Models\Visitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ERP rows written straight to the database, for testing the lock rule and
 * retention before the push engine exists. Hosts are invented.
 */
trait ErpRows
{
    protected function erpConnection(string $kind, bool $active = false): int
    {
        return DB::table('erp_connections')->insertGetId([
            'kind' => $kind,
            'base_url' => "https://{$kind}-erp.example.test",
            'database' => "{$kind}-db",
            'login' => 'api@example.test',
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function syncState(Visitor $visitor, int $connectionId, string $state, ?int $leadId = null): void
    {
        DB::table('lead_syncs')->insert([
            'visitor_id' => $visitor->id,
            'connection_id' => $connectionId,
            'ext_ref' => 'EXR-'.Str::ulid(),
            'state' => $state,
            'erp_lead_id' => $leadId,
            'succeeded_at' => $state === 'succeeded' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A queued item that makes the visitor locked (active_item_id set). Returns the item ID. */
    protected function activeItem(Visitor $visitor, int $connectionId, string $itemState = 'queued'): int
    {
        $batch = DB::table('erp_batches')->insertGetId([
            'batch_token' => (string) Str::ulid(),
            'created_by' => User::factory()->admin()->create()->id,
            'connection_id' => $connectionId,
            'field_keys' => '[]',
            'item_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $item = DB::table('erp_push_items')->insertGetId([
            'batch_id' => $batch,
            'visitor_id' => $visitor->id,
            'connection_id' => $connectionId,
            'payload' => json_encode(['contact_name' => $visitor->name, 'phone' => $visitor->phone_e164]),
            'state' => $itemState,
            'next_attempt_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('lead_syncs')->insert([
            'visitor_id' => $visitor->id,
            'connection_id' => $connectionId,
            'ext_ref' => 'EXR-'.Str::ulid(),
            'state' => $itemState === 'needs_review' ? 'needs_review' : 'queued',
            'active_item_id' => $item,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $item;
    }
}
