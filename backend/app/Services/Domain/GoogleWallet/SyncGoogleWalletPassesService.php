<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\GoogleWallet;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventLocationDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\LocationDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Exceptions\GoogleWallet\GoogleWalletApiException;
use HiEvents\Exceptions\GoogleWallet\GoogleWalletConfigurationException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use Illuminate\Support\Collection;

class SyncGoogleWalletPassesService
{
    public function __construct(
        private readonly EnsureGoogleWalletObjectService $ensureObjectService,
        private readonly GoogleWalletPassSettingsResolver $passSettingsResolver,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    /**
     * @throws GoogleWalletApiException|GoogleWalletConfigurationException
     */
    public function syncOrder(int $orderId): void
    {
        $this->syncAttendees(
            $this->attendeesWhere([AttendeeDomainObjectAbstract::ORDER_ID => $orderId])
        );
    }

    /**
     * @throws GoogleWalletApiException|GoogleWalletConfigurationException
     */
    public function syncAttendee(int $attendeeId): void
    {
        $this->syncAttendees(
            $this->attendeesWhere([AttendeeDomainObjectAbstract::ID => $attendeeId])
        );
    }

    /**
     * @param  Collection<int, AttendeeDomainObject>  $attendees
     *
     * @throws GoogleWalletApiException|GoogleWalletConfigurationException
     */
    private function syncAttendees(Collection $attendees): void
    {
        if ($attendees->isEmpty()) {
            return;
        }

        $event = $this->loadEvent($attendees->first()->getEventId());

        if ($event === null) {
            return;
        }

        $passSettings = $this->passSettingsResolver->resolveForOrganizer($event->getOrganizerId());

        if ($passSettings === null) {
            return;
        }

        foreach ($attendees as $attendee) {
            $this->ensureObjectService->ensure(
                attendee: $attendee,
                event: $event,
                organizer: $event->getOrganizer(),
                passSettings: $passSettings,
            );
        }
    }

    /**
     * @return Collection<int, AttendeeDomainObject>
     */
    private function attendeesWhere(array $where): Collection
    {
        return $this->attendeeRepository
            ->loadRelation(new Relationship(EventOccurrenceDomainObject::class, name: 'event_occurrence', nested: [
                new Relationship(EventLocationDomainObject::class, name: 'event_location', nested: [
                    new Relationship(LocationDomainObject::class, name: 'location'),
                ]),
            ]))
            ->loadRelation(new Relationship(ProductDomainObject::class, name: 'product'))
            ->findWhere($where);
    }

    private function loadEvent(int $eventId): ?EventDomainObject
    {
        return $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer', nested: [
                new Relationship(ImageDomainObject::class),
            ]))
            ->loadRelation(new Relationship(ImageDomainObject::class))
            ->loadRelation(new Relationship(EventLocationDomainObject::class, name: 'event_location', nested: [
                new Relationship(LocationDomainObject::class, name: 'location'),
            ]))
            ->loadRelation(new Relationship(EventOccurrenceDomainObject::class, nested: [
                new Relationship(EventLocationDomainObject::class, name: 'event_location', nested: [
                    new Relationship(LocationDomainObject::class, name: 'location'),
                ]),
            ]))
            ->findById($eventId);
    }
}
