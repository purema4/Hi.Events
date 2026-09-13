<?php

namespace Tests\Unit\Services\Infrastructure\AppleWallet;

use HiEvents\Services\Infrastructure\AppleWallet\AppleWalletCredentialsResolver;
use HiEvents\Services\Infrastructure\AppleWallet\AppleWalletPassSigner;
use HiEvents\Services\Infrastructure\AppleWallet\DTO\AppleWalletCredentialsDTO;
use Mockery;
use Tests\TestCase;

class AppleWalletPassSignerTest extends TestCase
{
    private const MANIFEST = '{"pass.json":"2fd4e1c67a2d28fced849ee1bb76e7391b93eb12"}';

    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        Mockery::close();

        parent::tearDown();
    }

    private function credentials(): AppleWalletCredentialsDTO
    {
        $keyOptions = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        $wwdrKey = openssl_pkey_new($keyOptions);
        $wwdr = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test WWDR'], $wwdrKey), null, $wwdrKey, 1, serial: 1);

        $passKey = openssl_pkey_new($keyOptions);
        $pass = openssl_csr_sign(openssl_csr_new(['commonName' => 'Pass Type ID: pass.events.hi.test'], $passKey), $wwdr, $wwdrKey, 1, serial: 2);

        openssl_x509_export($pass, $certificate);
        openssl_pkey_export($passKey, $privateKey);
        openssl_x509_export($wwdr, $wwdrCertificate);

        return new AppleWalletCredentialsDTO(
            certificate: $certificate,
            privateKey: $privateKey,
            wwdrCertificate: $wwdrCertificate,
        );
    }

    private function signer(): AppleWalletPassSigner
    {
        $credentialsResolver = Mockery::mock(AppleWalletCredentialsResolver::class);
        $credentialsResolver->shouldReceive('resolve')->andReturn($this->credentials());

        return new AppleWalletPassSigner($credentialsResolver);
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'apple-wallet-signer-test-');
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function verifies(string $manifest, string $signature): bool
    {
        return openssl_cms_verify(
            $this->temporaryFile($manifest),
            OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED,
            null,
            [],
            null,
            null,
            null,
            $this->temporaryFile($signature),
            OPENSSL_ENCODING_DER,
        );
    }

    public function test_the_signature_verifies_against_the_manifest(): void
    {
        $signature = $this->signer()->sign(self::MANIFEST);

        $this->assertTrue($this->verifies(self::MANIFEST, $signature));
    }

    public function test_a_changed_manifest_no_longer_matches_the_signature(): void
    {
        $signature = $this->signer()->sign(self::MANIFEST);

        $this->assertFalse($this->verifies('{"pass.json":"tampered"}', $signature));
    }

    public function test_the_wwdr_intermediate_certificate_is_embedded_in_the_signature(): void
    {
        $signature = $this->signer()->sign(self::MANIFEST);

        $certificates = [];
        openssl_pkcs7_read(
            "-----BEGIN PKCS7-----\n".chunk_split(base64_encode($signature), 64, "\n")."-----END PKCS7-----\n",
            $certificates,
        );

        $commonNames = array_map(
            static fn (string $certificate) => openssl_x509_parse($certificate)['subject']['CN'],
            $certificates,
        );

        $this->assertEqualsCanonicalizing(['Test WWDR', 'Pass Type ID: pass.events.hi.test'], $commonNames);
    }

    public function test_no_temporary_files_are_left_behind(): void
    {
        $signer = $this->signer();
        $before = glob(sys_get_temp_dir().'/apple-wallet-*');

        $signer->sign(self::MANIFEST);

        $this->assertSame($before, glob(sys_get_temp_dir().'/apple-wallet-*'));
    }
}
