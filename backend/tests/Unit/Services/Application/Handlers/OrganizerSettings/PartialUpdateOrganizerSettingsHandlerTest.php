<?php

namespace Tests\Unit\Services\Application\Handlers\OrganizerSettings;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\DTO\PartialUpdateOrganizerSettingsDTO;
use HiEvents\Services\Application\Handlers\Organizer\Settings\PartialUpdateOrganizerSettingsHandler;
use HiEvents\Services\Domain\Event\EventSpamCheckDispatchService;
use HiEvents\Services\Domain\Event\HomepageSectionsSanitizer;
use HiEvents\Services\Domain\Wallet\WalletPassSyncDispatcher;
use Illuminate\Encryption\Encrypter;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class PartialUpdateOrganizerSettingsHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private OrganizerSettingsRepositoryInterface $settingsRepository;

    private OrganizerRepositoryInterface $organizerRepository;

    private PartialUpdateOrganizerSettingsHandler $handler;

    private Encrypter $encrypter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsRepository = Mockery::mock(OrganizerSettingsRepositoryInterface::class);
        $this->organizerRepository = Mockery::mock(OrganizerRepositoryInterface::class);
        $this->encrypter = new Encrypter(Encrypter::generateKey('aes-256-cbc'), 'aes-256-cbc');

        $this->handler = new PartialUpdateOrganizerSettingsHandler(
            organizerSettingsRepository: $this->settingsRepository,
            organizerRepository: $this->organizerRepository,
            eventSpamCheckDispatchService: Mockery::mock(EventSpamCheckDispatchService::class)->shouldIgnoreMissing(),
            homepageSectionsSanitizer: Mockery::mock(HomepageSectionsSanitizer::class),
            walletPassSyncDispatcher: Mockery::mock(WalletPassSyncDispatcher::class)->shouldIgnoreMissing(),
            encrypter: $this->encrypter,
        );
    }

    public function test_tracking_pixels_are_persisted(): void
    {
        $organizer = new OrganizerDomainObject;
        $organizer->setId(1);

        $existingSettings = new OrganizerSettingDomainObject;
        $existingSettings->setId(10);
        $existingSettings->setOrganizerId(1);

        $this->organizerRepository
            ->shouldReceive('findFirstWhere')
            ->with(['id' => 1, 'account_id' => '100'])
            ->once()
            ->andReturn($organizer);

        $this->settingsRepository
            ->shouldReceive('findFirstWhere')
            ->with(['organizer_id' => 1])
            ->once()
            ->andReturn($existingSettings);

        $trackingPixels = [
            ['provider' => 'facebook_pixel', 'pixel_id' => '1234567890', 'enabled' => true],
            ['provider' => 'google_tag_manager', 'pixel_id' => 'GTM-XXXXXXX', 'enabled' => true],
        ];

        $this->settingsRepository
            ->shouldReceive('updateWhere')
            ->once()
            ->with(
                Mockery::on(function (array $attributes) use ($trackingPixels) {
                    return $attributes['tracking_pixels'] === $trackingPixels
                        && $attributes['tracking_consent_acknowledged'] === true;
                }),
                Mockery::any()
            );

        $updatedSettings = new OrganizerSettingDomainObject;
        $updatedSettings->setId(10);

        $this->settingsRepository
            ->shouldReceive('findFirst')
            ->with(10)
            ->once()
            ->andReturn($updatedSettings);

        $dto = PartialUpdateOrganizerSettingsDTO::from([
            'organizer_id' => 1,
            'account_id' => '100',
            'tracking_pixels' => $trackingPixels,
            'tracking_consent_acknowledged' => true,
        ]);

        $result = $this->handler->handle($dto);

        $this->assertInstanceOf(OrganizerSettingDomainObject::class, $result);
    }

    public function test_tracking_pixels_default_to_existing_when_not_provided(): void
    {
        $organizer = new OrganizerDomainObject;
        $organizer->setId(1);

        $existingPixels = [
            ['provider' => 'facebook_pixel', 'pixel_id' => '9999999', 'enabled' => true],
        ];

        $existingSettings = new OrganizerSettingDomainObject;
        $existingSettings->setId(10);
        $existingSettings->setOrganizerId(1);
        $existingSettings->setTrackingPixels($existingPixels);
        $existingSettings->setTrackingConsentAcknowledged(true);

        $this->organizerRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($organizer);

        $this->settingsRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($existingSettings);

        $this->settingsRepository
            ->shouldReceive('updateWhere')
            ->once()
            ->with(
                Mockery::on(function (array $attributes) use ($existingPixels) {
                    return $attributes['tracking_pixels'] === $existingPixels
                        && $attributes['tracking_consent_acknowledged'] === true;
                }),
                Mockery::any()
            );

        $this->settingsRepository
            ->shouldReceive('findFirst')
            ->with(10)
            ->once()
            ->andReturn($existingSettings);

        // Only update SEO title, not tracking pixels
        $dto = PartialUpdateOrganizerSettingsDTO::from([
            'organizer_id' => 1,
            'account_id' => '100',
            'seo_title' => 'New Title',
        ]);

        $this->handler->handle($dto);
    }

    public function test_meta_conversions_api_access_token_is_stored_encrypted(): void
    {
        $storedToken = null;
        $this->expectSettingsUpdate(new OrganizerSettingDomainObject, function (array $attributes) use (&$storedToken) {
            $storedToken = $attributes['meta_conversions_api_access_token'];

            return true;
        });

        $this->handler->handle(PartialUpdateOrganizerSettingsDTO::from([
            'organizer_id' => 1,
            'account_id' => '100',
            'meta_conversions_api_access_token' => 'EAAtoken123',
        ]));

        $this->assertNotSame('EAAtoken123', $storedToken);
        $this->assertSame('EAAtoken123', $this->encrypter->decryptString($storedToken));
    }

    public function test_meta_conversions_api_access_token_is_cleared_when_null_is_provided(): void
    {
        $existingSettings = new OrganizerSettingDomainObject;
        $existingSettings->setMetaConversionsApiAccessToken('existing-encrypted-token');

        $this->expectSettingsUpdate($existingSettings, fn (array $attributes) => $attributes['meta_conversions_api_access_token'] === null);

        $this->handler->handle(PartialUpdateOrganizerSettingsDTO::from([
            'organizer_id' => 1,
            'account_id' => '100',
            'meta_conversions_api_access_token' => null,
        ]));
    }

    public function test_meta_conversions_api_access_token_is_kept_when_not_provided(): void
    {
        $existingSettings = new OrganizerSettingDomainObject;
        $existingSettings->setMetaConversionsApiAccessToken('existing-encrypted-token');

        $this->expectSettingsUpdate(
            $existingSettings,
            fn (array $attributes) => $attributes['meta_conversions_api_access_token'] === 'existing-encrypted-token',
        );

        $this->handler->handle(PartialUpdateOrganizerSettingsDTO::from([
            'organizer_id' => 1,
            'account_id' => '100',
            'seo_title' => 'New Title',
        ]));
    }

    public function test_first_day_of_week_is_persisted_when_provided(): void
    {
        $this->assertFirstDayOfWeekWritten(['first_day_of_week' => 0], existing: 1, expected: 0);
    }

    public function test_first_day_of_week_keeps_existing_value_when_not_provided(): void
    {
        $this->assertFirstDayOfWeekWritten(['seo_title' => 'New Title'], existing: 6, expected: 6);
    }

    private function assertFirstDayOfWeekWritten(array $input, int $existing, int $expected): void
    {
        $existingSettings = new OrganizerSettingDomainObject;
        $existingSettings->setFirstDayOfWeek($existing);

        $this->expectSettingsUpdate($existingSettings, fn (array $attributes) => $attributes['first_day_of_week'] === $expected);

        $this->handler->handle(PartialUpdateOrganizerSettingsDTO::from([
            'organizer_id' => 1,
            'account_id' => '100',
            ...$input,
        ]));
    }

    private function expectSettingsUpdate(OrganizerSettingDomainObject $existingSettings, callable $attributesMatcher): void
    {
        $organizer = new OrganizerDomainObject;
        $organizer->setId(1);

        $existingSettings->setId(10);
        $existingSettings->setOrganizerId(1);

        $this->organizerRepository->shouldReceive('findFirstWhere')->once()->andReturn($organizer);
        $this->settingsRepository->shouldReceive('findFirstWhere')->once()->andReturn($existingSettings);
        $this->settingsRepository
            ->shouldReceive('updateWhere')
            ->once()
            ->with(Mockery::on($attributesMatcher), Mockery::any());
        $this->settingsRepository->shouldReceive('findFirst')->with(10)->once()->andReturn($existingSettings);
    }
}
