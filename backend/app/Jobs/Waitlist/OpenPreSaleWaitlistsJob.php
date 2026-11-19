<?php

namespace HiEvents\Jobs\Waitlist;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\CapacityChangeDirection;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\WaitlistEntryStatus;
use HiEvents\DomainObjects\WaitlistEntryDomainObject;
use HiEvents\Events\CapacityChangedEvent;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\WaitlistEntryRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenPreSaleWaitlistsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(
        WaitlistEntryRepositoryInterface $waitlistEntryRepository,
        ProductPriceRepositoryInterface $productPriceRepository,
        ProductRepositoryInterface $productRepository,
    ): void {
        $entryGroups = $waitlistEntryRepository
            ->findWhere([
                'status' => WaitlistEntryStatus::WAITING->name,
                ['next_sale_start_at', '!=', null],
            ])
            ->groupBy(fn (WaitlistEntryDomainObject $entry) => implode(':', [
                $entry->getProductPriceId(),
                $entry->getEventOccurrenceId() ?? '',
                $entry->getNextSaleStartAt(),
            ]));

        foreach ($entryGroups as $entries) {
            /** @var WaitlistEntryDomainObject $firstEntry */
            $firstEntry = $entries->first();
            $storedStart = $firstEntry->getNextSaleStartAt();

            try {
                $productPrice = $productPriceRepository->findFirstWhere(['id' => $firstEntry->getProductPriceId()]);
                $product = $productPrice
                    ? $productRepository->loadRelation(ProductPriceDomainObject::class)->findFirstWhere(['id' => $productPrice->getProductId()])
                    : null;
                $nextStart = $product?->getNextSaleStartDate($productPrice);

                if ($nextStart !== null && Carbon::parse($nextStart)->equalTo(Carbon::parse($storedStart))) {
                    continue;
                }

                $waitlistEntryRepository->updateWhere(
                    attributes: ['next_sale_start_at' => $nextStart],
                    where: [
                        'product_price_id' => $firstEntry->getProductPriceId(),
                        'event_occurrence_id' => $firstEntry->getEventOccurrenceId(),
                        'status' => WaitlistEntryStatus::WAITING->name,
                        'next_sale_start_at' => $storedStart,
                    ],
                );

                if ($product === null || ! $product->isOnSale()) {
                    continue;
                }

                event(new CapacityChangedEvent(
                    eventId: $firstEntry->getEventId(),
                    direction: CapacityChangeDirection::INCREASED,
                    productId: $product->getId(),
                    productPriceId: $firstEntry->getProductPriceId(),
                    eventOccurrenceId: $firstEntry->getEventOccurrenceId(),
                ));
            } catch (Throwable $e) {
                Log::error('Failed to open pre-sale waitlist', [
                    'product_price_id' => $firstEntry->getProductPriceId(),
                    'event_occurrence_id' => $firstEntry->getEventOccurrenceId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
