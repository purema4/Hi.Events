<?php

namespace Tests\Unit\Jobs\GoogleWallet;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Jobs\GoogleWallet\SyncOrganizerGoogleWalletClassesJob;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletSyncDispatcher;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class SyncOrganizerGoogleWalletClassesJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_queues_a_class_sync_for_each_of_the_organizers_events(): void
    {
        $events = new Collection([
            (new EventDomainObject)->setId(11),
            (new EventDomainObject)->setId(12),
        ]);

        $eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $eventRepository
            ->shouldReceive('findWhere')
            ->once()
            ->with(['organizer_id' => 7])
            ->andReturn($events);

        $syncDispatcher = Mockery::mock(GoogleWalletSyncDispatcher::class);
        $syncDispatcher->shouldReceive('queueEventClassSync')->once()->with(11);
        $syncDispatcher->shouldReceive('queueEventClassSync')->once()->with(12);

        (new SyncOrganizerGoogleWalletClassesJob(7))->handle($eventRepository, $syncDispatcher);
    }
}
