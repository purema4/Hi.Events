<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\AppleWallet;

use HiEvents\Exceptions\AppleWallet\AppleWalletConfigurationException;
use HiEvents\Services\Infrastructure\AppleWallet\DTO\AppleWalletCredentialsDTO;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;

class AppleWalletCredentialsResolver
{
    private const PEM_MARKER = '-----BEGIN';

    private ?AppleWalletCredentialsDTO $resolved = null;

    public function __construct(
        private readonly Repository $config,
        private readonly Filesystem $filesystem,
    ) {}

    /**
     * @throws AppleWalletConfigurationException
     */
    public function resolve(): AppleWalletCredentialsDTO
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        [$certificate, $privateKey] = $this->signingIdentity($this->read(
            'certificate',
            'certificate_file',
            __('No Apple Wallet pass certificate is configured.'),
        ));

        $wwdrCertificate = $this->certificateToPem($this->read(
            'wwdr_certificate',
            'wwdr_certificate_file',
            __('No Apple WWDR intermediate certificate is configured.'),
        ));

        if (openssl_x509_read($wwdrCertificate) === false) {
            throw new AppleWalletConfigurationException(
                __('The Apple WWDR intermediate certificate could not be read.')
            );
        }

        return $this->resolved = new AppleWalletCredentialsDTO(
            certificate: $certificate,
            privateKey: $privateKey,
            wwdrCertificate: $wwdrCertificate,
        );
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws AppleWalletConfigurationException
     */
    private function signingIdentity(string $raw): array
    {
        $password = (string) $this->config->get('apple-wallet.certificate_password');

        if (str_contains($raw, self::PEM_MARKER)) {
            return $this->pemIdentity($raw, $password);
        }

        $bundle = [];

        if (! openssl_pkcs12_read($raw, $bundle, $password)) {
            throw new AppleWalletConfigurationException(
                __('The Apple Wallet pass certificate could not be read. Check the password, or convert the .p12 to PEM with "openssl pkcs12 -legacy -nodes".')
            );
        }

        return [$bundle['cert'], $bundle['pkey']];
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws AppleWalletConfigurationException
     */
    private function pemIdentity(string $pem, string $password): array
    {
        $certificate = openssl_x509_read($pem);
        $privateKey = openssl_pkey_get_private($pem, $password);

        if ($certificate === false || $privateKey === false) {
            throw new AppleWalletConfigurationException(
                __('The Apple Wallet pass certificate PEM must contain both the certificate and its private key.')
            );
        }

        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($privateKey, $privateKeyPem);

        return [$certificatePem, $privateKeyPem];
    }

    /**
     * @throws AppleWalletConfigurationException
     */
    private function read(string $inlineKey, string $fileKey, string $missingMessage): string
    {
        $inline = trim((string) $this->config->get('apple-wallet.'.$inlineKey));

        if ($inline !== '') {
            return $this->decodeIfBase64($inline);
        }

        $path = trim((string) $this->config->get('apple-wallet.'.$fileKey));

        if ($path === '') {
            throw new AppleWalletConfigurationException($missingMessage);
        }

        if (! $this->filesystem->exists($path)) {
            throw new AppleWalletConfigurationException(
                __('The Apple Wallet certificate file :path does not exist.', ['path' => $path])
            );
        }

        return $this->filesystem->get($path);
    }

    private function decodeIfBase64(string $value): string
    {
        if (str_contains($value, self::PEM_MARKER)) {
            return $value;
        }

        $decoded = base64_decode($value, true);

        return $decoded === false ? $value : $decoded;
    }

    private function certificateToPem(string $certificate): string
    {
        if (str_contains($certificate, self::PEM_MARKER)) {
            return $certificate;
        }

        return "-----BEGIN CERTIFICATE-----\n"
            .chunk_split(base64_encode($certificate), 64, "\n")
            ."-----END CERTIFICATE-----\n";
    }
}
