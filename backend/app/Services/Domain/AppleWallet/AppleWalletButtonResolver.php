<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\AppleWallet;

use HiEvents\Locale;

class AppleWalletButtonResolver
{
    private const DEFAULT_ASSET = 'US_UK';

    private const ASSET_BY_LOCALE = [
        Locale::DE->value => 'DE',
        Locale::EL->value => 'GR',
        Locale::ES->value => 'ES',
        Locale::FR->value => 'FR',
        Locale::HU->value => 'HU',
        Locale::IT->value => 'IT',
        Locale::NL->value => 'NL',
        Locale::PL->value => 'PL',
        Locale::PT->value => 'PT',
        Locale::PT_BR->value => 'PTBR',
        Locale::SE->value => 'SE',
        Locale::SK->value => 'SK',
        Locale::TR->value => 'TR',
        Locale::VI->value => 'VN',
        Locale::ZH_CN->value => 'CN',
        Locale::ZH_HK->value => 'HK',
    ];

    public function pathForLocale(?string $locale): string
    {
        $asset = self::ASSET_BY_LOCALE[strtolower((string) $locale)] ?? self::DEFAULT_ASSET;

        return resource_path("images/apple-wallet/{$asset}_add_to_apple_wallet.png");
    }
}
