<?php

namespace Tests\Unit\Services\Domain\GoogleWallet;

use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Domain\GoogleWallet\DTO\GoogleWalletPassSettingsDTO;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletPassSettingsResolver;
use Illuminate\Config\Repository;
use Mockery;
use Tests\TestCase;

class GoogleWalletPassSettingsResolverTest extends TestCase
{
    private function resolve(array $passSettings, ?string $accent = null): ?GoogleWalletPassSettingsDTO
    {
        $settings = (new OrganizerSettingDomainObject)
            ->setGoogleWalletEnabled(true)
            ->setGoogleWalletPassSettings($passSettings)
            ->setHomepageThemeSettings($accent === null ? [] : ['accent' => $accent]);

        $repository = Mockery::mock(OrganizerSettingsRepositoryInterface::class);
        $repository->shouldReceive('findFirstWhere')->andReturn($settings);

        $resolver = new GoogleWalletPassSettingsResolver(
            new Repository(['google-wallet' => ['enabled' => true, 'issuer_id' => '3388000000000000000']]),
            $repository,
        );

        return $resolver->resolveForOrganizer(1);
    }

    public function test_a_theme_accent_carrying_an_alpha_channel_is_reduced_to_rgb(): void
    {
        $this->assertSame('#de0f00', $this->resolve([], '#de0f00ff')->themeAccentColor);
    }

    public function test_shorthand_hex_is_expanded(): void
    {
        $this->assertSame('#ffaa00', $this->resolve([], '#fa0')->themeAccentColor);
    }

    public function test_a_plain_six_digit_hex_is_kept(): void
    {
        $this->assertSame('#8b5cf6', $this->resolve(['background_color' => '#8B5CF6'])->backgroundColor);
    }

    public function test_an_unparseable_colour_is_dropped_rather_than_sent_to_google(): void
    {
        $this->assertNull($this->resolve([], 'rgba(222, 15, 0, 1)')->themeAccentColor);
    }

    public function test_the_explicit_colour_and_theme_accent_are_kept_apart(): void
    {
        $settings = $this->resolve(['background_color' => '#123456'], '#de0f00ff');

        $this->assertSame('#123456', $settings->backgroundColor);
        $this->assertSame('#de0f00', $settings->themeAccentColor);
    }
}
