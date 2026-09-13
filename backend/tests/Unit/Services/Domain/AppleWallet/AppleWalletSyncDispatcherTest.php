<?php

namespace Tests\Unit\Services\Domain\AppleWallet;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Jobs\AppleWallet\SyncAttendeeAppleWalletPassJob;
use HiEvents\Jobs\AppleWallet\SyncEventAppleWalletPassesJob;
use HiEvents\Jobs\AppleWallet\SyncOccurrenceAppleWalletPassesJob;
use HiEvents\Jobs\AppleWallet\SyncOrderAppleWalletPassesJob;
use HiEvents\Jobs\AppleWallet\SyncOrganizerAppleWalletPassesJob;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassSettingsResolver;
use HiEvents\Services\Domain\AppleWallet\AppleWalletSyncDispatcher;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class AppleWalletSyncDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
    }

    private function dispatcher(bool $configured = true): AppleWalletSyncDispatcher
    {
        $resolver = Mockery::mock(AppleWalletPassSettingsResolver::class);
        $resolver->shouldReceive('isConfigured')->andReturn($configured);

        return new AppleWalletSyncDispatcher($resolver);
    }

    public function test_each_change_queues_the_matching_pass_sync(): void
    {
        $dispatcher = $this->dispatcher();

        $dispatcher->queueEventSync(1);
        $dispatcher->queueOrganizerSync(2);
        $dispatcher->queueOccurrenceSync(3);
        $dispatcher->queueOrderSync(4);
        $dispatcher->queueAttendeeSync(5);

        Bus::assertDispatched(SyncEventAppleWalletPassesJob::class);
        Bus::assertDispatched(SyncOrganizerAppleWalletPassesJob::class);
        Bus::assertDispatched(SyncOccurrenceAppleWalletPassesJob::class);
        Bus::assertDispatched(SyncOrderAppleWalletPassesJob::class);
        Bus::assertDispatched(SyncAttendeeAppleWalletPassJob::class);
    }

    public function test_nothing_is_queued_when_apple_wallet_is_not_configured(): void
    {
        $dispatcher = $this->dispatcher(configured: false);

        $dispatcher->queueEventSync(1);
        $dispatcher->queueOrganizerSync(2);
        $dispatcher->queueOccurrenceSync(3);
        $dispatcher->queueOrderSync(4);
        $dispatcher->queueAttendeeSync(5);
        $dispatcher->queueImageOwnerSync(ImageType::EVENT_COVER, 1);

        Bus::assertNothingDispatched();
    }

    public function test_an_event_cover_change_resyncs_the_event_and_a_logo_change_resyncs_the_organizer(): void
    {
        $dispatcher = $this->dispatcher();

        $dispatcher->queueImageOwnerSync(ImageType::EVENT_COVER, 1);
        $dispatcher->queueImageOwnerSync(ImageType::ORGANIZER_LOGO, 2);

        Bus::assertDispatchedTimes(SyncEventAppleWalletPassesJob::class, 1);
        Bus::assertDispatchedTimes(SyncOrganizerAppleWalletPassesJob::class, 1);
    }

    public function test_images_that_do_not_appear_on_a_pass_are_ignored(): void
    {
        $this->dispatcher()->queueImageOwnerSync(ImageType::ORGANIZER_COVER, 2);

        Bus::assertNothingDispatched();
    }
}
