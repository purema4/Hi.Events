<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\GoogleWallet;

use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Services\Infrastructure\GoogleWallet\GoogleWalletBannerRenderer;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\FilesystemManager;
use Psr\Log\LoggerInterface;
use Throwable;

class GoogleWalletBannerService
{
    private const DIRECTORY = 'google_wallet_banner';

    private const RENDER_VERSION = 2;

    public function __construct(
        private readonly FilesystemManager $filesystemManager,
        private readonly Repository $config,
        private readonly GoogleWalletBannerRenderer $renderer,
        private readonly LoggerInterface $logger,
    ) {}

    public function bannerUrlForCover(ImageDomainObject $cover, string $backgroundColor): ?string
    {
        $backgroundColor = strtolower($backgroundColor);
        $path = sprintf(
            '%s/cover-%d-%s-v%d.png',
            self::DIRECTORY,
            $cover->getId(),
            ltrim($backgroundColor, '#'),
            self::RENDER_VERSION,
        );

        try {
            $disk = $this->filesystemManager->disk($this->config->get('filesystems.public'));

            if (! $disk->exists($path)) {
                $disk->put(
                    $path,
                    $this->renderer->render(
                        (string) $this->filesystemManager->disk($cover->getDisk())->get($cover->getPath()),
                        $backgroundColor,
                    ),
                    ['visibility' => 'public'],
                );
            }
        } catch (Throwable $exception) {
            $this->logger->warning('Failed to build a Google Wallet banner from an event cover', [
                'image_id' => $cover->getId(),
                'exception' => $exception,
            ]);

            return null;
        }

        return Url::getCdnUrl($path);
    }
}
