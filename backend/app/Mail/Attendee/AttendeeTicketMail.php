<?php

namespace HiEvents\Mail\Attendee;

use Carbon\Carbon;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\LocationType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\DateHelper;
use HiEvents\Helper\EventVenueHelper;
use HiEvents\Helper\StringHelper;
use HiEvents\Helper\Url;
use HiEvents\Mail\BaseMail;
use HiEvents\Services\Domain\Email\DTO\AttendeeTicketSummaryDTO;
use HiEvents\Services\Domain\Email\DTO\RenderedEmailTemplateDTO;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\IcalendarGenerator\Components\Calendar;
use Spatie\IcalendarGenerator\Components\Event;

/**
 * @uses /backend/resources/views/emails/orders/attendee-ticket.blade.php
 */
class AttendeeTicketMail extends BaseMail
{
    private readonly ?RenderedEmailTemplateDTO $renderedTemplate;

    /**
     * @param  Collection<int, AttendeeDomainObject>|null  $additionalAttendees  Other attendees in the order sharing $attendee's email address
     */
    public function __construct(
        private readonly OrderDomainObject $order,
        private readonly AttendeeDomainObject $attendee,
        private readonly EventDomainObject $event,
        private readonly EventSettingDomainObject $eventSettings,
        private readonly OrganizerDomainObject $organizer,
        ?RenderedEmailTemplateDTO $renderedTemplate = null,
        private readonly ?EventOccurrenceDomainObject $occurrence = null,
        private readonly ?Collection $additionalAttendees = null,
        private readonly ?string $googleWalletSaveUrl = null,
        private readonly ?string $googleWalletButtonPath = null,
        private readonly ?string $appleWalletPassUrl = null,
        private readonly ?string $appleWalletButtonPath = null,
    ) {
        parent::__construct();
        $this->renderedTemplate = $renderedTemplate;
    }

