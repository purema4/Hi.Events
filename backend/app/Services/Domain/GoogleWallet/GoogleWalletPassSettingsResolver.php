<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\GoogleWallet;

use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Domain\Wallet\DTO\WalletPassBrandingDTO;
use HiEvents\Services\Domain\Wallet\WalletPassBrandingResolver;
use Illuminate\Config\Repository;

class GoogleWalletPassSettingsResolver
{
    public function __construct(
        private readonly Repository $config,
        private readonly OrganizerSettingsRepositoryInterface $organizerSettingsRepository,
        private readonly WalletPassBrandingResolver $brandingResolver,
    ) {}

    public function isConfigured(): bool
    {
        return (bool) $this->config->get('google-wallet.enabled')
            && trim((string) $this->config->get('google-wallet.issuer_id')) !== '';
    }

    public function resolveForOrganizer(int $organizerId): ?WalletPassBrandingDTO
    {
        if (! $this->isConfigured()) {
            return null;
        }

        /** @var OrganizerSettingDomainObject|null $settings */
        $settings = $this->organizerSettingsRepository->findFirstWhere([
            'organizer_id' => $organizerId,
        ]);

        if ($settings === null || ! $settings->getGoogleWalletEnabled()) {
            return null;
        }

        return $this->brandingResolver->fromOrganizerSettings($settings);
    }
}
