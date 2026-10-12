<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exhibitions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->char('public_token', 10)->unique();
            $table->boolean('is_open')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->datetimes();
        });

        Schema::create('field_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->boolean('is_system')->default(false);
            $table->enum('type', ['text', 'select', 'region', 'phone', 'email']);
            $table->string('label_en', 100);
            $table->string('label_ar', 100);
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->boolean('send_to_erp')->default(true);
            $table->datetimes();
        });

        Schema::create('regions', function (Blueprint $table) {
            $table->char('country_code', 2);
            $table->string('code', 10);
            $table->string('name_en', 100);
            $table->string('name_ar', 100);
            $table->string('erp_code', 10);
            $table->integer('sort_order')->default(0);
            $table->primary(['country_code', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
        Schema::dropIfExists('field_definitions');
        Schema::dropIfExists('exhibitions');
    }
};
