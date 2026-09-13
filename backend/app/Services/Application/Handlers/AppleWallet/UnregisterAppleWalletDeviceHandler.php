<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\AppleWallet;

use HiEvents\DomainObjects\Generated\AppleWalletRegistrationDomainObjectAbstract;
use HiEvents\Exceptions\AppleWallet\AppleWalletAuthenticationException;
use HiEvents\Repository\Interfaces\AppleWalletRegistrationRepositoryInterface;
use HiEvents\Services\Application\Handlers\AppleWallet\DTO\UnregisterAppleWalletDeviceDTO;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassAuthenticator;

class UnregisterAppleWalletDeviceHandler
{
    public function __construct(
        private readonly AppleWalletPassAuthenticator $authenticator,
        private readonly AppleWalletRegistrationRepositoryInterface $registrationRepository,
    ) {}

    /**
     * @throws AppleWalletAuthenticationException
     */
    public function handle(UnregisterAppleWalletDeviceDTO $dto): void
    {
        $attendeeId = $this->authenticator->authenticate(
            $dto->passTypeIdentifier,
            $dto->serialNumber,
            $dto->authenticationToken,
        );

        $this->registrationRepository->deleteWhere([
            AppleWalletRegistrationDomainObjectAbstract::DEVICE_LIBRARY_IDENTIFIER => $dto->deviceLibraryIdentifier,
            AppleWalletRegistrationDomainObjectAbstract::ATTENDEE_ID => $attendeeId,
        ]);
    }
}
