<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\AppleWallet;

use HiEvents\Exceptions\AppleWallet\AppleWalletConfigurationException;

class AppleWalletPassSigner
{
    public function __construct(
        private readonly AppleWalletCredentialsResolver $credentialsResolver,
    ) {}

    /**
     * @return string DER-encoded detached CMS signature of the manifest
     *
     * @throws AppleWalletConfigurationException
     */
    public function sign(string $manifest): string
    {
        $credentials = $this->credentialsResolver->resolve();

        $manifestPath = $this->temporaryFile($manifest);
        $wwdrCertificatePath = $this->temporaryFile($credentials->wwdrCertificate);
        $signaturePath = $this->temporaryFile('');

        try {
            $signed = openssl_cms_sign(
                $manifestPath,
                $signaturePath,
                $credentials->certificate,
                $credentials->privateKey,
                [],
                OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED,
                OPENSSL_ENCODING_DER,
                $wwdrCertificatePath,
            );

            if (! $signed) {
                throw new AppleWalletConfigurationException(
                    __('Failed to sign the Apple Wallet pass with the configured certificate.')
                );
            }

            return (string) file_get_contents($signaturePath);
        } finally {
            foreach ([$manifestPath, $wwdrCertificatePath, $signaturePath] as $path) {
                @unlink($path);
            }
        }
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'apple-wallet-');
        file_put_contents($path, $contents);

        return $path;
    }
}
