<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\GoogleWallet;

use Illuminate\Config\Repository;

class GoogleWalletIdGenerator
{
    private const DEFAULT_PREFIX = 'hievents';

    public function __construct(
        private readonly Repository $config,
    ) {}

    public function classIdForOccurrence(int $occurrenceId): string
    {
        return $this->qualify('occurrence_'.$occurrenceId);
    }

    public function objectIdForAttendee(int $attendeeId): string
    {
        return $this->qualify('attendee_'.$attendeeId);
    }

    private function qualify(string $suffix): string
    {
        return sprintf(
            '%s.%s_%s',
            $this->config->get('google-wallet.issuer_id'),
            $this->prefix(),
            $suffix,
        );
    }

    private function prefix(): string
    {
        $sanitised = preg_replace(
            '/[^A-Za-z0-9_-]/',
            '',
            (string) $this->config->get('google-wallet.id_prefix'),
        );

        return $sanitised === '' ? self::DEFAULT_PREFIX : $sanitised;
    }
}
