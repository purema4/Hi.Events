<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\GoogleWallet;

use HiEvents\Exceptions\GoogleWallet\GoogleWalletConfigurationException;

class GoogleWalletJwtSigner
{
    public function __construct(
        private readonly GoogleWalletCredentialsResolver $credentialsResolver,
    ) {}

    /**
     * @throws GoogleWalletConfigurationException
     */
    public function sign(array $claims): string
    {
        $credentials = $this->credentialsResolver->resolve();

        $privateKey = openssl_pkey_get_private($credentials->privateKey);

        if ($privateKey === false) {
            throw new GoogleWalletConfigurationException(
                __('The Google Wallet service account private key could not be read.')
            );
        }

        $signingInput = $this->encodeSegment(['alg' => 'RS256', 'typ' => 'JWT'])
            .'.'
            .$this->encodeSegment($claims);

        $signature = '';

        if (! openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new GoogleWalletConfigurationException(
                __('Failed to sign the Google Wallet request with the configured service account key.')
            );
        }

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    public function serviceAccountEmail(): string
    {
        return $this->credentialsResolver->resolve()->clientEmail;
    }

    private function encodeSegment(array $segment): string
    {
        return $this->base64UrlEncode(json_encode($segment, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
