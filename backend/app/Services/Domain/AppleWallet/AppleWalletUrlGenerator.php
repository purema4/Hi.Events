<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\AppleWallet;

use Illuminate\Config\Repository;

class AppleWalletUrlGenerator
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    public function webServiceUrl(): string
    {
        return $this->apiUrl().'/apple-wallet';
    }

    /**
     * @param  array<int, string>  $attendeeShortIds
     */
    public function passesDownloadUrl(int $eventId, array $attendeeShortIds): string
    {
        return sprintf(
            '%s/public/events/%d/apple-wallet-passes?%s',
            $this->apiUrl(),
            $eventId,
            http_build_query(['attendees' => implode(',', $attendeeShortIds)]),
        );
    }

    private function apiUrl(): string
    {
        return rtrim((string) $this->config->get('apple-wallet.api_url'), '/');
    }
}
