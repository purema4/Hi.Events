<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_settings', static function (Blueprint $table) {
            $table->string('apple_wallet_logo_url')->nullable();
            $table->string('apple_wallet_strip_image_url')->nullable();
            $table->string('apple_wallet_background_color', 9)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_settings', static function (Blueprint $table) {
            $table->dropColumn(['apple_wallet_logo_url', 'apple_wallet_strip_image_url', 'apple_wallet_background_color']);
        });
    }
};
