<?php

namespace HiEvents\Jobs\GoogleWallet;

use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\Generated\EventOccurrenceDomainObjectAbstract;
use HiEvents\DomainObjects\Status\EventOccurrenceStatus;
use HiEvents\Repository\Interfaces\EventOccurrenceRepositoryInterface;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletSyncDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncEventGoogleWalletClassesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $eventId) {}

    public function handle(
        EventOccurrenceRepositoryInterface $occurrenceRepository,
        GoogleWalletSyncDispatcher $syncDispatcher,
    ): void {
        $occurrenceRepository
            ->findWhere([
                EventOccurrenceDomainObjectAbstract::EVENT_ID => $this->eventId,
                [EventOccurrenceDomainObjectAbstract::GOOGLE_WALLET_CLASS_ID, 'not null', null],
                [EventOccurrenceDomainObjectAbstract::STATUS, '!=', EventOccurrenceStatus::CANCELLED->name],
            ])
            ->reject(fn (EventOccurrenceDomainObject $occurrence) => $occurrence->isPast())
            ->each(fn (EventOccurrenceDomainObject $occurrence) => $syncDispatcher->queueOccurrenceClassSync($occurrence->getId()));
    }
}
