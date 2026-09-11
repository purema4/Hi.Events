<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\GoogleWallet;

use HiEvents\Exceptions\GoogleWallet\GoogleWalletConfigurationException;
use HiEvents\Services\Infrastructure\GoogleWallet\DTO\GoogleWalletCredentialsDTO;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;

class GoogleWalletCredentialsResolver
{
    private ?GoogleWalletCredentialsDTO $resolved = null;

    public function __construct(
        private readonly Repository $config,
        private readonly Filesystem $filesystem,
    ) {}

    /**
     * @throws GoogleWalletConfigurationException
     */
    public function resolve(): GoogleWalletCredentialsDTO
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $decoded = json_decode($this->rawServiceAccountJson(), true);

        if (! is_array($decoded)) {
            throw new GoogleWalletConfigurationException(
                __('The Google Wallet service account key is not valid JSON.')
            );
        }

        $clientEmail = $decoded['client_email'] ?? null;
        $privateKey = $decoded['private_key'] ?? null;

        if (! is_string($clientEmail) || ! is_string($privateKey)) {
            throw new GoogleWalletConfigurationException(
                __('The Google Wallet service account key is missing a client_email or private_key.')
            );
        }

        return $this->resolved = new GoogleWalletCredentialsDTO(
            clientEmail: $clientEmail,
            privateKey: $privateKey,
        );
    }

    /**
     * @throws GoogleWalletConfigurationException
     */
    private function rawServiceAccountJson(): string
    {
        $inline = trim((string) $this->config->get('google-wallet.service_account_json'));

        if ($inline !== '') {
            return $this->decodeIfBase64($inline);
        }

        $path = trim((string) $this->config->get('google-wallet.service_account_file'));

        if ($path === '') {
            throw new GoogleWalletConfigurationException(
                __('No Google Wallet service account key is configured.')
            );
        }

        if (! $this->filesystem->exists($path)) {
            throw new GoogleWalletConfigurationException(
                __('The Google Wallet service account key file :path does not exist.', ['path' => $path])
            );
        }

        return $this->filesystem->get($path);
    }

    private function decodeIfBase64(string $value): string
    {
        if (str_starts_with($value, '{')) {
            return $value;
        }

        $decoded = base64_decode($value, true);

        return $decoded === false ? $value : $decoded;
    }
}
