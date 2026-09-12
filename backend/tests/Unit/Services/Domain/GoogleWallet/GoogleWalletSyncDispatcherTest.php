<?php

namespace Tests\Unit\Services\Domain\GoogleWallet;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Jobs\GoogleWallet\SyncAttendeeGoogleWalletPassJob;
use HiEvents\Jobs\GoogleWallet\SyncEventGoogleWalletClassesJob;
use HiEvents\Jobs\GoogleWallet\SyncGoogleWalletClassJob;
use HiEvents\Jobs\GoogleWallet\SyncOccurrenceGoogleWalletPassesJob;
use HiEvents\Jobs\GoogleWallet\SyncOrderGoogleWalletPassesJob;
use HiEvents\Jobs\GoogleWallet\SyncOrganizerGoogleWalletClassesJob;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletPassSettingsResolver;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletSyncDispatcher;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class GoogleWalletSyncDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function dispatcher(bool $isConfigured): GoogleWalletSyncDispatcher
    {
        return new GoogleWalletSyncDispatcher(
            Mockery::mock(GoogleWalletPassSettingsResolver::class, ['isConfigured' => $isConfigured]),
        );
    }

    public function test_it_queues_a_job_for_each_sync_target(): void
    {
        $dispatcher = $this->dispatcher(true);

        $dispatcher->queueOccurrenceClassSync(1);
        $dispatcher->queueEventClassSync(2);
        $dispatcher->queueOrganizerClassSync(3);
        $dispatcher->queueAttendeePassSync(4);
        $dispatcher->queueOrderPassSync(5);
        $dispatcher->queueOccurrencePassSync(6);

        Bus::assertDispatched(SyncGoogleWalletClassJob::class);
        Bus::assertDispatched(SyncEventGoogleWalletClassesJob::class);
        Bus::assertDispatched(SyncOrganizerGoogleWalletClassesJob::class);
        Bus::assertDispatched(SyncAttendeeGoogleWalletPassJob::class);
        Bus::assertDispatched(SyncOrderGoogleWalletPassesJob::class);
        Bus::assertDispatched(SyncOccurrenceGoogleWalletPassesJob::class);
    }

    public function test_it_queues_nothing_when_google_wallet_is_not_configured(): void
    {
        $dispatcher = $this->dispatcher(false);

        $dispatcher->queueOccurrenceClassSync(1);
        $dispatcher->queueEventClassSync(2);
        $dispatcher->queueOrganizerClassSync(3);
        $dispatcher->queueAttendeePassSync(4);
        $dispatcher->queueOrderPassSync(5);
        $dispatcher->queueOccurrencePassSync(6);

        Bus::assertNothingDispatched();
    }

    public function test_an_event_cover_queues_an_event_sync_and_an_organizer_logo_queues_an_organizer_sync(): void
    {
        $dispatcher = $this->dispatcher(true);

        $dispatcher->queueImageOwnerClassSync(ImageType::EVENT_COVER, 10);
        $dispatcher->queueImageOwnerClassSync(ImageType::ORGANIZER_LOGO, 20);

        Bus::assertDispatched(SyncEventGoogleWalletClassesJob::class);
        Bus::assertDispatched(SyncOrganizerGoogleWalletClassesJob::class);
    }

    public function test_an_unrelated_image_type_queues_nothing(): void
    {
        $dispatcher = $this->dispatcher(true);

        $dispatcher->queueImageOwnerClassSync(ImageType::TICKET_LOGO, 10);
        $dispatcher->queueImageOwnerClassSync(ImageType::GENERIC, 20);

        Bus::assertNothingDispatched();
    }
}
