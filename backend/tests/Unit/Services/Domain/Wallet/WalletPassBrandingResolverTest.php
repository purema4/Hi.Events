<?php

namespace Tests\Unit\Services\Domain\Wallet;

use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Services\Domain\Wallet\DTO\WalletPassBrandingDTO;
use HiEvents\Services\Domain\Wallet\WalletPassBrandingResolver;
use Tests\TestCase;

class WalletPassBrandingResolverTest extends TestCase
{
    private function resolve(array|string|null $passSettings, ?array $themeSettings = null): WalletPassBrandingDTO
    {
        return (new WalletPassBrandingResolver)->fromOrganizerSettings(
            (new OrganizerSettingDomainObject)
                ->setWalletPassSettings($passSettings)
                ->setHomepageThemeSettings($themeSettings)
        );
    }

    public function test_the_shared_branding_is_resolved(): void
    {
        $branding = $this->resolve([
            'logo_url' => ' https://example.com/logo.png ',
            'banner_image_url' => 'https://example.com/banner.png',
            'background_color' => '#DE0F00FF',
        ]);

        $this->assertSame('https://example.com/logo.png', $branding->logoUrl);
        $this->assertSame('https://example.com/banner.png', $branding->bannerImageUrl);
        $this->assertSame('#de0f00', $branding->backgroundColor);
    }

    public function test_branding_stored_as_json_is_read(): void
    {
        $branding = $this->resolve('{"banner_image_url":"https://example.com/banner.png"}');

        $this->assertSame('https://example.com/banner.png', $branding->bannerImageUrl);
    }

    public function test_blank_branding_is_unset_and_the_accent_colour_is_the_fallback(): void
    {
        $branding = $this->resolve(
            ['logo_url' => '', 'banner_image_url' => '   ', 'background_color' => ''],
            ['accent' => '#8b5cf6'],
        );

        $this->assertNull($branding->logoUrl);
        $this->assertNull($branding->bannerImageUrl);
        $this->assertSame('#8b5cf6', $branding->backgroundColor);
    }

    public function test_an_organizer_without_branding_gets_no_overrides(): void
    {
        $branding = $this->resolve(null);

        $this->assertNull($branding->logoUrl);
        $this->assertNull($branding->bannerImageUrl);
        $this->assertNull($branding->backgroundColor);
    }
}
