<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_settings', static function (Blueprint $table) {
            $table->jsonb('wallet_pass_settings')->nullable();
        });

        Schema::table('event_settings', static function (Blueprint $table) {
            $table->string('wallet_pass_logo_url')->nullable();
            $table->string('wallet_pass_banner_url')->nullable();
            $table->string('wallet_pass_background_color', 9)->nullable();
        });

        DB::table('organizer_settings')
            ->where(static fn ($query) => $query
                ->whereNotNull('google_wallet_pass_settings')
                ->orWhereNotNull('apple_wallet_pass_settings'))
            ->orderBy('id')
            ->each(static function (object $settings) {
                $google = json_decode((string) $settings->google_wallet_pass_settings, true) ?: [];
                $apple = json_decode((string) $settings->apple_wallet_pass_settings, true) ?: [];

                $shared = array_filter([
                    'logo_url' => $google['logo_url'] ?? $apple['logo_url'] ?? null,
                    'banner_image_url' => $google['hero_image_url'] ?? $apple['strip_image_url'] ?? null,
                    'background_color' => $google['background_color'] ?? $apple['background_color'] ?? null,
                ]);

                DB::table('organizer_settings')
                    ->where('id', $settings->id)
                    ->update(['wallet_pass_settings' => $shared === [] ? null : json_encode($shared)]);
            });

        DB::table('event_settings')->update([
            'wallet_pass_logo_url' => DB::raw('COALESCE(google_wallet_logo_url, apple_wallet_logo_url)'),
            'wallet_pass_banner_url' => DB::raw('COALESCE(google_wallet_banner_url, apple_wallet_strip_image_url)'),
            'wallet_pass_background_color' => DB::raw('COALESCE(google_wallet_background_color, apple_wallet_background_color)'),
        ]);

        Schema::table('organizer_settings', static function (Blueprint $table) {
            $table->dropColumn(['google_wallet_pass_settings', 'apple_wallet_pass_settings']);
        });

        Schema::table('event_settings', static function (Blueprint $table) {
            $table->dropColumn([
                'google_wallet_banner_url',
                'google_wallet_logo_url',
                'google_wallet_background_color',
                'apple_wallet_logo_url',
                'apple_wallet_strip_image_url',
                'apple_wallet_background_color',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('organizer_settings', static function (Blueprint $table) {
            $table->jsonb('google_wallet_pass_settings')->nullable();
            $table->jsonb('apple_wallet_pass_settings')->nullable();
        });

        Schema::table('event_settings', static function (Blueprint $table) {
            $table->string('google_wallet_banner_url')->nullable();
            $table->string('google_wallet_logo_url')->nullable();
            $table->string('google_wallet_background_color', 9)->nullable();
            $table->string('apple_wallet_logo_url')->nullable();
            $table->string('apple_wallet_strip_image_url')->nullable();
            $table->string('apple_wallet_background_color', 9)->nullable();
        });

        DB::table('organizer_settings')
            ->whereNotNull('wallet_pass_settings')
            ->orderBy('id')
            ->each(static function (object $settings) {
                $shared = json_decode((string) $settings->wallet_pass_settings, true) ?: [];

                DB::table('organizer_settings')
                    ->where('id', $settings->id)
                    ->update([
                        'google_wallet_pass_settings' => json_encode(array_filter([
                            'logo_url' => $shared['logo_url'] ?? null,
                            'hero_image_url' => $shared['banner_image_url'] ?? null,
                            'background_color' => $shared['background_color'] ?? null,
                        ])),
                        'apple_wallet_pass_settings' => json_encode(array_filter([
                            'logo_url' => $shared['logo_url'] ?? null,
                            'strip_image_url' => $shared['banner_image_url'] ?? null,
                            'background_color' => $shared['background_color'] ?? null,
                        ])),
                    ]);
            });

        DB::table('event_settings')->update([
            'google_wallet_logo_url' => DB::raw('wallet_pass_logo_url'),
            'google_wallet_banner_url' => DB::raw('wallet_pass_banner_url'),
            'google_wallet_background_color' => DB::raw('wallet_pass_background_color'),
            'apple_wallet_logo_url' => DB::raw('wallet_pass_logo_url'),
            'apple_wallet_strip_image_url' => DB::raw('wallet_pass_banner_url'),
            'apple_wallet_background_color' => DB::raw('wallet_pass_background_color'),
        ]);

        Schema::table('organizer_settings', static function (Blueprint $table) {
            $table->dropColumn('wallet_pass_settings');
        });

        Schema::table('event_settings', static function (Blueprint $table) {
            $table->dropColumn(['wallet_pass_logo_url', 'wallet_pass_banner_url', 'wallet_pass_background_color']);
        });
    }
};
