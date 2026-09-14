<?php

namespace Tests\Unit\Mail\Attendee;

use Closure;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Mail\Attendee\AttendeeTicketMail;
use HiEvents\Services\Domain\Email\DTO\AttendeeTicketSummaryDTO;
use Illuminate\Support\Collection;
use ReflectionClass;
use Tests\TestCase;

class AttendeeTicketMailTest extends TestCase
{
    public function test_attachments_render_for_a_mail_queued_by_a_previous_release(): void
    {
        $event = (new EventDomainObject)
            ->setId(1)
            ->setTitle('Test Event')
            ->setTimezone('UTC')
            ->setEventOccurrences(collect([
                (new EventOccurrenceDomainObject)
                    ->setStartDate('2026-09-01 18:00:00')
                    ->setEndDate('2026-09-01 20:00:00'),
            ]));

        $attendee = (new AttendeeDomainObject)->setId(5);

        $organizer = (new OrganizerDomainObject)
            ->setEmail('organizer@example.com')
            ->setName('Organizer');

        $mail = (new ReflectionClass(AttendeeTicketMail::class))->newInstanceWithoutConstructor();
        Closure::bind(function () use ($event, $attendee, $organizer): void {
            $this->event = $event;
            $this->attendee = $attendee;
            $this->organizer = $organizer;
        }, $mail, AttendeeTicketMail::class)();

        $attachments = $mail->attachments();

        $this->assertCount(1, $attachments);
    }

    public function test_content_includes_a_ticket_for_every_attendee_sharing_the_email_address(): void
    {
        $mail = $this->buildMail(collect([
            $this->attendee(6, 'second-short-id', 'Sam', 'Jones', 'General Admission'),
            $this->attendee(7, 'third-short-id', 'Alex', 'Ray', 'VIP'),
        ]));

        $content = $mail->content();

        /** @var Collection<int, AttendeeTicketSummaryDTO> $tickets */
        $tickets = $content->with['tickets'];

        $this->assertCount(3, $tickets);
        $this->assertSame(
            ['Jane Doe', 'Sam Jones', 'Alex Ray'],
            $tickets->map(fn (AttendeeTicketSummaryDTO $ticket) => $ticket->attendeeName)->all(),
        );
        $this->assertSame(
            ['Early Bird', 'General Admission', 'VIP'],
            $tickets->map(fn (AttendeeTicketSummaryDTO $ticket) => $ticket->productTitle)->all(),
        );
        $this->assertStringContainsString('/product/1/first-short-id', $content->with['ticketUrl']);
    }

    public function test_content_includes_a_single_ticket_when_the_attendee_has_no_siblings(): void
    {
        $tickets = $this->buildMail(null)->content()->with['tickets'];

        $this->assertCount(1, $tickets);
        $this->assertSame('Jane Doe', $tickets->first()->attendeeName);
    }

    public function test_rendered_body_lists_every_ticket_behind_one_link(): void
    {
        $mail = $this->buildMail(collect([
            $this->attendee(6, 'second-short-id', 'Sam', 'Jones', 'General Admission'),
        ]));

        $rendered = $mail->render();

        $this->assertStringContainsString('Early Bird', $rendered);
        $this->assertStringContainsString('Jane Doe', $rendered);
        $this->assertStringContainsString('General Admission', $rendered);
        $this->assertStringContainsString('Sam Jones', $rendered);
        $this->assertStringContainsString('View Tickets', $rendered);
        $this->assertSame(1, substr_count($rendered, '/product/1/first-short-id'));
        $this->assertStringNotContainsString('/product/1/second-short-id', $rendered);
    }

