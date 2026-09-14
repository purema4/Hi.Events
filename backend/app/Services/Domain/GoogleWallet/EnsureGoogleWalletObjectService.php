<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\GoogleWallet;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\EventOccurrenceDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\GoogleWallet\GoogleWalletApiException;
use HiEvents\Exceptions\GoogleWallet\GoogleWalletConfigurationException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventOccurrenceRepositoryInterface;
use HiEvents\Services\Domain\Wallet\DTO\WalletPassBrandingDTO;
use HiEvents\Services\Infrastructure\GoogleWallet\GoogleWalletApiClient;

class EnsureGoogleWalletObjectService
{
    public function __construct(
        private readonly GoogleWalletApiClient $apiClient,
        private readonly GoogleWalletIdGenerator $idGenerator,
        private readonly GoogleWalletObjectPayloadBuilder $payloadBuilder,
        private readonly GoogleWalletPassSettingsResolver $passSettingsResolver,
        private readonly EnsureGoogleWalletClassService $ensureClassService,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventOccurrenceRepositoryInterface $occurrenceRepository,
    ) {}

    /**
     * @throws GoogleWalletApiException|GoogleWalletConfigurationException
     */
    public function ensure(
        AttendeeDomainObject $attendee,
        EventDomainObject $event,
        OrganizerDomainObject $organizer,
        ?EventOccurrenceDomainObject $occurrence = null,
        ?WalletPassBrandingDTO $passSettings = null,
    ): ?string {
        $passSettings ??= $this->passSettingsResolver->resolveForOrganizer($organizer->getId());

        if ($passSettings === null) {
            return null;
        }

        $occurrence = $this->resolveOccurrence($attendee, $event, $occurrence);

        if ($occurrence === null) {
            return null;
        }

        $classId = $this->ensureClassService->ensure($occurrence, $event, $organizer, $passSettings);

        if ($classId === null) {
            return null;
        }

        $objectId = $this->idGenerator->objectIdForAttendee($attendee->getId());

        $this->apiClient->upsertEventTicketObject(
            $objectId,
            $this->payloadBuilder->build($objectId, $classId, $attendee, $event),
        );

        if ($attendee->getGoogleWalletObjectId() !== $objectId) {
            $this->attendeeRepository->updateWhere(
                attributes: [AttendeeDomainObjectAbstract::GOOGLE_WALLET_OBJECT_ID => $objectId],
                where: ['id' => $attendee->getId()],
            );

            $attendee->setGoogleWalletObjectId($objectId);
        }

        return $objectId;
    }

    private function resolveOccurrence(
        AttendeeDomainObject $attendee,
        EventDomainObject $event,
        ?EventOccurrenceDomainObject $occurrence,
    ): ?EventOccurrenceDomainObject {
        $resolved = $attendee->getEventOccurrence() ?? $occurrence;

        if ($resolved !== null) {
            return $resolved;
        }

        $occurrenceId = $attendee->getEventOccurrenceId();

        if ($occurrenceId !== null) {
            return $this->occurrenceRepository->findById($occurrenceId);
        }

        $occurrences = $event->getEventOccurrences()
            ?? $this->occurrenceRepository->findWhere([
                EventOccurrenceDomainObjectAbstract::EVENT_ID => $event->getId(),
            ]);

        return $occurrences->count() === 1 ? $occurrences->first() : null;
    }
}
