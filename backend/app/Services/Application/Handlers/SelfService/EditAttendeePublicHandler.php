<?php

namespace HiEvents\Services\Application\Handlers\SelfService;

use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\Exceptions\SelfServiceDisabledException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\SelfService\DTO\EditAttendeePublicDTO;
use HiEvents\Services\Domain\SelfService\DTO\EditAttendeeResultDTO;
use HiEvents\Services\Domain\SelfService\SelfServiceEditAttendeeService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\AttendeeEvent;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class EditAttendeePublicHandler
{
    use SelfServiceValidationTrait;

    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly SelfServiceEditAttendeeService $selfServiceEditAttendeeService,
        private readonly DomainEventDispatcherService $domainEventDispatcherService,
    ) {}

    /**
     * @throws SelfServiceDisabledException
     */
    public function handle(EditAttendeePublicDTO $dto): EditAttendeeResultDTO
    {
        $this->loadAndValidateEvent($dto->eventId);
        $order = $this->loadAndValidateOrder($dto->orderShortId, $dto->eventId);

        $attendee = $this->attendeeRepository->findFirstWhere([
            AttendeeDomainObjectAbstract::SHORT_ID => $dto->attendeeShortId,
            AttendeeDomainObjectAbstract::ORDER_ID => $order->getId(),
            AttendeeDomainObjectAbstract::EVENT_ID => $dto->eventId,
        ]);

        if (! $attendee) {
            throw new ResourceNotFoundException(__('Attendee not found'));
        }

        $result = $this->selfServiceEditAttendeeService->editAttendee(
            attendee: $attendee,
            firstName: $dto->firstName,
            lastName: $dto->lastName,
            email: $dto->email,
            ipAddress: $dto->ipAddress,
            userAgent: $dto->userAgent
        );

        if ($result->updated) {
            $this->domainEventDispatcherService->dispatch(
                new AttendeeEvent(
                    type: DomainEventType::ATTENDEE_UPDATED,
                    attendeeId: $attendee->getId(),
                )
            );
        }

        return $result;
    }
}
