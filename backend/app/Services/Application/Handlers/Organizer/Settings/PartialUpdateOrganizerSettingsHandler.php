<?php

namespace HiEvents\Services\Application\Handlers\Organizer\Settings;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\DTO\PartialUpdateOrganizerSettingsDTO;
use HiEvents\Services\Domain\Event\EventSpamCheckDispatchService;
use HiEvents\Services\Domain\Event\HomepageSectionsSanitizer;
use HiEvents\Services\Domain\Wallet\WalletPassSyncDispatcher;
use Illuminate\Contracts\Encryption\Encrypter;

class PartialUpdateOrganizerSettingsHandler
{
    public function __construct(
        private readonly OrganizerSettingsRepositoryInterface $organizerSettingsRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly EventSpamCheckDispatchService $eventSpamCheckDispatchService,
        private readonly HomepageSectionsSanitizer $homepageSectionsSanitizer,
        private readonly WalletPassSyncDispatcher $walletPassSyncDispatcher,
        private readonly Encrypter $encrypter,
    ) {}

    public function handle(PartialUpdateOrganizerSettingsDTO $dto): OrganizerSettingDomainObject
    {
        /** @var OrganizerDomainObject $organizer */
        $organizer = $this->organizerRepository->findFirstWhere([
            'id' => $dto->organizerId,
            'account_id' => $dto->accountId,
        ]);

        /** @var OrganizerSettingDomainObject $organizerSettings */
        $organizerSettings = $this->organizerSettingsRepository->findFirstWhere([
            'organizer_id' => $organizer->getId(),
        ]);

        $this->organizerSettingsRepository->updateWhere([
            'default_attendee_details_collection_method' => $dto->getProvided(
                'defaultAttendeeDetailsCollectionMethod',
                $organizerSettings->getDefaultAttendeeDetailsCollectionMethod()
            )?->name ?? $organizerSettings->getDefaultAttendeeDetailsCollectionMethod(),

            'default_show_marketing_opt_in' => $dto->getProvided('defaultShowMarketingOptIn')
                ?? $organizerSettings->getDefaultShowMarketingOptIn(),

            'default_pass_platform_fee_to_buyer' => $dto->getProvided('defaultPassPlatformFeeToBuyer')
                ?? $organizerSettings->getDefaultPassPlatformFeeToBuyer(),

            'default_allow_attendee_self_edit' => $dto->getProvided('defaultAllowAttendeeSelfEdit')
                ?? $organizerSettings->getDefaultAllowAttendeeSelfEdit(),

            'social_media_handles' => array_filter([
                'facebook' => $dto->getProvided('facebookHandle', $organizerSettings->getSocialMediaHandle('facebook')),
                'instagram' => $dto->getProvided('instagramHandle', $organizerSettings->getSocialMediaHandle('instagram')),
                'twitter' => $dto->getProvided('twitterHandle', $organizerSettings->getSocialMediaHandle('twitter')),
                'linkedin' => $dto->getProvided('linkedinHandle', $organizerSettings->getSocialMediaHandle('linkedin')),
                'discord' => $dto->getProvided('discordHandle', $organizerSettings->getSocialMediaHandle('discord')),
                'tiktok' => $dto->getProvided('tiktokHandle', $organizerSettings->getSocialMediaHandle('tiktok')),
                'bluesky' => $dto->getProvided('blueskyHandle', $organizerSettings->getSocialMediaHandle('bluesky')),
                'youtube' => $dto->getProvided('youtubeHandle', $organizerSettings->getSocialMediaHandle('youtube')),
                'snapchat' => $dto->getProvided('snapchatHandle', $organizerSettings->getSocialMediaHandle('snapchat')),
                'twitch' => $dto->getProvided('twitchHandle', $organizerSettings->getSocialMediaHandle('twitch')),
                'reddit' => $dto->getProvided('redditHandle', $organizerSettings->getSocialMediaHandle('reddit')),
                'pinterest' => $dto->getProvided('pinterestHandle', $organizerSettings->getSocialMediaHandle('pinterest')),
                'whatsapp' => $dto->getProvided('whatsappHandle', $organizerSettings->getSocialMediaHandle('whatsapp')),
                'telegram' => $dto->getProvided('telegramHandle', $organizerSettings->getSocialMediaHandle('telegram')),
                'vk' => $dto->getProvided('vkHandle', $organizerSettings->getSocialMediaHandle('vk')),
                'weibo' => $dto->getProvided('weiboHandle', $organizerSettings->getSocialMediaHandle('weibo')),
                'wechat' => $dto->getProvided('wechatHandle', $organizerSettings->getSocialMediaHandle('wechat')),
                'flickr' => $dto->getProvided('flickrHandle', $organizerSettings->getSocialMediaHandle('flickr')),
                'tumblr' => $dto->getProvided('tumblrHandle', $organizerSettings->getSocialMediaHandle('tumblr')),
                'quora' => $dto->getProvided('quoraHandle', $organizerSettings->getSocialMediaHandle('quora')),
                'vimeo' => $dto->getProvided('vimeoHandle', $organizerSettings->getSocialMediaHandle('vimeo')),
                'github' => $dto->getProvided('githubHandle', $organizerSettings->getSocialMediaHandle('github')),
            ]),

            'website_url' => $dto->getProvided('websiteUrl', $organizerSettings->getWebsiteUrl()),

            'homepage_visibility' => $dto->getProvided('homepageVisibility', $organizerSettings->getHomepageVisibility()),

            'homepage_theme_settings' => $dto->getProvided('homepageThemeSettings', $organizerSettings->getHomepageThemeSettings()),

            'homepage_sections' => $this->homepageSections($dto, $organizerSettings),

            'seo_keywords' => $dto->getProvided('seoKeywords', $organizerSettings->getSeoKeywords()),
            'seo_title' => $dto->getProvided('seoTitle', $organizerSettings->getSeoTitle()),
            'seo_description' => $dto->getProvided('seoDescription', $organizerSettings->getSeoDescription()),
            'allow_search_engine_indexing' => $dto->getProvided('allowSearchEngineIndexing', $organizerSettings->getAllowSearchEngineIndexing()),

            'homepage_password' => $dto->getProvided('homepagePassword', $organizerSettings->getHomepagePassword()),

            'google_wallet_enabled' => $dto->getProvided('googleWalletEnabled', $organizerSettings->getGoogleWalletEnabled()),
            'apple_wallet_enabled' => $dto->getProvided('appleWalletEnabled', $organizerSettings->getAppleWalletEnabled()),
            'wallet_pass_settings' => $dto->getProvided('walletPassSettings', $organizerSettings->getWalletPassSettings()),

            'tracking_pixels' => $dto->getProvided('trackingPixels', $organizerSettings->getTrackingPixels()),
            'tracking_consent_acknowledged' => $dto->getProvided('trackingConsentAcknowledged', $organizerSettings->getTrackingConsentAcknowledged()),
            'meta_conversions_api_access_token' => $this->resolveMetaConversionsApiAccessToken($dto, $organizerSettings),

            'first_day_of_week' => $dto->getProvided('firstDayOfWeek', $organizerSettings->getFirstDayOfWeek()),
        ], [
            'organizer_id' => $dto->organizerId,
            'id' => $organizerSettings->getId(),
        ]);

        $this->eventSpamCheckDispatchService->dispatchForOrganizer($organizer->getId());

        $updatedSettings = $this->organizerSettingsRepository->findFirst($organizerSettings->getId());

        if ($this->walletBrandingChanged($organizerSettings, $updatedSettings)) {
            $this->walletPassSyncDispatcher->queueOrganizerSync($organizer->getId());
        }

        return $updatedSettings;
    }

