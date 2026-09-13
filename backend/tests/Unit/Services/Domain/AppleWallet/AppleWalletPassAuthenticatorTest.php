<?php

namespace Tests\Unit\Services\Domain\AppleWallet;

use HiEvents\Exceptions\AppleWallet\AppleWalletAuthenticationException;
use HiEvents\Services\Domain\AppleWallet\AppleWalletPassAuthenticator;
use HiEvents\Services\Domain\AppleWallet\AppleWalletSerialNumberService;
use Illuminate\Config\Repository;
use Tests\TestCase;

class AppleWalletPassAuthenticatorTest extends TestCase
{
    private const PASS_TYPE_IDENTIFIER = 'pass.events.hi.test';

    private const SERIAL_NUMBER = 'hievents-attendee-31';

    private AppleWalletSerialNumberService $serialNumberService;

    private function authenticator(string $passTypeIdentifier = self::PASS_TYPE_IDENTIFIER): AppleWalletPassAuthenticator
    {
        $config = new Repository([
            'app' => ['key' => 'base64:secret'],
            'apple-wallet' => [
                'serial_prefix' => 'hievents',
                'pass_type_identifier' => $passTypeIdentifier,
            ],
        ]);

        $this->serialNumberService = new AppleWalletSerialNumberService($config);

        return new AppleWalletPassAuthenticator($config, $this->serialNumberService);
    }

    public function test_a_valid_token_resolves_the_attendee(): void
    {
        $authenticator = $this->authenticator();
        $token = $this->serialNumberService->authenticationTokenFor(self::SERIAL_NUMBER);

        $this->assertSame(31, $authenticator->authenticate(self::PASS_TYPE_IDENTIFIER, self::SERIAL_NUMBER, $token));
    }

    public function test_a_wrong_token_is_rejected(): void
    {
        $this->expectException(AppleWalletAuthenticationException::class);

        $this->authenticator()->authenticate(self::PASS_TYPE_IDENTIFIER, self::SERIAL_NUMBER, 'not-the-token');
    }

    public function test_a_missing_token_is_rejected(): void
    {
        $this->expectException(AppleWalletAuthenticationException::class);

        $this->authenticator()->authenticate(self::PASS_TYPE_IDENTIFIER, self::SERIAL_NUMBER, null);
    }

    public function test_a_pass_of_another_type_is_rejected(): void
    {
        $authenticator = $this->authenticator();
        $token = $this->serialNumberService->authenticationTokenFor(self::SERIAL_NUMBER);

        $this->expectException(AppleWalletAuthenticationException::class);

        $authenticator->authenticate('pass.com.example.other', self::SERIAL_NUMBER, $token);
    }

    public function test_no_pass_type_is_issued_when_none_is_configured(): void
    {
        $this->assertFalse($this->authenticator('')->isIssuedPassType(''));
        $this->assertTrue($this->authenticator()->isIssuedPassType(self::PASS_TYPE_IDENTIFIER));
    }
}
