<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The space owner sets a single, flat parking price per reservation. It is
 * charged once when the car is entered (no time-based accrual). Existing
 * parks default to 3000 so nothing changes for them until the owner sets
 * their own price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parks', function (Blueprint $table) {
            $table->decimal('price', 10, 3)->default(3000)->after('free_spaces');
        });
    }

    public function down(): void
    {
        Schema::table('parks', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }
};
