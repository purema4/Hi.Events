<?php

namespace Tests\Unit\Services\Domain\GoogleWallet;

use HiEvents\Services\Domain\GoogleWallet\GoogleWalletSaveUrlService;
use HiEvents\Services\Infrastructure\GoogleWallet\GoogleWalletCredentialsResolver;
use HiEvents\Services\Infrastructure\GoogleWallet\GoogleWalletJwtSigner;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

class GoogleWalletSaveUrlServiceTest extends TestCase
{
    private const SERVICE_ACCOUNT_EMAIL = 'wallet@hievents.iam.gserviceaccount.com';

    private string $privateKeyPem;

    private string $publicKeyPem;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($key, $privateKeyPem);

        $this->privateKeyPem = $privateKeyPem;
        $this->publicKeyPem = openssl_pkey_get_details($key)['key'];
    }

    private function service(array $origins = ['https://tickets.example.com']): GoogleWalletSaveUrlService
    {
        $config = new Repository([
            'google-wallet' => [
                'origins' => $origins,
                'save_link_base_url' => 'https://pay.google.com/gp/v/save/',
                'service_account_json' => json_encode([
                    'client_email' => self::SERVICE_ACCOUNT_EMAIL,
                    'private_key' => $this->privateKeyPem,
                ]),
                'service_account_file' => null,
            ],
        ]);

        return new GoogleWalletSaveUrlService(
            $config,
            new GoogleWalletJwtSigner(new GoogleWalletCredentialsResolver($config, new Filesystem)),
        );
    }

    private function decodeSegment(string $segment): array
    {
        return json_decode(base64_decode(strtr($segment, '-_', '+/')), true);
    }

    public function test_the_url_points_at_googles_save_endpoint(): void
    {
        $url = $this->service()->buildForObjectIds(['issuer.obj_1']);

        $this->assertStringStartsWith('https://pay.google.com/gp/v/save/', $url);
    }

    public function test_the_token_claims_identify_the_object_to_save(): void
    {
        $url = $this->service()->buildForObjectIds(['issuer.obj_1']);
        $token = substr($url, strlen('https://pay.google.com/gp/v/save/'));

        [$header, $claims] = array_map($this->decodeSegment(...), array_slice(explode('.', $token), 0, 2));

        $this->assertSame('RS256', $header['alg']);
        $this->assertSame(self::SERVICE_ACCOUNT_EMAIL, $claims['iss']);
        $this->assertSame('google', $claims['aud']);
        $this->assertSame('savetowallet', $claims['typ']);
        $this->assertSame(['https://tickets.example.com'], $claims['origins']);
        $this->assertSame([['id' => 'issuer.obj_1']], $claims['payload']['eventTicketObjects']);
    }

    public function test_every_ticket_in_an_order_is_saved_by_a_single_link(): void
    {
        $url = $this->service()->buildForObjectIds([
            3 => 'issuer.obj_1',
            7 => 'issuer.obj_2',
            9 => 'issuer.obj_3',
            11 => 'issuer.obj_4',
        ]);
        $token = substr($url, strlen('https://pay.google.com/gp/v/save/'));

        [, $claims] = array_map($this->decodeSegment(...), array_slice(explode('.', $token), 0, 2));

        $this->assertSame([
            ['id' => 'issuer.obj_1'],
            ['id' => 'issuer.obj_2'],
            ['id' => 'issuer.obj_3'],
            ['id' => 'issuer.obj_4'],
        ], $claims['payload']['eventTicketObjects']);
    }

    public function test_the_token_does_not_expire_so_old_ticket_emails_keep_working(): void
    {
        $url = $this->service()->buildForObjectIds(['issuer.obj_1']);
        $token = substr($url, strlen('https://pay.google.com/gp/v/save/'));

        [, $claims] = array_map($this->decodeSegment(...), array_slice(explode('.', $token), 0, 2));

        $this->assertArrayNotHasKey('exp', $claims);
    }

    public function test_the_token_signature_verifies_against_the_service_account_key(): void
    {
        $url = $this->service()->buildForObjectIds(['issuer.obj_1']);
        $token = substr($url, strlen('https://pay.google.com/gp/v/save/'));

        [$header, $claims, $signature] = explode('.', $token);

        $this->assertSame(1, openssl_verify(
            $header.'.'.$claims,
            base64_decode(strtr($signature, '-_', '+/')),
            $this->publicKeyPem,
            OPENSSL_ALGO_SHA256,
        ));
    }
}
