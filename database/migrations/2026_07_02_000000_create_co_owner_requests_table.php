<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person can ask to co-manage an existing park ("تسجيل دخول لكراج آخر").
 * They introduce themselves in the bot (phone + name) and pick the target
 * park; this table holds that pending request until the park's owner
 * approves it from the web dashboard.
 *
 * On approval the requester's Telegram chat is linked to the owner's account
 * (see `telegram_accounts`), giving them full control of the owner's parks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('co_owner_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Target park and its owner at request time. owner_id is
            // denormalised so the dashboard can scope requests to the
            // signed-in owner with a single indexed lookup.
            $table->foreignUuid('park_id')->constrained('parks')->cascadeOnDelete();
            $table->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();

            // How the requester introduced themselves in the bot.
            $table->string('requester_name');
            $table->string('requester_phone');
            $table->string('telegram_chat_id');
            $table->string('channel')->default('telegram');

            // pending → approved | rejected.
            $table->string('status')->default('pending');

            // Who decided, and when.
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->index(['owner_id', 'status']);
            $table->index('telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('co_owner_requests');
    }
};
