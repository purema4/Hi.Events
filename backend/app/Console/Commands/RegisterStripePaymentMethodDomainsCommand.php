<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\Enums\StripePlatform;
use HiEvents\DomainObjects\OrganizerStripePlatformDomainObject;
use HiEvents\Repository\Interfaces\OrganizerStripePlatformRepositoryInterface;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentMethodDomainRegistrationService;
use HiEvents\Services\Infrastructure\Stripe\StripeConfigurationService;
use Illuminate\Console\Command;

class RegisterStripePaymentMethodDomainsCommand extends Command
{
    protected $signature = 'stripe:register-payment-method-domains';

    protected $description = 'Register the checkout domain with Stripe so wallet payment methods can appear at checkout';

    public function __construct(
        private readonly OrganizerStripePlatformRepositoryInterface $organizerStripePlatformRepository,
        private readonly StripePaymentMethodDomainRegistrationService $domainRegistrationService,
        private readonly StripeConfigurationService $stripeConfigurationService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $failureCount = 0;

        $this->info('Registering the checkout domain on the platform account...');

        if (! $this->domainRegistrationService->registerCheckoutDomain($this->stripeConfigurationService->getPrimaryPlatform())) {
            $failureCount++;
            $this->error('Failed to register the checkout domain on the platform account.');
        }

        $connectedAccounts = $this->organizerStripePlatformRepository
            ->findWhere([])
            ->filter(fn (OrganizerStripePlatformDomainObject $platform) => $platform->getStripeAccountId() !== null)
            ->unique(fn (OrganizerStripePlatformDomainObject $platform) => $platform->getStripeAccountId());

        $this->info(sprintf('Registering the checkout domain on %d connected account(s)...', $connectedAccounts->count()));

        foreach ($connectedAccounts as $connectedAccount) {
            $registered = $this->domainRegistrationService->registerCheckoutDomain(
                platform: StripePlatform::fromString($connectedAccount->getStripeConnectPlatform()),
                stripeAccountId: $connectedAccount->getStripeAccountId(),
            );

            if (! $registered) {
                $failureCount++;
                $this->error(sprintf('Failed to register the checkout domain on %s.', $connectedAccount->getStripeAccountId()));
            }
        }

        if ($failureCount > 0) {
            $this->error(sprintf('%d registration(s) failed. Check the application logs for details.', $failureCount));

            return self::FAILURE;
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
