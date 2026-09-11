import { test, expect } from '../../fixtures';
import { OrganizerPage } from '../../pages/organizer.page';
import { createFreshOrganizer } from '../../api/factory';

test.describe('organizer google wallet settings', () => {
  test('an organizer enables Google Wallet passes and sets pass branding', async ({ authedPage, api }) => {
    const seeded = await createFreshOrganizer(api);
    const bannerUrl = 'https://example.com/e2e-wallet-banner.png';

    const organizer = new OrganizerPage(authedPage);
    await organizer.gotoSettings(seeded.id);

    await expect(organizer.googleWalletEnabledSwitch).not.toBeChecked();
    await organizer.googleWalletEnabledSwitch.check({ force: true });
    await organizer.googleWalletBannerInput.fill(bannerUrl);
    await organizer.saveGoogleWalletSettings();
    await expect(authedPage.getByText('Successfully Updated Google Wallet Settings')).toBeVisible();

    await authedPage.reload();
    await authedPage.waitForLoadState('networkidle');

    await expect(organizer.googleWalletEnabledSwitch).toBeChecked();
    await expect(organizer.googleWalletBannerInput).toHaveValue(bannerUrl);
  });
});
