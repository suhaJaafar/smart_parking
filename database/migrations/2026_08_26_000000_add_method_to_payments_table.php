<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record how a stay is settled.
 *
 * Until now every payment implicitly went through QiCard. Customers can also
 * hand cash to the garage owner, and the owner needs to know which to expect
 * before releasing the car — so the intent is stored on the payment row.
 *
 * Nullable with no default: existing rows keep NULL, which reads as "card"
 * (the only option that existed when they were created).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('method', 8)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('method');
        });
    }
};
