import { test, expect } from '../../fixtures';
import { OrganizerPage } from '../../pages/organizer.page';
import { createFreshOrganizer } from '../../api/factory';

test.describe('organizer wallet pass settings', () => {
  test('an organizer enables Google Wallet and Apple Wallet passes with shared branding', async ({ authedPage, api }) => {
    const seeded = await createFreshOrganizer(api);
    const bannerUrl = 'https://example.com/e2e-wallet-banner.png';
    const appleStripUrl = 'https://example.com/e2e-apple-wallet-strip.png';

    const organizer = new OrganizerPage(authedPage);
    await organizer.gotoSettings(seeded.id);

    await expect(organizer.googleWalletEnabledSwitch).not.toBeChecked();
    await expect(organizer.appleWalletEnabledSwitch).not.toBeChecked();
    await organizer.googleWalletEnabledSwitch.check({ force: true });
    await organizer.appleWalletEnabledSwitch.check({ force: true });
    await organizer.walletPassBannerInput.fill(bannerUrl);
    await organizer.walletPassAppleStripInput.fill(appleStripUrl);
    await organizer.saveWalletPassSettings();
    await expect(authedPage.getByText('Successfully Updated Wallet Pass Settings')).toBeVisible();

    await authedPage.reload();
    await authedPage.waitForLoadState('networkidle');

    await expect(organizer.googleWalletEnabledSwitch).toBeChecked();
    await expect(organizer.appleWalletEnabledSwitch).toBeChecked();
    await expect(organizer.walletPassBannerInput).toHaveValue(bannerUrl);
    await expect(organizer.walletPassAppleStripInput).toHaveValue(appleStripUrl);
  });
});
