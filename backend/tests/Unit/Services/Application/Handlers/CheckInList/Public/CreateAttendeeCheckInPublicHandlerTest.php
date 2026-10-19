<?php

namespace Tests\Unit\Services\Application\Handlers\CheckInList\Public;

use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\DomainObjects\Enums\AttendeeCheckInActionType;
use HiEvents\Services\Application\Handlers\CheckInList\Public\CreateAttendeeCheckInPublicHandler;
use HiEvents\Services\Application\Handlers\CheckInList\Public\DTO\AttendeeAndActionDTO;
use HiEvents\Services\Application\Handlers\CheckInList\Public\DTO\CreateAttendeeCheckInPublicDTO;
use HiEvents\Services\Domain\CheckInList\CreateAttendeeCheckInService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\CheckinEvent;
use Illuminate\Support\Collection;
use Mockery;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\TestCase;

class CreateAttendeeCheckInPublicHandlerTest extends TestCase
{
    public function test_check_ins_created_before_a_failure_still_send_their_webhook(): void
    {
        $service = Mockery::mock(CreateAttendeeCheckInService::class);
        $service->shouldReceive('checkInAttendees')->andReturnUsing(
            static function (string $uuid, string $ip, Collection $actions, Collection $createdCheckIns): never {
                $createdCheckIns->push((new AttendeeCheckInDomainObject)->setId(41)->setAttendeeId(1));

                throw new RuntimeException('The second attendee failed');
            },
        );

        $dispatched = [];
        $dispatcher = Mockery::mock(DomainEventDispatcherService::class);
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function (CheckinEvent $event) use (&$dispatched): void {
            $dispatched[] = [$event->type, $event->attendeeCheckinId];
        });

        $handler = new CreateAttendeeCheckInPublicHandler($service, new NullLogger, $dispatcher);

        try {
            $handler->handle(CreateAttendeeCheckInPublicDTO::from([
                'checkInListUuid' => 'cil_test',
                'checkInUserIpAddress' => '127.0.0.1',
                'attendeesAndActions' => [
                    new AttendeeAndActionDTO('A-0000001', AttendeeCheckInActionType::CHECK_IN),
                    new AttendeeAndActionDTO('A-0000002', AttendeeCheckInActionType::CHECK_IN),
                ],
            ]));
            $this->fail('The failure of the second attendee must reach the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The second attendee failed', $exception->getMessage());
        }

        $this->assertSame([[DomainEventType::CHECKIN_CREATED, 41]], $dispatched);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
