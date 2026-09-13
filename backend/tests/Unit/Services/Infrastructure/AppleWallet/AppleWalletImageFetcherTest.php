<?php

namespace Tests\Unit\Services\Infrastructure\AppleWallet;

use HiEvents\Exceptions\AppleWallet\AppleWalletImageException;
use HiEvents\Exceptions\UnsafeWebhookUrlException;
use HiEvents\Services\Infrastructure\AppleWallet\AppleWalletImageFetcher;
use HiEvents\Services\Infrastructure\Webhook\DTO\WebhookTargetDTO;
use HiEvents\Services\Infrastructure\Webhook\WebhookUrlValidator;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory as HttpClient;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AppleWalletImageFetcherTest extends TestCase
{
    private const URL = 'https://cdn.example.com/logo.png';

    private HttpClient $http;

    private WebhookUrlValidator|MockInterface $urlValidator;

    private AppleWalletImageFetcher $fetcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new HttpClient;
        $this->urlValidator = Mockery::mock(WebhookUrlValidator::class);
        $this->urlValidator
            ->shouldReceive('validate')
            ->with(self::URL)
            ->andReturn(new WebhookTargetDTO(host: 'cdn.example.com', port: 443, ipAddresses: ['93.184.216.34']))
            ->byDefault();

        $this->fetcher = new AppleWalletImageFetcher(
            $this->http,
            new CacheRepository(new ArrayStore),
            new Repository(['apple-wallet' => [
                'request_timeout_seconds' => 10,
                'image_max_bytes' => 16,
                'image_cache_seconds' => 3600,
            ]]),
            $this->urlValidator,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_the_image_is_downloaded_once_and_then_served_from_cache(): void
    {
        $this->http->fake([self::URL => HttpClient::response("\x89PNG-bytes", 200)]);

        $this->assertSame("\x89PNG-bytes", $this->fetcher->fetch(self::URL));
        $this->assertSame("\x89PNG-bytes", $this->fetcher->fetch(self::URL));

        $this->http->assertSentCount(1);
    }

    public function test_images_must_be_served_over_https(): void
    {
        $this->urlValidator->shouldNotReceive('validate');

        $this->expectException(AppleWalletImageException::class);

        $this->fetcher->fetch('http://cdn.example.com/logo.png');
    }

    public function test_urls_pointing_at_internal_addresses_are_refused(): void
    {
        $this->urlValidator
            ->shouldReceive('validate')
            ->with('https://169.254.169.254/latest/meta-data')
            ->andThrow(new UnsafeWebhookUrlException('The :attribute cannot point to cloud metadata endpoints.'));

        $this->expectException(AppleWalletImageException::class);

        $this->fetcher->fetch('https://169.254.169.254/latest/meta-data');

        $this->http->assertNothingSent();
    }

    public function test_a_failed_download_is_an_error(): void
    {
        $this->http->fake([self::URL => HttpClient::response('Not found', 404)]);

        $this->expectException(AppleWalletImageException::class);

        $this->fetcher->fetch(self::URL);
    }

    public function test_an_image_larger_than_the_limit_is_refused(): void
    {
        $this->http->fake([self::URL => HttpClient::response(str_repeat('x', 17), 200)]);

        $this->expectException(AppleWalletImageException::class);

        $this->fetcher->fetch(self::URL);
    }
}
