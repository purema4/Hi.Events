<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\AppleWallet;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventLocationDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\LocationDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Exceptions\AppleWallet\AppleWalletConfigurationException;
use HiEvents\Exceptions\AppleWallet\AppleWalletPassGenerationException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\AppleWallet\DTO\AppleWalletPassFileDTO;
use Illuminate\Support\Traits\Localizable;

class AppleWalletPassService
{
    use Localizable;

    private const PASS_MIME_TYPE = 'application/vnd.apple.pkpass';

    private const BUNDLE_MIME_TYPE = 'application/vnd.apple.pkpasses';

    private const BUNDLE_FILENAME = 'tickets.pkpasses';

    public function __construct(
        private readonly AppleWalletPassSettingsResolver $passSettingsResolver,
        private readonly AppleWalletSerialNumberService $serialNumberService,
        private readonly AppleWalletPassJsonBuilder $passJsonBuilder,
        private readonly AppleWalletPassImageBuilder $imageBuilder,
        private readonly AppleWalletPassPackager $packager,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    /**
     * @param  array  $attendeeWhere  Conditions matching attendees of a single event
     * @return AppleWalletPassFileDTO|null A .pkpass for one attendee, a .pkpasses bundle for several
     *
     * @throws AppleWalletConfigurationException|AppleWalletPassGenerationException
     */
    public function generate(array $attendeeWhere): ?AppleWalletPassFileDTO
    {
        $attendees = $this->attendeeRepository
            ->loadRelation(new Relationship(ProductDomainObject::class, name: 'product'))
            ->loadRelation(new Relationship(EventOccurrenceDomainObject::class, name: 'event_occurrence', nested: [
                new Relationship(EventLocationDomainObject::class, name: 'event_location', nested: [
                    new Relationship(LocationDomainObject::class, name: 'location'),
                ]),
            ]))
            ->findWhere($attendeeWhere);

        if ($attendees->isEmpty()) {
            return null;
        }

        $event = $this->loadEvent($attendees->first()->getEventId());
        $passSettings = $event === null
            ? null
            : $this->passSettingsResolver->resolveForOrganizer($event->getOrganizerId());

        if ($passSettings === null) {
            return null;
        }

        $images = $this->imageBuilder->build($event, $event->getOrganizer(), $passSettings);

        $passes = $attendees->mapWithKeys(fn (AttendeeDomainObject $attendee) => [
            $this->serialNumberService->serialNumberForAttendee($attendee->getId()).'.pkpass' => $this->withLocale(
                $attendee->getLocale(),
                fn () => $this->packager->package(
                    $this->passJsonBuilder->build(
                        attendee: $attendee,
                        event: $event,
                        occurrence: $this->resolveOccurrence($attendee, $event),
                        organizer: $event->getOrganizer(),
                        passSettings: $passSettings,
                    ),
                    $images,
                ),
            ),
        ]);

        if ($passes->count() === 1) {
            return new AppleWalletPassFileDTO(
                contents: $passes->first(),
                mimeType: self::PASS_MIME_TYPE,
                filename: $passes->keys()->first(),
            );
        }

        return new AppleWalletPassFileDTO(
            contents: $this->packager->bundle($passes->all()),
            mimeType: self::BUNDLE_MIME_TYPE,
            filename: self::BUNDLE_FILENAME,
        );
    }

    private function resolveOccurrence(AttendeeDomainObject $attendee, EventDomainObject $event): ?EventOccurrenceDomainObject
    {
        if ($attendee->getEventOccurrence() !== null) {
            return $attendee->getEventOccurrence();
        }

        $occurrences = $event->getEventOccurrences() ?? collect();

        if ($attendee->getEventOccurrenceId() !== null) {
            return $occurrences->first(
                static fn (EventOccurrenceDomainObject $occurrence) => $occurrence->getId() === $attendee->getEventOccurrenceId()
            );
        }

        return $occurrences->count() === 1 ? $occurrences->first() : null;
    }

    private function loadEvent(int $eventId): ?EventDomainObject
    {
        return $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer', nested: [
                new Relationship(ImageDomainObject::class),
            ]))
            ->loadRelation(new Relationship(ImageDomainObject::class))
            ->loadRelation(new Relationship(EventSettingDomainObject::class))
            ->loadRelation(new Relationship(EventLocationDomainObject::class, name: 'event_location', nested: [
                new Relationship(LocationDomainObject::class, name: 'location'),
            ]))
            ->loadRelation(new Relationship(EventOccurrenceDomainObject::class, nested: [
                new Relationship(EventLocationDomainObject::class, name: 'event_location', nested: [
                    new Relationship(LocationDomainObject::class, name: 'location'),
                ]),
            ]))
            ->findFirst($eventId);
    }
}
