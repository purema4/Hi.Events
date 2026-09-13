<?php

namespace Tests\Unit\Services\Domain\Wallet;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Services\Domain\AppleWallet\AppleWalletSyncDispatcher;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletSyncDispatcher;
use HiEvents\Services\Domain\Wallet\WalletPassSyncDispatcher;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class WalletPassSyncDispatcherTest extends TestCase
{
    private GoogleWalletSyncDispatcher|MockInterface $google;

    private AppleWalletSyncDispatcher|MockInterface $apple;

    private WalletPassSyncDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->google = Mockery::mock(GoogleWalletSyncDispatcher::class);
        $this->apple = Mockery::mock(AppleWalletSyncDispatcher::class);
        $this->dispatcher = new WalletPassSyncDispatcher($this->google, $this->apple);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_an_occurrence_change_updates_the_google_class_and_the_apple_passes(): void
    {
        $this->google->shouldReceive('queueOccurrenceClassSync')->once()->with(3);
        $this->apple->shouldReceive('queueOccurrenceSync')->once()->with(3);

        $this->dispatcher->queueOccurrenceSync(3);
    }

    public function test_an_occurrence_attendee_change_updates_the_passes_on_both_wallets(): void
    {
        $this->google->shouldReceive('queueOccurrencePassSync')->once()->with(3);
        $this->apple->shouldReceive('queueOccurrenceSync')->once()->with(3);

        $this->dispatcher->queueOccurrenceAttendeesSync(3);
    }

    public function test_an_event_change_is_sent_to_both_wallets(): void
    {
        $this->google->shouldReceive('queueEventClassSync')->once()->with(10);
        $this->apple->shouldReceive('queueEventSync')->once()->with(10);

        $this->dispatcher->queueEventSync(10);
    }

    public function test_an_image_change_is_sent_to_both_wallets(): void
    {
        $this->google->shouldReceive('queueImageOwnerClassSync')->once()->with(ImageType::EVENT_COVER, 10);
        $this->apple->shouldReceive('queueImageOwnerSync')->once()->with(ImageType::EVENT_COVER, 10);

        $this->dispatcher->queueImageOwnerSync(ImageType::EVENT_COVER, 10);
    }

    public function test_an_organizer_change_is_sent_to_both_wallets(): void
    {
        $this->google->shouldReceive('queueOrganizerClassSync')->once()->with(5);
        $this->apple->shouldReceive('queueOrganizerSync')->once()->with(5);

        $this->dispatcher->queueOrganizerSync(5);
    }

    public function test_an_attendee_change_is_sent_to_both_wallets(): void
    {
        $this->google->shouldReceive('queueAttendeePassSync')->once()->with(31);
        $this->apple->shouldReceive('queueAttendeeSync')->once()->with(31);

        $this->dispatcher->queueAttendeeSync(31);
    }

    public function test_an_order_change_is_sent_to_both_wallets(): void
    {
        $this->google->shouldReceive('queueOrderPassSync')->once()->with(20);
        $this->apple->shouldReceive('queueOrderSync')->once()->with(20);

        $this->dispatcher->queueOrderSync(20);
    }
}
