<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Core, follow-on B: what a person has chosen for themselves.
 *
 * Small settings that belong to a user rather than to the business — the light
 * or dark choice today, later a saved grid layout or a default report scope.
 * A key/value table rather than a column per preference, because these arrive
 * one at a time and a column per preference means a migration against a
 * production database every time somebody adds a checkbox.
 *
 * Business settings do NOT live here. Thresholds, approval bands and anything
 * else the company decides go in agora.Setting (T026), because those are the
 * company's rules and must not be per-user.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationHelper::table('UserPreference', function (Blueprint $table) {
            $table->bigInteger('UserId');
            $table->string('PrefKey', 60);
            $table->string('PrefValue', 400)->nullable();
        });
        MigrationHelper::naturalKey('UserPreference', ['BranchId', 'UserId', 'PrefKey']);

        MigrationHelper::recordVersion('1.1', 'Per-user preferences, starting with the light/dark choice.');
    }

    public function down(): void
    {
        MigrationHelper::drop('UserPreference');
    }
};
