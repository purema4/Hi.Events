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
use HiEvents\Services\Domain\Email\DTO\AttendeeTicketWalletButtonsDTO;
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

    public function test_content_renders_one_ticket_for_a_mail_queued_before_grouping(): void
    {
        $event = (new EventDomainObject)
            ->setId(1)
            ->setTitle('Test Event')
            ->setTimezone('UTC')
            ->setEventOccurrences(collect());

        $attendee = (new AttendeeDomainObject)->setId(5)->setShortId('a_old');
        $occurrence = (new EventOccurrenceDomainObject)
            ->setId(3)
            ->setStartDate('2026-09-01 18:00:00');

        $mail = (new ReflectionClass(AttendeeTicketMail::class))->newInstanceWithoutConstructor();
        Closure::bind(function () use ($event, $attendee, $occurrence): void {
            $this->event = $event;
            $this->attendee = $attendee;
            $this->occurrence = $occurrence;
            $this->order = new OrderDomainObject;
            $this->organizer = new OrganizerDomainObject;
            $this->eventSettings = new EventSettingDomainObject;
            $this->renderedTemplate = null;
        }, $mail, AttendeeTicketMail::class)();

        $content = $mail->content();
        $tickets = $content->with['tickets'];

        $this->assertSame('🎟️ Your Ticket for Test Event', $mail->envelope()->subject);
        $this->assertCount(1, $tickets);
        $this->assertSame($attendee, $tickets[0]['attendee']);
        $this->assertSame($occurrence, $tickets[0]['occurrence']);
        $this->assertNull($content->with['walletButtons']);
    }

    public function test_content_includes_a_ticket_for_every_attendee_sharing_the_email_address(): void
    {
        $content = $this->buildMail([
            $this->attendee(6, 'second-short-id', 'Sam', 'Jones', 'General Admission'),
            $this->attendee(7, 'third-short-id', 'Alex', 'Ray', 'VIP'),
        ])->content();

        $tickets = $content->with['tickets'];

        $this->assertCount(3, $tickets);
        $this->assertSame(
            ['Jane', 'Sam', 'Alex'],
            array_map(fn (array $ticket) => $ticket['attendee']->getFirstName(), $tickets),
        );
        $this->assertStringContainsString('/product/1/first-short-id', $content->with['ticketUrl']);
    }

    public function test_rendered_body_lists_every_ticket_behind_one_link(): void
    {
        $rendered = $this->buildMail([
            $this->attendee(6, 'second-short-id', 'Sam', 'Jones', 'General Admission'),
        ])->render();

        $this->assertStringContainsString('Early Bird', $rendered);
        $this->assertStringContainsString('Jane Doe', $rendered);
        $this->assertStringContainsString('General Admission', $rendered);
        $this->assertStringContainsString('Sam Jones', $rendered);
        $this->assertStringContainsString('View Tickets', $rendered);
        $this->assertSame(1, substr_count($rendered, '/product/1/first-short-id'));
        $this->assertStringNotContainsString('/product/1/second-short-id', $rendered);
    }

    public function test_content_passes_the_wallet_buttons_to_the_view(): void
    {
        $walletButtons = new AttendeeTicketWalletButtonsDTO(
            googleWalletSaveUrl: 'https://pay.google.com/gp/v/save/token',
            googleWalletButtonPath: '/tmp/google.png',
            appleWalletPassUrl: 'https://example.com/apple-wallet/passes',
            appleWalletButtonPath: '/tmp/apple.png',
        );

        $content = $this->buildMail([], $walletButtons)->content();

        $this->assertSame($walletButtons, $content->with['walletButtons']);
    }

    public function test_calendar_attachment_holds_an_entry_per_distinct_occurrence(): void
    {
        $newYearsEve = $this->occurrence(1, 'New Year\'s Eve', '2026-12-31 23:00:00', '2027-01-01 04:00:00');
        $newYearsDay = $this->occurrence(2, 'New Year\'s Day', '2027-01-01 18:00:00', '2027-01-01 22:00:00');

        $mail = $this->buildMail([
            $this->attendee(6, 'second-short-id', 'Sam', 'Jones', 'General Admission')->setEventOccurrence($newYearsEve),
            $this->attendee(7, 'third-short-id', 'Alex', 'Ray', 'VIP')->setEventOccurrence($newYearsDay),
        ], firstAttendeeOccurrence: $newYearsEve);

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
        $single = $this->buildMail([]);
        $multiple = $this->buildMail([$this->attendee(6, 'second-short-id', 'Sam', 'Jones', 'VIP')]);

        $this->assertSame('🎟️ Your Ticket for Test Event', $single->envelope()->subject);
        $this->assertSame('🎟️ Your Tickets for Test Event', $multiple->envelope()->subject);
    }

    /**
     * @param  AttendeeDomainObject[]  $otherAttendees
     */
    private function buildMail(
        array $otherAttendees,
        ?AttendeeTicketWalletButtonsDTO $walletButtons = null,
        ?EventOccurrenceDomainObject $firstAttendeeOccurrence = null,
    ): AttendeeTicketMail {
        $event = (new EventDomainObject)
            ->setId(1)
            ->setTitle('Test Event')
            ->setTimezone('UTC')
            ->setEventOccurrences(collect([
                $this->occurrence(1, 'New Year\'s Eve', '2026-12-31 23:00:00', '2027-01-01 04:00:00'),
            ]));

        $attendee = $this->attendee(5, 'first-short-id', 'Jane', 'Doe', 'Early Bird')
            ->setEventOccurrence($firstAttendeeOccurrence);

        return new AttendeeTicketMail(
            order: (new OrderDomainObject)->setStatus(OrderStatus::COMPLETED->name),
            attendee: $attendee,
            event: $event,
            eventSettings: (new EventSettingDomainObject)->setSupportEmail('support@example.com'),
            organizer: (new OrganizerDomainObject)->setEmail('organizer@example.com')->setName('Organizer'),
            occurrence: $firstAttendeeOccurrence,
            attendees: $otherAttendees === [] ? null : collect([$attendee, ...$otherAttendees]),
            walletButtons: $walletButtons,
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
