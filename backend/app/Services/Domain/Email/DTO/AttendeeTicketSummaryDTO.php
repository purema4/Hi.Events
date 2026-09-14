<?php

namespace HiEvents\Services\Domain\Email\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class AttendeeTicketSummaryDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $attendeeName,
        public readonly ?string $productTitle,
        public readonly ?string $sessionLabel,
        public readonly ?string $startFormatted,
        public readonly ?string $endFormatted,
        public readonly ?string $venueName,
        public readonly ?string $addressString,
    ) {}
}
