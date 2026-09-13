<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\GoogleWallet;

use Imagick;
use ImagickException;

class GoogleWalletBannerRenderer
{
    public const WIDTH = 1032;

    public const HEIGHT = 812;

    private const FADE_HEIGHT_RATIO = 0.22;

    /**
     * @throws ImagickException
     */
    public function render(string $imageBlob, string $backgroundColor): string
    {
        $banner = new Imagick;
        $banner->readImageBlob($imageBlob);
        $banner->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        $this->coverCrop($banner, self::WIDTH, self::HEIGHT);

        $fade = new Imagick;
        $fade->newPseudoImage(
            self::WIDTH,
            (int) round(self::HEIGHT * self::FADE_HEIGHT_RATIO),
            "gradient:{$backgroundColor}ff-{$backgroundColor}00",
        );
        $banner->compositeImage($fade, Imagick::COMPOSITE_OVER, 0, 0);
        $banner->setImageFormat('png');
        $banner->stripImage();

        $blob = $banner->getImageBlob();

        $fade->clear();
        $banner->clear();

        return $blob;
    }

    /**
     * @throws ImagickException
     */
    private function coverCrop(Imagick $image, int $width, int $height): void
    {
        $scale = max($width / $image->getImageWidth(), $height / $image->getImageHeight());

        $image->resizeImage(
            (int) ceil($image->getImageWidth() * $scale),
            (int) ceil($image->getImageHeight() * $scale),
            Imagick::FILTER_LANCZOS,
            1,
        );
        $image->cropImage(
            $width,
            $height,
            intdiv($image->getImageWidth() - $width, 2),
            intdiv($image->getImageHeight() - $height, 2),
        );
        $image->setImagePage(0, 0, 0, 0);
    }
}
