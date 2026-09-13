<?php

namespace Tests\Unit\Services\Application\Handlers\AppleWallet;

use HiEvents\Exceptions\AppleWallet\AppleWalletAuthenticationException;
use HiEvents\Repository\Interfaces\AppleWalletRegistrationRepositoryInterface;
use HiEvents\Services\Application\Handlers\AppleWallet\DTO\UnregisterAppleWalletDeviceDTO;
use HiEvents\Services\Application\Handlers\AppleWallet\UnregisterAppleWalletDeviceHandler;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassAuthenticator;
use Mockery;
use Tests\TestCase;

class UnregisterAppleWalletDeviceHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function dto(): UnregisterAppleWalletDeviceDTO
    {
        return new UnregisterAppleWalletDeviceDTO(
            passTypeIdentifier: 'pass.events.hi.test',
            serialNumber: 'hievents-attendee-31',
            authenticationToken: 'token',
            deviceLibraryIdentifier: 'device-1',
        );
    }

    public function test_the_device_stops_receiving_updates_for_the_pass(): void
    {
        $authenticator = Mockery::mock(AppleWalletPassAuthenticator::class);
        $authenticator->shouldReceive('authenticate')->with('pass.events.hi.test', 'hievents-attendee-31', 'token')->andReturn(31);

        $registrationRepository = Mockery::mock(AppleWalletRegistrationRepositoryInterface::class);
        $registrationRepository
            ->shouldReceive('deleteWhere')
            ->once()
            ->with(['device_library_identifier' => 'device-1', 'attendee_id' => 31]);

        (new UnregisterAppleWalletDeviceHandler($authenticator, $registrationRepository))->handle($this->dto());
    }

    public function test_an_unauthenticated_request_removes_nothing(): void
    {
        $authenticator = Mockery::mock(AppleWalletPassAuthenticator::class);
        $authenticator->shouldReceive('authenticate')->andThrow(new AppleWalletAuthenticationException('nope'));

        $registrationRepository = Mockery::mock(AppleWalletRegistrationRepositoryInterface::class);
        $registrationRepository->shouldNotReceive('deleteWhere');

        $this->expectException(AppleWalletAuthenticationException::class);

        (new UnregisterAppleWalletDeviceHandler($authenticator, $registrationRepository))->handle($this->dto());
    }
}
