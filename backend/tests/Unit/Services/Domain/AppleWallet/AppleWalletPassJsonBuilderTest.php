<?php

namespace Tests\Unit\Services\Domain\AppleWallet;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassJsonBuilder;
use HiEvents\Services\Domain\AppleWallet\AppleWalletSerialNumberService;
use HiEvents\Services\Domain\AppleWallet\AppleWalletUrlGenerator;
use HiEvents\Services\Domain\Wallet\DTO\WalletPassBrandingDTO;
use Illuminate\Config\Repository;
use Tests\TestCase;

class AppleWalletPassJsonBuilderTest extends TestCase
{
    private Repository $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new Repository([
            'app' => ['key' => 'base64:secret'],
            'apple-wallet' => [
                'pass_type_identifier' => 'pass.events.hi.test',
                'team_identifier' => 'TEAM123456',
                'organization_name' => 'Hi.Events',
                'serial_prefix' => 'hievents',
                'api_url' => 'https://tickets.example.com/api/',
            ],
        ]);
    }

    private function builder(): AppleWalletPassJsonBuilder
    {
        return new AppleWalletPassJsonBuilder(
            $this->config,
            new AppleWalletSerialNumberService($this->config),
            new AppleWalletUrlGenerator($this->config),
        );
    }

    private function attendee(string $status = 'ACTIVE'): AttendeeDomainObject
    {
        return (new AttendeeDomainObject)
            ->setId(31)
            ->setEventId(10)
            ->setShortId('a_short31')
            ->setPublicId('A-TICKET31')
            ->setFirstName('Ada')
            ->setLastName('Lovelace')
            ->setStatus($status)
            ->setProduct((new ProductDomainObject)->setTitle('General Admission'));
    }

    private function event(?EventSettingDomainObject $settings = null): EventDomainObject
    {
        return (new EventDomainObject)
            ->setId(10)
            ->setTitle('Tech Conference')
            ->setTimezone('Europe/Paris')
            ->setEventOccurrences(collect([
                (new EventOccurrenceDomainObject)
                    ->setId(1)
                    ->setStartDate('2026-10-01 18:00:00')
                    ->setEndDate('2026-10-01 21:30:00'),
            ]))
            ->setEventSettings($settings ?? new EventSettingDomainObject);
    }

    private function organizer(string $name = 'Acme Events'): OrganizerDomainObject
    {
        return (new OrganizerDomainObject)->setId(5)->setName($name);
    }

    private function passSettings(
        ?string $backgroundColor = null,
        ?string $themeAccentColor = null,
        ?string $bannerImageUrl = null,
        ?string $appleStripImageUrl = null,
    ): WalletPassBrandingDTO {
        return new WalletPassBrandingDTO(
            logoUrl: null,
            bannerImageUrl: $bannerImageUrl,
            appleStripImageUrl: $appleStripImageUrl,
            backgroundColor: $backgroundColor,
            themeAccentColor: $themeAccentColor,
        );
    }

    private function field(array $fields, string $key): ?array
    {
        return collect($fields)->firstWhere('key', $key);
    }

    public function test_the_pass_is_identified_and_can_be_updated_through_the_web_service(): void
    {
        $serialNumberService = new AppleWalletSerialNumberService($this->config);

        $pass = $this->builder()->build($this->attendee(), $this->event(), null, $this->organizer(), $this->passSettings());

        $this->assertSame(1, $pass['formatVersion']);
        $this->assertSame('pass.events.hi.test', $pass['passTypeIdentifier']);
        $this->assertSame('TEAM123456', $pass['teamIdentifier']);
        $this->assertSame('hievents-attendee-31', $pass['serialNumber']);
        $this->assertSame($serialNumberService->authenticationTokenFor('hievents-attendee-31'), $pass['authenticationToken']);
        $this->assertSame('https://tickets.example.com/api/apple-wallet', $pass['webServiceURL']);
        $this->assertSame('Acme Events', $pass['organizationName']);
    }

    public function test_the_barcode_carries_the_attendee_public_id_used_for_check_in(): void
    {
        $pass = $this->builder()->build($this->attendee(), $this->event(), null, $this->organizer(), $this->passSettings());

        $this->assertSame([[
            'format' => 'PKBarcodeFormatQR',
            'message' => 'A-TICKET31',
            'messageEncoding' => 'iso-8859-1',
            'altText' => 'A-TICKET31',
        ]], $pass['barcodes']);
    }

    public function test_the_occurrence_dates_are_shown_in_the_event_timezone(): void
    {
        $occurrence = (new EventOccurrenceDomainObject)
            ->setId(3)
            ->setLabel('Evening session')
            ->setStartDate('2026-10-02 17:00:00')
            ->setEndDate('2026-10-02 19:00:00');

        $pass = $this->builder()->build($this->attendee(), $this->event(), $occurrence, $this->organizer(), $this->passSettings());

        $this->assertSame('2026-10-02T19:00:00+02:00', $pass['relevantDate']);
        $this->assertSame('Tech Conference - Evening session', $this->field($pass['eventTicket']['primaryFields'], 'event')['value']);
        $this->assertSame('2026-10-02T19:00:00+02:00', $this->field($pass['eventTicket']['secondaryFields'], 'starts')['value']);
        $this->assertTrue($this->field($pass['eventTicket']['secondaryFields'], 'starts')['ignoresTimeZone']);
        $this->assertSame('2026-10-02T21:00:00+02:00', $this->field($pass['eventTicket']['backFields'], 'ends')['value']);
        $this->assertSame('2026-10-02T21:00:00+02:00', $pass['semantics']['eventEndDate']);
    }

    public function test_the_event_dates_are_used_when_the_ticket_has_no_occurrence(): void
    {
        $pass = $this->builder()->build($this->attendee(), $this->event(), null, $this->organizer(), $this->passSettings());

        $this->assertSame('2026-10-01T20:00:00+02:00', $pass['relevantDate']);
        $this->assertSame('Tech Conference', $pass['semantics']['eventName']);
    }

    public function test_the_ticket_holder_and_ticket_type_are_shown(): void
    {
        $pass = $this->builder()->build($this->attendee(), $this->event(), null, $this->organizer(), $this->passSettings());

        $this->assertSame('Ada Lovelace', $this->field($pass['eventTicket']['auxiliaryFields'], 'attendee')['value']);
        $this->assertSame('General Admission', $this->field($pass['eventTicket']['auxiliaryFields'], 'ticket')['value']);
        $this->assertSame('A-TICKET31', $this->field($pass['eventTicket']['backFields'], 'ticket_number')['value']);
    }

    public function test_the_back_of_the_pass_links_to_the_online_ticket(): void
    {
        $pass = $this->builder()->build($this->attendee(), $this->event(), null, $this->organizer(), $this->passSettings());

        $link = $this->field($pass['eventTicket']['backFields'], 'ticket_link');

        $this->assertStringContainsString('a_short31', $link['value']);
        $this->assertStringContainsString('<a href="', $link['attributedValue']);
    }

    public function test_a_cancelled_ticket_is_voided(): void
    {
        $cancelled = $this->builder()->build($this->attendee(AttendeeStatus::CANCELLED->name), $this->event(), null, $this->organizer(), $this->passSettings());
        $active = $this->builder()->build($this->attendee(), $this->event(), null, $this->organizer(), $this->passSettings());

        $this->assertTrue($cancelled['voided']);
        $this->assertArrayNotHasKey('voided', $active);
    }

    public function test_a_ticket_awaiting_payment_says_so_on_the_front(): void
    {
        $pass = $this->builder()->build($this->attendee(AttendeeStatus::AWAITING_PAYMENT->name), $this->event(), null, $this->organizer(), $this->passSettings());

        $this->assertSame('Awaiting payment', $this->field($pass['eventTicket']['headerFields'], 'status')['value']);
        $this->assertArrayNotHasKey('voided', $pass);
    }

    public function test_a_dark_background_gets_light_text(): void
    {
        $pass = $this->builder()->build($this->attendee(), $this->event(), null, $this->organizer(), $this->passSettings('#1e1b4b'));

        $this->assertSame('rgb(30, 27, 75)', $pass['backgroundColor']);
        $this->assertSame('rgb(255, 255, 255)', $pass['foregroundColor']);
        $this->assertSame('rgb(255, 255, 255)', $pass['labelColor']);
    }

    public function test_the_event_background_colour_overrides_the_organizer_colour(): void
    {
        $settings = (new EventSettingDomainObject)->setWalletPassBackgroundColor('#FDE68AFF');

        $pass = $this->builder()->build($this->attendee(), $this->event($settings), null, $this->organizer(), $this->passSettings('#1e1b4b'));

        $this->assertSame('rgb(253, 230, 138)', $pass['backgroundColor']);
        $this->assertSame('rgb(0, 0, 0)', $pass['foregroundColor']);
    }

    public function test_apple_default_colours_apply_when_no_colour_is_set(): void
    {
        $pass = $this->builder()->build($this->attendee(), $this->event(), null, $this->organizer(), $this->passSettings());

        $this->assertArrayNotHasKey('backgroundColor', $pass);
        $this->assertArrayNotHasKey('foregroundColor', $pass);
    }

    public function test_the_theme_accent_is_the_last_colour_fallback(): void
    {
        $pass = $this->builder()->build($this->attendee(), $this->event(), null, $this->organizer(), $this->passSettings(themeAccentColor: '#1e1b4b'));

        $this->assertSame('rgb(30, 27, 75)', $pass['backgroundColor']);
    }

    public function test_the_cover_colour_is_used_before_the_theme_accent_when_no_banner_is_chosen(): void
    {
        $event = $this->event()->setImages(collect([
            (new ImageDomainObject)->setType(ImageType::EVENT_COVER->name)->setAvgColour('#FDE68A'),
        ]));

        $pass = $this->builder()->build($this->attendee(), $event, null, $this->organizer(), $this->passSettings(themeAccentColor: '#1e1b4b'));
        $withBanner = $this->builder()->build($this->attendee(), $event, null, $this->organizer(), $this->passSettings(
            themeAccentColor: '#1e1b4b',
            bannerImageUrl: 'https://cdn.example.com/banner.png',
        ));

        $withStrip = $this->builder()->build($this->attendee(), $event, null, $this->organizer(), $this->passSettings(
            themeAccentColor: '#1e1b4b',
            appleStripImageUrl: 'https://cdn.example.com/strip.png',
        ));

        $this->assertSame('rgb(253, 230, 138)', $pass['backgroundColor']);
        $this->assertSame('rgb(30, 27, 75)', $withBanner['backgroundColor']);
        $this->assertSame('rgb(30, 27, 75)', $withStrip['backgroundColor']);
    }

    public function test_the_platform_name_is_used_when_the_organizer_has_no_name(): void
    {
        $pass = $this->builder()->build($this->attendee(), $this->event(), null, $this->organizer(''), $this->passSettings());

        $this->assertSame('Hi.Events', $pass['organizationName']);
        $this->assertArrayNotHasKey('logoText', $pass);
    }
}
