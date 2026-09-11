<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\GoogleWallet;

use HiEvents\Locale;

class GoogleWalletButtonResolver
{
    private const DEFAULT_ASSET = 'enUS';

    private const ASSET_BY_LOCALE = [
        Locale::DE->value => 'de',
        Locale::EL->value => 'gr',
        Locale::ES->value => 'esES',
        Locale::FR->value => 'frFR',
        Locale::HU->value => 'hu',
        Locale::IT->value => 'it',
        Locale::NL->value => 'nl',
        Locale::PL->value => 'pl',
        Locale::PT->value => 'pt',
        Locale::PT_BR->value => 'pt',
        Locale::SE->value => 'se',
        Locale::SK->value => 'sk',
        Locale::TR->value => 'tr',
        Locale::VI->value => 'vi',
        Locale::ZH_HK->value => 'zhHK',
    ];

    public function pathForLocale(?string $locale): string
    {
        $asset = self::ASSET_BY_LOCALE[strtolower((string) $locale)] ?? self::DEFAULT_ASSET;

        return resource_path("images/google-wallet/{$asset}_add_to_google_wallet_wallet-button.png");
    }
}
