<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_settings', static function (Blueprint $table) {
            $table->boolean('apple_wallet_enabled')->default(false);
            $table->jsonb('apple_wallet_pass_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizer_settings', static function (Blueprint $table) {
            $table->dropColumn(['apple_wallet_enabled', 'apple_wallet_pass_settings']);
        });
    }
};
