<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\SaleLink;

use Carbon\Carbon;
use HiEvents\DomainObjects\AffiliateDomainObject;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\SaleLinkPromoMode;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\AffiliateDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\PromoCodeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SaleLinkDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\PromoCodeDomainObject;
use HiEvents\DomainObjects\SaleLinkDomainObject;
use HiEvents\DomainObjects\SaleLinkItemDomainObject;
use HiEvents\DomainObjects\Status\AffiliateStatus;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\SaleLinkStatus;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\Enterprise\Seating\Services\Domain\SeatedProductLookupService;
use HiEvents\Enterprise\Seating\Services\Domain\SeatingEventLockService;
use HiEvents\Exceptions\EventNotOpenForOrdersException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SaleLinkUnavailableException;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\PromoCodeRepositoryInterface;
use HiEvents\Repository\Interfaces\SaleLinkRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\ProductOrderDetailsDTO;
use HiEvents\Services\Domain\EventOccurrence\OccurrencePurchaseEligibilityService;
use HiEvents\Services\Domain\Order\DTO\ProcessedOrderItemDTO;
use HiEvents\Services\Domain\Order\OrderItemProcessingService;
use HiEvents\Services\Domain\Order\OrderManagementService;
use HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService;
use HiEvents\Services\Domain\Product\DTO\OrderProductPriceDTO;
use HiEvents\Services\Domain\PromoCode\PromoCodeUsageValidationService;
use HiEvents\Services\Domain\SaleLink\SaleLinkPriceService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateSaleLinkOrderHandler
{
    public function __construct(
        private readonly SaleLinkRepositoryInterface $saleLinkRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly PromoCodeRepositoryInterface $promoCodeRepository,
        private readonly PromoCodeUsageValidationService $promoCodeUsageValidationService,
        private readonly AffiliateRepositoryInterface $affiliateRepository,
        private readonly OrderManagementService $orderManagementService,
        private readonly OrderItemProcessingService $orderItemProcessingService,
        private readonly AvailableProductQuantitiesFetchService $availableProductQuantitiesFetchService,
        private readonly OccurrencePurchaseEligibilityService $occurrencePurchaseEligibilityService,
        private readonly SeatedProductLookupService $seatedProductLookup,
        private readonly SaleLinkPriceService $saleLinkPriceService,
        private readonly SeatingEventLockService $seatingEventLock,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws SaleLinkUnavailableException
     * @throws EventNotOpenForOrdersException
     * @throws ValidationException
     * @throws Throwable
     */
    public function handle(int $eventId, string $token, string $sessionId, string $locale): OrderDomainObject
    {
        return $this->databaseManager->transaction(function () use ($eventId, $token, $sessionId, $locale) {
            $this->seatingEventLock->lock($eventId);

            $saleLink = $this->saleLinkRepository
                ->loadRelation(SaleLinkItemDomainObject::class)
                ->findFirstWhere([
                    SaleLinkDomainObjectAbstract::EVENT_ID => $eventId,
                    SaleLinkDomainObjectAbstract::TOKEN => $token,
                ]);

            if ($saleLink === null) {
                throw new ResourceNotFoundException(__('This link does not exist'));
            }

            $existingOrder = $this->findOpenOrderForSession($saleLink, $sessionId);
            if ($existingOrder !== null) {
                return $existingOrder;
            }

            $this->assertUsable($saleLink, $sessionId);

            $event = $this->eventRepository
                ->loadRelation(EventSettingDomainObject::class)
                ->findById($eventId);

            if ($event->getStatus() === EventStatus::PENDING_MANUAL_REVIEW->name) {
                throw new EventNotOpenForOrdersException(__('This event is not live.'));
            }

            $products = $this->getProducts($saleLink);
            $this->assertCanBeSold($saleLink, $products);

            $promoCode = $this->getPromoCode($saleLink);

            $this->orderManagementService->deleteExistingOrders($eventId, $sessionId);

            $order = $this->orderManagementService->createNewOrder(
                eventId: $eventId,
                event: $event,
                timeOutMinutes: $event->getEventSettings()?->getOrderTimeoutInMinutes(),
                locale: $locale,
                promoCode: $promoCode,
                affiliate: $this->getAffiliate($saleLink),
                sessionId: $sessionId,
            );

            $this->orderRepository->updateFromArray($order->getId(), [
                OrderDomainObjectAbstract::SALE_LINK_ID => $saleLink->getId(),
                OrderDomainObjectAbstract::COUNTS_TOWARD_CAPACITY => $saleLink->getCountTowardCap(),
            ]);

            $orderItems = $this->orderItemProcessingService->process(
                order: $order,
                productsOrderDetails: $this->buildOrderDetails($saleLink, $products, $promoCode),
                event: $event,
                promoCode: $saleLink->getPromoMode() === SaleLinkPromoMode::APPLY_DISCOUNT->value ? $promoCode : null,
                allowClientPrices: true,
            );

            return $this->orderManagementService->updateOrderTotals(
                $order,
                $orderItems->map(fn (ProcessedOrderItemDTO $item) => $item->order_item),
            );
        });
    }

    private function findOpenOrderForSession(SaleLinkDomainObject $saleLink, string $sessionId): ?OrderDomainObject
    {
        $order = $this->orderRepository->findFirstWhere([
            OrderDomainObjectAbstract::SALE_LINK_ID => $saleLink->getId(),
            OrderDomainObjectAbstract::SESSION_ID => $sessionId,
            OrderDomainObjectAbstract::STATUS => OrderStatus::RESERVED->name,
            [OrderDomainObjectAbstract::RESERVED_UNTIL, '>', Carbon::now()],
        ]);

        if ($order === null) {
            return null;
        }

        return $this->orderRepository
            ->loadRelation(OrderItemDomainObject::class)
            ->findById($order->getId());
    }

    /**
     * @throws SaleLinkUnavailableException
     */
    private function assertUsable(SaleLinkDomainObject $saleLink, string $sessionId): void
    {
        $message = match ($saleLink->getDerivedStatus()) {
            SaleLinkStatus::REVOKED => __('This link is no longer available'),
            SaleLinkStatus::EXPIRED => __('This link has expired'),
            SaleLinkStatus::USED => __('This link has already been used'),
            SaleLinkStatus::ACTIVE => null,
        };

        if ($message === null && $this->saleLinkRepository->countOrdersHoldingUse($saleLink->getId(), $sessionId) >= $saleLink->getMaxUses()) {
            $message = __('This link is being used by someone else. Please try again in a few minutes.');
        }

        if ($message !== null) {
            throw new SaleLinkUnavailableException($message);
        }
    }

    /**
     * @return Collection<int, ProductDomainObject>
     */
    private function getProducts(SaleLinkDomainObject $saleLink): Collection
    {
        return $this->productRepository
            ->loadRelation(TaxAndFeesDomainObject::class)
            ->findWhereIn(
                field: ProductDomainObjectAbstract::ID,
                values: $saleLink->getSaleLinkItems()->map(fn (SaleLinkItemDomainObject $item) => $item->getProductId())->unique()->values()->all(),
                additionalWhere: [ProductDomainObjectAbstract::EVENT_ID => $saleLink->getEventId()],
            )
            ->keyBy(fn (ProductDomainObject $product) => $product->getId());
    }

    /**
     * @param  Collection<int, ProductDomainObject>  $products
     *
     * @throws SaleLinkUnavailableException
     * @throws ValidationException
     */
    private function assertCanBeSold(SaleLinkDomainObject $saleLink, Collection $products): void
    {
        foreach ($saleLink->getSaleLinkItems() as $item) {
            if (! $products->has($item->getProductId()) || $this->seatedProductLookup->isSeated($item->getProductId())) {
                throw new SaleLinkUnavailableException(__('This link is no longer available'));
            }
        }

        $occurrenceId = $saleLink->getEventOccurrenceId();
        $ticketQuantity = $saleLink->getSaleLinkItems()
            ->filter(fn (SaleLinkItemDomainObject $item) => $products->get($item->getProductId())->getProductType() === ProductType::TICKET->name)
            ->sum(fn (SaleLinkItemDomainObject $item) => $item->getQuantity());

        if ($saleLink->getBypassCapacity()) {
            if ($occurrenceId !== null) {
                $this->occurrencePurchaseEligibilityService->assertOccurrencePurchasable(
                    eventId: $saleLink->getEventId(),
                    occurrenceId: $occurrenceId,
                    overrideCapacity: true,
                );
            }

            return;
        }

        $availability = $this->availableProductQuantitiesFetchService->getAvailableProductQuantities(
            $saleLink->getEventId(),
            ignoreCache: true,
            eventOccurrenceId: $occurrenceId,
        );

        if ($occurrenceId !== null) {
            $this->occurrencePurchaseEligibilityService->assertOccurrencePurchasable(
                eventId: $saleLink->getEventId(),
                occurrenceId: $occurrenceId,
                additionalQuantity: $ticketQuantity,
                occurrence: $availability->occurrence,
                reservedQuantity: $availability->occurrenceReservedQuantity,
            );
        }

        $quantityByProductId = [];
        foreach ($saleLink->getSaleLinkItems() as $item) {
            $available = $availability->productQuantities
                ->where('product_id', $item->getProductId())
                ->where('price_id', $item->getProductPriceId())
                ->first()
                ?->purchasableQuantity() ?? 0;

            if ($item->getQuantity() > $available) {
                throw new SaleLinkUnavailableException(__('These tickets are sold out'));
            }

            $quantityByProductId[$item->getProductId()] = ($quantityByProductId[$item->getProductId()] ?? 0) + $item->getQuantity();
        }

        if ($availability->firstOverflowingPool($quantityByProductId) !== null) {
            throw new SaleLinkUnavailableException(__('These tickets are sold out'));
        }
    }

    private function getPromoCode(SaleLinkDomainObject $saleLink): ?PromoCodeDomainObject
    {
        if ($saleLink->getPromoCodeId() === null) {
            return null;
        }

        $promoCode = $this->promoCodeRepository->findFirstWhere([
            PromoCodeDomainObjectAbstract::ID => $saleLink->getPromoCodeId(),
            PromoCodeDomainObjectAbstract::EVENT_ID => $saleLink->getEventId(),
        ]);

        if ($saleLink->getPromoMode() === SaleLinkPromoMode::APPLY_DISCOUNT->value
            && ! $this->promoCodeUsageValidationService->isPromoCodeUsable($promoCode)) {
            return null;
        }

        return $promoCode;
    }

    private function getAffiliate(SaleLinkDomainObject $saleLink): ?AffiliateDomainObject
    {
        if ($saleLink->getAffiliateId() === null) {
            return null;
        }

        return $this->affiliateRepository->findFirstWhere([
            AffiliateDomainObjectAbstract::ID => $saleLink->getAffiliateId(),
            AffiliateDomainObjectAbstract::EVENT_ID => $saleLink->getEventId(),
            AffiliateDomainObjectAbstract::STATUS => AffiliateStatus::ACTIVE->value,
        ]);
    }

    /**
     * @param  Collection<int, ProductDomainObject>  $products
     * @return Collection<int, ProductOrderDetailsDTO>
     */
    private function buildOrderDetails(SaleLinkDomainObject $saleLink, Collection $products, ?PromoCodeDomainObject $promoCode): Collection
    {
        return $saleLink->getSaleLinkItems()
            ->map(fn (SaleLinkItemDomainObject $item) => new ProductOrderDetailsDTO(
                product_id: $item->getProductId(),
                quantities: collect([new OrderProductPriceDTO(
                    quantity: $item->getQuantity(),
                    price_id: $item->getProductPriceId(),
                    price: $this->saleLinkPriceService->getUnitPrice(
                        saleLink: $saleLink,
                        product: $products->get($item->getProductId()),
                        unitPrice: $item->getUnitPrice(),
                        promoCode: $promoCode,
                    ),
                )]),
                event_occurrence_id: $saleLink->getEventOccurrenceId(),
            ))
            ->values();
    }
}
