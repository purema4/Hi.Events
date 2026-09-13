<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\AppleWallet;

use HiEvents\Exceptions\AppleWallet\AppleWalletConfigurationException;
use HiEvents\Exceptions\AppleWallet\AppleWalletPassGenerationException;
use HiEvents\Services\Infrastructure\AppleWallet\AppleWalletPassSigner;
use JsonException;
use ZipArchive;

class AppleWalletPassPackager
{
    public function __construct(
        private readonly AppleWalletPassSigner $signer,
    ) {}

    /**
     * @param  array<string, string>  $images  PNG contents keyed by pass file name
     * @return string The contents of a signed .pkpass file
     *
     * @throws AppleWalletConfigurationException|AppleWalletPassGenerationException
     */
    public function package(array $passJson, array $images): string
    {
        try {
            $files = [
                'pass.json' => json_encode($passJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ...$images,
            ];
        } catch (JsonException $exception) {
            throw new AppleWalletPassGenerationException(
                __('The Apple Wallet pass could not be encoded: :message', ['message' => $exception->getMessage()]),
                previous: $exception,
            );
        }

        $manifest = (string) json_encode(
            array_map(static fn (string $contents) => sha1($contents), $files),
            JSON_UNESCAPED_SLASHES,
        );

        return $this->zip([
            ...$files,
            'manifest.json' => $manifest,
            'signature' => $this->signer->sign($manifest),
        ]);
    }

    /**
     * @param  array<string, string>  $passes  .pkpass contents keyed by file name
     * @return string The contents of a .pkpasses bundle
     *
     * @throws AppleWalletPassGenerationException
     */
    public function bundle(array $passes): string
    {
        return $this->zip($passes);
    }

    /**
     * @param  array<string, string>  $files
     *
     * @throws AppleWalletPassGenerationException
     */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'apple-wallet-');
        $archive = new ZipArchive;

        try {
            if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new AppleWalletPassGenerationException(__('Could not create the Apple Wallet pass archive.'));
            }

            foreach ($files as $name => $contents) {
                $archive->addFromString($name, $contents);
            }

            if (! $archive->close()) {
                throw new AppleWalletPassGenerationException(__('Could not create the Apple Wallet pass archive.'));
            }

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }
}
