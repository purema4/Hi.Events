<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\AppleWallet;

use Illuminate\Config\Repository;

class AppleWalletSerialNumberService
{
    private const DEFAULT_PREFIX = 'hievents';

    public function __construct(
        private readonly Repository $config,
    ) {}

    public function serialNumberForAttendee(int $attendeeId): string
    {
        return $this->prefix().'-attendee-'.$attendeeId;
    }

    public function attendeeIdFromSerialNumber(string $serialNumber): ?int
    {
        $pattern = '/^'.preg_quote($this->prefix(), '/').'-attendee-([1-9]\d*)$/';

        return preg_match($pattern, $serialNumber, $matches) === 1 ? (int) $matches[1] : null;
    }

    public function authenticationTokenFor(string $serialNumber): string
    {
        return hash_hmac(
            'sha256',
            $this->config->get('apple-wallet.pass_type_identifier').'|'.$serialNumber,
            (string) $this->config->get('app.key'),
        );
    }

    public function isValidAuthenticationToken(string $serialNumber, string $authenticationToken): bool
    {
        return hash_equals($this->authenticationTokenFor($serialNumber), $authenticationToken);
    }

    private function prefix(): string
    {
        $sanitised = preg_replace(
            '/[^A-Za-z0-9_]/',
            '',
            (string) $this->config->get('apple-wallet.serial_prefix'),
        );

        return $sanitised === '' ? self::DEFAULT_PREFIX : $sanitised;
    }
}
