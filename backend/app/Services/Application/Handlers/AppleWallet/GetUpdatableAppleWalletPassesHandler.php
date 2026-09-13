<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\AppleWallet;

use Carbon\Carbon;
use HiEvents\DomainObjects\AppleWalletRegistrationDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Generated\AppleWalletRegistrationDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\Repository\Interfaces\AppleWalletRegistrationRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Services\Application\Handlers\AppleWallet\DTO\AppleWalletUpdatablePassesDTO;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassAuthenticator;
use HiEvents\Services\Domain\AppleWallet\AppleWalletSerialNumberService;

class GetUpdatableAppleWalletPassesHandler
{
    public function __construct(
        private readonly AppleWalletPassAuthenticator $authenticator,
        private readonly AppleWalletSerialNumberService $serialNumberService,
        private readonly AppleWalletRegistrationRepositoryInterface $registrationRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
    ) {}

    /**
     * @return AppleWalletUpdatablePassesDTO|null null when none of the device's passes changed since the tag
     */
    public function handle(
        string $passTypeIdentifier,
        string $deviceLibraryIdentifier,
        ?string $passesUpdatedSince,
    ): ?AppleWalletUpdatablePassesDTO {
        if (! $this->authenticator->isIssuedPassType($passTypeIdentifier)) {
            return null;
        }

        $attendeeIds = $this->registrationRepository
            ->findWhere([
                AppleWalletRegistrationDomainObjectAbstract::DEVICE_LIBRARY_IDENTIFIER => $deviceLibraryIdentifier,
            ])
            ->map(static fn (AppleWalletRegistrationDomainObject $registration) => $registration->getAttendeeId())
            ->all();

        if ($attendeeIds === []) {
            return null;
        }

        $attendees = $this->attendeeRepository->findWhere(array_filter([
            [AttendeeDomainObjectAbstract::ID, 'in', $attendeeIds],
            is_numeric($passesUpdatedSince)
                ? [AttendeeDomainObjectAbstract::APPLE_WALLET_PASS_UPDATED_AT, '>=', Carbon::createFromTimestamp((int) $passesUpdatedSince)]
                : null,
        ]));

        if ($attendees->isEmpty()) {
            return null;
        }

        $lastUpdated = $attendees
            ->map(static fn (AttendeeDomainObject $attendee) => $attendee->getAppleWalletPassUpdatedAt())
            ->filter()
            ->map(static fn (string $updatedAt) => Carbon::parse($updatedAt)->getTimestamp())
            ->max();

        return new AppleWalletUpdatablePassesDTO(
            serialNumbers: $attendees
                ->map(fn (AttendeeDomainObject $attendee) => $this->serialNumberService->serialNumberForAttendee($attendee->getId()))
                ->values()
                ->all(),
            lastUpdated: (string) ($lastUpdated ?? Carbon::now()->getTimestamp()),
        );
    }
}
