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
        Schema::create('cars', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Owner of the car (every car belongs to exactly one user).
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Governorate / plate prefix, e.g. "BG" for Baghdad.
            $table->string('plate_prefix', 8);

            $table->string('car_number', 20);
            $table->string('model')->nullable();

            // The park the car is CURRENTLY inside (null when not parked).
            $table->foreignUuid('park_id')
                ->nullable()
                ->constrained('parks')
                ->nullOnDelete();

            $table->timestamps();

            // A plate is unique within its governorate.
            $table->unique(['plate_prefix', 'car_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cars');
    }
};
