<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->boolean('any_tier')->default(false);
            $table->timestamp('next_sale_start_at')->nullable();
            $table->index(['status', 'next_sale_start_at'], 'idx_waitlist_next_sale_start');
        });

        // Entries that were waiting for their sale to start wait for the later of the product and price start dates.
        DB::statement("
            UPDATE waitlist_entries
            SET next_sale_start_at = GREATEST(products.sale_start_date, product_prices.sale_start_date)
            FROM product_prices
            JOIN products ON products.id = product_prices.product_id
            WHERE product_prices.id = waitlist_entries.product_price_id
              AND waitlist_entries.awaiting_sale_start = true
              AND waitlist_entries.status = 'WAITING'
        ");

        // Waiting entries on tiered products move to the whole product; a person waiting on several tiers keeps their earliest entry.
        DB::statement("
            UPDATE waitlist_entries
            SET any_tier = true
            WHERE id IN (
                SELECT id FROM (
                    SELECT waitlist_entries.id,
                        ROW_NUMBER() OVER (
                            PARTITION BY waitlist_entries.email, product_prices.product_id, COALESCE(waitlist_entries.event_occurrence_id, 0)
                            ORDER BY waitlist_entries.created_at, waitlist_entries.id
                        ) AS entry_rank
                    FROM waitlist_entries
                    JOIN product_prices ON product_prices.id = waitlist_entries.product_price_id
                    JOIN products ON products.id = product_prices.product_id
                    WHERE products.type = 'TIERED'
                      AND waitlist_entries.status = 'WAITING'
                      AND waitlist_entries.deleted_at IS NULL
                ) ranked
                WHERE ranked.entry_rank = 1
            )
        ");

        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->dropIndex('idx_waitlist_awaiting_sale_start');
            $table->dropColumn('awaiting_sale_start');
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->boolean('awaiting_sale_start')->default(false);
            $table->index(['awaiting_sale_start', 'status'], 'idx_waitlist_awaiting_sale_start');
        });

        DB::statement('UPDATE waitlist_entries SET awaiting_sale_start = next_sale_start_at IS NOT NULL');

        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->dropIndex('idx_waitlist_next_sale_start');
            $table->dropColumn(['any_tier', 'next_sale_start_at']);
        });
    }
};
