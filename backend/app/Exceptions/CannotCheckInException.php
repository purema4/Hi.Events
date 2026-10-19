<?php

namespace HiEvents\Exceptions;

use Exception;
use HiEvents\DomainObjects\Enums\CheckInFailureCode;

class CannotCheckInException extends Exception
{
    public function __construct(
        string $message = '',
        private readonly ?CheckInFailureCode $failureCode = null,
    ) {
        parent::__construct($message);
    }

    public function getFailureCode(): ?CheckInFailureCode
    {
        return $this->failureCode;
    }
}
