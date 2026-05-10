<?php

use App\Enums\CountryTypes;
use App\Enums\StateTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PostGIS is required for the `geography` column and the GiST spatial index.
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

        Schema::create('locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->integer('country')->default(CountryTypes::IRAQ->value);
            $table->string('city')->nullable();
            $table->char('postal_code', 20)->nullable();
            $table->integer('state')->default(StateTypes::BAGHDAD->value);
            $table->geography('coordinates', 'POINT', 4326);
            $table->jsonb('meta_data')->nullable();
            $table->timestamps();

            // GiST spatial index — makes ST_DWithin / ST_Distance / KNN sub-millisecond.
            $table->spatialIndex('coordinates', 'locations_coordinates_gix');
        });

        // Now that `locations` exists, attach the deferred FK from users.location_id.
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'location_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign('location_id')
                    ->references('id')
                    ->on('locations')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['location_id']);
            });
        }

        Schema::dropIfExists('locations');
    }
};
