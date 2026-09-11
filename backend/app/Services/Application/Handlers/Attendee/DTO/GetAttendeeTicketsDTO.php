<?php

namespace HiEvents\Services\Application\Handlers\Attendee\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class GetAttendeeTicketsDTO extends BaseDataObject
{
    public function __construct(
        public string $attendeeShortId,
        public int $eventId,
    ) {}
}
