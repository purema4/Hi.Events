<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_settings', function (Blueprint $table) {
            $table->string('google_wallet_logo_url')->nullable();
            $table->string('google_wallet_background_color', 9)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_settings', function (Blueprint $table) {
            $table->dropColumn(['google_wallet_logo_url', 'google_wallet_background_color']);
        });
    }
};
