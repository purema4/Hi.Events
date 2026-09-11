<?php

namespace HiEvents\Services\Application\Handlers\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventLocationDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\LocationDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\DTO\GetAttendeeTicketsDTO;
use HiEvents\Services\Domain\Attendee\AttendeeTicketGroupService;
use Illuminate\Support\Collection;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

readonly class GetAttendeeTicketsHandler
{
    public function __construct(
        private AttendeeRepositoryInterface $attendeeRepository,
        private AttendeeTicketGroupService $attendeeTicketGroupService,
    ) {}

    /**
     * @return Collection<int, AttendeeDomainObject>
     *
     * @throws ResourceNotFoundException
     */
    public function handle(GetAttendeeTicketsDTO $getAttendeeTicketsDTO): Collection
    {
        $attendee = $this->attendeeRepository->findFirstWhere([
            AttendeeDomainObjectAbstract::SHORT_ID => $getAttendeeTicketsDTO->attendeeShortId,
            AttendeeDomainObjectAbstract::EVENT_ID => $getAttendeeTicketsDTO->eventId,
        ]);

        if (! $attendee) {
            throw new ResourceNotFoundException;
        }

        $orderAttendees = $this->attendeeRepository
            ->loadRelation(new Relationship(
                domainObject: ProductDomainObject::class,
                nested: [
                    new Relationship(domainObject: ProductPriceDomainObject::class),
                ],
                name: 'product',
            ))
            ->loadRelation(new Relationship(
                domainObject: EventOccurrenceDomainObject::class,
                nested: [
                    new Relationship(
                        domainObject: EventLocationDomainObject::class,
                        nested: [
                            new Relationship(domainObject: LocationDomainObject::class, name: 'location'),
                        ],
                        name: 'event_location',
                    ),
                ],
                name: 'event_occurrence',
            ))
            ->findWhere([
                AttendeeDomainObjectAbstract::ORDER_ID => $attendee->getOrderId(),
                AttendeeDomainObjectAbstract::EVENT_ID => $getAttendeeTicketsDTO->eventId,
            ]);

        return $this->attendeeTicketGroupService
            ->groupByRecipient($orderAttendees)
            ->get($this->attendeeTicketGroupService->recipientKey($attendee), collect())
            ->sortBy(fn (AttendeeDomainObject $ticketHolder) => $ticketHolder->getId())
            ->values();
    }
}
