<?php

namespace Tests\Unit\Services\Domain;

use HiEvents\DomainObjects\Generated\WebhookDomainObjectAbstract;
use HiEvents\DomainObjects\WebhookDomainObject;
use HiEvents\Repository\Interfaces\WebhookRepositoryInterface;
use HiEvents\Services\Domain\CreateWebhookService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CreateWebhookServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private array $capturedAttributes = [];

    private CreateWebhookService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $webhookRepository = Mockery::mock(WebhookRepositoryInterface::class);
        $webhookRepository
            ->shouldReceive('create')
            ->once()
            ->andReturnUsing(function (array $attributes) {
                $this->capturedAttributes = $attributes;

                return new WebhookDomainObject;
            });

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info');

        $this->service = new CreateWebhookService($webhookRepository, $logger);
    }

    public function test_keeps_the_secret_of_the_given_webhook(): void
    {
        $secret = str_repeat('s', 32);

        $this->service->createWebhook($this->makeWebhook()->setSecret($secret));

        $this->assertSame($secret, $this->capturedAttributes[WebhookDomainObjectAbstract::SECRET]);
    }

    public function test_generates_a_random_32_character_secret_when_none_is_given(): void
    {
        $this->service->createWebhook($this->makeWebhook());

        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9]{32}$/',
            $this->capturedAttributes[WebhookDomainObjectAbstract::SECRET],
        );
    }

    private function makeWebhook(): WebhookDomainObject
    {
        return (new WebhookDomainObject)
            ->setUrl('https://example.com/webhook')
            ->setEventTypes(['order.created'])
            ->setStatus('ENABLED')
            ->setEventId(1)
            ->setAccountId(1)
            ->setUserId(1);
    }
}
