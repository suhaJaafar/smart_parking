<?php

use App\Enums\PaymentStatusTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('status', array_column(PaymentStatusTypes::cases(), 'value'))
                ->default(PaymentStatusTypes::CREATED->value);
            $table->string('qi_status')->nullable();
            $table->foreignUuid('reserve_id')->nullable()->constrained('reserves')->nullOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 15, 3);
            $table->string('currency', 3);
            $table->string('request_id')->unique();
            $table->string('payment_id')->nullable();
            $table->text('form_url')->nullable();
            $table->string('token')->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['reserve_id', 'status']);
            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
