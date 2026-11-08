<?php

namespace HiEvents\Services\Domain\Email\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class AttendeeTicketWalletButtonsDTO extends BaseDataObject
{
    public function __construct(
        public readonly ?string $googleWalletSaveUrl,
        public readonly ?string $googleWalletButtonPath,
        public readonly ?string $appleWalletPassUrl,
        public readonly ?string $appleWalletButtonPath,
    ) {}
}
