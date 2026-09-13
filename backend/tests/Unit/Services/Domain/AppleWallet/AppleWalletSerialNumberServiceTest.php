<?php

namespace Tests\Unit\Services\Domain\AppleWallet;

use HiEvents\Services\Domain\AppleWallet\AppleWalletSerialNumberService;
use Illuminate\Config\Repository;
use Tests\TestCase;

class AppleWalletSerialNumberServiceTest extends TestCase
{
    private function service(
        string $prefix = 'hievents',
        string $passTypeIdentifier = 'pass.events.hi.test',
        string $appKey = 'base64:first-secret',
    ): AppleWalletSerialNumberService {
        return new AppleWalletSerialNumberService(new Repository([
            'app' => ['key' => $appKey],
            'apple-wallet' => [
                'serial_prefix' => $prefix,
                'pass_type_identifier' => $passTypeIdentifier,
            ],
        ]));
    }

    public function test_serial_numbers_are_prefixed_per_environment(): void
    {
        $this->assertSame('staging-attendee-42', $this->service('staging')->serialNumberForAttendee(42));
    }

    public function test_unsafe_prefix_characters_are_stripped(): void
    {
        $this->assertSame('myenv-attendee-1', $this->service('my-env!')->serialNumberForAttendee(1));
        $this->assertSame('hievents-attendee-1', $this->service('!!!')->serialNumberForAttendee(1));
    }

    public function test_the_attendee_is_read_back_from_its_serial_number(): void
    {
        $service = $this->service();

        $this->assertSame(42, $service->attendeeIdFromSerialNumber($service->serialNumberForAttendee(42)));
    }

    public function test_serial_numbers_that_were_not_issued_here_are_rejected(): void
    {
        $service = $this->service('production');

        $this->assertNull($service->attendeeIdFromSerialNumber('staging-attendee-42'));
        $this->assertNull($service->attendeeIdFromSerialNumber('production-attendee-0'));
        $this->assertNull($service->attendeeIdFromSerialNumber('production-attendee-42x'));
        $this->assertNull($service->attendeeIdFromSerialNumber('production-attendee-'));
    }

    public function test_authentication_tokens_are_stable_per_pass(): void
    {
        $service = $this->service();

        $token = $service->authenticationTokenFor('hievents-attendee-1');

        $this->assertSame($token, $service->authenticationTokenFor('hievents-attendee-1'));
        $this->assertNotSame($token, $service->authenticationTokenFor('hievents-attendee-2'));
        $this->assertGreaterThanOrEqual(16, strlen($token));
    }

    public function test_authentication_tokens_depend_on_the_application_key(): void
    {
        $this->assertNotSame(
            $this->service(appKey: 'base64:first-secret')->authenticationTokenFor('hievents-attendee-1'),
            $this->service(appKey: 'base64:second-secret')->authenticationTokenFor('hievents-attendee-1'),
        );
    }

    public function test_only_the_issued_token_is_accepted(): void
    {
        $service = $this->service();
        $token = $service->authenticationTokenFor('hievents-attendee-1');

        $this->assertTrue($service->isValidAuthenticationToken('hievents-attendee-1', $token));
        $this->assertFalse($service->isValidAuthenticationToken('hievents-attendee-2', $token));
        $this->assertFalse($service->isValidAuthenticationToken('hievents-attendee-1', 'guess'));
    }
}
