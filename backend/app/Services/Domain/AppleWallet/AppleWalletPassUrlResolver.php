<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\AppleWallet;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use Illuminate\Support\Collection;

class AppleWalletPassUrlResolver
{
    public function __construct(
        private readonly AppleWalletPassSettingsResolver $passSettingsResolver,
        private readonly AppleWalletUrlGenerator $urlGenerator,
    ) {}

    /**
     * @param  Collection<int, AttendeeDomainObject>  $attendees
     * @return string|null A single download URL covering every attendee's pass
     */
    public function resolveForAttendees(
        Collection $attendees,
        EventDomainObject $event,
        OrganizerDomainObject $organizer,
    ): ?string {
        if ($attendees->isEmpty() || $this->passSettingsResolver->resolveForOrganizer($organizer->getId()) === null) {
            return null;
        }

        return $this->urlGenerator->passesDownloadUrl(
            $event->getId(),
            $attendees
                ->map(static fn (AttendeeDomainObject $attendee) => $attendee->getShortId())
                ->values()
                ->all(),
        );
    }
}
