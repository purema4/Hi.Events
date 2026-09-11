<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\GoogleWallet;

use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Domain\GoogleWallet\DTO\GoogleWalletPassSettingsDTO;
use Illuminate\Config\Repository;

class GoogleWalletPassSettingsResolver
{
    public function __construct(
        private readonly Repository $config,
        private readonly OrganizerSettingsRepositoryInterface $organizerSettingsRepository,
    ) {}

    public function isConfigured(): bool
    {
        return (bool) $this->config->get('google-wallet.enabled')
            && trim((string) $this->config->get('google-wallet.issuer_id')) !== '';
    }

    public function resolveForOrganizer(int $organizerId): ?GoogleWalletPassSettingsDTO
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

        $passSettings = $this->toArray($settings->getGoogleWalletPassSettings());
        $themeSettings = $this->toArray($settings->getHomepageThemeSettings());

        return new GoogleWalletPassSettingsDTO(
            logoUrl: $this->nullableString($passSettings['logo_url'] ?? null),
            heroImageUrl: $this->nullableString($passSettings['hero_image_url'] ?? null),
            backgroundColor: $this->nullableString($passSettings['background_color'] ?? null)
                ?? $this->nullableString($themeSettings['accent'] ?? null),
        );
    }

    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return is_string($value) ? (json_decode($value, true) ?: []) : [];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