    private function resolveMetaConversionsApiAccessToken(
        PartialUpdateOrganizerSettingsDTO $dto,
        OrganizerSettingDomainObject $existingSettings,
    ): ?string {
        if (! $dto->wasProvided('metaConversionsApiAccessToken')) {
            return $existingSettings->getMetaConversionsApiAccessToken();
        }

        if ($dto->metaConversionsApiAccessToken === null) {
            return null;
        }

        return $this->encrypter->encryptString($dto->metaConversionsApiAccessToken);
    }

    private function walletBrandingChanged(
        OrganizerSettingDomainObject $existingSettings,
        OrganizerSettingDomainObject $updatedSettings,
    ): bool {
        return $existingSettings->getGoogleWalletEnabled() !== $updatedSettings->getGoogleWalletEnabled()
            || $existingSettings->getAppleWalletEnabled() !== $updatedSettings->getAppleWalletEnabled()
            || $existingSettings->getWalletPassSettings() !== $updatedSettings->getWalletPassSettings()
            || $existingSettings->getHomepageThemeSettings() !== $updatedSettings->getHomepageThemeSettings();
    }

    private function homepageSections(PartialUpdateOrganizerSettingsDTO $dto, OrganizerSettingDomainObject $organizerSettings): array|string|null
    {
        if (! $dto->wasProvided('homepageSections')) {
            return $organizerSettings->getHomepageSections();
        }

        return $dto->homepageSections === null
            ? null
            : $this->homepageSectionsSanitizer->sanitizeForOrganizer($dto->homepageSections, $dto->organizerId);
    }
}
