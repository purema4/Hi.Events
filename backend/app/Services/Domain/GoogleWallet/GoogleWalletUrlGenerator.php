<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\GoogleWallet;

use Illuminate\Config\Repository;

class GoogleWalletUrlGenerator
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * @param  array<int, string>  $attendeeShortIds
     */
    public function passesSaveUrl(int $eventId, array $attendeeShortIds): string
    {
        return sprintf(
            '%s/public/events/%d/google-wallet-save?%s',
            rtrim((string) $this->config->get('google-wallet.api_url'), '/'),
            $eventId,
            http_build_query(['attendees' => implode(',', $attendeeShortIds)]),
        );
    }
}
