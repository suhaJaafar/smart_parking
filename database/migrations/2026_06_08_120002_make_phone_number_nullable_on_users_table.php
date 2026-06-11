<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram users have no phone number — they are identified by
 * `telegram_chat_id`. The original `users` table marked `phone_number`
 * NOT NULL because, at the time, every account came in through the
 * WhatsApp bot or the SPA login (both of which always carry a phone).
 *
 * Now that Telegram is a first-class channel, the column must be
 * nullable so an account can be created with `telegram_chat_id` only.
 *
 * Application-layer guarantee ({@see \App\Bots\Flows\OnboardingFlow}):
 * exactly one of (`phone_number`, `telegram_chat_id`) is always set
 * on a bot-provisioned user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_number')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_number')->nullable(false)->change();
        });
    }
};
