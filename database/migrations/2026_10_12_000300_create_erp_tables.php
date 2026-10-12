<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_connections', function (Blueprint $table) {
            $table->id();
            $table->enum('kind', ['test', 'live']);
            $table->string('base_url', 255);
            $table->string('database', 100);
            $table->string('login', 254);
            $table->text('api_key')->nullable();
            $table->boolean('is_active')->default(false);
            $table->dateTime('archived_at')->nullable();
            $table->dateTime('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_server_version', 40)->nullable();
            $table->integer('last_test_uid')->nullable();
            $table->dateTime('changed_at')->nullable();
            $table->datetimes();

            $table->index(['kind', 'archived_at']);
        });

        Schema::create('erp_batches', function (Blueprint $table) {
            $table->id();
            $table->char('batch_token', 26)->unique();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('connection_id')->constrained('erp_connections');
            $table->json('field_keys');
            $table->integer('item_count');
            $table->datetimes();
        });

        Schema::create('erp_push_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('erp_batches');
            $table->foreignId('visitor_id')->constrained('visitors');
            $table->foreignId('connection_id')->constrained('erp_connections');
            $table->json('payload');
            $table->enum('state', ['queued', 'processing', 'succeeded', 'failed_retry', 'failed_final', 'needs_review', 'cancelled']);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at');
            $table->dateTime('claimed_at')->nullable();
            $table->char('claim_id', 26)->nullable();
            $table->string('error_class', 40)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->unsignedBigInteger('erp_lead_id')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->datetimes();

            $table->index(['state', 'next_attempt_at']);
            $table->index(['connection_id', 'state']);
        });

        // Authority for "has this visitor reached the ERP", one row per visitor and connection.
        Schema::create('lead_syncs', function (Blueprint $table) {
            $table->foreignId('visitor_id')->constrained('visitors');
            $table->foreignId('connection_id')->constrained('erp_connections');
            $table->char('ext_ref', 30)->unique();
            $table->enum('state', ['never', 'queued', 'succeeded', 'failed', 'needs_review'])->default('never');
            $table->unsignedBigInteger('erp_lead_id')->nullable();
            $table->foreignId('active_item_id')->nullable()->constrained('erp_push_items');
            $table->dateTime('succeeded_at')->nullable();
            $table->datetimes();

            $table->primary(['visitor_id', 'connection_id']);
            $table->index(['connection_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_syncs');
        Schema::dropIfExists('erp_push_items');
        Schema::dropIfExists('erp_batches');
        Schema::dropIfExists('erp_connections');
    }
};
