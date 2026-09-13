<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apple_wallet_registrations', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendee_id')->constrained('attendees')->cascadeOnDelete();
            $table->string('device_library_identifier');
            $table->string('push_token');
            $table->timestamps();

            $table->unique(['device_library_identifier', 'attendee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apple_wallet_registrations');
    }
};
