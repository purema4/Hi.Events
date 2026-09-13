<?php

namespace Tests\Unit\Services\Application\Handlers\AppleWallet;

use HiEvents\Exceptions\AppleWallet\AppleWalletAuthenticationException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Application\Handlers\AppleWallet\GetLatestAppleWalletPassHandler;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassAuthenticator;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassService;
use HiEvents\Services\Domain\AppleWallet\DTO\AppleWalletPassFileDTO;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class GetLatestAppleWalletPassHandlerTest extends TestCase
{
    private AppleWalletPassAuthenticator|MockInterface $authenticator;

    private AppleWalletPassService|MockInterface $passService;

    private GetLatestAppleWalletPassHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticator = Mockery::mock(AppleWalletPassAuthenticator::class);
        $this->passService = Mockery::mock(AppleWalletPassService::class);
        $this->handler = new GetLatestAppleWalletPassHandler($this->authenticator, $this->passService);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_the_latest_version_of_the_pass_is_built(): void
    {
        $pass = new AppleWalletPassFileDTO(contents: 'pkpass', mimeType: 'application/vnd.apple.pkpass', filename: 'hievents-attendee-31.pkpass');

        $this->authenticator->shouldReceive('authenticate')->with('pass.events.hi.test', 'hievents-attendee-31', 'token')->andReturn(31);
        $this->passService->shouldReceive('generate')->once()->with(['id' => 31])->andReturn($pass);

        $this->assertSame($pass, $this->handler->handle('pass.events.hi.test', 'hievents-attendee-31', 'token'));
    }

    public function test_a_pass_that_can_no_longer_be_built_is_not_found(): void
    {
        $this->authenticator->shouldReceive('authenticate')->andReturn(31);
        $this->passService->shouldReceive('generate')->andReturn(null);

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle('pass.events.hi.test', 'hievents-attendee-31', 'token');
    }

    public function test_an_unauthenticated_request_gets_no_pass(): void
    {
        $this->authenticator->shouldReceive('authenticate')->andThrow(new AppleWalletAuthenticationException('nope'));
        $this->passService->shouldNotReceive('generate');

        $this->expectException(AppleWalletAuthenticationException::class);

        $this->handler->handle('pass.events.hi.test', 'hievents-attendee-31', 'wrong');
    }
}
