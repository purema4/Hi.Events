<?php

namespace Tests\Unit\Services\Application\Handlers\Wallet;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Wallet\GetOrderWalletPassesPublicHandler;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassUrlResolver;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletSaveUrlResolver;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class GetOrderWalletPassesPublicHandlerTest extends TestCase
{
    private MockInterface $orderRepository;

    private MockInterface $eventRepository;

    private MockInterface $appleWalletPassUrlResolver;

    private MockInterface $googleWalletSaveUrlResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();

        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();

        $this->appleWalletPassUrlResolver = Mockery::mock(AppleWalletPassUrlResolver::class);
        $this->googleWalletSaveUrlResolver = Mockery::mock(GoogleWalletSaveUrlResolver::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_both_wallets_cover_every_active_ticket_in_the_order(): void
    {
        $active = (new AttendeeDomainObject)->setId(1)->setShortId('a_first')->setStatus(AttendeeStatus::ACTIVE->name);
        $cancelled = (new AttendeeDomainObject)->setId(2)->setShortId('a_cancelled')->setStatus(AttendeeStatus::CANCELLED->name);

        $this->orderRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['short_id' => 'o_first', 'event_id' => 10])
            ->andReturn($this->order(OrderStatus::COMPLETED, collect([$active, $cancelled])));

        $event = (new EventDomainObject)->setId(10)->setOrganizer((new OrganizerDomainObject)->setId(5));
        $this->eventRepository->shouldReceive('findById')->with(10)->andReturn($event);

        $this->appleWalletPassUrlResolver
            ->shouldReceive('resolveForAttendees')
            ->once()
            ->withArgs(fn (Collection $attendees) => $attendees->map(static fn (AttendeeDomainObject $attendee) => $attendee->getShortId())->all() === ['a_first'])
            ->andReturn('https://tickets.example.com/apple');
        $this->googleWalletSaveUrlResolver
            ->shouldReceive('resolveForAttendees')
            ->once()
            ->withArgs(fn (Collection $attendees) => $attendees->map(static fn (AttendeeDomainObject $attendee) => $attendee->getShortId())->all() === ['a_first'])
            ->andReturn('https://pay.example.com/google');

        $passes = $this->handler()->handle(10, 'o_first');

        $this->assertSame('https://tickets.example.com/apple', $passes->appleWalletPassUrl);
        $this->assertSame('https://pay.example.com/google', $passes->googleWalletSaveUrl);
    }

    public function test_an_order_that_is_not_paid_for_has_no_passes(): void
    {
        $this->orderRepository
            ->shouldReceive('findFirstWhere')
            ->andReturn($this->order(OrderStatus::RESERVED, collect([
                (new AttendeeDomainObject)->setId(1)->setShortId('a_first')->setStatus(AttendeeStatus::ACTIVE->name),
            ])));

        $this->eventRepository->shouldNotReceive('findById');
        $this->appleWalletPassUrlResolver->shouldNotReceive('resolveForAttendees');
        $this->googleWalletSaveUrlResolver->shouldNotReceive('resolveForAttendees');

        $passes = $this->handler()->handle(10, 'o_first');

        $this->assertNull($passes->appleWalletPassUrl);
        $this->assertNull($passes->googleWalletSaveUrl);
    }

    public function test_an_order_of_only_cancelled_tickets_has_no_passes(): void
    {
        $this->orderRepository
            ->shouldReceive('findFirstWhere')
            ->andReturn($this->order(OrderStatus::COMPLETED, collect([
                (new AttendeeDomainObject)->setId(1)->setShortId('a_first')->setStatus(AttendeeStatus::CANCELLED->name),
            ])));

        $this->eventRepository->shouldNotReceive('findById');
        $this->appleWalletPassUrlResolver->shouldNotReceive('resolveForAttendees');
        $this->googleWalletSaveUrlResolver->shouldNotReceive('resolveForAttendees');

        $passes = $this->handler()->handle(10, 'o_first');

        $this->assertNull($passes->appleWalletPassUrl);
        $this->assertNull($passes->googleWalletSaveUrl);
    }

    public function test_an_order_of_another_event_is_not_found(): void
    {
        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn(null);

        $this->expectException(ResourceNotFoundException::class);

        $this->handler()->handle(10, 'o_first');
    }

    private function order(OrderStatus $status, Collection $attendees): OrderDomainObject
    {
        return (new OrderDomainObject)
            ->setId(1)
            ->setShortId('o_first')
            ->setEventId(10)
            ->setStatus($status->name)
            ->setAttendees($attendees);
    }

    private function handler(): GetOrderWalletPassesPublicHandler
    {
        return new GetOrderWalletPassesPublicHandler(
            $this->orderRepository,
            $this->eventRepository,
            $this->appleWalletPassUrlResolver,
            $this->googleWalletSaveUrlResolver,
        );
    }
}
