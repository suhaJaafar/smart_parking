<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Pre-bookings are made for a future arrival, so we record the time
     * the customer intends to reach the park. NULL means "no specific
     * time" (legacy rows and on-site reservations).
     */
    public function up(): void
    {
        Schema::table('reserves', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reserves', function (Blueprint $table) {
            $table->dropColumn('scheduled_at');
        });
    }
};
