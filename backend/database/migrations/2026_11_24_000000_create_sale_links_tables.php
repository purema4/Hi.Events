<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->foreignId('event_occurrence_id')->nullable()->constrained('event_occurrences')->onDelete('set null');
            $table->string('token', 64)->unique();
            $table->boolean('price_includes_tax')->default(false);
            $table->foreignId('promo_code_id')->nullable()->constrained('promo_codes')->onDelete('set null');
            $table->string('promo_mode', 32)->default('ATTRIBUTION_ONLY');
            $table->foreignId('affiliate_id')->nullable()->constrained('affiliates')->onDelete('set null');
            $table->boolean('bypass_capacity')->default(true);
            $table->boolean('count_toward_cap')->default(true);
            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('uses_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->string('email')->nullable();
            $table->boolean('email_locked')->default(false);
            $table->text('note')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['event_id', 'created_at']);
        });

        Schema::create('sale_link_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_link_id')->constrained('sale_links')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('product_price_id')->constrained('product_prices')->onDelete('cascade');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('sale_link_id')->nullable()->constrained('sale_links')->onDelete('set null');
            $table->boolean('counts_toward_capacity')->default(true);
            $table->index(['sale_link_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['sale_link_id', 'status']);
            $table->dropConstrainedForeignId('sale_link_id');
            $table->dropColumn('counts_toward_capacity');
        });

        Schema::dropIfExists('sale_link_items');
        Schema::dropIfExists('sale_links');
    }
};
