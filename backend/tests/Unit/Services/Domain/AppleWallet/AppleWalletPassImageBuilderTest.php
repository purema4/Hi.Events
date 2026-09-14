<?php

namespace Tests\Unit\Services\Domain\AppleWallet;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\AppleWallet\AppleWalletImageException;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassImageBuilder;
use HiEvents\Services\Domain\Wallet\DTO\WalletPassBrandingDTO;
use HiEvents\Services\Infrastructure\AppleWallet\AppleWalletImageFetcher;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemDisk;
use Illuminate\Filesystem\Filesystem;
use Imagick;
use ImagickPixel;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class AppleWalletPassImageBuilderTest extends TestCase
{
    private const ICONS = ['icon.png', 'icon@2x.png', 'icon@3x.png'];

    private const LOGOS = ['logo.png', 'logo@2x.png', 'logo@3x.png'];

    private const STRIPS = ['strip.png', 'strip@2x.png', 'strip@3x.png'];

    private AppleWalletImageFetcher|MockInterface $imageFetcher;

    private FilesystemFactory|MockInterface $filesystemFactory;

    private AppleWalletPassImageBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imageFetcher = Mockery::mock(AppleWalletImageFetcher::class);
        $this->filesystemFactory = Mockery::mock(FilesystemFactory::class);

        $this->builder = new AppleWalletPassImageBuilder(
            $this->imageFetcher,
            $this->filesystemFactory,
            new Filesystem,
            new Repository(['filesystems' => ['public' => 's3-public']]),
            Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing(),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function png(int $width, int $height): string
    {
        $image = new Imagick;
        $image->newImage($width, $height, new ImagickPixel('#8b5cf6'));
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function dimensions(string $png): array
    {
        $image = new Imagick;
        $image->readImageBlob($png);

        return [$image->getImageWidth(), $image->getImageHeight()];
    }

    private function passSettings(?string $logoUrl = null, ?string $bannerImageUrl = null, ?string $appleStripImageUrl = null): WalletPassBrandingDTO
    {
        return new WalletPassBrandingDTO(
            logoUrl: $logoUrl,
            bannerImageUrl: $bannerImageUrl,
            appleStripImageUrl: $appleStripImageUrl,
            backgroundColor: null,
            themeAccentColor: null,
        );
    }

    private function storedImage(ImageType $type, string $path): ImageDomainObject
    {
        return (new ImageDomainObject)->setId(1)->setType($type->name)->setPath($path)->setDisk('s3-public');
    }

    private function disk(array $contentsByPath): void
    {
        $disk = Mockery::mock(FilesystemDisk::class);

        foreach ($contentsByPath as $path => $contents) {
            $disk->shouldReceive('get')->with($path)->andReturn($contents);
        }

        $this->filesystemFactory->shouldReceive('disk')->with('s3-public')->andReturn($disk);
    }

    public function test_images_set_for_the_pass_are_resized_to_apples_sizes(): void
    {
        $event = (new EventDomainObject)->setEventSettings(
            (new EventSettingDomainObject)->setWalletPassLogoUrl('https://example.com/event-logo.png')
        );

        $this->imageFetcher->shouldReceive('fetch')->with('https://example.com/event-logo.png')->andReturn($this->png(1000, 500));
        $this->imageFetcher->shouldReceive('fetch')->with('https://example.com/strip.png')->andReturn($this->png(2000, 1000));

        $images = $this->builder->build(
            $event,
            new OrganizerDomainObject,
            $this->passSettings(logoUrl: 'https://example.com/organizer-logo.png', bannerImageUrl: 'https://example.com/strip.png'),
        );

        $this->assertEqualsCanonicalizing([...self::ICONS, ...self::LOGOS, ...self::STRIPS], array_keys($images));
        $this->assertSame([58, 58], $this->dimensions($images['icon@2x.png']));
        $this->assertSame([200, 100], $this->dimensions($images['logo@2x.png']));
        $this->assertSame([750, 196], $this->dimensions($images['strip@2x.png']));
    }

    public function test_the_apple_strip_image_is_used_instead_of_the_shared_banner(): void
    {
        $this->imageFetcher->shouldReceive('fetch')->with('https://example.com/apple-strip.png')->once()->andReturn($this->png(1125, 294));
        $this->imageFetcher->shouldNotReceive('fetch')->with('https://example.com/banner.png');

        $images = $this->builder->build(
            (new EventDomainObject)->setEventSettings(
                (new EventSettingDomainObject)->setWalletPassBannerUrl('https://example.com/banner.png')
            ),
            new OrganizerDomainObject,
            $this->passSettings(appleStripImageUrl: 'https://example.com/apple-strip.png'),
        );

        $this->assertSame([1125, 294], $this->dimensions($images['strip@3x.png']));
    }

    public function test_the_event_apple_strip_image_overrides_the_organizer_one(): void
    {
        $this->imageFetcher->shouldReceive('fetch')->with('https://example.com/event-strip.png')->once()->andReturn($this->png(1125, 294));
        $this->imageFetcher->shouldNotReceive('fetch')->with('https://example.com/organizer-strip.png');

        $images = $this->builder->build(
            (new EventDomainObject)->setEventSettings(
                (new EventSettingDomainObject)->setWalletPassAppleStripUrl('https://example.com/event-strip.png')
            ),
            new OrganizerDomainObject,
            $this->passSettings(appleStripImageUrl: 'https://example.com/organizer-strip.png'),
        );

        $this->assertArrayHasKey('strip.png', $images);
    }

    public function test_the_organizer_logo_and_event_cover_are_used_when_no_pass_images_are_set(): void
    {
        $this->disk([
            'organizers/logo.png' => $this->png(400, 400),
            'events/cover.png' => $this->png(1600, 900),
        ]);

        $images = $this->builder->build(
            (new EventDomainObject)->setImages(collect([$this->storedImage(ImageType::EVENT_COVER, 'events/cover.png')])),
            (new OrganizerDomainObject)->setImages(collect([$this->storedImage(ImageType::ORGANIZER_LOGO, 'organizers/logo.png')])),
            $this->passSettings(),
        );

        $this->assertEqualsCanonicalizing([...self::ICONS, ...self::LOGOS, ...self::STRIPS], array_keys($images));
        $this->assertSame([100, 100], $this->dimensions($images['logo@2x.png']));
    }

    public function test_an_image_that_cannot_be_downloaded_falls_back_to_the_stored_image(): void
    {
        $this->imageFetcher->shouldReceive('fetch')->andThrow(new AppleWalletImageException('unreachable'));
        $this->disk(['organizers/logo.png' => $this->png(400, 400)]);

        $images = $this->builder->build(
            new EventDomainObject,
            (new OrganizerDomainObject)->setImages(collect([$this->storedImage(ImageType::ORGANIZER_LOGO, 'organizers/logo.png')])),
            $this->passSettings(logoUrl: 'https://example.com/missing.png'),
        );

        $this->assertArrayHasKey('logo.png', $images);
    }

    public function test_a_pass_without_any_images_still_gets_the_required_icon(): void
    {
        $images = $this->builder->build(new EventDomainObject, new OrganizerDomainObject, $this->passSettings());

        $this->assertEqualsCanonicalizing(self::ICONS, array_keys($images));
        $this->assertSame([87, 87], $this->dimensions($images['icon@3x.png']));
    }

    public function test_an_image_that_is_not_really_an_image_is_skipped(): void
    {
        $this->imageFetcher->shouldReceive('fetch')->andReturn('<html>not an image</html>');

        $images = $this->builder->build(new EventDomainObject, new OrganizerDomainObject, $this->passSettings(logoUrl: 'https://example.com/logo.png'));

        $this->assertEqualsCanonicalizing(self::ICONS, array_keys($images));
    }
}
