<?php

namespace Tests\Unit\Services\Infrastructure\GoogleWallet;

use HiEvents\Services\Infrastructure\GoogleWallet\GoogleWalletBannerRenderer;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class GoogleWalletBannerRendererTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('The imagick extension is required to render Google Wallet banners.');
        }
    }

    private function solidImage(int $width, int $height, string $colour): string
    {
        $image = new Imagick;
        $image->newImage($width, $height, new ImagickPixel($colour));
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    private function pixel(string $blob, int $x, int $y): array
    {
        $image = new Imagick;
        $image->readImageBlob($blob);

        return $image->getImagePixelColor($x, $y)->getColor();
    }

    public function test_the_banner_uses_googles_recommended_size(): void
    {
        $banner = (new GoogleWalletBannerRenderer)->render($this->solidImage(2400, 1000, '#ff0000'), '#102030');

        $image = new Imagick;
        $image->readImageBlob($banner);

        $this->assertSame(GoogleWalletBannerRenderer::WIDTH, $image->getImageWidth());
        $this->assertSame(GoogleWalletBannerRenderer::HEIGHT, $image->getImageHeight());
        $this->assertSame('PNG', $image->getImageFormat());
    }

    public function test_the_top_of_the_banner_is_the_card_colour_so_it_blends_into_the_pass(): void
    {
        $banner = (new GoogleWalletBannerRenderer)->render($this->solidImage(2400, 1000, '#ff0000'), '#102030');

        $this->assertSame(['r' => 16, 'g' => 32, 'b' => 48, 'a' => 1], $this->pixel($banner, 516, 0));
    }

    public function test_the_photo_fills_the_banner_below_the_fade(): void
    {
        $banner = (new GoogleWalletBannerRenderer)->render($this->solidImage(600, 1200, '#ff0000'), '#102030');
        $red = ['r' => 255, 'g' => 0, 'b' => 0, 'a' => 1];

        $this->assertSame($red, $this->pixel($banner, 516, intdiv(GoogleWalletBannerRenderer::HEIGHT, 2)));
        $this->assertSame($red, $this->pixel($banner, 516, GoogleWalletBannerRenderer::HEIGHT - 1));
    }
}
