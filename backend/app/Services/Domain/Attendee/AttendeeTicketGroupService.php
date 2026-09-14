<?php

namespace HiEvents\Services\Domain\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use Illuminate\Support\Collection;

class AttendeeTicketGroupService
{
    /**
     * @param  Collection<int, AttendeeDomainObject>  $attendees
     * @return Collection<string, Collection<int, AttendeeDomainObject>>
     */
    public function groupByRecipient(Collection $attendees): Collection
    {
        return $attendees->groupBy(fn (AttendeeDomainObject $attendee) => $this->recipientKey($attendee));
    }

    public function recipientKey(AttendeeDomainObject $attendee): string
    {
        return mb_strtolower(trim((string) $attendee->getEmail()));
    }
}
