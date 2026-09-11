<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\GoogleWallet;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventLocationDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\Generated\EventOccurrenceDomainObjectAbstract;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\LocationDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\GoogleWallet\GoogleWalletApiException;
use HiEvents\Exceptions\GoogleWallet\GoogleWalletConfigurationException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventOccurrenceRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\GoogleWallet\DTO\GoogleWalletPassSettingsDTO;
use HiEvents\Services\Infrastructure\GoogleWallet\GoogleWalletApiClient;

class EnsureGoogleWalletClassService
{
    public function __construct(
        private readonly GoogleWalletApiClient $apiClient,
        private readonly GoogleWalletIdGenerator $idGenerator,
        private readonly GoogleWalletClassPayloadBuilder $payloadBuilder,
        private readonly GoogleWalletPassSettingsResolver $passSettingsResolver,
        private readonly EventOccurrenceRepositoryInterface $occurrenceRepository,
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    /**
     * @throws GoogleWalletApiException|GoogleWalletConfigurationException
     */
    public function ensureForOccurrenceId(int $occurrenceId): ?string
    {
        $occurrence = $this->occurrenceRepository
            ->loadRelation(new Relationship(EventLocationDomainObject::class, name: 'event_location', nested: [
                new Relationship(LocationDomainObject::class, name: 'location'),
            ]))
            ->findById($occurrenceId);

        if ($occurrence === null) {
            return null;
        }

        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer', nested: [
                new Relationship(ImageDomainObject::class),
            ]))
            ->loadRelation(new Relationship(ImageDomainObject::class))
            ->loadRelation(new Relationship(EventLocationDomainObject::class, name: 'event_location', nested: [
                new Relationship(LocationDomainObject::class, name: 'location'),
            ]))
            ->findById($occurrence->getEventId());

        if ($event === null) {
            return null;
        }

        return $this->ensure($occurrence, $event, $event->getOrganizer());
    }

    /**
     * @throws GoogleWalletApiException|GoogleWalletConfigurationException
     */
    public function ensure(
        EventOccurrenceDomainObject $occurrence,
        EventDomainObject $event,
        OrganizerDomainObject $organizer,
        ?GoogleWalletPassSettingsDTO $passSettings = null,
    ): ?string {
        $passSettings ??= $this->passSettingsResolver->resolveForOrganizer($organizer->getId());

        if ($passSettings === null) {
            return null;
        }

        $classId = $this->idGenerator->classIdForOccurrence($occurrence->getId());

        $this->apiClient->upsertEventTicketClass(
            $classId,
            $this->payloadBuilder->build($classId, $event, $occurrence, $organizer, $passSettings),
        );

        if ($occurrence->getGoogleWalletClassId() !== $classId) {
            $this->occurrenceRepository->updateWhere(
                attributes: [EventOccurrenceDomainObjectAbstract::GOOGLE_WALLET_CLASS_ID => $classId],
                where: ['id' => $occurrence->getId()],
            );

            $occurrence->setGoogleWalletClassId($classId);
        }

        return $classId;
    }
}
