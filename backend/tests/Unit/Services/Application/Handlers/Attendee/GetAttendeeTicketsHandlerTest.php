<?php

namespace Tests\Unit\Services\Application\Handlers\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\DTO\GetAttendeeTicketsDTO;
use HiEvents\Services\Application\Handlers\Attendee\GetAttendeeTicketsHandler;
use HiEvents\Services\Domain\Attendee\AttendeeTicketGroupService;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class GetAttendeeTicketsHandlerTest extends TestCase
{
    private MockInterface|AttendeeRepositoryInterface $attendeeRepository;

    private GetAttendeeTicketsHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->attendeeRepository->shouldReceive('loadRelation')->andReturnSelf();

        $this->handler = new GetAttendeeTicketsHandler(
            $this->attendeeRepository,
            new AttendeeTicketGroupService,
        );
    }

    public function test_returns_every_ticket_in_the_order_sharing_the_recipient_email(): void
    {
        $anchor = $this->attendee(1, 'ticket-one', 'buyer@example.com');

        $this->attendeeRepository->shouldReceive('findFirstWhere')->andReturn($anchor);
        $this->attendeeRepository->shouldReceive('findWhere')->andReturn(collect([
            $this->attendee(3, 'ticket-three', 'guest@example.com'),
            $this->attendee(2, 'ticket-two', 'Buyer@Example.com'),
            $anchor,
        ]));

        $tickets = $this->handler->handle(new GetAttendeeTicketsDTO(
            attendeeShortId: 'ticket-one',
            eventId: 1,
        ));

        $this->assertSame(
            ['ticket-one', 'ticket-two'],
            $tickets->map(fn (AttendeeDomainObject $attendee) => $attendee->getShortId())->all(),
        );
    }

    public function test_throws_when_the_short_id_does_not_match_the_event(): void
    {
        $this->attendeeRepository->shouldReceive('findFirstWhere')->andReturnNull();

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle(new GetAttendeeTicketsDTO(
            attendeeShortId: 'unknown',
            eventId: 1,
        ));
    }

    private function attendee(int $id, string $shortId, string $email): AttendeeDomainObject
    {
        return (new AttendeeDomainObject)
            ->setId($id)
            ->setOrderId(10)
            ->setShortId($shortId)
            ->setEmail($email);
    }
}
