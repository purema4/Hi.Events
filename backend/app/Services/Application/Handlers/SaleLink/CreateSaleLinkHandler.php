<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\SaleLink;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\AffiliateDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\EventOccurrenceDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\PromoCodeDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\SaleLinkDomainObject;
use HiEvents\DomainObjects\Status\AffiliateStatus;
use HiEvents\DomainObjects\Status\SaleLinkStatus;
use HiEvents\Enterprise\Seating\Services\Domain\SeatedProductLookupService;
use HiEvents\Helper\DateHelper;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\EventOccurrenceRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\PromoCodeRepositoryInterface;
use HiEvents\Repository\Interfaces\SaleLinkItemRepositoryInterface;
use HiEvents\Repository\Interfaces\SaleLinkRepositoryInterface;
use HiEvents\Services\Application\Handlers\SaleLink\DTO\CreateSaleLinkDTO;
use HiEvents\Services\Application\Handlers\SaleLink\DTO\SaleLinkItemDTO;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateSaleLinkHandler
{
    private const TOKEN_LENGTH = 32;

    public function __construct(
        private readonly SaleLinkRepositoryInterface $saleLinkRepository,
        private readonly SaleLinkItemRepositoryInterface $saleLinkItemRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly EventOccurrenceRepositoryInterface $occurrenceRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly PromoCodeRepositoryInterface $promoCodeRepository,
        private readonly AffiliateRepositoryInterface $affiliateRepository,
        private readonly SeatedProductLookupService $seatedProductLookup,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function handle(CreateSaleLinkDTO $data): SaleLinkDomainObject
    {
        $event = $this->eventRepository->findById($data->event_id);

        $this->assertItemsBelongToEvent($data);
        $occurrenceId = $this->resolveOccurrenceId($event, $data->event_occurrence_id);
        $this->assertPromoCodeBelongsToEvent($data);
        $this->assertAffiliateIsActive($data);

        return $this->databaseManager->transaction(function () use ($data, $event, $occurrenceId) {
            $saleLink = $this->saleLinkRepository->create([
                'event_id' => $data->event_id,
                'event_occurrence_id' => $occurrenceId,
                'token' => Str::random(self::TOKEN_LENGTH),
                'price_includes_tax' => $data->price_includes_tax,
                'promo_code_id' => $data->promo_code_id,
                'promo_mode' => $data->promo_mode->value,
                'affiliate_id' => $data->affiliate_id,
                'bypass_capacity' => $data->bypass_capacity,
                'count_toward_cap' => $data->count_toward_cap,
                'max_uses' => $data->max_uses,
                'expires_at' => $data->expires_at === null ? null : DateHelper::convertToUTC($data->expires_at, $event->getTimezone()),
                'email' => $data->email === null ? null : strtolower(trim($data->email)),
                'email_locked' => $data->email !== null && $data->email_locked,
                'note' => $data->note,
                'status' => SaleLinkStatus::ACTIVE->name,
                'created_by' => $data->user_id,
            ]);

            foreach ($data->items as $item) {
                $this->saleLinkItemRepository->create([
                    'sale_link_id' => $saleLink->getId(),
                    'product_id' => $item->product_id,
                    'product_price_id' => $item->product_price_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                ]);
            }

            return $this->saleLinkRepository
                ->loadItemsWithProducts()
                ->findById($saleLink->getId());
        });
    }

    /**
     * @throws ValidationException
     */
    private function assertItemsBelongToEvent(CreateSaleLinkDTO $data): void
    {
        /** @var Collection<int, ProductDomainObject> $products */
        $products = $this->productRepository
            ->loadRelation(ProductPriceDomainObject::class)
            ->findWhereIn(
                field: ProductDomainObjectAbstract::ID,
                values: collect($data->items)->pluck('product_id')->unique()->values()->all(),
                additionalWhere: [ProductDomainObjectAbstract::EVENT_ID => $data->event_id],
            )
            ->keyBy(fn (ProductDomainObject $product) => $product->getId());

        $errors = [];
        $seen = [];

        foreach ($data->items as $index => $item) {
            /** @var SaleLinkItemDTO $item */
            $product = $products->get($item->product_id);

            if ($product === null || $product->getPriceById($item->product_price_id) === null) {
                $errors["items.$index.product_price_id"] = __('This ticket was not found');

                continue;
            }

            if ($this->seatedProductLookup->isSeated($product->getId())) {
                $errors["items.$index.product_id"] = __('Tickets with reserved seating cannot be sold through a sale link');

                continue;
            }

            if (isset($seen[$item->product_price_id])) {
                $errors["items.$index.product_price_id"] = __('This ticket is already in the link');
            }

            $seen[$item->product_price_id] = true;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @throws ValidationException
     */
    private function resolveOccurrenceId(EventDomainObject $event, ?int $occurrenceId): ?int
    {
        if ($occurrenceId !== null) {
            $occurrence = $this->occurrenceRepository->findFirstWhere([
                EventOccurrenceDomainObjectAbstract::ID => $occurrenceId,
                EventOccurrenceDomainObjectAbstract::EVENT_ID => $event->getId(),
            ]);

            if ($occurrence === null) {
                throw ValidationException::withMessages([
                    'event_occurrence_id' => __('The specified event occurrence was not found'),
                ]);
            }

            return $occurrence->getId();
        }

        $occurrences = $this->occurrenceRepository->findWhere([
            EventOccurrenceDomainObjectAbstract::EVENT_ID => $event->getId(),
        ]);

        if ($occurrences->count() > 1) {
            throw ValidationException::withMessages([
                'event_occurrence_id' => __('An event occurrence must be specified'),
            ]);
        }

        return $occurrences->first()?->getId();
    }

    /**
     * @throws ValidationException
     */
    private function assertPromoCodeBelongsToEvent(CreateSaleLinkDTO $data): void
    {
        if ($data->promo_code_id === null) {
            return;
        }

        $promoCode = $this->promoCodeRepository->findFirstWhere([
            PromoCodeDomainObjectAbstract::ID => $data->promo_code_id,
            PromoCodeDomainObjectAbstract::EVENT_ID => $data->event_id,
        ]);

        if ($promoCode === null) {
            throw ValidationException::withMessages([
                'promo_code_id' => __('This promo code is invalid'),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertAffiliateIsActive(CreateSaleLinkDTO $data): void
    {
        if ($data->affiliate_id === null) {
            return;
        }

        $affiliate = $this->affiliateRepository->findFirstWhere([
            AffiliateDomainObjectAbstract::ID => $data->affiliate_id,
            AffiliateDomainObjectAbstract::EVENT_ID => $data->event_id,
            AffiliateDomainObjectAbstract::STATUS => AffiliateStatus::ACTIVE->value,
        ]);

        if ($affiliate === null) {
            throw ValidationException::withMessages([
                'affiliate_id' => __('This affiliate was not found or is inactive'),
            ]);
        }
    }
}
