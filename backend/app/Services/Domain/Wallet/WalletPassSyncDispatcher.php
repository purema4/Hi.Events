<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Wallet;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Services\Domain\AppleWallet\AppleWalletSyncDispatcher;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletSyncDispatcher;

class WalletPassSyncDispatcher
{
    public function __construct(
        private readonly GoogleWalletSyncDispatcher $googleWalletSyncDispatcher,
        private readonly AppleWalletSyncDispatcher $appleWalletSyncDispatcher,
    ) {}

    public function queueOccurrenceSync(int $occurrenceId): void
    {
        $this->googleWalletSyncDispatcher->queueOccurrenceClassSync($occurrenceId);
        $this->appleWalletSyncDispatcher->queueOccurrenceSync($occurrenceId);
    }

    public function queueOccurrenceAttendeesSync(int $occurrenceId): void
    {
        $this->googleWalletSyncDispatcher->queueOccurrencePassSync($occurrenceId);
        $this->appleWalletSyncDispatcher->queueOccurrenceSync($occurrenceId);
    }

    public function queueEventSync(int $eventId): void
    {
        $this->googleWalletSyncDispatcher->queueEventClassSync($eventId);
        $this->appleWalletSyncDispatcher->queueEventSync($eventId);
    }

    public function queueImageOwnerSync(ImageType $imageType, int $entityId): void
    {
        $this->googleWalletSyncDispatcher->queueImageOwnerClassSync($imageType, $entityId);
        $this->appleWalletSyncDispatcher->queueImageOwnerSync($imageType, $entityId);
    }

    public function queueOrganizerSync(int $organizerId): void
    {
        $this->googleWalletSyncDispatcher->queueOrganizerClassSync($organizerId);
        $this->appleWalletSyncDispatcher->queueOrganizerSync($organizerId);
    }

    public function queueAttendeeSync(int $attendeeId): void
    {
        $this->googleWalletSyncDispatcher->queueAttendeePassSync($attendeeId);
        $this->appleWalletSyncDispatcher->queueAttendeeSync($attendeeId);
    }

    public function queueOrderSync(int $orderId): void
    {
        $this->googleWalletSyncDispatcher->queueOrderPassSync($orderId);
        $this->appleWalletSyncDispatcher->queueOrderSync($orderId);
    }
}
