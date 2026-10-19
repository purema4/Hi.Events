<?php

namespace HiEvents\Services\Application\Handlers\SelfService;

use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\SelfService\DTO\EditOrderPublicDTO;
use HiEvents\Services\Domain\SelfService\DTO\EditOrderResultDTO;
use HiEvents\Services\Domain\SelfService\SelfServiceEditOrderService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;

class EditOrderPublicHandler
{
    use SelfServiceValidationTrait;

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly SelfServiceEditOrderService $selfServiceEditOrderService,
        private readonly DomainEventDispatcherService $domainEventDispatcherService,
    ) {}

    public function handle(EditOrderPublicDTO $dto): EditOrderResultDTO
    {
        $this->loadAndValidateEvent($dto->eventId);
        $order = $this->loadAndValidateOrder($dto->orderShortId, $dto->eventId);

        $result = $this->selfServiceEditOrderService->editOrder(
            order: $order,
            firstName: $dto->firstName,
            lastName: $dto->lastName,
            email: $dto->email,
            ipAddress: $dto->ipAddress,
            userAgent: $dto->userAgent
        );

        if ($result->updated) {
            $this->domainEventDispatcherService->dispatch(
                new OrderEvent(
                    type: DomainEventType::ORDER_UPDATED,
                    orderId: $order->getId(),
                )
            );
        }

        return $result;
    }
}
