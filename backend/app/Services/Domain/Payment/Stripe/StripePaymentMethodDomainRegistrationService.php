<?php

namespace HiEvents\Services\Domain\Payment\Stripe;

use HiEvents\DomainObjects\Enums\StripePlatform;
use HiEvents\Services\Infrastructure\Stripe\StripeClientFactory;
use Illuminate\Config\Repository;
use Psr\Log\LoggerInterface;
use Throwable;

class StripePaymentMethodDomainRegistrationService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Repository $config,
        private readonly StripeClientFactory $stripeClientFactory,
    ) {}

    public function registerCheckoutDomain(?StripePlatform $platform = null, ?string $stripeAccountId = null): bool
    {
        $domain = $this->getCheckoutDomain();

        if ($domain === null) {
            $this->logger->warning('Cannot register Stripe payment method domain, no frontend host is configured', [
                'frontend_url' => $this->config->get('app.frontend_url'),
            ]);

            return false;
        }

        try {
            $stripeClient = $this->stripeClientFactory->createForPlatform($platform);
            $requestOptions = $stripeAccountId ? ['stripe_account' => $stripeAccountId] : [];

            $registeredDomains = $stripeClient->paymentMethodDomains->all([
                'domain_name' => $domain,
                'limit' => 1,
            ], $requestOptions);

            if (count($registeredDomains->data) > 0) {
                return true;
            }

            $stripeClient->paymentMethodDomains->create([
                'domain_name' => $domain,
            ], $requestOptions);

            $this->logger->info('Registered Stripe payment method domain', [
                'domain' => $domain,
                'stripe_account_id' => $stripeAccountId,
                'platform' => $platform?->value,
            ]);

            return true;
        } catch (Throwable $exception) {
            $this->logger->error('Failed to register Stripe payment method domain', [
                'domain' => $domain,
                'stripe_account_id' => $stripeAccountId,
                'platform' => $platform?->value,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function getCheckoutDomain(): ?string
    {
        return parse_url((string) $this->config->get('app.frontend_url'), PHP_URL_HOST) ?: null;
    }
}
