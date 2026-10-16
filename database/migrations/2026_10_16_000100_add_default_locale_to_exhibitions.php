<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M8 CO-6 (additive): each exhibition's public form opens in its own default
 * language. Existing exhibitions get Arabic, the former default for all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exhibitions', function (Blueprint $table) {
            $table->enum('default_locale', ['ar', 'en'])->default('ar')->after('is_open');
        });
    }

    public function down(): void
    {
        Schema::table('exhibitions', function (Blueprint $table) {
            $table->dropColumn('default_locale');
        });
    }
};
