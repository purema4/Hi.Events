<?php

namespace Tests\Unit\Services\Domain\AppleWallet;

use HiEvents\Locale;
use HiEvents\Services\Domain\AppleWallet\AppleWalletButtonResolver;
use Tests\TestCase;

class AppleWalletButtonResolverTest extends TestCase
{
    public function test_every_supported_locale_has_an_official_badge(): void
    {
        $resolver = new AppleWalletButtonResolver;

        foreach (Locale::getSupportedLocales() as $locale) {
            $this->assertFileExists($resolver->pathForLocale($locale), "No Apple Wallet badge for $locale");
        }
    }

    public function test_the_badge_follows_the_attendee_locale(): void
    {
        $resolver = new AppleWalletButtonResolver;

        $this->assertStringEndsWith('/FR_add_to_apple_wallet.png', $resolver->pathForLocale('fr'));
        $this->assertStringEndsWith('/PTBR_add_to_apple_wallet.png', $resolver->pathForLocale('pt-BR'));
    }

    public function test_an_unknown_locale_falls_back_to_the_english_badge(): void
    {
        $resolver = new AppleWalletButtonResolver;

        $this->assertStringEndsWith('/US_UK_add_to_apple_wallet.png', $resolver->pathForLocale('xx'));
        $this->assertStringEndsWith('/US_UK_add_to_apple_wallet.png', $resolver->pathForLocale(null));
    }
}
