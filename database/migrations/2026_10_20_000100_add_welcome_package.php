<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

use App\Support\PackageCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M9 CO-4 and CO-5 (additive): an exhibition may offer a welcome package with
 * a note in each language; every visitor has a package code and a "received"
 * status. Existing visitors get codes here (and from ProductSeeder later).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exhibitions', function (Blueprint $table) {
            $table->boolean('offers_package')->default(false)->after('default_locale');
            $table->text('package_note_ar')->nullable()->after('offers_package');
            $table->text('package_note_en')->nullable()->after('package_note_ar');
        });

        Schema::table('visitors', function (Blueprint $table) {
            $table->char('package_code', 6)->nullable()->unique();
            $table->dateTime('package_received_at')->nullable();
            $table->foreignId('package_received_by')->nullable()->constrained('users');
        });

        PackageCode::backfill();
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_received_by');
            $table->dropUnique(['package_code']);
            $table->dropColumn(['package_code', 'package_received_at']);
        });
        Schema::table('exhibitions', function (Blueprint $table) {
            $table->dropColumn(['offers_package', 'package_note_ar', 'package_note_en']);
        });
    }
};
