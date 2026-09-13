<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\AppleWallet;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Jobs\AppleWallet\SyncAttendeeAppleWalletPassJob;
use HiEvents\Jobs\AppleWallet\SyncEventAppleWalletPassesJob;
use HiEvents\Jobs\AppleWallet\SyncOccurrenceAppleWalletPassesJob;
use HiEvents\Jobs\AppleWallet\SyncOrderAppleWalletPassesJob;
use HiEvents\Jobs\AppleWallet\SyncOrganizerAppleWalletPassesJob;

class AppleWalletSyncDispatcher
{
    public function __construct(
        private readonly AppleWalletPassSettingsResolver $passSettingsResolver,
    ) {}

    public function queueEventSync(int $eventId): void
    {
        if (! $this->passSettingsResolver->isConfigured()) {
            return;
        }

        SyncEventAppleWalletPassesJob::dispatch($eventId);
    }

    public function queueOrganizerSync(int $organizerId): void
    {
        if (! $this->passSettingsResolver->isConfigured()) {
            return;
        }

        SyncOrganizerAppleWalletPassesJob::dispatch($organizerId);
    }

    public function queueImageOwnerSync(ImageType $imageType, int $entityId): void
    {
        match ($imageType) {
            ImageType::EVENT_COVER => $this->queueEventSync($entityId),
            ImageType::ORGANIZER_LOGO => $this->queueOrganizerSync($entityId),
            default => null,
        };
    }

    public function queueOccurrenceSync(int $occurrenceId): void
    {
        if (! $this->passSettingsResolver->isConfigured()) {
            return;
        }

        SyncOccurrenceAppleWalletPassesJob::dispatch($occurrenceId);
    }

    public function queueOrderSync(int $orderId): void
    {
        if (! $this->passSettingsResolver->isConfigured()) {
            return;
        }

        SyncOrderAppleWalletPassesJob::dispatch($orderId);
    }

    public function queueAttendeeSync(int $attendeeId): void
    {
        if (! $this->passSettingsResolver->isConfigured()) {
            return;
        }

        SyncAttendeeAppleWalletPassJob::dispatch($attendeeId);
    }
}
