<?php

namespace Tests\Unit\Services\Domain\AppleWallet;

use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassSettingsResolver;
use Illuminate\Config\Repository;
use Mockery;
use Tests\TestCase;

class AppleWalletPassSettingsResolverTest extends TestCase
{
    private const ORGANIZER_ID = 5;

    private const CONFIGURED = [
        'enabled' => true,
        'pass_type_identifier' => 'pass.events.hi.test',
        'team_identifier' => 'TEAM123456',
    ];

    private function resolver(array $config, ?OrganizerSettingDomainObject $settings = null): AppleWalletPassSettingsResolver
    {
        $repository = Mockery::mock(OrganizerSettingsRepositoryInterface::class);
        $repository
            ->shouldReceive('findFirstWhere')
            ->with(['organizer_id' => self::ORGANIZER_ID])
            ->andReturn($settings);

        return new AppleWalletPassSettingsResolver(new Repository(['apple-wallet' => $config]), $repository);
    }

    private function settings(bool $enabled, ?array $passSettings = null, ?array $themeSettings = null): OrganizerSettingDomainObject
    {
        return (new OrganizerSettingDomainObject)
            ->setAppleWalletEnabled($enabled)
            ->setAppleWalletPassSettings($passSettings)
            ->setHomepageThemeSettings($themeSettings);
    }

    public function test_it_is_not_configured_without_a_pass_type_and_team(): void
    {
        $this->assertFalse($this->resolver([...self::CONFIGURED, 'enabled' => false])->isConfigured());
        $this->assertFalse($this->resolver([...self::CONFIGURED, 'pass_type_identifier' => ' '])->isConfigured());
        $this->assertFalse($this->resolver([...self::CONFIGURED, 'team_identifier' => null])->isConfigured());
        $this->assertTrue($this->resolver(self::CONFIGURED)->isConfigured());
    }

    public function test_no_settings_are_resolved_when_the_platform_is_not_configured(): void
    {
        $resolver = $this->resolver([...self::CONFIGURED, 'enabled' => false], $this->settings(true));

        $this->assertNull($resolver->resolveForOrganizer(self::ORGANIZER_ID));
    }

    public function test_no_settings_are_resolved_when_the_organizer_has_not_enabled_apple_wallet(): void
    {
        $this->assertNull($this->resolver(self::CONFIGURED, $this->settings(false))->resolveForOrganizer(self::ORGANIZER_ID));
        $this->assertNull($this->resolver(self::CONFIGURED)->resolveForOrganizer(self::ORGANIZER_ID));
    }

    public function test_the_organizer_pass_branding_is_resolved(): void
    {
        $settings = $this->resolver(self::CONFIGURED, $this->settings(true, [
            'logo_url' => ' https://example.com/logo.png ',
            'strip_image_url' => 'https://example.com/strip.png',
            'background_color' => '#DE0F00FF',
        ]))->resolveForOrganizer(self::ORGANIZER_ID);

        $this->assertSame('https://example.com/logo.png', $settings->logoUrl);
        $this->assertSame('https://example.com/strip.png', $settings->stripImageUrl);
        $this->assertSame('#de0f00', $settings->backgroundColor);
    }

    public function test_blank_branding_is_treated_as_unset_and_the_accent_colour_is_the_fallback(): void
    {
        $settings = $this->resolver(self::CONFIGURED, $this->settings(
            true,
            ['logo_url' => '', 'strip_image_url' => '   ', 'background_color' => ''],
            ['accent' => '#8b5cf6'],
        ))->resolveForOrganizer(self::ORGANIZER_ID);

        $this->assertNull($settings->logoUrl);
        $this->assertNull($settings->stripImageUrl);
        $this->assertSame('#8b5cf6', $settings->backgroundColor);
    }
}
