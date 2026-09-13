<?php

namespace Tests\Unit\Services\Infrastructure\AppleWallet;

use HiEvents\Exceptions\AppleWallet\AppleWalletConfigurationException;
use HiEvents\Services\Infrastructure\AppleWallet\AppleWalletCredentialsResolver;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

class AppleWalletCredentialsResolverTest extends TestCase
{
    private string $certificatePem;

    private string $privateKeyPem;

    private string $wwdrPem;

    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $keyOptions = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        $passKey = openssl_pkey_new($keyOptions);
        $pass = openssl_csr_sign(openssl_csr_new(['commonName' => 'Pass'], $passKey), null, $passKey, 1);
        openssl_x509_export($pass, $certificatePem);
        openssl_pkey_export($passKey, $privateKeyPem);

        $wwdrKey = openssl_pkey_new($keyOptions);
        openssl_x509_export(openssl_csr_sign(openssl_csr_new(['commonName' => 'WWDR'], $wwdrKey), null, $wwdrKey, 1), $wwdrPem);

        $this->certificatePem = $certificatePem;
        $this->privateKeyPem = $privateKeyPem;
        $this->wwdrPem = $wwdrPem;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function resolver(array $config): AppleWalletCredentialsResolver
    {
        return new AppleWalletCredentialsResolver(new Repository(['apple-wallet' => $config]), new Filesystem);
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'apple-wallet-credentials-test-');
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function derFromPem(string $pem): string
    {
        return base64_decode(preg_replace('/-----[A-Z ]+-----|\s/', '', $pem));
    }

    public function test_a_pem_containing_the_certificate_and_its_key_is_read(): void
    {
        $credentials = $this->resolver([
            'certificate' => $this->certificatePem.$this->privateKeyPem,
            'wwdr_certificate' => $this->wwdrPem,
        ])->resolve();

        $this->assertTrue(openssl_x509_check_private_key($credentials->certificate, $credentials->privateKey));
        $this->assertSame('WWDR', openssl_x509_parse($credentials->wwdrCertificate)['subject']['CN']);
    }

    public function test_a_base64_encoded_pkcs12_bundle_is_read_with_its_password(): void
    {
        openssl_pkcs12_export($this->certificatePem, $bundle, $this->privateKeyPem, 'secret');

        $credentials = $this->resolver([
            'certificate' => base64_encode($bundle),
            'certificate_password' => 'secret',
            'wwdr_certificate' => base64_encode($this->wwdrPem),
        ])->resolve();

        $this->assertTrue(openssl_x509_check_private_key($credentials->certificate, $credentials->privateKey));
    }

    public function test_a_wrong_pkcs12_password_is_a_configuration_error(): void
    {
        openssl_pkcs12_export($this->certificatePem, $bundle, $this->privateKeyPem, 'secret');

        $this->expectException(AppleWalletConfigurationException::class);

        $this->resolver([
            'certificate' => base64_encode($bundle),
            'certificate_password' => 'wrong',
            'wwdr_certificate' => $this->wwdrPem,
        ])->resolve();
    }

    public function test_certificates_are_read_from_files_and_apples_der_wwdr_download_is_accepted(): void
    {
        $credentials = $this->resolver([
            'certificate_file' => $this->temporaryFile($this->certificatePem.$this->privateKeyPem),
            'wwdr_certificate_file' => $this->temporaryFile($this->derFromPem($this->wwdrPem)),
        ])->resolve();

        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', $credentials->wwdrCertificate);
        $this->assertSame('WWDR', openssl_x509_parse($credentials->wwdrCertificate)['subject']['CN']);
    }

    public function test_a_missing_certificate_is_a_configuration_error(): void
    {
        $this->expectException(AppleWalletConfigurationException::class);

        $this->resolver(['wwdr_certificate' => $this->wwdrPem])->resolve();
    }

    public function test_a_certificate_file_that_does_not_exist_is_a_configuration_error(): void
    {
        $this->expectException(AppleWalletConfigurationException::class);

        $this->resolver([
            'certificate_file' => '/run/secrets/does-not-exist.pem',
            'wwdr_certificate' => $this->wwdrPem,
        ])->resolve();
    }

    public function test_a_certificate_without_its_private_key_is_a_configuration_error(): void
    {
        $this->expectException(AppleWalletConfigurationException::class);

        $this->resolver([
            'certificate' => $this->certificatePem,
            'wwdr_certificate' => $this->wwdrPem,
        ])->resolve();
    }
}
