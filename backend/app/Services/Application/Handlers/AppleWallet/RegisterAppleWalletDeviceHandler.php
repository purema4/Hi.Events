<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\AppleWallet;

use HiEvents\DomainObjects\Generated\AppleWalletRegistrationDomainObjectAbstract;
use HiEvents\Exceptions\AppleWallet\AppleWalletAuthenticationException;
use HiEvents\Repository\Interfaces\AppleWalletRegistrationRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Services\Application\Handlers\AppleWallet\DTO\RegisterAppleWalletDeviceDTO;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassAuthenticator;

class RegisterAppleWalletDeviceHandler
{
    public function __construct(
        private readonly AppleWalletPassAuthenticator $authenticator,
        private readonly AppleWalletRegistrationRepositoryInterface $registrationRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
    ) {}

    /**
     * @return bool true when the device was newly registered for the pass
     *
     * @throws AppleWalletAuthenticationException
     */
    public function handle(RegisterAppleWalletDeviceDTO $dto): bool
    {
        $attendeeId = $this->authenticator->authenticate(
            $dto->passTypeIdentifier,
            $dto->serialNumber,
            $dto->authenticationToken,
        );

        if ($this->attendeeRepository->findFirst($attendeeId) === null) {
            throw new AppleWalletAuthenticationException(__('The Apple Wallet pass could not be authenticated.'));
        }

        $registration = [
            AppleWalletRegistrationDomainObjectAbstract::DEVICE_LIBRARY_IDENTIFIER => $dto->deviceLibraryIdentifier,
            AppleWalletRegistrationDomainObjectAbstract::ATTENDEE_ID => $attendeeId,
        ];

        $existing = $this->registrationRepository->findFirstWhere($registration);

        if ($existing === null) {
            $this->registrationRepository->create([
                ...$registration,
                AppleWalletRegistrationDomainObjectAbstract::PUSH_TOKEN => $dto->pushToken,
            ]);

            return true;
        }

        if ($existing->getPushToken() !== $dto->pushToken) {
            $this->registrationRepository->updateWhere(
                attributes: [AppleWalletRegistrationDomainObjectAbstract::PUSH_TOKEN => $dto->pushToken],
                where: $registration,
            );
        }

        return false;
    }
}
