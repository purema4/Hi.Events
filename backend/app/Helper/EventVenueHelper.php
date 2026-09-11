<?php

namespace HiEvents\Helper;

use HiEvents\DomainObjects\Enums\LocationType;
use HiEvents\DomainObjects\EventLocationDomainObject;
use HiEvents\DomainObjects\LocationDomainObject;

class EventVenueHelper
{
    public static function venueName(?EventLocationDomainObject $eventLocation): ?string
    {
        $venue = self::inPersonLocation($eventLocation);

        if ($venue === null) {
            return null;
        }

        $name = $venue->getName();

        if ($name !== null && $name !== '') {
            return $name;
        }

        return $venue->getStructuredAddress()['venue_name'] ?? null;
    }

    public static function formattedAddress(?EventLocationDomainObject $eventLocation): ?string
    {
        $venue = self::inPersonLocation($eventLocation);

        if ($venue === null) {
            return null;
        }

        $address = $venue->getStructuredAddress();

        if (! is_array($address)) {
            return null;
        }

        $formatted = AddressHelper::formatAddress($address);

        return $formatted === '' ? null : $formatted;
    }

    private static function inPersonLocation(?EventLocationDomainObject $eventLocation): ?LocationDomainObject
    {
        if ($eventLocation === null || $eventLocation->getType() !== LocationType::IN_PERSON->name) {
            return null;
        }

        return $eventLocation->getLocation();
    }
}
