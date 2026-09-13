<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\AppleWallet;

use HiEvents\Locale;
use Illuminate\Filesystem\Filesystem;

class AppleWalletButtonResolver
{
    public function __construct(
        private readonly Filesystem $filesystem,
    ) {}

    public function pathForLocale(?string $locale): ?string
    {
        $candidates = array_unique([strtolower((string) $locale), Locale::EN->value]);

        foreach ($candidates as $candidate) {
            $path = resource_path("images/apple-wallet/{$candidate}_add_to_apple_wallet.png");

            if ($candidate !== '' && $this->filesystem->exists($path)) {
                return $path;
            }
        }

        return null;
    }
}
