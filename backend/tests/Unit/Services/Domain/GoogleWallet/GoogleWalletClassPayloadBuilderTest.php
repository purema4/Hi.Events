<?php

namespace Tests\Unit\Services\Domain\GoogleWallet;

use HiEvents\DomainObjects\Enums\LocationType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventLocationDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\LocationDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Services\Domain\GoogleWallet\DTO\GoogleWalletPassSettingsDTO;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletClassPayloadBuilder;
use Illuminate\Config\Repository;
use Tests\TestCase;

class GoogleWalletClassPayloadBuilderTest extends TestCase
{
    private function builder(): GoogleWalletClassPayloadBuilder
    {
        return new GoogleWalletClassPayloadBuilder(
            new Repository(['google-wallet' => ['issuer_name' => 'Hi.Events']]),
            $this->app->make('translator'),
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

    private function passSettings(): GoogleWalletPassSettingsDTO
    {
        return new GoogleWalletPassSettingsDTO(
            logoUrl: 'https://cdn.example.com/logo.png',
            heroImageUrl: null,
            backgroundColor: '#112233',
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
}
