<?php

namespace Tests\Unit\Jobs\AppleWallet;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\Jobs\AppleWallet\SyncOrganizerAppleWalletPassesJob;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\AppleWallet\AppleWalletSyncDispatcher;
use Mockery;
use Tests\TestCase;

class SyncOrganizerAppleWalletPassesJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_every_event_of_the_organizer_is_resynced(): void
    {
        $eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $eventRepository
            ->shouldReceive('findWhere')
            ->once()
            ->with([EventDomainObjectAbstract::ORGANIZER_ID => 5])
            ->andReturn(collect([
                (new EventDomainObject)->setId(10),
                (new EventDomainObject)->setId(11),
            ]));

        $syncDispatcher = Mockery::mock(AppleWalletSyncDispatcher::class);
        $syncDispatcher->shouldReceive('queueEventSync')->once()->with(10);
        $syncDispatcher->shouldReceive('queueEventSync')->once()->with(11);

        (new SyncOrganizerAppleWalletPassesJob(5))->handle($eventRepository, $syncDispatcher);
    }
}
