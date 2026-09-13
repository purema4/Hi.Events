<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\AppleWallet;

use Closure;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\AppleWallet\AppleWalletImageException;
use HiEvents\Services\Domain\AppleWallet\DTO\AppleWalletPassSettingsDTO;
use HiEvents\Services\Infrastructure\AppleWallet\AppleWalletImageFetcher;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Imagick;
use ImagickException;
use ImagickPixel;
use Psr\Log\LoggerInterface;
use Throwable;

class AppleWalletPassImageBuilder
{
    private const DEFAULT_ICON_PATH = 'images/apple-wallet/default-icon.png';

    private const ICON_VARIANTS = [
        'icon.png' => [29, 29],
        'icon@2x.png' => [58, 58],
        'icon@3x.png' => [87, 87],
    ];

    private const LOGO_VARIANTS = [
        'logo.png' => [160, 50],
        'logo@2x.png' => [320, 100],
        'logo@3x.png' => [480, 150],
    ];

    private const STRIP_VARIANTS = [
        'strip.png' => [375, 98],
        'strip@2x.png' => [750, 196],
        'strip@3x.png' => [1125, 294],
    ];

    public function __construct(
        private readonly AppleWalletImageFetcher $imageFetcher,
        private readonly FilesystemFactory $filesystemFactory,
        private readonly Filesystem $filesystem,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array<string, string> PNG contents keyed by pass file name
     */
    public function build(
        EventDomainObject $event,
        OrganizerDomainObject $organizer,
        AppleWalletPassSettingsDTO $passSettings,
    ): array {
        $logo = $this->source(
            $this->nonEmpty($event->getEventSettings()?->getAppleWalletLogoUrl()) ?? $passSettings->logoUrl,
            $this->storedImage($organizer->getImages(), ImageType::ORGANIZER_LOGO),
        );

        $strip = $this->source(
            $this->nonEmpty($event->getEventSettings()?->getAppleWalletStripImageUrl()) ?? $passSettings->stripImageUrl,
            $this->storedImage($event->getImages(), ImageType::EVENT_COVER),
        );

        $padToSquare = static function (Imagick $image, int $width, int $height): void {
            $image->setImageBackgroundColor(new ImagickPixel('transparent'));
            $image->thumbnailImage($width, $height, true, true);
        };

        return [
            ...($this->render($logo, self::ICON_VARIANTS, $padToSquare)
                ?? $this->render($this->filesystem->get(resource_path(self::DEFAULT_ICON_PATH)), self::ICON_VARIANTS, $padToSquare)
                ?? []),
            ...($this->render(
                $logo,
                self::LOGO_VARIANTS,
                static fn (Imagick $image, int $width, int $height) => $image->thumbnailImage($width, $height, true),
            ) ?? []),
            ...($this->render(
                $strip,
                self::STRIP_VARIANTS,
                static fn (Imagick $image, int $width, int $height) => $image->cropThumbnailImage($width, $height),
            ) ?? []),
        ];
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $variants
     * @param  Closure(Imagick, int, int): mixed  $resize
     * @return array<string, string>|null
     */
    private function render(?string $source, array $variants, Closure $resize): ?array
    {
        if ($source === null) {
            return null;
        }

        try {
            $rendered = [];

            foreach ($variants as $filename => [$width, $height]) {
                $image = new Imagick;
                $image->readImageBlob($source);
                $image->setFirstIterator();
                $resize($image, $width, $height);
                $image->setImageFormat('png');

                $rendered[$filename] = $image->getImageBlob();
                $image->clear();
            }

            return $rendered;
        } catch (ImagickException $exception) {
            $this->logger->warning('Could not render an Apple Wallet pass image', [
                'exception' => $exception,
            ]);

            return null;
        }
    }

    private function source(?string $url, ?ImageDomainObject $storedImage): ?string
    {
        if ($url !== null) {
            try {
                return $this->imageFetcher->fetch($url);
            } catch (AppleWalletImageException $exception) {
                $this->logger->warning('Could not download an Apple Wallet pass image', [
                    'url' => $url,
                    'exception' => $exception,
                ]);
            }
        }

        if ($storedImage === null) {
            return null;
        }

        try {
            return $this->filesystemFactory
                ->disk($storedImage->getDisk() ?? (string) $this->config->get('filesystems.public'))
                ->get($storedImage->getPath());
        } catch (Throwable $exception) {
            $this->logger->warning('Could not read a stored Apple Wallet pass image', [
                'image_id' => $storedImage->getId(),
                'exception' => $exception,
            ]);

            return null;
        }
    }

    /**
     * @param  Collection<int, ImageDomainObject>|null  $images
     */
    private function storedImage(?Collection $images, ImageType $type): ?ImageDomainObject
    {
        return $images?->first(static fn (ImageDomainObject $image) => $image->getType() === $type->name);
    }

    private function nonEmpty(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
