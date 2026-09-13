<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendees', static function (Blueprint $table) {
            $table->timestamp('apple_wallet_pass_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendees', static function (Blueprint $table) {
            $table->dropColumn('apple_wallet_pass_updated_at');
        });
    }
};
