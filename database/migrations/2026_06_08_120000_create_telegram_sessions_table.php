<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Telegram chat_id (numeric, but stored as string so 64-bit
            // identifiers survive intact on every DB driver).
            $table->string('chat_id')->unique();

            // Optional link to a registered user (resolved when the flow starts).
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();

            // Which flow this session is in (e.g. "park_create", "nearby_parks").
            $table->string('flow')->nullable();

            // Current step in the flow.
            $table->string('step')->default('idle');

            // Collected answers so far.
            $table->json('data')->nullable();

            // Expiry — anything older is considered abandoned.
            $table->timestamp('expires_at')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_sessions');
    }
};
