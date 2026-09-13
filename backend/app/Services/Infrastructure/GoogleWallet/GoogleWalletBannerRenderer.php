<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\GoogleWallet;

use Imagick;
use ImagickException;
use ImagickPixel;

class GoogleWalletBannerRenderer
{
    public const WIDTH = 1032;

    public const HEIGHT = 812;

    private const PHOTO_HEIGHT_RATIO = 0.75;

    private const FADE_HEIGHT_RATIO = 0.4;

    /**
     * @throws ImagickException
     */
    public function render(string $imageBlob, string $backgroundColor): string
    {
        $photoHeight = (int) round(self::HEIGHT * self::PHOTO_HEIGHT_RATIO);

        $photo = new Imagick;
        $photo->readImageBlob($imageBlob);
        $photo->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        $this->coverCrop($photo, self::WIDTH, $photoHeight);

        $fade = new Imagick;
        $fade->newPseudoImage(
            self::WIDTH,
            (int) round($photoHeight * self::FADE_HEIGHT_RATIO),
            "gradient:{$backgroundColor}ff-{$backgroundColor}00",
        );
        $photo->compositeImage($fade, Imagick::COMPOSITE_OVER, 0, 0);

        $banner = new Imagick;
        $banner->newImage(self::WIDTH, self::HEIGHT, new ImagickPixel($backgroundColor));
        $banner->compositeImage($photo, Imagick::COMPOSITE_OVER, 0, self::HEIGHT - $photoHeight);
        $banner->setImageFormat('png');
        $banner->stripImage();

        $blob = $banner->getImageBlob();

        foreach ([$photo, $fade, $banner] as $image) {
            $image->clear();
        }

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
