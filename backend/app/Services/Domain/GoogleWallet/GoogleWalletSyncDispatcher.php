<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\GoogleWallet;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Jobs\GoogleWallet\SyncAttendeeGoogleWalletPassJob;
use HiEvents\Jobs\GoogleWallet\SyncEventGoogleWalletClassesJob;
use HiEvents\Jobs\GoogleWallet\SyncGoogleWalletClassJob;
use HiEvents\Jobs\GoogleWallet\SyncOccurrenceGoogleWalletPassesJob;
use HiEvents\Jobs\GoogleWallet\SyncOrderGoogleWalletPassesJob;
use HiEvents\Jobs\GoogleWallet\SyncOrganizerGoogleWalletClassesJob;

class GoogleWalletSyncDispatcher
{
    public function __construct(
        private readonly GoogleWalletPassSettingsResolver $passSettingsResolver,
    ) {}

    public function queueOccurrenceClassSync(int $occurrenceId): void
    {
        if (! $this->passSettingsResolver->isConfigured()) {
            return;
        }

        SyncGoogleWalletClassJob::dispatch($occurrenceId);
    }

    public function queueEventClassSync(int $eventId): void
    {
        if (! $this->passSettingsResolver->isConfigured()) {
            return;
        }

        SyncEventGoogleWalletClassesJob::dispatch($eventId);
    }

    public function queueImageOwnerClassSync(ImageType $imageType, int $entityId): void
    {
        match ($imageType) {
            ImageType::EVENT_COVER => $this->queueEventClassSync($entityId),
            ImageType::ORGANIZER_LOGO => $this->queueOrganizerClassSync($entityId),
            default => null,
        };
    }

    public function queueOrganizerClassSync(int $organizerId): void
    {
        if (! $this->passSettingsResolver->isConfigured()) {
            return;
        }

        SyncOrganizerGoogleWalletClassesJob::dispatch($organizerId);
    }

    public function queueAttendeePassSync(int $attendeeId): void
    {
        if (! $this->passSettingsResolver->isConfigured()) {
            return;
        }

        SyncAttendeeGoogleWalletPassJob::dispatch($attendeeId);
    }

    public function queueOrderPassSync(int $orderId): void
    {
        if (! $this->passSettingsResolver->isConfigured()) {
            return;
        }

        SyncOrderGoogleWalletPassesJob::dispatch($orderId);
    }

    public function queueOccurrencePassSync(int $occurrenceId): void
    {
        if (! $this->passSettingsResolver->isConfigured()) {
            return;
        }

        SyncOccurrenceGoogleWalletPassesJob::dispatch($occurrenceId);
    }
}
