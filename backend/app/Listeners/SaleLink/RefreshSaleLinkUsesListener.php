<?php

namespace HiEvents\Listeners\SaleLink;

use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Repository\Interfaces\SaleLinkRepositoryInterface;

class RefreshSaleLinkUsesListener
{
    public function __construct(
        private readonly SaleLinkRepositoryInterface $saleLinkRepository,
    ) {}

    public function handle(OrderStatusChangedEvent $event): void
    {
        $saleLinkId = $event->order->getSaleLinkId();

        if ($saleLinkId === null) {
            return;
        }

        $this->saleLinkRepository->refreshUsesCount($saleLinkId);
    }
}
