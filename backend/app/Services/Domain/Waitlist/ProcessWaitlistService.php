<?php

namespace HiEvents\Services\Domain\Waitlist;

use HiEvents\Constants;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\EventOccurrenceDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\PromoCodeDomainObject;
use HiEvents\DomainObjects\Status\WaitlistEntryStatus;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\DomainObjects\WaitlistEntryDomainObject;
use HiEvents\Enterprise\Seating\Services\Domain\SeatedProductLookupService;
use HiEvents\Exceptions\NoCapacityAvailableException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Jobs\Waitlist\SendWaitlistOfferEmailJob;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\EventOccurrenceRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\PromoCodeRepositoryInterface;
use HiEvents\Repository\Interfaces\WaitlistEntryRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\ProductOrderDetailsDTO;
use HiEvents\Services\Domain\EventOccurrence\OccurrencePurchaseEligibilityService;
use HiEvents\Services\Domain\Order\DTO\ProcessedOrderItemDTO;
use HiEvents\Services\Domain\Order\OrderItemProcessingService;
use HiEvents\Services\Domain\Order\OrderManagementService;
use HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesDTO;
use HiEvents\Services\Domain\Product\DTO\OrderProductPriceDTO;
use HiEvents\Services\Domain\PromoCode\PromoCodeUsageValidationService;
use HiEvents\Services\Infrastructure\Lock\TransactionLockService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProcessWaitlistService
{
    private const DEFAULT_OFFER_TIMEOUT_MINUTES = 60 * 12;

    public function __construct(
        private readonly WaitlistEntryRepositoryInterface $waitlistEntryRepository,
        private readonly DatabaseManager $databaseManager,
        private readonly OrderManagementService $orderManagementService,
        private readonly OrderItemProcessingService $orderItemProcessingService,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly AvailableProductQuantitiesFetchService $availableQuantitiesService,
        private readonly ProductPriceRepositoryInterface $productPriceRepository,
        private readonly EventOccurrenceRepositoryInterface $eventOccurrenceRepository,
        private readonly OccurrencePurchaseEligibilityService $eligibilityService,
        private readonly SeatedProductLookupService $seatedProductLookup,
        private readonly TransactionLockService $transactionLockService,
        private readonly PromoCodeRepositoryInterface $promoCodeRepository,
        private readonly PromoCodeUsageValidationService $promoCodeUsageValidationService,
    ) {}

    /**
     * @return Collection<int, WaitlistEntryDomainObject>
     */
    public function offerToNext(
        int $productPriceId,
        int $quantity,
        EventDomainObject $event,
        EventSettingDomainObject $eventSettings,
        ?int $eventOccurrenceId = null,
    ): Collection {
        if ($quantity <= 0) {
            throw new NoCapacityAvailableException(
                __('No capacity available for the selected waitlist entries.')
            );
        }

        return $this->databaseManager->transaction(function () use ($productPriceId, $quantity, $event, $eventSettings, $eventOccurrenceId) {
            $this->transactionLockService->lockEvent($event->getId());
            $this->waitlistEntryRepository->lockForProductPrice($productPriceId, $eventOccurrenceId);

            $entries = $eventOccurrenceId !== null
                ? $this->waitlistEntryRepository->getNextWaitingEntries($productPriceId, eventOccurrenceId: $eventOccurrenceId)
                : $this->waitlistEntryRepository->getNextWaitingEntries($productPriceId);

            if ($entries->isEmpty()) {
                throw new NoCapacityAvailableException(
                    __('There are no waiting entries for this product')
                );
            }

            if ($this->isSeatedPrice($productPriceId)) {
                throw new NoCapacityAvailableException(
                    __('The waitlist is not available for reserved seating tickets')
                );
            }

            $offeredEntries = collect();
            $priceQuantitiesByOccurrence = [];
            $offeredTicketsByOccurrenceAndPrice = [];

            foreach ($entries as $entry) {
                if ($entry->getAwaitingSaleStart()) {
                    continue;
                }

                try {
                    $occurrenceId = $this->resolveEventOccurrenceId($event, $entry);
                } catch (ResourceConflictException|ResourceNotFoundException) {
                    continue;
                }

                if (! $this->isOccurrenceStillEligibleForEntry($event, $occurrenceId, $entry)) {
                    continue;
                }

                $priceId = $entry->getProductPriceId();

                if (! isset($priceQuantitiesByOccurrence[$occurrenceId][$priceId])) {
                    $quantities = $this->availableQuantitiesService->getAvailableProductQuantities(
                        $event->getId(),
                        ignoreCache: true,
                        eventOccurrenceId: $occurrenceId,
                    );
                    $priceQuantitiesByOccurrence[$occurrenceId][$priceId] = $this->findPriceQuantities($quantities, $priceId);
                    $offeredTicketsByOccurrenceAndPrice[$occurrenceId][$priceId] = 0;
                }

                $priceQuantities = $priceQuantitiesByOccurrence[$occurrenceId][$priceId];
                $offeredTickets = $offeredTicketsByOccurrenceAndPrice[$occurrenceId][$priceId];

                if ($priceQuantities === null || ! $this->hasRoomForOffer($priceQuantities, $offeredTickets)) {
                    continue;
                }

                $updatedEntry = $this->offerEntry($entry, $event, $eventSettings);
                $offeredEntries->push($updatedEntry);
                $offeredTicketsByOccurrenceAndPrice[$occurrenceId][$priceId] += $priceQuantities->minimumPerOrder();

                if ($offeredEntries->count() >= $quantity) {
                    break;
                }
            }

            if ($offeredEntries->isEmpty()) {
                throw new NoCapacityAvailableException(
                    __('No capacity available for the selected waitlist entries.')
                );
            }

            return $offeredEntries;
        });
    }

    /**
     * @return Collection<int, WaitlistEntryDomainObject>
     */
    public function offerSpecificEntry(
        int $entryId,
        int $eventId,
        EventDomainObject $event,
        EventSettingDomainObject $eventSettings,
    ): Collection {
        return $this->databaseManager->transaction(function () use ($entryId, $eventId, $event, $eventSettings) {
            $this->transactionLockService->lockEvent($event->getId());

            /** @var WaitlistEntryDomainObject|null $entry */
            $entry = $this->waitlistEntryRepository->findFirstWhere([
                'id' => $entryId,
                'event_id' => $eventId,
            ]);

            if ($entry === null) {
                throw new ResourceNotFoundException(__('Waitlist entry not found'));
            }

            $validStatuses = [WaitlistEntryStatus::WAITING->name, WaitlistEntryStatus::OFFER_EXPIRED->name];
            if (! in_array($entry->getStatus(), $validStatuses, true)) {
                throw new ResourceConflictException(
                    __('This waitlist entry cannot be offered in its current status')
                );
            }

            if ($this->isSeatedPrice($entry->getProductPriceId())) {
                throw new ResourceConflictException(
                    __('The waitlist is not available for reserved seating tickets')
                );
            }

            $this->waitlistEntryRepository->lockForProductPrice(
                $entry->getProductPriceId(),
                $entry->getEventOccurrenceId(),
            );

            try {
                $occurrenceId = $this->resolveEventOccurrenceId($event, $entry);
            } catch (ResourceConflictException|ResourceNotFoundException $e) {
                throw new ResourceConflictException(
                    __('This waitlist entry is no longer linked to a valid event date.'),
                    previous: $e,
                );
            }

            if (! $this->isOccurrenceStillEligibleForEntry($event, $occurrenceId, $entry)) {
                throw new ResourceConflictException(
                    __('This event date is no longer available for this product.')
                );
            }

            if (! $this->hasCapacityForEntry($entry, $event)) {
                throw new NoCapacityAvailableException(
                    __('No capacity available to offer this waitlist entry. You will need to increase the available quantity for the product or date.')
                );
            }

            $updatedEntry = $this->offerEntry($entry, $event, $eventSettings);

            return collect([$updatedEntry]);
        });
    }

    private function isOccurrenceStillEligibleForEntry(
        EventDomainObject $event,
        int $occurrenceId,
        WaitlistEntryDomainObject $entry,
    ): bool {
        try {
            $this->eligibilityService->assertOccurrencePurchasable(
                eventId: $event->getId(),
                occurrenceId: $occurrenceId,
                additionalQuantity: 1,
                overrideCapacity: true,
            );

            $productPrice = $this->productPriceRepository->findById($entry->getProductPriceId());
            if ($productPrice === null) {
                return false;
            }

            $this->eligibilityService->assertProductsVisibleOnOccurrence(
                $occurrenceId,
                [$productPrice->getProductId()],
            );
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    private function offerEntry(
        WaitlistEntryDomainObject $entry,
        EventDomainObject $event,
        EventSettingDomainObject $eventSettings,
    ): WaitlistEntryDomainObject {
        $offerExpiresAt = $this->calculateOfferExpiry($eventSettings);
        $sessionIdentifier = sha1(Str::uuid().Str::random(40));
        $order = $this->createReservedOrder($entry, $event, $eventSettings, $sessionIdentifier);

        $this->waitlistEntryRepository->updateWhere(
            attributes: [
                'status' => WaitlistEntryStatus::OFFERED->name,
                'offer_token' => Str::random(64),
                'offered_at' => now(),
                'offer_expires_at' => $offerExpiresAt,
                'order_id' => $order->getId(),
            ],
            where: ['id' => $entry->getId()],
        );

        /** @var WaitlistEntryDomainObject $updatedEntry */
        $updatedEntry = $this->waitlistEntryRepository->findById($entry->getId());

        SendWaitlistOfferEmailJob::dispatch($updatedEntry, $order->getShortId(), $sessionIdentifier);

        return $updatedEntry;
    }

    private function createReservedOrder(
        WaitlistEntryDomainObject $entry,
        EventDomainObject $event,
        EventSettingDomainObject $eventSettings,
        string $sessionIdentifier,
    ): OrderDomainObject {
        $timeoutMinutes = $eventSettings->getWaitlistOfferTimeoutMinutes() ?? self::DEFAULT_OFFER_TIMEOUT_MINUTES;
        $promoCode = $this->getUsablePromoCode($entry);

        $order = $this->orderManagementService->createNewOrder(
            eventId: $event->getId(),
            event: $event,
            timeOutMinutes: $timeoutMinutes,
            locale: $entry->getLocale(),
            promoCode: $promoCode,
            sessionId: $sessionIdentifier,
        );

        $productPrice = $this->productPriceRepository->findById($entry->getProductPriceId());

        $product = $this->productRepository
            ->loadRelation(TaxAndFeesDomainObject::class)
            ->loadRelation(ProductPriceDomainObject::class)
            ->findById($productPrice->getProductId());

        $eventOccurrenceId = $this->resolveEventOccurrenceId($event, $entry);

        $orderDetails = collect([
            new ProductOrderDetailsDTO(
                product_id: $product->getId(),
                quantities: collect([
                    new OrderProductPriceDTO(
                        quantity: max(1, $product->getMinPerOrder() ?? 1),
                        price_id: $productPrice->getId(),
                    ),
                ]),
                event_occurrence_id: $eventOccurrenceId,
            ),
        ]);

        $orderItems = $this->orderItemProcessingService->process(
            order: $order,
            productsOrderDetails: $orderDetails,
            event: $event,
            promoCode: $promoCode,
        );

        return $this->orderManagementService->updateOrderTotals(
            $order,
            $orderItems->map(fn (ProcessedOrderItemDTO $item) => $item->order_item),
        );
    }

    private function getUsablePromoCode(WaitlistEntryDomainObject $entry): ?PromoCodeDomainObject
    {
        if ($entry->getPromoCodeId() === null) {
            return null;
        }

        $promoCode = $this->promoCodeRepository->findFirstWhere([
            'id' => $entry->getPromoCodeId(),
            'event_id' => $entry->getEventId(),
        ]);

        return $this->promoCodeUsageValidationService->isPromoCodeUsable($promoCode) ? $promoCode : null;
    }

    private function resolveEventOccurrenceId(EventDomainObject $event, WaitlistEntryDomainObject $entry): ?int
    {
        if ($entry->getEventOccurrenceId() !== null) {
            return $entry->getEventOccurrenceId();
        }

        if ($event->isRecurring()) {
            throw new ResourceConflictException(__('Waitlist entry is missing an event date.'));
        }

        $occurrence = $this->eventOccurrenceRepository
            ->findWhere(
                where: [
                    EventOccurrenceDomainObjectAbstract::EVENT_ID => $event->getId(),
                ],
                orderAndDirections: [
                    new OrderAndDirection(EventOccurrenceDomainObjectAbstract::START_DATE, 'asc'),
                ],
            )
            ->first();

        if ($occurrence === null) {
            throw new ResourceNotFoundException(__('No occurrence found for this event.'));
        }

        return $occurrence->getId();
    }

    private function isSeatedPrice(int $productPriceId): bool
    {
        return $this->seatedProductLookup->isSeated($this->productPriceRepository->findById($productPriceId)->getProductId());
    }

    private function hasCapacityForEntry(WaitlistEntryDomainObject $entry, EventDomainObject $event): bool
    {
        try {
            $eventOccurrenceId = $this->resolveEventOccurrenceId($event, $entry);
        } catch (ResourceConflictException|ResourceNotFoundException) {
            return false;
        }

        $quantities = $this->availableQuantitiesService->getAvailableProductQuantities(
            $event->getId(),
            ignoreCache: true,
            eventOccurrenceId: $eventOccurrenceId,
        );

        $priceQuantities = $this->findPriceQuantities($quantities, $entry->getProductPriceId());

        return $priceQuantities !== null && $this->hasRoomForOffer($priceQuantities);
    }

    private function findPriceQuantities(object $quantities, int $priceId): ?AvailableProductQuantitiesDTO
    {
        return collect($quantities->productQuantities)
            ->first(fn (AvailableProductQuantitiesDTO $productQuantity) => $productQuantity->price_id === $priceId);
    }

    private function hasRoomForOffer(AvailableProductQuantitiesDTO $priceQuantities, int $alreadyOfferedTickets = 0): bool
    {
        $ticketsPerOffer = $priceQuantities->minimumPerOrder();
        $remaining = $this->remainingAfter($priceQuantities->quantity_available, $alreadyOfferedTickets);
        $purchasable = $this->remainingAfter($priceQuantities->purchasableQuantity(), $alreadyOfferedTickets);

        return $remaining > 0 && ($remaining >= $ticketsPerOffer || $purchasable >= $ticketsPerOffer);
    }

    private function remainingAfter(int $quantity, int $alreadyOfferedTickets): int
    {
        return $quantity === Constants::INFINITE ? Constants::INFINITE : max(0, $quantity - $alreadyOfferedTickets);
    }

    private function calculateOfferExpiry(EventSettingDomainObject $eventSettings): string
    {
        $timeoutMinutes = $eventSettings->getWaitlistOfferTimeoutMinutes() ?? self::DEFAULT_OFFER_TIMEOUT_MINUTES;

        return now()->addMinutes($timeoutMinutes)->toDateTimeString();
    }
}
