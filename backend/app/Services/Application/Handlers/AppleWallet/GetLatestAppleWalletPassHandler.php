<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\AppleWallet;

use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\Exceptions\AppleWallet\AppleWalletAuthenticationException;
use HiEvents\Exceptions\AppleWallet\AppleWalletConfigurationException;
use HiEvents\Exceptions\AppleWallet\AppleWalletPassGenerationException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassAuthenticator;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassService;
use HiEvents\Services\Domain\AppleWallet\DTO\AppleWalletPassFileDTO;

class GetLatestAppleWalletPassHandler
{
    public function __construct(
        private readonly AppleWalletPassAuthenticator $authenticator,
        private readonly AppleWalletPassService $passService,
    ) {}

    /**
     * @throws AppleWalletAuthenticationException|ResourceNotFoundException|AppleWalletConfigurationException|AppleWalletPassGenerationException
     */
    public function handle(string $passTypeIdentifier, string $serialNumber, ?string $authenticationToken): AppleWalletPassFileDTO
    {
        $attendeeId = $this->authenticator->authenticate($passTypeIdentifier, $serialNumber, $authenticationToken);

        $pass = $this->passService->generate([AttendeeDomainObjectAbstract::ID => $attendeeId]);

        if ($pass === null) {
            throw new ResourceNotFoundException(__('The Apple Wallet pass is no longer available.'));
        }

        return $pass;
    }
}
