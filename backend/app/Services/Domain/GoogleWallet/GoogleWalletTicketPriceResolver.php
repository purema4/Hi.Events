<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\GoogleWallet;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\OrderItemDomainObjectAbstract;
use HiEvents\Helper\Currency;
use HiEvents\Repository\Interfaces\OrderItemRepositoryInterface;
use Illuminate\Contracts\Translation\Translator;

class GoogleWalletTicketPriceResolver
{
    public function __construct(
        private readonly OrderItemRepositoryInterface $orderItemRepository,
        private readonly Translator $translator,
    ) {}

    public function resolve(AttendeeDomainObject $attendee, EventDomainObject $event): ?string
    {
        $orderItem = $this->orderItemRepository->findFirstWhere(array_filter([
            OrderItemDomainObjectAbstract::ORDER_ID => $attendee->getOrderId(),
            OrderItemDomainObjectAbstract::PRODUCT_PRICE_ID => $attendee->getProductPriceId(),
            OrderItemDomainObjectAbstract::EVENT_OCCURRENCE_ID => $attendee->getEventOccurrenceId(),
        ], static fn ($value) => $value !== null));

        if ($orderItem === null) {
            return null;
        }

        return Currency::format($orderItem->getPrice(), $event->getCurrency(), $this->translator->getLocale());
    }
}
