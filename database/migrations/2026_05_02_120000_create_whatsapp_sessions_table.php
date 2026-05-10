<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // WhatsApp sender phone (e.g. "9647701234566") — one active session per phone.
            $table->string('phone')->unique();

            // Optional link to a registered user (resolved when the flow starts).
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();

            // Which flow this session is in (e.g. "park_create", "reserve").
            $table->string('flow')->nullable();

            // Current step in the flow (e.g. "name", "capacity", "city", "location").
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
        Schema::dropIfExists('whatsapp_sessions');
    }
};
