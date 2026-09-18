<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\GoogleWallet;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventLocationDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\LocationDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletSaveUrlResolver;

class ResolveGoogleWalletSaveUrlPublicHandler
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly GoogleWalletSaveUrlResolver $saveUrlResolver,
    ) {}

    /**
     * @param  array<int, string>  $attendeeShortIds
     *
     * @throws ResourceNotFoundException
     */
    public function handle(int $eventId, array $attendeeShortIds): string
    {
        if ($attendeeShortIds === []) {
            throw new ResourceNotFoundException(__('No Google Wallet passes were found for these tickets.'));
        }

        $attendees = $this->attendeeRepository
            ->loadRelation(new Relationship(ProductDomainObject::class, name: 'product'))
            ->loadRelation(new Relationship(EventOccurrenceDomainObject::class, name: 'event_occurrence', nested: [
                new Relationship(EventLocationDomainObject::class, name: 'event_location', nested: [
                    new Relationship(LocationDomainObject::class, name: 'location'),
                ]),
            ]))
            ->findWhere([
                AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
                [AttendeeDomainObjectAbstract::SHORT_ID, 'in', $attendeeShortIds],
            ])
            ->filter(static fn (AttendeeDomainObject $attendee) => $attendee->getStatus() !== AttendeeStatus::CANCELLED->name)
            ->values();

        if ($attendees->isEmpty()) {
            throw new ResourceNotFoundException(__('No Google Wallet passes were found for these tickets.'));
        }

        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->loadRelation(EventSettingDomainObject::class)
            ->loadRelation(ImageDomainObject::class)
            ->loadRelation(new Relationship(domainObject: EventLocationDomainObject::class, name: 'event_location', nested: [
                new Relationship(domainObject: LocationDomainObject::class, name: 'location'),
            ]))
            ->findById($eventId);

        if ($event === null) {
            throw new ResourceNotFoundException(__('No Google Wallet passes were found for these tickets.'));
        }

        $saveUrl = $this->saveUrlResolver->resolveForAttendees(
            attendees: $attendees,
            event: $event,
            organizer: $event->getOrganizer(),
        );

        if ($saveUrl === null) {
            throw new ResourceNotFoundException(__('No Google Wallet passes were found for these tickets.'));
        }

        return $saveUrl;
    }
}
