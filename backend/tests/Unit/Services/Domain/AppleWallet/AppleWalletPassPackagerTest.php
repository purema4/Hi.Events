<?php

namespace Tests\Unit\Services\Domain\AppleWallet;

use HiEvents\Services\Domain\AppleWallet\AppleWalletPassPackager;
use HiEvents\Services\Infrastructure\AppleWallet\AppleWalletPassSigner;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class AppleWalletPassPackagerTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        Mockery::close();

        parent::tearDown();
    }

    /**
     * @return array<string, string>
     */
    private function unzip(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'apple-wallet-test-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $contents);

        $archive = new ZipArchive;
        $archive->open($path);

        $files = [];

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index);
            $files[$name] = $archive->getFromName($name);
        }

        $archive->close();

        return $files;
    }

    public function test_a_pass_contains_its_json_images_manifest_and_signature(): void
    {
        $signer = Mockery::mock(AppleWalletPassSigner::class);
        $signer->shouldReceive('sign')->once()->andReturn('signature-bytes');

        $files = $this->unzip((new AppleWalletPassPackager($signer))->package(
            ['formatVersion' => 1, 'description' => 'Ticket für Café'],
            ['icon.png' => 'icon-bytes', 'logo.png' => 'logo-bytes'],
        ));

        $this->assertEqualsCanonicalizing(
            ['pass.json', 'icon.png', 'logo.png', 'manifest.json', 'signature'],
            array_keys($files),
        );
        $this->assertSame(['formatVersion' => 1, 'description' => 'Ticket für Café'], json_decode($files['pass.json'], true));
        $this->assertSame('signature-bytes', $files['signature']);
    }

    public function test_the_manifest_lists_the_sha1_of_every_file_and_is_what_gets_signed(): void
    {
        $signedManifest = null;

        $signer = Mockery::mock(AppleWalletPassSigner::class);
        $signer->shouldReceive('sign')->once()->andReturnUsing(function (string $manifest) use (&$signedManifest) {
            $signedManifest = $manifest;

            return 'signature-bytes';
        });

        $files = $this->unzip((new AppleWalletPassPackager($signer))->package(
            ['formatVersion' => 1],
            ['icon.png' => 'icon-bytes'],
        ));

        $this->assertSame([
            'pass.json' => sha1($files['pass.json']),
            'icon.png' => sha1('icon-bytes'),
        ], json_decode($files['manifest.json'], true));
        $this->assertSame($files['manifest.json'], $signedManifest);
    }

    public function test_several_passes_are_bundled_into_one_file(): void
    {
        $files = $this->unzip((new AppleWalletPassPackager(Mockery::mock(AppleWalletPassSigner::class)))->bundle([
            'hievents-attendee-1.pkpass' => 'first-pass',
            'hievents-attendee-2.pkpass' => 'second-pass',
        ]));

        $this->assertSame([
            'hievents-attendee-1.pkpass' => 'first-pass',
            'hievents-attendee-2.pkpass' => 'second-pass',
        ], $files);
    }
}
