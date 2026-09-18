<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\GoogleWallet;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use Illuminate\Support\Collection;

class GoogleWalletSaveLinkResolver
{
    public function __construct(
        private readonly GoogleWalletPassSettingsResolver $passSettingsResolver,
        private readonly GoogleWalletUrlGenerator $urlGenerator,
    ) {}

    /**
     * @param  Collection<int, AttendeeDomainObject>  $attendees
     * @return string|null A link that mints the save URL when it is followed
     */
    public function resolveForAttendees(
        Collection $attendees,
        int $eventId,
        OrganizerDomainObject $organizer,
    ): ?string {
        if ($attendees->isEmpty() || $this->passSettingsResolver->resolveForOrganizer($organizer->getId()) === null) {
            return null;
        }

        return $this->urlGenerator->passesSaveUrl(
            $eventId,
            $attendees
                ->map(static fn (AttendeeDomainObject $attendee) => $attendee->getShortId())
                ->values()
                ->all(),
        );
    }
}
