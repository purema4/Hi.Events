<?php

namespace HiEvents\Services\Domain\Waitlist;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\PromoCodeDomainObject;
use HiEvents\DomainObjects\Status\WaitlistEntryStatus;
use HiEvents\DomainObjects\WaitlistEntryDomainObject;
use HiEvents\Enterprise\Seating\Services\Domain\SeatedProductLookupService;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Helper\EmailHelper;
use HiEvents\Jobs\Waitlist\SendWaitlistConfirmationEmailJob;
use HiEvents\Repository\Interfaces\WaitlistEntryRepositoryInterface;
use HiEvents\Services\Application\Handlers\Waitlist\DTO\CreateWaitlistEntryDTO;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

class CreateWaitlistEntryService
{
    public function __construct(
        private readonly WaitlistEntryRepositoryInterface $waitlistEntryRepository,
        private readonly DatabaseManager $databaseManager,
        private readonly SeatedProductLookupService $seatedProductLookup,
    ) {}

    /**
     * @throws ResourceConflictException
     */
    public function createEntry(
        CreateWaitlistEntryDTO $dto,
        EventSettingDomainObject $eventSettings,
        ProductDomainObject $product,
        ProductPriceDomainObject $productPrice,
        ?PromoCodeDomainObject $promoCode = null,
    ): WaitlistEntryDomainObject {
        $this->validateWaitlistEnabled($product);

        $anyTier = $product->isTieredType();
        $queuePrice = $anyTier ? $product->getWaitlistablePrices()->first() : $productPrice;

        $this->validateOnPublicSaleOrUpcoming($product, $queuePrice, $anyTier, $promoCode);

        $queuePriceId = $queuePrice->getId();
        $nextSaleStartAt = $product->getNextSaleStartDate($queuePrice);
        $promoCodeId = $promoCode?->getId();

        /** @var WaitlistEntryDomainObject $entry */
        $entry = $this->databaseManager->transaction(function () use ($dto, $queuePriceId, $anyTier, $nextSaleStartAt, $promoCodeId) {
            $this->waitlistEntryRepository->lockForProductPrice($queuePriceId, $dto->event_occurrence_id);
            $this->validateNoDuplicate($dto, $queuePriceId);

            return $this->waitlistEntryRepository->create([
                'event_id' => $dto->event_id,
                'product_price_id' => $queuePriceId,
                'event_occurrence_id' => $dto->event_occurrence_id,
                'email' => EmailHelper::normalize($dto->email),
                'first_name' => trim($dto->first_name),
                'last_name' => $dto->last_name ? trim($dto->last_name) : null,
                'status' => WaitlistEntryStatus::WAITING->name,
                'cancel_token' => Str::random(64),
                'position' => $this->waitlistEntryRepository->getMaxPosition($queuePriceId, $dto->event_occurrence_id) + 1,
                'any_tier' => $anyTier,
                'next_sale_start_at' => $nextSaleStartAt,
                'promo_code_id' => $promoCodeId,
                'locale' => $dto->locale,
            ]);
        });

        SendWaitlistConfirmationEmailJob::dispatch($entry);

        return $entry;
    }

    /**
     * @throws ResourceConflictException
     */
    private function validateWaitlistEnabled(ProductDomainObject $product): void
    {
        if ($product->getWaitlistEnabled() !== true) {
            throw new ResourceConflictException(__('Waitlist is not enabled for this product'));
        }

        if ($this->seatedProductLookup->isSeated($product->getId())) {
            throw new ResourceConflictException(__('The waitlist is not available for reserved seating tickets'));
        }
    }

    /**
     * @throws ResourceConflictException
     */
    private function validateOnPublicSaleOrUpcoming(
        ProductDomainObject $product,
        ?ProductPriceDomainObject $queuePrice,
        bool $anyTier,
        ?PromoCodeDomainObject $promoCode,
    ): void {
        if (! $product->isOnPublicSaleOrUpcoming($promoCode)
            || $queuePrice === null
            || ! $queuePrice->isOnSaleOrUpcoming()
            || ($product->getHideBeforeSaleStartDate() && $this->isHiddenUntilSaleStart($product, $queuePrice, $anyTier))
        ) {
            throw new ResourceConflictException(__('Waitlist is not enabled for this product'));
        }
    }

    private function isHiddenUntilSaleStart(ProductDomainObject $product, ProductPriceDomainObject $queuePrice, bool $anyTier): bool
    {
        if (! $anyTier) {
            return $queuePrice->isBeforeSaleStartDate();
        }

        return $product->getWaitlistablePrices()->every(
            fn (ProductPriceDomainObject $price) => $price->isBeforeSaleStartDate()
        );
    }

    /**
     * @throws ResourceConflictException
     */
    private function validateNoDuplicate(CreateWaitlistEntryDTO $dto, int $queuePriceId): void
    {
        $conditions = [
            'email' => EmailHelper::normalize($dto->email),
            'event_id' => $dto->event_id,
            ['status', 'in', [WaitlistEntryStatus::WAITING->name, WaitlistEntryStatus::OFFERED->name]],
            'product_price_id' => $queuePriceId,
            'event_occurrence_id' => $dto->event_occurrence_id,
        ];

        $existing = $this->waitlistEntryRepository->findFirstWhere($conditions);

        if ($existing !== null) {
            throw new ResourceConflictException(
                __('You are already on the waitlist for this product')
            );
        }
    }
}
