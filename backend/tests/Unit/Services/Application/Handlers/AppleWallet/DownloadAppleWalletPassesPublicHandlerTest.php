<?php

namespace Tests\Unit\Services\Application\Handlers\AppleWallet;

use Carbon\Carbon;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Application\Handlers\AppleWallet\DownloadAppleWalletPassesPublicHandler;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassService;
use HiEvents\Services\Domain\AppleWallet\DTO\AppleWalletPassFileDTO;
use Mockery;
use Tests\TestCase;

class DownloadAppleWalletPassesPublicHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_the_requested_tickets_of_the_event_are_downloaded(): void
    {
        $bundle = new AppleWalletPassFileDTO(contents: 'bundle', mimeType: 'application/vnd.apple.pkpasses', filename: 'tickets.pkpasses', lastModified: Carbon::now());

        $passService = Mockery::mock(AppleWalletPassService::class);
        $passService
            ->shouldReceive('generate')
            ->once()
            ->with(['event_id' => 10, ['short_id', 'in', ['a_first', 'a_second']]])
            ->andReturn($bundle);

        $this->assertSame($bundle, (new DownloadAppleWalletPassesPublicHandler($passService))->handle(10, ['a_first', 'a_second']));
    }

    public function test_tickets_that_cannot_be_found_are_not_found(): void
    {
        $passService = Mockery::mock(AppleWalletPassService::class);
        $passService->shouldReceive('generate')->andReturn(null);

        $this->expectException(ResourceNotFoundException::class);

        (new DownloadAppleWalletPassesPublicHandler($passService))->handle(10, ['a_unknown']);
    }

    public function test_a_request_without_tickets_is_not_found(): void
    {
        $passService = Mockery::mock(AppleWalletPassService::class);
        $passService->shouldNotReceive('generate');

        $this->expectException(ResourceNotFoundException::class);

        (new DownloadAppleWalletPassesPublicHandler($passService))->handle(10, []);
    }
}
