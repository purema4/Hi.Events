<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\AppleWallet;

use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\Exceptions\AppleWallet\AppleWalletConfigurationException;
use HiEvents\Exceptions\AppleWallet\AppleWalletPassGenerationException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassService;
use HiEvents\Services\Domain\AppleWallet\DTO\AppleWalletPassFileDTO;

class DownloadAppleWalletPassesPublicHandler
{
    public function __construct(
        private readonly AppleWalletPassService $passService,
    ) {}

    /**
     * @param  array<int, string>  $attendeeShortIds
     *
     * @throws ResourceNotFoundException|AppleWalletConfigurationException|AppleWalletPassGenerationException
     */
    public function handle(int $eventId, array $attendeeShortIds): AppleWalletPassFileDTO
    {
        $pass = $attendeeShortIds === [] ? null : $this->passService->generate([
            AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
            [AttendeeDomainObjectAbstract::SHORT_ID, 'in', $attendeeShortIds],
        ]);

        if ($pass === null) {
            throw new ResourceNotFoundException(__('No Apple Wallet passes were found for these tickets.'));
        }

        return $pass;
    }
}
