<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Telegram chat_id is a 64-bit signed integer (Telegram docs).
            // We store it as a string for portability and to keep parity
            // with how `phone_number` is treated.
            $table->string('telegram_chat_id')->nullable()->unique()->after('phone_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telegram_chat_id']);
            $table->dropColumn('telegram_chat_id');
        });
    }
};
