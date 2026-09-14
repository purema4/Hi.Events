<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\AppleWallet;

use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Domain\Wallet\DTO\WalletPassBrandingDTO;
use HiEvents\Services\Domain\Wallet\WalletPassBrandingResolver;
use Illuminate\Config\Repository;

class AppleWalletPassSettingsResolver
{
    public function __construct(
        private readonly Repository $config,
        private readonly OrganizerSettingsRepositoryInterface $organizerSettingsRepository,
        private readonly WalletPassBrandingResolver $brandingResolver,
    ) {}

    public function isConfigured(): bool
    {
        return (bool) $this->config->get('apple-wallet.enabled')
            && trim((string) $this->config->get('apple-wallet.pass_type_identifier')) !== ''
            && trim((string) $this->config->get('apple-wallet.team_identifier')) !== '';
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

        if ($settings === null || ! $settings->getAppleWalletEnabled()) {
            return null;
        }

        return $this->brandingResolver->fromOrganizerSettings($settings);
    }
}
