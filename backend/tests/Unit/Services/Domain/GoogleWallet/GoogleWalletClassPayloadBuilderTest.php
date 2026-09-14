<?php

namespace Tests\Unit\Services\Domain\GoogleWallet;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\Enums\LocationType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventLocationDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\LocationDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletBannerService;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletClassPayloadBuilder;
use HiEvents\Services\Domain\Wallet\DTO\WalletPassBrandingDTO;
use Illuminate\Config\Repository;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class GoogleWalletClassPayloadBuilderTest extends TestCase
{
    private GoogleWalletBannerService|MockInterface $bannerService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bannerService = Mockery::mock(GoogleWalletBannerService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function builder(): GoogleWalletClassPayloadBuilder
    {
        return new GoogleWalletClassPayloadBuilder(
            new Repository(['google-wallet' => ['issuer_name' => 'Hi.Events']]),
            $this->app->make('translator'),
            $this->bannerService,
        );
    }

    private function eventWithCover(?string $avgColour = '#0d0b0a'): EventDomainObject
    {
        return $this->event()->setImages(new Collection([
            (new ImageDomainObject)
                ->setId(7)
                ->setType(ImageType::EVENT_COVER->name)
                ->setDisk('s3-public')
                ->setPath('event_cover/cover.png')
                ->setAvgColour($avgColour),
        ]));
    }

    private function settingsWithoutColour(): WalletPassBrandingDTO
    {
        return new WalletPassBrandingDTO(
            logoUrl: null,
            bannerImageUrl: null,
            appleStripImageUrl: null,
            backgroundColor: null,
            themeAccentColor: '#de0f00',
        );
    }

    private function event(): EventDomainObject
    {
        $event = new EventDomainObject;
        $event->setId(1)
            ->setTitle('Synth Night')
            ->setTimezone('Europe/Dublin');

        return $event;
    }

    private function occurrence(?string $label = null): EventOccurrenceDomainObject
    {
        $occurrence = new EventOccurrenceDomainObject;
        $occurrence->setId(9)
            ->setStartDate('2026-06-01 19:00:00')
            ->setEndDate('2026-06-01 23:00:00')
            ->setLabel($label);

        return $occurrence;
    }

    private function organizer(): OrganizerDomainObject
    {
        $organizer = new OrganizerDomainObject;
        $organizer->setId(3)->setName('Night Owl Events');

        return $organizer;
    }

    private function passSettings(): WalletPassBrandingDTO
    {
        return new WalletPassBrandingDTO(
            logoUrl: 'https://cdn.example.com/logo.png',
            bannerImageUrl: null,
            appleStripImageUrl: null,
            backgroundColor: '#112233',
            themeAccentColor: null,
        );
    }

    private function venue(): EventLocationDomainObject
    {
        $location = new LocationDomainObject;
        $location->setName('The Button Factory')
            ->setStructuredAddress([
                'address_line_1' => 'Curved Street',
                'city' => 'Dublin',
                'country' => 'Ireland',
            ]);

        $eventLocation = new EventLocationDomainObject;
        $eventLocation->setType(LocationType::IN_PERSON->name)
            ->setLocation($location);

        return $eventLocation;
    }

    public function test_it_builds_the_fields_google_requires(): void
    {
        $payload = $this->builder()->build(
            'issuer.class_1',
            $this->event(),
            $this->occurrence(),
            $this->organizer(),
            $this->passSettings(),
        );

        $this->assertSame('issuer.class_1', $payload['id']);
        $this->assertSame('Night Owl Events', $payload['issuerName']);
        $this->assertSame('UNDER_REVIEW', $payload['reviewStatus']);
        $this->assertSame('Synth Night', $payload['eventName']['defaultValue']['value']);
    }

    public function test_dates_are_expressed_in_the_events_own_timezone(): void
    {
        $payload = $this->builder()->build(
            'issuer.class_1',
            $this->event(),
            $this->occurrence(),
            $this->organizer(),
            $this->passSettings(),
        );

        $this->assertSame('2026-06-01T20:00:00', $payload['dateTime']['start']);
        $this->assertSame('2026-06-02T00:00:00', $payload['dateTime']['end']);
    }

    public function test_the_occurrence_label_distinguishes_sessions_of_a_recurring_event(): void
    {
        $payload = $this->builder()->build(
            'issuer.class_1',
            $this->event(),
            $this->occurrence('Late Show'),
            $this->organizer(),
            $this->passSettings(),
        );

        $this->assertSame('Synth Night - Late Show', $payload['eventName']['defaultValue']['value']);
    }

    public function test_the_venue_is_taken_from_the_occurrence_location(): void
    {
        $occurrence = $this->occurrence();
        $occurrence->setEventLocation($this->venue());

        $payload = $this->builder()->build(
            'issuer.class_1',
            $this->event(),
            $occurrence,
            $this->organizer(),
            $this->passSettings(),
        );

        $this->assertSame('The Button Factory', $payload['venue']['name']['defaultValue']['value']);
        $this->assertSame(
            'Curved Street, Dublin, Ireland',
            $payload['venue']['address']['defaultValue']['value'],
        );
    }

    public function test_an_online_event_is_sent_without_a_venue(): void
    {
        $payload = $this->builder()->build(
            'issuer.class_1',
            $this->event(),
            $this->occurrence(),
            $this->organizer(),
            $this->passSettings(),
        );

        $this->assertArrayNotHasKey('venue', $payload);
    }

    public function test_branding_is_only_sent_when_the_organizer_supplied_it(): void
    {
        $payload = $this->builder()->build(
            'issuer.class_1',
            $this->event(),
            $this->occurrence(),
            $this->organizer(),
            $this->passSettings(),
        );

        $this->assertSame('https://cdn.example.com/logo.png', $payload['logo']['sourceUri']['uri']);
        $this->assertSame('#112233', $payload['hexBackgroundColor']);
        $this->assertArrayNotHasKey('heroImage', $payload);
    }

    public function test_it_falls_back_to_the_platform_issuer_name(): void
    {
        $organizer = new OrganizerDomainObject;
        $organizer->setId(3)->setName('');

        $payload = $this->builder()->build(
            'issuer.class_1',
            $this->event(),
            $this->occurrence(),
            $organizer,
            $this->passSettings(),
        );

        $this->assertSame('Hi.Events', $payload['issuerName']);
    }

    public function test_the_pass_can_be_saved_by_several_people_and_devices(): void
    {
        $payload = $this->builder()->build(
            'issuer.class_1',
            $this->event(),
            $this->occurrence(),
            $this->organizer(),
            $this->passSettings(),
        );

        $this->assertSame('MULTIPLE_HOLDERS', $payload['multipleDevicesAndHoldersAllowedStatus']);
    }

    public function test_the_date_location_and_end_time_are_spelled_out_on_the_pass(): void
    {
        $event = $this->event();
        $event->setEventLocation($this->venue());

        $payload = $this->builder()->build(
            'issuer.class_1',
            $event,
            $this->occurrence(),
            $this->organizer(),
            $this->passSettings(),
        );

        $modules = collect($payload['textModulesData'])->keyBy('id');

        $this->assertSame('Date', $modules['event_date']['header']);
        $this->assertSame('June 1, 2026 @ 8:00 pm', $modules['event_date']['body']);
        $this->assertSame('Location', $modules['event_location']['header']);
        $this->assertSame('The Button Factory, Curved Street, Dublin, Ireland', $modules['event_location']['body']);
        $this->assertSame('June 2, 2026 @ 12:00 am', $modules['event_end']['body']);
    }

    public function test_an_event_without_a_venue_carries_no_location_row(): void
    {
        $payload = $this->builder()->build(
            'issuer.class_1',
            $this->event(),
            $this->occurrence(),
            $this->organizer(),
            $this->passSettings(),
        );

        $this->assertSame(['event_date', 'event_end'], collect($payload['textModulesData'])->pluck('id')->all());
    }

    public function test_the_card_shows_ticket_and_price_then_date_and_location(): void
    {
        $payload = $this->builder()->build(
            'issuer.class_1',
            $this->event(),
            $this->occurrence(),
            $this->organizer(),
            $this->passSettings(),
        );

        $rows = collect($payload['classTemplateInfo']['cardTemplateOverride']['cardRowTemplateInfos'])
            ->map(fn (array $row) => [
                $row['twoItems']['startItem']['firstValue']['fields'][0]['fieldPath'],
                $row['twoItems']['endItem']['firstValue']['fields'][0]['fieldPath'],
            ])
            ->all();

        $this->assertSame([
            ["object.textModulesData['ticket']", "object.textModulesData['price']"],
            ["class.textModulesData['event_date']", "class.textModulesData['event_location']"],
        ], $rows);
    }

    public function test_event_branding_wins_over_the_organizer_settings(): void
    {
        $event = $this->event();
        $event->setEventSettings(
            (new EventSettingDomainObject)
                ->setWalletPassLogoUrl('https://cdn.example.com/event-logo.png')
                ->setWalletPassBannerUrl('https://cdn.example.com/event-banner.png')
                ->setWalletPassBackgroundColor('#AABBCCDD')
        );

        $payload = $this->builder()->build(
            'issuer.class_1',
            $event,
            $this->occurrence(),
            $this->organizer(),
            $this->passSettings(),
        );

        $this->assertSame('https://cdn.example.com/event-logo.png', $payload['logo']['sourceUri']['uri']);
        $this->assertSame('https://cdn.example.com/event-banner.png', $payload['heroImage']['sourceUri']['uri']);
        $this->assertSame('#aabbcc', $payload['hexBackgroundColor']);
    }

    public function test_the_cover_becomes_a_generated_banner_that_blends_into_a_card_coloured_like_the_photo(): void
    {
        $this->bannerService
            ->shouldReceive('bannerUrlForCover')
            ->once()
            ->with(Mockery::on(fn (ImageDomainObject $cover) => $cover->getId() === 7), '#0d0b0a')
            ->andReturn('https://cdn.example.com/google_wallet_banner/cover-7-0d0b0a-v1.png');

        $payload = $this->builder()->build(
            'issuer.class_1',
            $this->eventWithCover(),
            $this->occurrence(),
            $this->organizer(),
            $this->settingsWithoutColour(),
        );

        $this->assertSame('#0d0b0a', $payload['hexBackgroundColor']);
        $this->assertSame(
            'https://cdn.example.com/google_wallet_banner/cover-7-0d0b0a-v1.png',
            $payload['heroImage']['sourceUri']['uri'],
        );
    }

    public function test_a_chosen_card_colour_wins_over_the_photo_and_the_banner_fades_into_it(): void
    {
        $this->bannerService
            ->shouldReceive('bannerUrlForCover')
            ->once()
            ->with(Mockery::any(), '#112233')
            ->andReturn('https://cdn.example.com/google_wallet_banner/cover-7-112233-v1.png');

        $payload = $this->builder()->build(
            'issuer.class_1',
            $this->eventWithCover(),
            $this->occurrence(),
            $this->organizer(),
            $this->passSettings(),
        );

        $this->assertSame('#112233', $payload['hexBackgroundColor']);
    }

    public function test_the_theme_accent_is_used_when_there_is_no_cover(): void
    {
        $this->bannerService->shouldNotReceive('bannerUrlForCover');

        $payload = $this->builder()->build(
            'issuer.class_1',
            $this->event(),
            $this->occurrence(),
            $this->organizer(),
            $this->settingsWithoutColour(),
        );

        $this->assertSame('#de0f00', $payload['hexBackgroundColor']);
        $this->assertArrayNotHasKey('heroImage', $payload);
    }

    public function test_a_banner_url_set_by_hand_is_used_as_is_without_generating_one(): void
    {
        $this->bannerService->shouldNotReceive('bannerUrlForCover');

        $event = $this->eventWithCover();
        $event->setEventSettings(
            (new EventSettingDomainObject)->setWalletPassBannerUrl('https://cdn.example.com/hand-made.png')
        );

        $payload = $this->builder()->build(
            'issuer.class_1',
            $event,
            $this->occurrence(),
            $this->organizer(),
            $this->settingsWithoutColour(),
        );

        $this->assertSame('https://cdn.example.com/hand-made.png', $payload['heroImage']['sourceUri']['uri']);
        $this->assertSame('#de0f00', $payload['hexBackgroundColor']);
    }

    public function test_blank_event_branding_falls_back_to_the_organizer_settings(): void
    {
        $event = $this->event();
        $event->setEventSettings(
            (new EventSettingDomainObject)
                ->setWalletPassLogoUrl('   ')
                ->setWalletPassBackgroundColor(null)
        );

        $payload = $this->builder()->build(
            'issuer.class_1',
            $event,
            $this->occurrence(),
            $this->organizer(),
            $this->passSettings(),
        );

        $this->assertSame('https://cdn.example.com/logo.png', $payload['logo']['sourceUri']['uri']);
        $this->assertSame('#112233', $payload['hexBackgroundColor']);
    }
}