    public function envelope(): Envelope
    {
        $subject = $this->renderedTemplate?->subject ?? $this->defaultSubject();

        return new Envelope(
            replyTo: $this->eventSettings->getSupportEmail(),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        if ($this->renderedTemplate) {
            return new Content(
                markdown: 'emails.custom-template',
                with: [
                    'renderedBody' => $this->renderedTemplate->body,
                    'renderedCta' => $this->renderedTemplate->cta,
                    'eventSettings' => $this->eventSettings,
                ]
            );
        }

        return new Content(
            markdown: 'emails.orders.attendee-ticket',
            with: [
                'event' => $this->event,
                'eventSettings' => $this->eventSettings,
                'organizer' => $this->organizer,
                'order' => $this->order,
                'tickets' => $this->ticketAttendees()
                    ->map(fn (AttendeeDomainObject $attendee) => $this->summariseTicket($attendee)),
                'googleWalletSaveUrl' => $this->googleWalletSaveUrl,
                'googleWalletButtonPath' => $this->googleWalletButtonPath,
                'appleWalletPassUrl' => $this->appleWalletPassUrl,
                'appleWalletButtonPath' => $this->appleWalletButtonPath,
            ]
        );
    }

    public function attachments(): array
    {
        $calendarEvents = $this->occurrencesForCalendar()
            ->map(fn (?EventOccurrenceDomainObject $occurrence) => $this->buildCalendarEvent($occurrence))
            ->filter();

        if ($calendarEvents->isEmpty()) {
            return [];
        }

        $calendar = Calendar::create()->event($calendarEvents->values()->all())->get();

        return [
            Attachment::fromData(static fn () => $calendar, 'event.ics')
                ->withMime('text/calendar'),
        ];
    }

    /**
     * @return Collection<int, AttendeeDomainObject>
     */
    private function ticketAttendees(): Collection
    {
        return collect([$this->attendee])->merge($this->additionalAttendees ?? collect());
    }

    private function defaultSubject(): string
    {
        $eventTitle = Str::limit($this->event->getTitle(), 50);

        if ($this->ticketAttendees()->count() > 1) {
            return __('🎟️ Your Tickets for :event', ['event' => $eventTitle]);
        }

        return __('🎟️ Your Ticket for :event', ['event' => $eventTitle]);
    }

    private function summariseTicket(AttendeeDomainObject $attendee): AttendeeTicketSummaryDTO
    {
        $occurrence = $this->occurrenceFor($attendee);
        $eventLocation = $occurrence?->getEventLocation() ?? $this->event->getEventLocation();

        $startDate = $occurrence?->getStartDate() ?? $this->event->getStartDate();
        $endDate = $occurrence?->getEndDate() ?? $this->event->getEndDate();

        return new AttendeeTicketSummaryDTO(
            attendeeName: trim($attendee->getFirstName().' '.$attendee->getLastName()),
            productTitle: $attendee->getProduct()?->getTitle(),
            sessionLabel: $occurrence?->getLabel(),
            startFormatted: $this->formatDateTime($startDate),
            endFormatted: $this->formatEndDate($startDate, $endDate),
            venueName: EventVenueHelper::venueName($eventLocation),
            addressString: EventVenueHelper::formattedAddress($eventLocation),
            ticketUrl: sprintf(
                Url::getFrontEndUrlFromConfig(Url::ATTENDEE_TICKET),
                $this->event->getId(),
                $attendee->getShortId(),
            ),
        );
    }

    private function occurrenceFor(AttendeeDomainObject $attendee): ?EventOccurrenceDomainObject
    {
        return $attendee->getEventOccurrence() ?? $this->occurrence ?? null;
    }

    /**
     * @return Collection<int, EventOccurrenceDomainObject|null>
     */
    private function occurrencesForCalendar(): Collection
    {
        $occurrences = $this->ticketAttendees()
            ->map(fn (AttendeeDomainObject $attendee) => $this->occurrenceFor($attendee))
            ->filter()
            ->unique(static fn (EventOccurrenceDomainObject $occurrence) => $occurrence->getId())
            ->values();

        return $occurrences->isEmpty() ? collect([null]) : $occurrences;
    }

    private function buildCalendarEvent(?EventOccurrenceDomainObject $occurrence): ?Event
    {
        $startDateRaw = $occurrence?->getStartDate() ?? $this->event->getStartDate();
        $endDateRaw = $occurrence?->getEndDate() ?? $this->event->getEndDate();

        if ($startDateRaw === null) {
            return null;
        }

        $eventTitle = $this->event->getTitle();
        if ($occurrence?->getLabel()) {
            $eventTitle .= ' - '.$occurrence->getLabel();
        }

        $calendarEvent = Event::create()
            ->name($eventTitle)
            ->uniqueIdentifier($this->calendarIdentifier($occurrence))
            ->startsAt(Carbon::parse($startDateRaw, $this->event->getTimezone()))
            ->url($this->event->getEventUrl())
            ->organizer($this->organizer->getEmail(), $this->organizer->getName());

        if ($this->event->getDescription()) {
            $calendarEvent->description(StringHelper::previewFromHtml($this->event->getDescription()));
        }

        $eventLocation = $occurrence?->getEventLocation() ?? $this->event->getEventLocation();
        $address = EventVenueHelper::formattedAddress($eventLocation);
        if ($address !== null) {
            $calendarEvent->address($address);
        } elseif ($eventLocation?->getType() === LocationType::ONLINE->name
            && $eventLocation->getOnlineEventConnectionDetails() !== null) {
            $calendarEvent->address(__('Online event'));
        }

        if ($endDateRaw) {
            $calendarEvent->endsAt(Carbon::parse($endDateRaw, $this->event->getTimezone()));
        }

        return $calendarEvent;
    }

    private function calendarIdentifier(?EventOccurrenceDomainObject $occurrence): string
    {
        if ($occurrence !== null) {
            return 'event-'.$this->event->getId().'-occurrence-'.$occurrence->getId();
        }

        return 'event-'.$this->attendee->getId();
    }

    private function formatDateTime(?string $utcDate): ?string
    {
        if ($utcDate === null) {
            return null;
        }

        return (new Carbon(DateHelper::convertFromUTC($utcDate, $this->event->getTimezone())))
            ->format('D, M j, Y · g:i A');
    }

    private function formatEndDate(?string $utcStartDate, ?string $utcEndDate): ?string
    {
        if ($utcStartDate === null || $utcEndDate === null) {
            return null;
        }

        if (substr($utcStartDate, 0, 10) !== substr($utcEndDate, 0, 10)) {
            return $this->formatDateTime($utcEndDate);
        }

        return (new Carbon(DateHelper::convertFromUTC($utcEndDate, $this->event->getTimezone())))
            ->format('g:i A');
    }
}
