<?php

namespace Tests\Unit\Services\Application\Handlers\AppleWallet;

use Carbon\Carbon;
use HiEvents\DomainObjects\AppleWalletRegistrationDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\Repository\Interfaces\AppleWalletRegistrationRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Services\Application\Handlers\AppleWallet\GetUpdatableAppleWalletPassesHandler;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassAuthenticator;
use HiEvents\Services\Domain\AppleWallet\AppleWalletSerialNumberService;
use Illuminate\Config\Repository;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class GetUpdatableAppleWalletPassesHandlerTest extends TestCase
{
    private const PASS_TYPE_IDENTIFIER = 'pass.events.hi.test';

    private AppleWalletPassAuthenticator|MockInterface $authenticator;

    private AppleWalletRegistrationRepositoryInterface|MockInterface $registrationRepository;

    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;

    private GetUpdatableAppleWalletPassesHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticator = Mockery::mock(AppleWalletPassAuthenticator::class);
        $this->registrationRepository = Mockery::mock(AppleWalletRegistrationRepositoryInterface::class);
        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);

        $this->handler = new GetUpdatableAppleWalletPassesHandler(
            $this->authenticator,
            new AppleWalletSerialNumberService(new Repository(['apple-wallet' => ['serial_prefix' => 'hievents']])),
            $this->registrationRepository,
            $this->attendeeRepository,
        );

        $this->authenticator->shouldReceive('isIssuedPassType')->with(self::PASS_TYPE_IDENTIFIER)->andReturn(true)->byDefault();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function registeredAttendees(int ...$attendeeIds): void
    {
        $this->registrationRepository
            ->shouldReceive('findWhere')
            ->with(['device_library_identifier' => 'device-1'])
            ->andReturn(collect(array_map(
                static fn (int $attendeeId) => (new AppleWalletRegistrationDomainObject)->setAttendeeId($attendeeId),
                $attendeeIds,
            )));
    }

    public function test_passes_of_another_pass_type_are_never_listed(): void
    {
        $this->authenticator->shouldReceive('isIssuedPassType')->with('pass.com.example.other')->andReturn(false);
        $this->registrationRepository->shouldNotReceive('findWhere');

        $this->assertNull($this->handler->handle('pass.com.example.other', 'device-1', null));
    }

    public function test_a_device_without_registrations_has_nothing_to_update(): void
    {
        $this->registeredAttendees();
        $this->attendeeRepository->shouldNotReceive('findWhere');

        $this->assertNull($this->handler->handle(self::PASS_TYPE_IDENTIFIER, 'device-1', null));
    }

    public function test_every_registered_pass_is_listed_when_the_device_has_no_update_tag(): void
    {
        $this->registeredAttendees(7, 9);

        $this->attendeeRepository
            ->shouldReceive('findWhere')
            ->once()
            ->with([['id', 'in', [7, 9]]])
            ->andReturn(collect([
                (new AttendeeDomainObject)->setId(7)->setAppleWalletPassUpdatedAt('2026-09-13 10:00:00'),
                (new AttendeeDomainObject)->setId(9)->setAppleWalletPassUpdatedAt(null),
            ]));

        $updatablePasses = $this->handler->handle(self::PASS_TYPE_IDENTIFIER, 'device-1', null);

        $this->assertSame(['hievents-attendee-7', 'hievents-attendee-9'], $updatablePasses->serialNumbers);
        $this->assertSame((string) Carbon::parse('2026-09-13 10:00:00')->getTimestamp(), $updatablePasses->lastUpdated);
    }

    public function test_only_passes_updated_since_the_tag_are_listed(): void
    {
        $this->registeredAttendees(7);
        $tag = (string) Carbon::parse('2026-09-13 09:00:00')->getTimestamp();

        $this->attendeeRepository
            ->shouldReceive('findWhere')
            ->once()
            ->withArgs(fn (array $where) => $where[0] === ['id', 'in', [7]]
                && $where[1][0] === 'apple_wallet_pass_updated_at'
                && $where[1][1] === '>='
                && $where[1][2]->getTimestamp() === (int) $tag)
            ->andReturn(collect());

        $this->assertNull($this->handler->handle(self::PASS_TYPE_IDENTIFIER, 'device-1', $tag));
    }
}
