<?php

namespace Tests\Unit\Services\Domain\AppleWallet;

use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassSettingsResolver;
use HiEvents\Services\Domain\Wallet\WalletPassBrandingResolver;
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

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function resolver(array $config, ?OrganizerSettingDomainObject $settings = null): AppleWalletPassSettingsResolver
    {
        $repository = Mockery::mock(OrganizerSettingsRepositoryInterface::class);
        $repository
            ->shouldReceive('findFirstWhere')
            ->with(['organizer_id' => self::ORGANIZER_ID])
            ->andReturn($settings);

        return new AppleWalletPassSettingsResolver(
            new Repository(['apple-wallet' => $config]),
            $repository,
            new WalletPassBrandingResolver,
        );
    }

    public function test_it_is_not_configured_without_a_pass_type_and_team(): void
    {
        $this->assertFalse($this->resolver([...self::CONFIGURED, 'enabled' => false])->isConfigured());
        $this->assertFalse($this->resolver([...self::CONFIGURED, 'pass_type_identifier' => ' '])->isConfigured());
        $this->assertFalse($this->resolver([...self::CONFIGURED, 'team_identifier' => null])->isConfigured());
        $this->assertTrue($this->resolver(self::CONFIGURED)->isConfigured());
    }

    public function test_no_branding_is_resolved_when_the_platform_is_not_configured(): void
    {
        $settings = (new OrganizerSettingDomainObject)->setAppleWalletEnabled(true);

        $this->assertNull($this->resolver([...self::CONFIGURED, 'enabled' => false], $settings)->resolveForOrganizer(self::ORGANIZER_ID));
    }

    public function test_no_branding_is_resolved_when_the_organizer_has_not_enabled_apple_wallet(): void
    {
        $googleOnly = (new OrganizerSettingDomainObject)->setGoogleWalletEnabled(true)->setAppleWalletEnabled(false);

        $this->assertNull($this->resolver(self::CONFIGURED, $googleOnly)->resolveForOrganizer(self::ORGANIZER_ID));
        $this->assertNull($this->resolver(self::CONFIGURED)->resolveForOrganizer(self::ORGANIZER_ID));
    }

    public function test_the_shared_wallet_branding_is_used_for_an_enabled_organizer(): void
    {
        $settings = (new OrganizerSettingDomainObject)
            ->setAppleWalletEnabled(true)
            ->setWalletPassSettings(['banner_image_url' => 'https://example.com/banner.png']);

        $branding = $this->resolver(self::CONFIGURED, $settings)->resolveForOrganizer(self::ORGANIZER_ID);

        $this->assertSame('https://example.com/banner.png', $branding->bannerImageUrl);
    }
}
