<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\AppleWallet;

use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Helper\HexColorHelper;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Domain\AppleWallet\DTO\AppleWalletPassSettingsDTO;
use Illuminate\Config\Repository;

class AppleWalletPassSettingsResolver
{
    public function __construct(
        private readonly Repository $config,
        private readonly OrganizerSettingsRepositoryInterface $organizerSettingsRepository,
    ) {}

    public function isConfigured(): bool
    {
        return (bool) $this->config->get('apple-wallet.enabled')
            && trim((string) $this->config->get('apple-wallet.pass_type_identifier')) !== ''
            && trim((string) $this->config->get('apple-wallet.team_identifier')) !== '';
    }

    public function resolveForOrganizer(int $organizerId): ?AppleWalletPassSettingsDTO
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

        $passSettings = $this->toArray($settings->getAppleWalletPassSettings());
        $themeSettings = $this->toArray($settings->getHomepageThemeSettings());

        return new AppleWalletPassSettingsDTO(
            logoUrl: $this->nullableString($passSettings['logo_url'] ?? null),
            stripImageUrl: $this->nullableString($passSettings['strip_image_url'] ?? null),
            backgroundColor: HexColorHelper::toRgbHex($passSettings['background_color'] ?? null)
                ?? HexColorHelper::toRgbHex($themeSettings['accent'] ?? null),
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
