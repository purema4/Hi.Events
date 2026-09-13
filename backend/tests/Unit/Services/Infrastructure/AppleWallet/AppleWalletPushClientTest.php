<?php

namespace Tests\Unit\Services\Infrastructure\AppleWallet;

use HiEvents\Exceptions\AppleWallet\AppleWalletPushException;
use HiEvents\Services\Infrastructure\AppleWallet\AppleWalletCredentialsResolver;
use HiEvents\Services\Infrastructure\AppleWallet\AppleWalletPushClient;
use HiEvents\Services\Infrastructure\AppleWallet\DTO\AppleWalletCredentialsDTO;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Client\Request;
use Mockery;
use Tests\TestCase;

class AppleWalletPushClientTest extends TestCase
{
    private HttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new HttpClient;
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function client(): AppleWalletPushClient
    {
        $credentialsResolver = Mockery::mock(AppleWalletCredentialsResolver::class);
        $credentialsResolver->shouldReceive('resolve')->andReturn(new AppleWalletCredentialsDTO(
            certificate: 'certificate-pem',
            privateKey: 'private-key-pem',
            wwdrCertificate: 'wwdr-pem',
        ));

        return new AppleWalletPushClient(
            $this->http,
            new Repository(['apple-wallet' => [
                'pass_type_identifier' => 'pass.events.hi.test',
                'apns_base_url' => 'https://api.push.apple.com/',
                'request_timeout_seconds' => 10,
            ]]),
            $credentialsResolver,
        );
    }

    public function test_the_device_is_told_that_passes_of_the_pass_type_changed(): void
    {
        $this->http->fake(['*' => HttpClient::response('', 200)]);

        $this->assertTrue($this->client()->notifyPassUpdated('push-token'));

        $this->http->assertSent(fn (Request $request) => $request->url() === 'https://api.push.apple.com/3/device/push-token'
            && $request->method() === 'POST'
            && $request->hasHeader('apns-topic', 'pass.events.hi.test')
            && $request->body() === '{}');
    }

    public function test_a_token_apple_no_longer_recognises_is_reported_as_unregistered(): void
    {
        $this->http->fake(['*' => HttpClient::response('{"reason":"Unregistered"}', 410)]);

        $this->assertFalse($this->client()->notifyPassUpdated('push-token'));
    }

    public function test_any_other_rejection_is_an_error(): void
    {
        $this->http->fake(['*' => HttpClient::response('{"reason":"BadCertificate"}', 403)]);

        $this->expectException(AppleWalletPushException::class);
        $this->expectExceptionMessage('BadCertificate');

        $this->client()->notifyPassUpdated('push-token');
    }

    public function test_an_unreachable_push_service_is_an_error(): void
    {
        $this->http->fake(fn () => throw new ConnectionException('Connection refused'));

        $this->expectException(AppleWalletPushException::class);

        $this->client()->notifyPassUpdated('push-token');
    }
}
