<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('drain_runs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->integer('items_processed')->default(0);
            $table->enum('outcome', ['ok', 'partial', 'skipped_disabled', 'skipped_locked', 'stopped_auth', 'stopped_version', 'error'])->nullable();
            $table->string('error_class', 40)->nullable();

            $table->index('started_at');
        });

        // No visitor personal data in meta.
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->dateTime('at');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('action', 40);
            $table->string('target_type', 40)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('outcome', 20)->default('ok');
            $table->json('meta')->nullable();

            $table->index('at');
            $table->index(['action', 'at']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('drain_runs');
        Schema::dropIfExists('settings');
    }
};
