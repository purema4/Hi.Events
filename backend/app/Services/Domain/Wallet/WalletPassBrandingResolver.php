<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Wallet;

use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Helper\HexColorHelper;
use HiEvents\Services\Domain\Wallet\DTO\WalletPassBrandingDTO;

class WalletPassBrandingResolver
{
    public function fromOrganizerSettings(OrganizerSettingDomainObject $settings): WalletPassBrandingDTO
    {
        $passSettings = $this->toArray($settings->getWalletPassSettings());
        $themeSettings = $this->toArray($settings->getHomepageThemeSettings());

        return new WalletPassBrandingDTO(
            logoUrl: $this->nullableString($passSettings['logo_url'] ?? null),
            bannerImageUrl: $this->nullableString($passSettings['banner_image_url'] ?? null),
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
