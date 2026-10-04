import { test, expect } from '../../fixtures';
import { RegisterPage } from '../../pages/register.page';
import { uniqueEmail } from '../../utils/unique';
import { IS_SAAS_MODE } from '../../utils/env';

const CONFIRMATION_SUBJECT = IS_SAAS_MODE ? 'Your confirmation code for Hi.Events' : 'Welcome to Hi.Events';

test.describe('registration', () => {
  test('a new organizer can register and reach the welcome page', { tag: '@smoke' }, async ({ page, mailpit }) => {
    const email = uniqueEmail();

    const register = new RegisterPage(page);
    await register.goto();
    await register.register({ firstName: 'New', lastName: 'Organizer', email, password: 'Password123!' });

    await expect(page).toHaveURL(/\/welcome/);

    const confirmationEmail = await mailpit.waitForMessage(email, { subjectContains: CONFIRMATION_SUBJECT });
    expect(confirmationEmail.Subject).toContain(CONFIRMATION_SUBJECT);
  });
});
