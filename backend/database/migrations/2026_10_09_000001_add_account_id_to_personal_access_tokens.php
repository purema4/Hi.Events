<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->nullable()->index();
            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->string('partial_token', 4)->nullable();
            $table->string('last_used_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropColumn(['account_id', 'partial_token', 'last_used_ip']);
        });
    }
};
