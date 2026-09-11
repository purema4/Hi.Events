<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\GoogleWallet;

use HiEvents\Exceptions\GoogleWallet\GoogleWalletConfigurationException;
use HiEvents\Services\Infrastructure\GoogleWallet\GoogleWalletJwtSigner;
use Illuminate\Config\Repository;

class GoogleWalletSaveUrlService
{
    public function __construct(
        private readonly Repository $config,
        private readonly GoogleWalletJwtSigner $jwtSigner,
    ) {}

    /**
     * @param  array<int, string>  $objectIds
     *
     * @throws GoogleWalletConfigurationException
     */
    public function buildForObjectIds(array $objectIds): string
    {
        $token = $this->jwtSigner->sign([
            'iss' => $this->jwtSigner->serviceAccountEmail(),
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => time(),
            'origins' => $this->config->get('google-wallet.origins'),
            'payload' => [
                'eventTicketObjects' => array_map(
                    static fn (string $objectId) => ['id' => $objectId],
                    array_values($objectIds),
                ),
            ],
        ]);

        return $this->config->get('google-wallet.save_link_base_url').$token;
    }
}
