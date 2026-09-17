<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Wallet;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventLocationDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\LocationDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Wallet\DTO\OrderWalletPassesDTO;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassUrlResolver;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletSaveUrlResolver;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class GetOrderWalletPassesPublicHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly AppleWalletPassUrlResolver $appleWalletPassUrlResolver,
        private readonly GoogleWalletSaveUrlResolver $googleWalletSaveUrlResolver,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $eventId, string $orderShortId): OrderWalletPassesDTO
    {
        $order = $this->orderRepository
            ->loadRelation(new Relationship(
                domainObject: AttendeeDomainObject::class,
                nested: [
                    new Relationship(domainObject: ProductDomainObject::class, name: 'product'),
                    new Relationship(
                        domainObject: EventOccurrenceDomainObject::class,
                        nested: [
                            new Relationship(domainObject: EventLocationDomainObject::class, name: 'event_location', nested: [
                                new Relationship(domainObject: LocationDomainObject::class, name: 'location'),
                            ]),
                        ],
                        name: 'event_occurrence',
                    ),
                ],
            ))
            ->findFirstWhere([
                OrderDomainObjectAbstract::SHORT_ID => $orderShortId,
                OrderDomainObjectAbstract::EVENT_ID => $eventId,
            ]);

        if ($order === null) {
            throw new ResourceNotFoundException(__('Order not found'));
        }

        if (! $order->isOrderCompleted() && ! $order->isOrderAwaitingOfflinePayment()) {
            return new OrderWalletPassesDTO(appleWalletPassUrl: null, googleWalletSaveUrl: null);
        }

        $attendees = ($order->getAttendees() ?? collect())
            ->filter(static fn (AttendeeDomainObject $attendee) => $attendee->getStatus() !== AttendeeStatus::CANCELLED->name)
            ->values();

        if ($attendees->isEmpty()) {
            return new OrderWalletPassesDTO(appleWalletPassUrl: null, googleWalletSaveUrl: null);
        }

        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->loadRelation(EventSettingDomainObject::class)
            ->loadRelation(ImageDomainObject::class)
            ->loadRelation(new Relationship(domainObject: EventLocationDomainObject::class, name: 'event_location', nested: [
                new Relationship(domainObject: LocationDomainObject::class, name: 'location'),
            ]))
            ->findById($eventId);

        return new OrderWalletPassesDTO(
            appleWalletPassUrl: $this->appleWalletPassUrlResolver->resolveForAttendees(
                attendees: $attendees,
                event: $event,
                organizer: $event->getOrganizer(),
            ),
            googleWalletSaveUrl: $this->googleWalletSaveUrlResolver->resolveForAttendees(
                attendees: $attendees,
                event: $event,
                organizer: $event->getOrganizer(),
            ),
        );
    }
}