    public function test_schedule_is_shown_once_when_all_tickets_share_an_occurrence(): void
    {
        $newYearsEve = $this->occurrence(1, 'New Year\'s Eve', '2026-12-31 23:00:00', '2027-01-01 04:00:00');
        $newYearsDay = $this->occurrence(2, 'New Year\'s Day', '2027-01-01 18:00:00', '2027-01-01 22:00:00');

        $shared = $this->buildMail(
            collect([$this->attendee(6, 'second-short-id', 'Sam', 'Jones', 'VIP')->setEventOccurrence($newYearsEve)]),
            $newYearsEve,
        );
        $split = $this->buildMail(
            collect([$this->attendee(6, 'second-short-id', 'Sam', 'Jones', 'VIP')->setEventOccurrence($newYearsDay)]),
            $newYearsEve,
        );

        $this->assertTrue($shared->content()->with['ticketsShareSchedule']);
        $this->assertFalse($split->content()->with['ticketsShareSchedule']);
    }

    public function test_calendar_attachment_holds_an_entry_per_distinct_occurrence(): void
    {
        $newYearsEve = $this->occurrence(1, 'New Year\'s Eve', '2026-12-31 23:00:00', '2027-01-01 04:00:00');
        $newYearsDay = $this->occurrence(2, 'New Year\'s Day', '2027-01-01 18:00:00', '2027-01-01 22:00:00');

        $mail = $this->buildMail(
            collect([
                $this->attendee(6, 'second-short-id', 'Sam', 'Jones', 'General Admission')->setEventOccurrence($newYearsEve),
                $this->attendee(7, 'third-short-id', 'Alex', 'Ray', 'VIP')->setEventOccurrence($newYearsDay),
            ]),
            $newYearsEve,
        );

        $attachments = $mail->attachments();
        $this->assertCount(1, $attachments);

        $calendar = $attachments[0]->attachWith(
            fn () => null,
            fn (Closure $resolver) => $resolver(),
        );

        $this->assertSame(2, substr_count($calendar, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('Test Event - New Year\'s Eve', $calendar);
        $this->assertStringContainsString('Test Event - New Year\'s Day', $calendar);
    }

    public function test_subject_is_pluralised_when_the_email_carries_multiple_tickets(): void
    {
        $single = $this->buildMail(null);
        $multiple = $this->buildMail(collect([$this->attendee(6, 'second-short-id', 'Sam', 'Jones', 'VIP')]));

        $this->assertSame('🎟️ Your Ticket for Test Event', $single->envelope()->subject);
        $this->assertSame('🎟️ Your Tickets for Test Event', $multiple->envelope()->subject);
    }

    private function buildMail(
        ?Collection $additionalAttendees,
        ?EventOccurrenceDomainObject $attendeeOccurrence = null,
    ): AttendeeTicketMail {
        $event = (new EventDomainObject)
            ->setId(1)
            ->setTitle('Test Event')
            ->setTimezone('UTC')
            ->setEventOccurrences(collect([
                $this->occurrence(1, 'New Year\'s Eve', '2026-12-31 23:00:00', '2027-01-01 04:00:00'),
            ]));

        return new AttendeeTicketMail(
            order: (new OrderDomainObject)->setStatus(OrderStatus::COMPLETED->name),
            attendee: $this->attendee(5, 'first-short-id', 'Jane', 'Doe', 'Early Bird')
                ->setEventOccurrence($attendeeOccurrence),
            event: $event,
            eventSettings: (new EventSettingDomainObject)->setSupportEmail('support@example.com'),
            organizer: (new OrganizerDomainObject)->setEmail('organizer@example.com')->setName('Organizer'),
            occurrence: $attendeeOccurrence,
            additionalAttendees: $additionalAttendees,
        );
    }

    private function occurrence(int $id, string $label, string $startDate, string $endDate): EventOccurrenceDomainObject
    {
        return (new EventOccurrenceDomainObject)
            ->setId($id)
            ->setLabel($label)
            ->setStartDate($startDate)
            ->setEndDate($endDate);
    }

    private function attendee(
        int $id,
        string $shortId,
        string $firstName,
        string $lastName,
        string $productTitle,
    ): AttendeeDomainObject {
        return (new AttendeeDomainObject)
            ->setId($id)
            ->setShortId($shortId)
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setEmail('shared@example.com')
            ->setProduct((new ProductDomainObject)->setTitle($productTitle));
    }
}
