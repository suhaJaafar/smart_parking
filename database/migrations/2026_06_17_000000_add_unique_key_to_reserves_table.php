<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reserves', function (Blueprint $table) {
            $table->string('booking_code', 4)->after('id');
            $table->index(['park_id', 'booking_code', 'status'], 'reserves_park_booking_status_idx');
            $table->index(['user_id', 'booking_code'], 'reserves_user_booking_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reserves', function (Blueprint $table) {
            $table->dropIndex('reserves_park_booking_status_idx');
            $table->dropIndex('reserves_user_booking_idx');
            $table->dropColumn('booking_code');
        });
    }
};
