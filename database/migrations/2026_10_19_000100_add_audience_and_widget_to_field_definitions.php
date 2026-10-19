<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M9 CO-1 (additive): a field can be shown on staff entry only, and a list
 * field can be shown as radio buttons. Existing fields stay public dropdowns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_definitions', function (Blueprint $table) {
            $table->enum('audience', ['all', 'staff'])->default('all')->after('send_to_erp');
            $table->enum('widget', ['select', 'radio'])->default('select')->after('audience');
        });
    }

    public function down(): void
    {
        Schema::table('field_definitions', function (Blueprint $table) {
            $table->dropColumn(['audience', 'widget']);
        });
    }
};
