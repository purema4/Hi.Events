<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\GoogleWallet;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Services\Domain\GoogleWallet\DTO\GoogleWalletPassSettingsDTO;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

class GoogleWalletSaveUrlResolver
{
    public function __construct(
        private readonly EnsureGoogleWalletObjectService $ensureObjectService,
        private readonly GoogleWalletPassSettingsResolver $passSettingsResolver,
        private readonly GoogleWalletSaveUrlService $saveUrlService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  Collection<int, AttendeeDomainObject>  $attendees
     * @return string|null A single save URL covering every attendee's pass
     */
    public function resolveForAttendees(
        Collection $attendees,
        EventDomainObject $event,
        OrganizerDomainObject $organizer,
    ): ?string {
        $passSettings = $this->resolvePassSettings($organizer);

        if ($passSettings === null) {
            return null;
        }

        $objectIds = [];

        foreach ($attendees as $attendee) {
            $objectId = $this->resolveObjectId($attendee, $event, $organizer, $passSettings);

            if ($objectId !== null) {
                $objectIds[] = $objectId;
            }
        }

        if ($objectIds === []) {
            return null;
        }

        try {
            return $this->saveUrlService->buildForObjectIds($objectIds);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to build a Google Wallet save URL', [
                'event_id' => $event->getId(),
                'exception' => $exception,
            ]);

            return null;
        }
    }

    private function resolveObjectId(
        AttendeeDomainObject $attendee,
        EventDomainObject $event,
        OrganizerDomainObject $organizer,
        GoogleWalletPassSettingsDTO $passSettings,
    ): ?string {
        try {
            return $this->ensureObjectService->ensure(
                attendee: $attendee,
                event: $event,
                organizer: $organizer,
                passSettings: $passSettings,
            );
        } catch (Throwable $exception) {
            $this->logger->error('Failed to build a Google Wallet pass for an attendee', [
                'attendee_id' => $attendee->getId(),
                'event_id' => $event->getId(),
                'exception' => $exception,
            ]);

            return null;
        }
    }

    private function resolvePassSettings(OrganizerDomainObject $organizer): ?GoogleWalletPassSettingsDTO
    {
        try {
            return $this->passSettingsResolver->resolveForOrganizer($organizer->getId());
        } catch (Throwable $exception) {
            $this->logger->error('Failed to resolve Google Wallet pass settings', [
                'organizer_id' => $organizer->getId(),
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
