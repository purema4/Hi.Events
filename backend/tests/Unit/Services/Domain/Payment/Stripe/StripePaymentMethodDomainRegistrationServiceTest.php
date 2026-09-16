<?php

namespace Tests\Unit\Services\Domain\Payment\Stripe;

use HiEvents\Services\Domain\Payment\Stripe\StripePaymentMethodDomainRegistrationService;
use HiEvents\Services\Infrastructure\Stripe\StripeClientFactory;
use Illuminate\Config\Repository;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Stripe\Collection;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentMethodDomain;
use Stripe\Service\PaymentMethodDomainService;
use Stripe\StripeClient;
use Tests\TestCase;

class StripePaymentMethodDomainRegistrationServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function test_it_registers_the_checkout_domain_on_the_connected_account(): void
    {
        $domainService = m::mock(PaymentMethodDomainService::class);
        $domainService->shouldReceive('all')
            ->once()
            ->with(['domain_name' => 'tickets.example.com', 'limit' => 1], ['stripe_account' => 'acct_123'])
            ->andReturn(Collection::constructFrom(['data' => []]));
        $domainService->shouldReceive('create')
            ->once()
            ->with(['domain_name' => 'tickets.example.com'], ['stripe_account' => 'acct_123'])
            ->andReturn(PaymentMethodDomain::constructFrom(['id' => 'pmd_123']));

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->once();

        $service = $this->createService($domainService, $logger, 'https://tickets.example.com');

        $this->assertTrue($service->registerCheckoutDomain(stripeAccountId: 'acct_123'));
    }

    public function test_it_does_not_register_an_already_registered_domain(): void
    {
        $domainService = m::mock(PaymentMethodDomainService::class);
        $domainService->shouldReceive('all')
            ->once()
            ->andReturn(Collection::constructFrom([
                'data' => [PaymentMethodDomain::constructFrom(['id' => 'pmd_123'])],
            ]));
        $domainService->shouldNotReceive('create');

        $service = $this->createService($domainService, m::mock(LoggerInterface::class), 'https://tickets.example.com');

        $this->assertTrue($service->registerCheckoutDomain(stripeAccountId: 'acct_123'));
    }

    public function test_it_reports_failure_when_stripe_rejects_the_domain(): void
    {
        $domainService = m::mock(PaymentMethodDomainService::class);
        $domainService->shouldReceive('all')->once()->andReturn(Collection::constructFrom(['data' => []]));
        $domainService->shouldReceive('create')
            ->once()
            ->andThrow(new InvalidRequestException('Invalid domain name'));

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once();

        $service = $this->createService($domainService, $logger, 'https://tickets.example.com');

        $this->assertFalse($service->registerCheckoutDomain());
    }

    public function test_it_does_not_call_stripe_when_no_frontend_host_is_configured(): void
    {
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once();

        $stripeClientFactory = m::mock(StripeClientFactory::class);
        $stripeClientFactory->shouldNotReceive('createForPlatform');

        $service = new StripePaymentMethodDomainRegistrationService(
            $logger,
            new Repository(['app' => ['frontend_url' => '']]),
            $stripeClientFactory,
        );

        $this->assertFalse($service->registerCheckoutDomain());
    }

    private function createService(
        PaymentMethodDomainService $domainService,
        LoggerInterface $logger,
        string $frontendUrl,
    ): StripePaymentMethodDomainRegistrationService {
        $stripeClient = m::mock(StripeClient::class);
        $stripeClient->shouldReceive('getService')->with('paymentMethodDomains')->andReturn($domainService);

        $stripeClientFactory = m::mock(StripeClientFactory::class);
        $stripeClientFactory->shouldReceive('createForPlatform')->andReturn($stripeClient);

        return new StripePaymentMethodDomainRegistrationService(
            $logger,
            new Repository(['app' => ['frontend_url' => $frontendUrl]]),
            $stripeClientFactory,
        );
    }
}
