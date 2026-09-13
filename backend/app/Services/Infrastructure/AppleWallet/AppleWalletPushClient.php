<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\AppleWallet;

use HiEvents\Exceptions\AppleWallet\AppleWalletConfigurationException;
use HiEvents\Exceptions\AppleWallet\AppleWalletPushException;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory as HttpClient;
use Throwable;

class AppleWalletPushClient
{
    private const DEVICE_UNREGISTERED_STATUS = 410;

    public function __construct(
        private readonly HttpClient $http,
        private readonly Repository $config,
        private readonly AppleWalletCredentialsResolver $credentialsResolver,
    ) {}

    /**
     * @return bool false when Apple reports the push token is no longer registered
     *
     * @throws AppleWalletPushException|AppleWalletConfigurationException
     */
    public function notifyPassUpdated(string $pushToken): bool
    {
        $credentials = $this->credentialsResolver->resolve();

        try {
            $response = $this->http
                ->withHeaders(['apns-topic' => (string) $this->config->get('apple-wallet.pass_type_identifier')])
                ->withOptions([
                    'version' => 2.0,
                    'curl' => [
                        CURLOPT_SSLCERT_BLOB => $credentials->certificate,
                        CURLOPT_SSLCERTTYPE => 'PEM',
                        CURLOPT_SSLKEY_BLOB => $credentials->privateKey,
                        CURLOPT_SSLKEYTYPE => 'PEM',
                    ],
                ])
                ->timeout($this->config->get('apple-wallet.request_timeout_seconds'))
                ->withBody('{}', 'application/json')
                ->post(rtrim((string) $this->config->get('apple-wallet.apns_base_url'), '/').'/3/device/'.rawurlencode($pushToken));
        } catch (Throwable $exception) {
            throw new AppleWalletPushException(
                __('Could not reach the Apple Push Notification service: :message', ['message' => $exception->getMessage()]),
                previous: $exception,
            );
        }

        if ($response->successful()) {
            return true;
        }

        if ($response->status() === self::DEVICE_UNREGISTERED_STATUS) {
            return false;
        }

        throw new AppleWalletPushException(
            __('Apple rejected the Wallet pass update notification (:status): :body', [
                'status' => $response->status(),
                'body' => $response->body(),
            ])
        );
    }
}
