<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\AppleWallet;

use HiEvents\Exceptions\AppleWallet\AppleWalletAuthenticationException;
use Illuminate\Config\Repository;

class AppleWalletPassAuthenticator
{
    public function __construct(
        private readonly Repository $config,
        private readonly AppleWalletSerialNumberService $serialNumberService,
    ) {}

    /**
     * @return int The attendee the pass belongs to
     *
     * @throws AppleWalletAuthenticationException
     */
    public function authenticate(string $passTypeIdentifier, string $serialNumber, ?string $authenticationToken): int
    {
        $attendeeId = $this->isIssuedPassType($passTypeIdentifier)
            ? $this->serialNumberService->attendeeIdFromSerialNumber($serialNumber)
            : null;

        if ($attendeeId === null
            || $authenticationToken === null
            || ! $this->serialNumberService->isValidAuthenticationToken($serialNumber, $authenticationToken)) {
            throw new AppleWalletAuthenticationException(__('The Apple Wallet pass could not be authenticated.'));
        }

        return $attendeeId;
    }

    public function isIssuedPassType(string $passTypeIdentifier): bool
    {
        $issuedPassType = trim((string) $this->config->get('apple-wallet.pass_type_identifier'));

        return $issuedPassType !== '' && hash_equals($issuedPassType, $passTypeIdentifier);
    }
}
