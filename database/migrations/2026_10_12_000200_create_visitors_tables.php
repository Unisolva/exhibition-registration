<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exhibition_id')->constrained('exhibitions');
            $table->string('name', 150);
            $table->string('phone_raw', 40);
            $table->string('phone_e164', 20)->nullable();
            $table->string('phone_key', 40);
            $table->boolean('phone_valid')->default(false);
            $table->string('email', 254)->nullable();
            $table->string('occupation', 100)->nullable();
            $table->string('region_code', 10)->nullable();
            $table->string('region_label', 100)->nullable();
            $table->json('extra')->nullable();
            $table->enum('source', ['public', 'staff']);
            $table->foreignId('entered_by')->nullable()->constrained('users');
            $table->char('submission_token', 26)->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('possible_duplicate_of')->nullable()->constrained('visitors');
            $table->string('locale', 5);
            $table->char('request_id', 26);
            $table->dateTime('edited_at')->nullable();
            $table->dateTime('trashed_at')->nullable();
            $table->foreignId('trashed_by')->nullable()->constrained('users');
            $table->dateTime('deleted_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users');
            $table->datetimes();

            $table->index(['exhibition_id', 'created_at']);
            $table->index('phone_key');
            $table->index(['exhibition_id', 'phone_key']);
            $table->index('source');
            $table->index('trashed_at');
            $table->index('deleted_at');
        });

        // Append-only edit log; purged with its visitor on permanent deletion.
        Schema::create('visitor_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visitor_id')->constrained('visitors');
            $table->foreignId('user_id')->constrained('users');
            $table->dateTime('at');
            $table->json('changes');

            $table->index(['visitor_id', 'at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_edits');
        Schema::dropIfExists('visitors');
    }
};
