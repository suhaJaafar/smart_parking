<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wires the 1:1 foreign keys between `locations` and the two tables that
 * reference it (`users` and `parks`).
 *
 * Why a dedicated migration?
 *   - `users` and `parks` are created before `locations` in migration order,
 *     so the FK cannot be declared inline at table-creation time.
 *   - Keeping the relation wiring in its own migration makes the dependency
 *     between the three tables explicit and easy to reason about.
 *
 * Delete semantics:
 *   - users.location_id  → ON DELETE SET NULL  (user identity survives if
 *                                               its location row goes away)
 *   - parks.location_id  → ON DELETE CASCADE   (a park cannot exist without
 *                                               its physical location)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
        });

        Schema::table('parks', function (Blueprint $table) {
            $table->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('parks', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
        });
    }
};
