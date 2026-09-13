import { test, expect } from '../../fixtures';
import { OrganizerPage } from '../../pages/organizer.page';
import { createFreshOrganizer } from '../../api/factory';

test.describe('organizer apple wallet settings', () => {
  test('an organizer enables Apple Wallet passes and sets pass branding', async ({ authedPage, api }) => {
    const seeded = await createFreshOrganizer(api);
    const stripImageUrl = 'https://example.com/e2e-wallet-strip.png';

    const organizer = new OrganizerPage(authedPage);
    await organizer.gotoSettings(seeded.id);

    await expect(organizer.appleWalletEnabledSwitch).not.toBeChecked();
    await organizer.appleWalletEnabledSwitch.check({ force: true });
    await organizer.appleWalletStripImageInput.fill(stripImageUrl);
    await organizer.saveAppleWalletSettings();
    await expect(authedPage.getByText('Successfully Updated Apple Wallet Settings')).toBeVisible();

    await authedPage.reload();
    await authedPage.waitForLoadState('networkidle');

    await expect(organizer.appleWalletEnabledSwitch).toBeChecked();
    await expect(organizer.appleWalletStripImageInput).toHaveValue(stripImageUrl);
  });
});
