<?php

namespace Tests\Unit\Services\Domain\CheckInList;

use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\Enums\AttendeeCheckInActionType;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\AttendeeCheckInRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\CheckInList\Public\DTO\AttendeeAndActionDTO;
use HiEvents\Services\Domain\CheckInList\CheckInListActivityValidator;
use HiEvents\Services\Domain\CheckInList\CheckInListDataService;
use HiEvents\Services\Domain\CheckInList\CreateAttendeeCheckInService;
use HiEvents\Services\Domain\Order\MarkOrderAsPaidService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class CreateAttendeeCheckInServiceTest extends TestCase
{
    private const EVENT_ID = 5;

    private const ORDER_ID = 9;

    public function test_two_attendees_of_an_order_awaiting_payment_mark_it_as_paid_once(): void
    {
        $attendees = new Collection([$this->attendee(1, 'A-0000001'), $this->attendee(2, 'A-0000002')]);

        $checkInListDataService = Mockery::mock(CheckInListDataService::class);
        $checkInListDataService->shouldReceive('getCheckInList')->andReturn(
            (new CheckInListDomainObject)->setId(3)->setEventId(self::EVENT_ID)->setShortId('cil_test'),
        );
        $checkInListDataService->shouldReceive('getAttendees')->andReturn($attendees);
        $checkInListDataService->shouldReceive('verifyAttendeeBelongsToCheckInList');

        $nextCheckInId = 100;
        $checkInRepository = Mockery::mock(AttendeeCheckInRepositoryInterface::class);
        $checkInRepository->shouldReceive('findWhereIn')->andReturn(new Collection);
        $checkInRepository->shouldReceive('create')->andReturnUsing(
            function (array $attributes) use (&$nextCheckInId) {
                return (new AttendeeCheckInDomainObject)
                    ->setId($nextCheckInId++)
                    ->setAttendeeId($attributes['attendee_id']);
            },
        );

        $eventSettingsRepository = Mockery::mock(EventSettingsRepositoryInterface::class);
        $eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn(
            (new EventSettingDomainObject)->setAllowOrdersAwaitingOfflinePaymentToCheckIn(true),
        );

        $db = Mockery::mock(ConnectionInterface::class);
        $db->shouldReceive('transaction')->andReturnUsing(static fn (callable $callback) => $callback());

        $paidOrders = [];
        $markOrderAsPaidService = Mockery::mock(MarkOrderAsPaidService::class);
        $markOrderAsPaidService->shouldReceive('markOrderAsPaid')->andReturnUsing(
            function (int $orderId) use (&$paidOrders) {
                if (in_array($orderId, $paidOrders, true)) {
                    throw new ResourceConflictException('Order is not awaiting offline payment');
                }
                $paidOrders[] = $orderId;

                return (new OrderDomainObject)->setId($orderId);
            },
        );

        $service = new CreateAttendeeCheckInService(
            $checkInRepository,
            $checkInListDataService,
            $eventSettingsRepository,
            $db,
            $markOrderAsPaidService,
            new CheckInListActivityValidator,
        );

        $createdCheckIns = new Collection;
        $result = $service->checkInAttendees('cil_test', '127.0.0.1', new Collection([
            new AttendeeAndActionDTO('A-0000001', AttendeeCheckInActionType::CHECK_IN_AND_MARK_ORDER_AS_PAID),
            new AttendeeAndActionDTO('A-0000002', AttendeeCheckInActionType::CHECK_IN_AND_MARK_ORDER_AS_PAID),
        ]), $createdCheckIns);

        $this->assertSame([self::ORDER_ID], $paidOrders);
        $this->assertSame([], $result->errors->toArray());
        $this->assertSame([1, 2], $result->attendeeCheckIns->map->getAttendeeId()->all());
        $this->assertSame([100, 101], $createdCheckIns->map->getId()->all());
    }

    private function attendee(int $id, string $publicId): AttendeeDomainObject
    {
        return (new AttendeeDomainObject)
            ->setId($id)
            ->setPublicId($publicId)
            ->setOrderId(self::ORDER_ID)
            ->setEventId(self::EVENT_ID)
            ->setProductId(1)
            ->setFirstName('Test')
            ->setLastName('Attendee')
            ->setStatus(AttendeeStatus::AWAITING_PAYMENT->name);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
