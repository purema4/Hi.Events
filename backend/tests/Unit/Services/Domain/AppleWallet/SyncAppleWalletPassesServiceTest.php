<?php

namespace Tests\Unit\Services\Domain\AppleWallet;

use HiEvents\DomainObjects\AppleWalletRegistrationDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\Exceptions\AppleWallet\AppleWalletPushException;
use HiEvents\Repository\Interfaces\AppleWalletRegistrationRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Services\Domain\AppleWallet\SyncAppleWalletPassesService;
use HiEvents\Services\Infrastructure\AppleWallet\AppleWalletPushClient;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class SyncAppleWalletPassesServiceTest extends TestCase
{
    private AppleWalletRegistrationRepositoryInterface|MockInterface $registrationRepository;

    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;

    private AppleWalletPushClient|MockInterface $pushClient;

    private LoggerInterface|MockInterface $logger;

    private SyncAppleWalletPassesService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registrationRepository = Mockery::mock(AppleWalletRegistrationRepositoryInterface::class);
        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->pushClient = Mockery::mock(AppleWalletPushClient::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->service = new SyncAppleWalletPassesService(
            $this->registrationRepository,
            $this->attendeeRepository,
            $this->pushClient,
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function registration(int $attendeeId, string $pushToken): AppleWalletRegistrationDomainObject
    {
        return (new AppleWalletRegistrationDomainObject)->setAttendeeId($attendeeId)->setPushToken($pushToken);
    }

    public function test_nothing_happens_when_no_device_saved_the_passes(): void
    {
        $this->registrationRepository
            ->shouldReceive('findWhereAttendee')
            ->once()
            ->with([AttendeeDomainObjectAbstract::EVENT_ID => 10])
            ->andReturn(collect());

        $this->attendeeRepository->shouldNotReceive('updateWhere');
        $this->pushClient->shouldNotReceive('notifyPassUpdated');

        $this->service->syncEvent(10);
    }

    public function test_updated_passes_are_marked_and_each_device_is_notified_once(): void
    {
        $this->registrationRepository
            ->shouldReceive('findWhereAttendee')
            ->once()
            ->with([AttendeeDomainObjectAbstract::ORDER_ID => 20])
            ->andReturn(collect([
                $this->registration(7, 'phone'),
                $this->registration(9, 'phone'),
                $this->registration(9, 'watch'),
            ]));

        $this->attendeeRepository
            ->shouldReceive('updateWhere')
            ->once()
            ->withArgs(fn (array $attributes, array $where) => array_key_exists(AttendeeDomainObjectAbstract::APPLE_WALLET_PASS_UPDATED_AT, $attributes)
                && $where === [[AttendeeDomainObjectAbstract::ID, 'in', [7, 9]]]);

        $this->pushClient->shouldReceive('notifyPassUpdated')->once()->with('phone')->andReturn(true);
        $this->pushClient->shouldReceive('notifyPassUpdated')->once()->with('watch')->andReturn(true);
        $this->registrationRepository->shouldNotReceive('deleteWhere');

        $this->service->syncOrder(20);
    }

    public function test_devices_that_removed_the_pass_are_forgotten(): void
    {
        $this->registrationRepository
            ->shouldReceive('findWhereAttendee')
            ->with([AttendeeDomainObjectAbstract::ID => 31])
            ->andReturn(collect([$this->registration(31, 'gone')]));
        $this->attendeeRepository->shouldReceive('updateWhere')->once();
        $this->pushClient->shouldReceive('notifyPassUpdated')->with('gone')->andReturn(false);

        $this->registrationRepository
            ->shouldReceive('deleteWhere')
            ->once()
            ->with(['push_token' => 'gone']);

        $this->service->syncAttendee(31);
    }

    public function test_a_failed_notification_does_not_stop_the_other_devices_being_notified(): void
    {
        $this->registrationRepository
            ->shouldReceive('findWhereAttendee')
            ->with([AttendeeDomainObjectAbstract::EVENT_OCCURRENCE_ID => 3])
            ->andReturn(collect([$this->registration(1, 'failing'), $this->registration(2, 'working')]));
        $this->attendeeRepository->shouldReceive('updateWhere')->once();

        $this->pushClient->shouldReceive('notifyPassUpdated')->with('failing')->andThrow(new AppleWalletPushException('APNs is down'));
        $this->pushClient->shouldReceive('notifyPassUpdated')->once()->with('working')->andReturn(true);
        $this->logger->shouldReceive('error')->once();
        $this->registrationRepository->shouldNotReceive('deleteWhere');

        $this->service->syncOccurrence(3);
    }
}
