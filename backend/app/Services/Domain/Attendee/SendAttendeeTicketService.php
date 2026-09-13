<?php

namespace HiEvents\Services\Domain\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassUrlResolver;
use HiEvents\Services\Domain\Email\MailBuilderService;
use HiEvents\Services\Domain\GoogleWallet\GoogleWalletSaveUrlResolver;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Collection;

class SendAttendeeTicketService
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly MailBuilderService $mailBuilderService,
        private readonly GoogleWalletSaveUrlResolver $googleWalletSaveUrlResolver,
        private readonly AppleWalletPassUrlResolver $appleWalletPassUrlResolver,
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
        $ticketAttendees = collect([$attendee])->merge($additionalAttendees ?? collect());

        $googleWalletSaveUrl = $this->googleWalletSaveUrlResolver->resolveForAttendees(
            attendees: $ticketAttendees,
            event: $event,
            organizer: $organizer,
        );

        $mail = $this->mailBuilderService->buildAttendeeTicketMail(
            $attendee,
            $order,
            $event,
            $eventSettings,
            $organizer,
            $attendee->getEventOccurrence(),
            $additionalAttendees,
            $googleWalletSaveUrl,
            $this->appleWalletPassUrlResolver->resolveForAttendees(
                attendees: $ticketAttendees,
                event: $event,
                organizer: $organizer,
            ),
        );

        $this->mailer
            ->to($attendee->getEmail())
            ->locale($attendee->getLocale())
            ->send($mail);
    }
}
