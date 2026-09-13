<?php

namespace Tests\Unit\Services\Domain\GoogleWallet;

use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletBannerService;
use HiEvents\Services\Infrastructure\GoogleWallet\GoogleWalletBannerRenderer;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

class GoogleWalletBannerServiceTest extends TestCase
{
    private const BANNER_PATH = 'google_wallet_banner/cover-7-0d0b0a-v2.png';

    private FilesystemManager|MockInterface $filesystemManager;

    private Filesystem|MockInterface $publicDisk;

    private Filesystem|MockInterface $coverDisk;

    private GoogleWalletBannerRenderer|MockInterface $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicDisk = Mockery::mock(Filesystem::class);
        $this->coverDisk = Mockery::mock(Filesystem::class);
        $this->renderer = Mockery::mock(GoogleWalletBannerRenderer::class);
        $this->filesystemManager = Mockery::mock(FilesystemManager::class);
        $this->filesystemManager->shouldReceive('disk')->with('wallet-public')->andReturn($this->publicDisk);
        $this->filesystemManager->shouldReceive('disk')->with('cover-disk')->andReturn($this->coverDisk);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function service(): GoogleWalletBannerService
    {
        return new GoogleWalletBannerService(
            $this->filesystemManager,
            new Repository(['filesystems' => ['public' => 'wallet-public']]),
            $this->renderer,
            Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing(),
        );
    }

    private function cover(): ImageDomainObject
    {
        return (new ImageDomainObject)->setId(7)->setDisk('cover-disk')->setPath('event_cover/cover.png');
    }

    public function test_it_renders_and_stores_the_banner_the_first_time(): void
    {
        $this->publicDisk->shouldReceive('exists')->with(self::BANNER_PATH)->andReturn(false);
        $this->coverDisk->shouldReceive('get')->with('event_cover/cover.png')->andReturn('cover-bytes');
        $this->renderer->shouldReceive('render')->once()->with('cover-bytes', '#0d0b0a')->andReturn('banner-bytes');
        $this->publicDisk
            ->shouldReceive('put')
            ->once()
            ->with(self::BANNER_PATH, 'banner-bytes', ['visibility' => 'public'])
            ->andReturn(true);

        $url = $this->service()->bannerUrlForCover($this->cover(), '#0D0B0A');

        $this->assertStringEndsWith(self::BANNER_PATH, $url);
    }

    public function test_an_existing_banner_is_reused_without_rendering(): void
    {
        $this->publicDisk->shouldReceive('exists')->with(self::BANNER_PATH)->andReturn(true);
        $this->renderer->shouldNotReceive('render');
        $this->publicDisk->shouldNotReceive('put');

        $url = $this->service()->bannerUrlForCover($this->cover(), '#0d0b0a');

        $this->assertStringEndsWith(self::BANNER_PATH, $url);
    }

    public function test_a_failed_render_returns_no_banner_so_the_pass_still_syncs(): void
    {
        $this->publicDisk->shouldReceive('exists')->andReturn(false);
        $this->coverDisk->shouldReceive('get')->andReturn('not-an-image');
        $this->renderer->shouldReceive('render')->andThrow(new RuntimeException('corrupt image'));
        $this->publicDisk->shouldNotReceive('put');

        $this->assertNull($this->service()->bannerUrlForCover($this->cover(), '#0d0b0a'));
    }
}
