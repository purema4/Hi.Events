<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\AppleWallet;

use HiEvents\Exceptions\AppleWallet\AppleWalletImageException;
use HiEvents\Services\Infrastructure\Webhook\WebhookUrlValidator;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpClient;
use Throwable;

class AppleWalletImageFetcher
{
    private const CACHE_KEY_PREFIX = 'apple-wallet:image:';

    public function __construct(
        private readonly HttpClient $http,
        private readonly CacheRepository $cache,
        private readonly Repository $config,
        private readonly WebhookUrlValidator $urlValidator,
    ) {}

    /**
     * @throws AppleWalletImageException
     */
    public function fetch(string $url): string
    {
        $cacheKey = self::CACHE_KEY_PREFIX.sha1($url);
        $cached = $this->cache->get($cacheKey);

        if (is_string($cached)) {
            return (string) base64_decode($cached);
        }

        $contents = $this->download($url);

        $this->cache->put(
            $cacheKey,
            base64_encode($contents),
            (int) $this->config->get('apple-wallet.image_cache_seconds'),
        );

        return $contents;
    }

    /**
     * @throws AppleWalletImageException
     */
    private function download(string $url): string
    {
        if (! str_starts_with(strtolower($url), 'https://')) {
            throw new AppleWalletImageException(
                __('Apple Wallet images must be served over https: :url', ['url' => $url])
            );
        }

        try {
            $target = $this->urlValidator->validate($url);

            $response = $this->http
                ->withoutRedirecting()
                ->withOptions(['curl' => [CURLOPT_RESOLVE => $target->toCurlResolveEntries()]])
                ->timeout((int) $this->config->get('apple-wallet.request_timeout_seconds'))
                ->get($url);
        } catch (Throwable $exception) {
            throw new AppleWalletImageException(
                __('Could not download the Apple Wallet image :url: :message', [
                    'url' => $url,
                    'message' => $exception->getMessage(),
                ]),
                previous: $exception,
            );
        }

        $contents = $response->body();

        if (! $response->successful()
            || $contents === ''
            || strlen($contents) > (int) $this->config->get('apple-wallet.image_max_bytes')) {
            throw new AppleWalletImageException(
                __('Could not download the Apple Wallet image :url (:status).', [
                    'url' => $url,
                    'status' => $response->status(),
                ])
            );
        }

        return $contents;
    }
}
