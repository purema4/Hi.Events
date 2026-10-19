<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Webhook;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\TransferStats;
use HiEvents\DomainObjects\Generated\WebhookDomainObjectAbstract;
use HiEvents\DomainObjects\Status\WebhookStatus;
use HiEvents\DomainObjects\WebhookDomainObject;
use HiEvents\Exceptions\UnresolvableWebhookHostException;
use HiEvents\Exceptions\UnsafeWebhookUrlException;
use HiEvents\Repository\Interfaces\WebhookRepositoryInterface;
use HiEvents\Services\Infrastructure\Webhook\WebhookUrlValidator;
use Spatie\WebhookServer\CallWebhookJob;

class SecureCallWebhookJob extends CallWebhookJob
{
    private const MAX_REDIRECTS = 3;

    private const REDIRECT_STATUS_CODES = [301, 302, 303, 307, 308];

    private bool $urlIsUnsafe = false;

    public function handle()
    {
        if ($this->attempts() > 1 && ! $this->webhookStillTargetsThisUrl()) {
            $this->delete();

            return;
        }

        parent::handle();
    }

    public function release($delay = 0)
    {
        if (! $this->urlIsUnsafe) {
            parent::release($delay);
        }
    }

    protected function shouldBeRemovedFromQueue(): bool
    {
        return $this->urlIsUnsafe;
    }

    private function webhookStillTargetsThisUrl(): bool
    {
        if (! isset($this->meta['webhook_id'])) {
            return true;
        }

        /** @var WebhookDomainObject|null $webhook */
        $webhook = app(WebhookRepositoryInterface::class)->findFirstWhere([
            WebhookDomainObjectAbstract::ID => (int) $this->meta['webhook_id'],
        ]);

        return $webhook !== null
            && $webhook->getStatus() === WebhookStatus::ENABLED->name
            && $webhook->getUrl() === $this->webhookUrl;
    }

    /**
     * @throws UnsafeWebhookUrlException
     */
    protected function createRequest(array $body): Response
    {
        $client = $this->getClient();
        $validator = app(WebhookUrlValidator::class);
        $url = $this->webhookUrl;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $target = $validator->validate($url);
            } catch (UnsafeWebhookUrlException $exception) {
                $this->urlIsUnsafe = ! $exception instanceof UnresolvableWebhookHostException;

                throw new UnsafeWebhookUrlException($this->describeUrlFailure($exception));
            }

            $response = $client->request($this->httpVerb, $url, array_merge(
                [
                    'timeout' => $this->requestTimeout,
                    'verify' => $this->verifySsl,
                    'headers' => $this->headers,
                    'allow_redirects' => false,
                    'curl' => [
                        CURLOPT_RESOLVE => $target->toCurlResolveEntries(),
                    ],
                    'on_stats' => function (TransferStats $stats) {
                        $this->transferStats = $stats;
                    },
                ],
                $body,
                is_null($this->proxy) ? [] : ['proxy' => $this->proxy],
                is_null($this->cert) ? [] : ['cert' => [$this->cert, $this->certPassphrase]],
                is_null($this->sslKey) ? [] : ['ssl_key' => [$this->sslKey, $this->sslKeyPassphrase]],
            ));

            $location = $this->redirectLocation($response);

            if ($location === null) {
                return $response;
            }

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
        }

        throw new UnsafeWebhookUrlException(
            __('The webhook URL exceeded the maximum number of redirects.')
        );
    }

    private function describeUrlFailure(UnsafeWebhookUrlException $exception): string
    {
        return str_replace(':attribute', __('webhook URL'), $exception->getMessage());
    }

    private function redirectLocation(Response $response): ?string
    {
        if (! in_array($response->getStatusCode(), self::REDIRECT_STATUS_CODES, true)) {
            return null;
        }

        $location = trim($response->getHeaderLine('Location'));

        return $location === '' ? null : $location;
    }
}
