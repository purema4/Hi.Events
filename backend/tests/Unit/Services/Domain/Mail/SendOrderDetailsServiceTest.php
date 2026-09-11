<?php

namespace Tests\Unit\Services\Domain\Mail;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Mail\Order\OrderSummary;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Attendee\SendAttendeeTicketService;
use HiEvents\Services\Domain\Email\MailBuilderService;
use HiEvents\Services\Domain\Mail\SendOrderDetailsService;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SendOrderDetailsServiceTest extends TestCase
{
    private MockInterface|SendAttendeeTicketService $sendAttendeeTicketService;

    private SendOrderDetailsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sendAttendeeTicketService = Mockery::mock(SendAttendeeTicketService::class);

        $pendingMail = Mockery::mock(PendingMail::class);
        $pendingMail->shouldReceive('locale')->andReturnSelf();
        $pendingMail->shouldReceive('send')->andReturnNull();

        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('to')->andReturn($pendingMail);

        $mailBuilderService = Mockery::mock(MailBuilderService::class);
        $mailBuilderService->shouldReceive('buildOrderSummaryMail')->andReturn(Mockery::mock(OrderSummary::class));

        $this->service = new SendOrderDetailsService(
            $this->eventRepository(),
            $this->orderRepository(),
            $mailer,
            $this->sendAttendeeTicketService,
            $mailBuilderService,
        );
    }

    public function test_attendees_sharing_an_email_address_receive_a_single_email_with_every_ticket(): void
    {
        $sentTo = [];

        $this->sendAttendeeTicketService
            ->shouldReceive('send')
            ->twice()
            ->andReturnUsing(function (...$args) use (&$sentTo): void {
                /** @var AttendeeDomainObject $attendee */
                $attendee = $args[1];
                /** @var Collection $additionalAttendees */
                $additionalAttendees = $args[5];

                $sentTo[$attendee->getEmail()] = collect([$attendee])
                    ->merge($additionalAttendees)
                    ->map(fn (AttendeeDomainObject $ticketHolder) => $ticketHolder->getShortId())
                    ->all();
            });

        $this->service->sendOrderSummaryAndTicketEmails($this->order());

        $this->assertSame([
            'buyer@example.com' => ['ticket-one', 'ticket-two'],
            'guest@example.com' => ['ticket-three'],
        ], $sentTo);
    }

    private function order(): OrderDomainObject
    {
        return (new OrderDomainObject)
            ->setId(10)
            ->setEventId(1)
            ->setStatus(OrderStatus::COMPLETED->name)
            ->setEmail('buyer@example.com')
            ->setIsManuallyCreated(true)
            ->setAttendees(collect([
                $this->attendee('ticket-one', 'buyer@example.com'),
                $this->attendee('ticket-two', 'Buyer@Example.com'),
                $this->attendee('ticket-three', 'guest@example.com'),
            ]));
    }

    private function attendee(string $shortId, string $email): AttendeeDomainObject
    {
        return (new AttendeeDomainObject)
            ->setShortId($shortId)
            ->setEmail($email);
    }

    private function orderRepository(): OrderRepositoryInterface
    {
        $repository = Mockery::mock(OrderRepositoryInterface::class);
        $repository->shouldReceive('loadRelation')->andReturnSelf();
        $repository->shouldReceive('findById')->andReturn($this->order());

        return $repository;
    }

    private function eventRepository(): EventRepositoryInterface
    {
        $event = (new EventDomainObject)
            ->setId(1)
            ->setTitle('Test Event')
            ->setTimezone('UTC')
            ->setEventSettings(new EventSettingDomainObject)
            ->setOrganizer((new OrganizerDomainObject)->setEmail('organizer@example.com'));

        $repository = Mockery::mock(EventRepositoryInterface::class);
        $repository->shouldReceive('loadRelation')->andReturnSelf();
        $repository->shouldReceive('findById')->andReturn($event);

        return $repository;
    }
}
