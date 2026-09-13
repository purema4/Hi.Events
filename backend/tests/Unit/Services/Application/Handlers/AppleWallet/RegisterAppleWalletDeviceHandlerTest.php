<?php

namespace Tests\Unit\Services\Application\Handlers\AppleWallet;

use HiEvents\DomainObjects\AppleWalletRegistrationDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\Exceptions\AppleWallet\AppleWalletAuthenticationException;
use HiEvents\Repository\Interfaces\AppleWalletRegistrationRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Services\Application\Handlers\AppleWallet\DTO\RegisterAppleWalletDeviceDTO;
use HiEvents\Services\Application\Handlers\AppleWallet\RegisterAppleWalletDeviceHandler;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassAuthenticator;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class RegisterAppleWalletDeviceHandlerTest extends TestCase
{
    private const REGISTRATION = [
        'device_library_identifier' => 'device-1',
        'attendee_id' => 31,
    ];

    private AppleWalletPassAuthenticator|MockInterface $authenticator;

    private AppleWalletRegistrationRepositoryInterface|MockInterface $registrationRepository;

    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;

    private RegisterAppleWalletDeviceHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticator = Mockery::mock(AppleWalletPassAuthenticator::class);
        $this->registrationRepository = Mockery::mock(AppleWalletRegistrationRepositoryInterface::class);
        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);

        $this->handler = new RegisterAppleWalletDeviceHandler(
            $this->authenticator,
            $this->registrationRepository,
            $this->attendeeRepository,
        );

        $this->authenticator
            ->shouldReceive('authenticate')
            ->with('pass.events.hi.test', 'hievents-attendee-31', 'token')
            ->andReturn(31)
            ->byDefault();
        $this->attendeeRepository
            ->shouldReceive('findFirst')
            ->with(31)
            ->andReturn((new AttendeeDomainObject)->setId(31))
            ->byDefault();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function dto(): RegisterAppleWalletDeviceDTO
    {
        return new RegisterAppleWalletDeviceDTO(
            passTypeIdentifier: 'pass.events.hi.test',
            serialNumber: 'hievents-attendee-31',
            authenticationToken: 'token',
            deviceLibraryIdentifier: 'device-1',
            pushToken: 'push-1',
        );
    }

    public function test_a_new_device_is_registered_for_the_pass(): void
    {
        $this->registrationRepository->shouldReceive('findFirstWhere')->with(self::REGISTRATION)->andReturn(null);
        $this->registrationRepository
            ->shouldReceive('create')
            ->once()
            ->with([...self::REGISTRATION, 'push_token' => 'push-1']);

        $this->assertTrue($this->handler->handle($this->dto()));
    }

    public function test_a_device_registering_again_refreshes_its_push_token(): void
    {
        $this->registrationRepository
            ->shouldReceive('findFirstWhere')
            ->with(self::REGISTRATION)
            ->andReturn((new AppleWalletRegistrationDomainObject)->setPushToken('old-push'));
        $this->registrationRepository
            ->shouldReceive('updateWhere')
            ->once()
            ->with(['push_token' => 'push-1'], self::REGISTRATION);
        $this->registrationRepository->shouldNotReceive('create');

        $this->assertFalse($this->handler->handle($this->dto()));
    }

    public function test_an_unchanged_registration_is_left_alone(): void
    {
        $this->registrationRepository
            ->shouldReceive('findFirstWhere')
            ->andReturn((new AppleWalletRegistrationDomainObject)->setPushToken('push-1'));
        $this->registrationRepository->shouldNotReceive('updateWhere');
        $this->registrationRepository->shouldNotReceive('create');

        $this->assertFalse($this->handler->handle($this->dto()));
    }

    public function test_an_unauthenticated_request_registers_nothing(): void
    {
        $this->authenticator->shouldReceive('authenticate')->andThrow(new AppleWalletAuthenticationException('nope'));
        $this->registrationRepository->shouldNotReceive('create');

        $this->expectException(AppleWalletAuthenticationException::class);

        $this->handler->handle($this->dto());
    }

    public function test_a_pass_for_a_deleted_ticket_cannot_be_registered(): void
    {
        $this->attendeeRepository->shouldReceive('findFirst')->with(31)->andReturn(null);
        $this->registrationRepository->shouldNotReceive('create');

        $this->expectException(AppleWalletAuthenticationException::class);

        $this->handler->handle($this->dto());
    }
}
