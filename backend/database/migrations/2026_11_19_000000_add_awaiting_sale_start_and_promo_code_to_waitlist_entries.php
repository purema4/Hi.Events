<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->boolean('awaiting_sale_start')->default(false);
            $table->foreignId('promo_code_id')->nullable()->constrained('promo_codes')->nullOnDelete();
            $table->index(['awaiting_sale_start', 'status'], 'idx_waitlist_awaiting_sale_start');
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->dropIndex('idx_waitlist_awaiting_sale_start');
            $table->dropConstrainedForeignId('promo_code_id');
            $table->dropColumn('awaiting_sale_start');
        });
    }
};
