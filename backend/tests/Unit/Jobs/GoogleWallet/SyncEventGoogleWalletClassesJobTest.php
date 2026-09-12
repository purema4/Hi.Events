<?php

namespace Tests\Unit\Jobs\GoogleWallet;

use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\Status\EventOccurrenceStatus;
use HiEvents\Jobs\GoogleWallet\SyncEventGoogleWalletClassesJob;
use HiEvents\Repository\Interfaces\EventOccurrenceRepositoryInterface;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletSyncDispatcher;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class SyncEventGoogleWalletClassesJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function occurrence(int $id, string $startDate, ?string $classId): EventOccurrenceDomainObject
    {
        return (new EventOccurrenceDomainObject)
            ->setId($id)
            ->setStartDate($startDate)
            ->setEndDate(null)
            ->setStatus(EventOccurrenceStatus::ACTIVE->name)
            ->setGoogleWalletClassId($classId);
    }

    public function test_it_queues_a_class_sync_for_each_upcoming_occurrence(): void
    {
        $occurrences = new Collection([
            $this->occurrence(1, now()->addDay()->toDateTimeString(), 'issuer.class_1'),
            $this->occurrence(2, now()->addWeek()->toDateTimeString(), 'issuer.class_2'),
        ]);

        $occurrenceRepository = Mockery::mock(EventOccurrenceRepositoryInterface::class);
        $occurrenceRepository
            ->shouldReceive('findWhere')
            ->once()
            ->andReturn($occurrences);

        $syncDispatcher = Mockery::mock(GoogleWalletSyncDispatcher::class);
        $syncDispatcher->shouldReceive('queueOccurrenceClassSync')->once()->with(1);
        $syncDispatcher->shouldReceive('queueOccurrenceClassSync')->once()->with(2);

        (new SyncEventGoogleWalletClassesJob(99))->handle($occurrenceRepository, $syncDispatcher);
    }

    public function test_it_skips_occurrences_that_have_already_happened(): void
    {
        $occurrences = new Collection([
            $this->occurrence(1, now()->subDay()->toDateTimeString(), 'issuer.class_1'),
        ]);

        $occurrenceRepository = Mockery::mock(EventOccurrenceRepositoryInterface::class);
        $occurrenceRepository
            ->shouldReceive('findWhere')
            ->once()
            ->andReturn($occurrences);

        $syncDispatcher = Mockery::mock(GoogleWalletSyncDispatcher::class);
        $syncDispatcher->shouldNotReceive('queueOccurrenceClassSync');

        (new SyncEventGoogleWalletClassesJob(99))->handle($occurrenceRepository, $syncDispatcher);
    }
}
