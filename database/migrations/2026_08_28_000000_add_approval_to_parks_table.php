<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Put newly registered garages behind admin review.
 *
 * A garage is a physical place taking real money, so it cannot go live purely
 * because someone filled in a form. The column defaults to `pending`, which
 * means every row that already exists would be switched off by this migration
 * — hence the backfill: anything created before review existed was, by
 * definition, already trusted and stays approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parks', function (Blueprint $table) {
            // Values mirror App\Models\Park::APPROVAL_* — kept as literals so
            // this migration never breaks if those constants are renamed.
            $table->string('approval_status')->default('pending');
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->index(['approval_status', 'user_id']);
        });

        DB::table('parks')->update([
            'approval_status' => 'approved',
            'approved_at'     => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('parks', function (Blueprint $table) {
            $table->dropIndex(['approval_status', 'user_id']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approval_status', 'approved_at', 'rejection_reason']);
        });
    }
};
