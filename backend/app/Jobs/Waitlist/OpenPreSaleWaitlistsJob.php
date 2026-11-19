<?php

namespace HiEvents\Jobs\Waitlist;

use HiEvents\DomainObjects\Enums\CapacityChangeDirection;
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
        $entriesByPriceAndOccurrence = $waitlistEntryRepository
            ->findWhere([
                'status' => WaitlistEntryStatus::WAITING->name,
                'awaiting_sale_start' => true,
            ])
            ->groupBy(fn (WaitlistEntryDomainObject $entry) => $entry->getProductPriceId().':'.($entry->getEventOccurrenceId() ?? ''));

        foreach ($entriesByPriceAndOccurrence as $entries) {
            /** @var WaitlistEntryDomainObject $firstEntry */
            $firstEntry = $entries->first();

            try {
                $productPrice = $productPriceRepository->findFirstWhere(['id' => $firstEntry->getProductPriceId()]);
                $product = $productPrice
                    ? $productRepository->findFirstWhere(['id' => $productPrice->getProductId()])
                    : null;

                if ($product !== null && ($product->isBeforeSaleStartDate() || $productPrice->isBeforeSaleStartDate())) {
                    continue;
                }

                $waitlistEntryRepository->updateWhere(
                    attributes: ['awaiting_sale_start' => false],
                    where: [
                        'product_price_id' => $firstEntry->getProductPriceId(),
                        'event_occurrence_id' => $firstEntry->getEventOccurrenceId(),
                        'status' => WaitlistEntryStatus::WAITING->name,
                        'awaiting_sale_start' => true,
                    ],
                );

                if ($product === null) {
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
