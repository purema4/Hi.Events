<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\GoogleWallet;

use HiEvents\Exceptions\GoogleWallet\GoogleWalletApiException;
use HiEvents\Exceptions\GoogleWallet\GoogleWalletConfigurationException;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Client\Response;
use Throwable;

class GoogleWalletApiClient
{
    private const ACCESS_TOKEN_CACHE_KEY = 'google-wallet:access-token';

    private const ASSERTION_LIFETIME_SECONDS = 3600;

    private const TOKEN_EXPIRY_LEEWAY_SECONDS = 60;

    public function __construct(
        private readonly HttpClient $http,
        private readonly CacheRepository $cache,
        private readonly Repository $config,
        private readonly GoogleWalletJwtSigner $jwtSigner,
    ) {}

    /**
     * @throws GoogleWalletApiException|GoogleWalletConfigurationException
     */
    public function upsertEventTicketClass(string $classId, array $payload): void
    {
        $this->upsert('eventTicketClass', $classId, $payload);
    }

    /**
     * @throws GoogleWalletApiException|GoogleWalletConfigurationException
     */
    public function upsertEventTicketObject(string $objectId, array $payload): void
    {
        $this->upsert('eventTicketObject', $objectId, $payload);
    }

    /**
     * @throws GoogleWalletApiException|GoogleWalletConfigurationException
     */
    private function upsert(string $resource, string $resourceId, array $payload): void
    {
        $insertResponse = $this->request('post', $resource, $payload);

        if ($insertResponse->successful()) {
            return;
        }

        if ($insertResponse->status() !== 409) {
            throw $this->apiException($resource, $insertResponse);
        }

        $patchResponse = $this->request('patch', $resource.'/'.$resourceId, $payload);

        if (! $patchResponse->successful()) {
            throw $this->apiException($resource, $patchResponse);
        }
    }

    /**
     * @throws GoogleWalletApiException|GoogleWalletConfigurationException
     */
    private function request(string $method, string $path, array $payload): Response
    {
        try {
            return $this->http
                ->withToken($this->accessToken())
                ->timeout($this->config->get('google-wallet.request_timeout_seconds'))
                ->{$method}($this->config->get('google-wallet.api_base_url').'/'.$path, $payload);
        } catch (GoogleWalletConfigurationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new GoogleWalletApiException(
                __('Could not reach the Google Wallet API: :message', ['message' => $exception->getMessage()]),
                previous: $exception,
            );
        }
    }

    /**
     * @throws GoogleWalletApiException|GoogleWalletConfigurationException
     */
    private function accessToken(): string
    {
        $cached = $this->cache->get(self::ACCESS_TOKEN_CACHE_KEY);

        if (is_string($cached)) {
            return $cached;
        }

        $issuedAt = time();

        $assertion = $this->jwtSigner->sign([
            'iss' => $this->jwtSigner->serviceAccountEmail(),
            'scope' => $this->config->get('google-wallet.scope'),
            'aud' => $this->config->get('google-wallet.token_endpoint'),
            'iat' => $issuedAt,
            'exp' => $issuedAt + self::ASSERTION_LIFETIME_SECONDS,
        ]);

        try {
            $response = $this->http
                ->asForm()
                ->timeout($this->config->get('google-wallet.request_timeout_seconds'))
                ->post($this->config->get('google-wallet.token_endpoint'), [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);
        } catch (Throwable $exception) {
            throw new GoogleWalletApiException(
                __('Could not reach the Google authorization server: :message', ['message' => $exception->getMessage()]),
                previous: $exception,
            );
        }

        $accessToken = $response->json('access_token');

        if (! $response->successful() || ! is_string($accessToken)) {
            throw new GoogleWalletApiException(
                __('Google rejected the Google Wallet service account credentials: :body', [
                    'body' => $response->body(),
                ])
            );
        }

        $this->cache->put(
            self::ACCESS_TOKEN_CACHE_KEY,
            $accessToken,
            max(60, ((int) $response->json('expires_in', 3600)) - self::TOKEN_EXPIRY_LEEWAY_SECONDS),
        );

        return $accessToken;
    }

    private function apiException(string $resource, Response $response): GoogleWalletApiException
    {
        return new GoogleWalletApiException(
            __('Google Wallet rejected the :resource request (:status): :body', [
                'resource' => $resource,
                'status' => $response->status(),
                'body' => $response->body(),
            ])
        );
    }
}
