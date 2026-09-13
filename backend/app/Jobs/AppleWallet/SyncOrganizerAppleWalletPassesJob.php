<?php

namespace HiEvents\Jobs\AppleWallet;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\AppleWallet\AppleWalletSyncDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncOrganizerAppleWalletPassesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $organizerId) {}

    public function handle(
        EventRepositoryInterface $eventRepository,
        AppleWalletSyncDispatcher $syncDispatcher,
    ): void {
        $eventRepository
            ->findWhere([EventDomainObjectAbstract::ORGANIZER_ID => $this->organizerId])
            ->each(fn (EventDomainObject $event) => $syncDispatcher->queueEventSync($event->getId()));
    }
}
