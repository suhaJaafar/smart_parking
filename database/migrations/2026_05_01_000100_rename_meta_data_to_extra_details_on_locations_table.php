<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            // Drop the old jsonb column and add the string column the app actually uses.
            // (The two have incompatible types, so a simple renameColumn wouldn't suffice.)
            if (Schema::hasColumn('locations', 'meta_data')) {
                $table->dropColumn('meta_data');
            }
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->text('extra_details')->nullable()->after('coordinates');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            if (Schema::hasColumn('locations', 'extra_details')) {
                $table->dropColumn('extra_details');
            }
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->jsonb('meta_data')->nullable();
        });
    }
};
