<?php

namespace HiEvents\Services\Domain\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Services\Domain\Email\MailBuilderService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Collection;

class SendAttendeeTicketService
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly MailBuilderService $mailBuilderService,
    ) {}

    /**
     * @param  Collection<int, AttendeeDomainObject>|null  $additionalAttendees  Other attendees in the order sharing $attendee's email address
     */
    public function send(
        OrderDomainObject $order,
        AttendeeDomainObject $attendee,
        EventDomainObject $event,
        EventSettingDomainObject $eventSettings,
        OrganizerDomainObject $organizer,
        ?Collection $additionalAttendees = null,
    ): void {
        $mail = $this->mailBuilderService->buildAttendeeTicketMail(
            $attendee,
            $order,
            $event,
            $eventSettings,
            $organizer,
            $attendee->getEventOccurrence(),
            $additionalAttendees,
        );

        $this->mailer
            ->to($attendee->getEmail())
            ->locale($attendee->getLocale())
            ->send($mail);
    }
}
