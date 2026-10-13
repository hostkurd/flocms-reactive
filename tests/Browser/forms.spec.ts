import { test, expect } from '@playwright/test';

test('dependent cities reset, empty countries explain their state, and select focus survives', async ({ page }) => {
  await page.goto('/en/demo/form');
  const country = page.getByLabel('Country', { exact: true });
  const city = page.getByLabel('City', { exact: true });
  await expect(city).toBeDisabled();
  await country.selectOption('1');
  await expect(city.locator('option')).toHaveCount(3);
  await expect(city.locator('option')).toContainText(['Choose a city', 'Sulaymaniyah', 'Erbil']);
  await city.selectOption('2');
  await expect(page.locator('#rx-address')).toHaveAttribute('aria-busy', 'false');
  await country.focus();
  await country.selectOption('2');
  await expect(city).toHaveValue('0');
  await expect(city.locator('option')).toContainText(['Choose a city', 'Athens', 'Thessaloniki']);
  await expect(country).toBeFocused();
  await country.selectOption('3');
  await expect(city).toBeDisabled();
  await expect(page.locator('#location-city-help')).toHaveText('No cities are available for this country.');
});

test('save returns accessible field errors, accepts a corrected draft, and persists after reload', async ({ page }) => {
  await page.goto('/en/demo/form');
  const form = page.locator('#rx-address');
  const response = page.waitForResponse(response => response.url().includes('/flo-reactive/update'));
  await page.getByRole('button', { name: 'Save address' }).click();
  expect((await response).status()).toBe(422);
  await expect(form.getByRole('alert')).toHaveCount(3);
  await expect(page.getByLabel('Full name')).toHaveAttribute('aria-invalid', 'true');
  await expect(page.getByLabel('Full name')).toHaveAttribute('aria-describedby', 'location-name-error');
  await page.getByLabel('Full name').fill('Khalat Jalal');
  await page.getByLabel('Country', { exact: true }).selectOption('1');
  await expect(page.getByLabel('City', { exact: true })).toBeEnabled();
  await page.getByLabel('City', { exact: true }).selectOption('2');
  await page.getByRole('button', { name: 'Save address' }).click();
  await expect(form.locator('[data-saved]')).toHaveText('Your address has been saved.');
  await expect(form.getByRole('alert')).toHaveCount(0);
  await expect(page).toHaveURL(/\/en\/demo\/form$/);
  await page.reload();
  await expect(page.getByLabel('Full name')).toHaveValue('Khalat Jalal');
  await expect(page.getByLabel('Country', { exact: true })).toHaveValue('1');
  await expect(page.getByLabel('City', { exact: true })).toHaveValue('2');
});

test('submit reads autofilled controls even without input events', async ({ page }) => {
  await page.goto('/en/demo/form');
  await page.getByLabel('Country', { exact: true }).selectOption('1');
  await expect(page.getByLabel('City', { exact: true })).toBeEnabled();
  await page.getByLabel('Full name').evaluate(input => { (input as HTMLInputElement).value = 'Autofilled name'; });
  await page.getByLabel('City', { exact: true }).evaluate(select => { (select as HTMLSelectElement).value = '1'; });
  await page.getByRole('button', { name: 'Save address' }).click();
  await expect(page.locator('[data-saved]')).toBeVisible();
  await page.reload();
  await expect(page.getByLabel('Full name')).toHaveValue('Autofilled name');
  await expect(page.getByLabel('City', { exact: true })).toHaveValue('1');
});

test('newer country choice supersedes slow options without stale selection', async ({ page }) => {
  await page.goto('/en/demo/form');
  await page.route('**/flo-reactive/update', async route => {
    const response = await route.fetch();
    await new Promise(resolve => setTimeout(resolve, 700));
    await route.fulfill({ response });
  });
  const country = page.getByLabel('Country', { exact: true });
  await country.selectOption('1');
  await expect(page.locator('#rx-address')).toHaveAttribute('aria-busy', 'true');
  await country.selectOption('2');
  await expect(page.getByLabel('City', { exact: true }).locator('option')).toContainText(['Choose a city', 'Athens', 'Thessaloniki']);
  await expect(country).toHaveValue('2');
  await expect(page.getByLabel('City', { exact: true })).toHaveValue('0');
});

test('submit stays disabled during a save and repeated submits do not replay it', async ({ page }) => {
  await page.goto('/en/demo/form');
  await page.getByLabel('Full name').fill('Khalat');
  await page.getByLabel('Country', { exact: true }).selectOption('1');
  await expect(page.getByLabel('City', { exact: true })).toBeEnabled();
  await page.getByLabel('City', { exact: true }).selectOption('1');
  let saves = 0;
  await page.route('**/flo-reactive/update', async route => {
    const data = new URLSearchParams(route.request().postData()!);
    const response = await route.fetch();
    if (data.get('_action') === 'save') {
      saves++;
      await new Promise(resolve => setTimeout(resolve, 700));
    }
    await route.fulfill({ response });
  });
  const button = page.getByRole('button', { name: 'Save address' });
  await button.click();
  await expect(button).toBeDisabled();
  await page.locator('form').evaluate(form => (form as HTMLFormElement).requestSubmit());
  await expect(page.locator('[data-saved]')).toBeVisible();
  await expect(button).toBeEnabled();
  expect(saves).toBe(1);
});

test('forged city from another country is rejected by the server and never persisted', async ({ page }) => {
  await page.goto('/en/demo/form');
  await page.getByLabel('Country', { exact: true }).selectOption('1');
  await expect(page.getByLabel('City', { exact: true })).toBeEnabled();
  const data = await page.locator('#rx-address').evaluate(el => ({ snapshot: (el as HTMLElement).dataset.rxSnapshot!, token: (el as HTMLElement).dataset.rxToken! }));
  const response = await page.request.post('/en/flo-reactive/update', { form: {
    _snapshot: data.snapshot, _token: data.token, _action: 'save',
    'state[name]': 'Forged', 'state[countryId]': '1', 'state[cityId]': '3',
  } });
  expect(response.status()).toBe(422);
  expect(await response.text()).toContain('Choose a city belonging to the selected country.');
  await page.reload();
  await expect(page.getByLabel('Full name')).toHaveValue('');
});

test('save permission loss blocks persistence and read-only submit remains disabled after updates', async ({ page }) => {
  await page.goto('/en/demo/form');
  await page.getByLabel('Full name').fill('Khalat');
  await page.getByLabel('Country', { exact: true }).selectOption('1');
  await expect(page.getByLabel('City', { exact: true })).toBeEnabled();
  await page.getByLabel('City', { exact: true }).selectOption('1');
  const token = await page.locator('#rx-address').getAttribute('data-rx-token');
  await page.request.post('/demo/readonly', { form: { _token: token! } });
  await page.getByRole('button', { name: 'Save address' }).click();
  await expect(page.locator('[data-rx-status]')).toContainText('permission');
  await page.reload();
  await expect(page.getByLabel('Full name')).toHaveValue('');
  const button = page.getByRole('button', { name: 'Save address' });
  await expect(button).toBeDisabled();
  await page.getByLabel('Country', { exact: true }).selectOption('1');
  await expect(page.getByLabel('City', { exact: true })).toBeEnabled();
  await expect(button).toBeDisabled();
});

test('Arabic and Kurdish form errors, labels, keyboard input and mobile RTL fit', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  for (const [language, error, saved] of [
    ['ku', 'ناوی تەواوت بنووسە.', 'ناونیشانەکەت پاشەکەوت کرا.'],
    ['ar', 'أدخل اسمك الكامل.', 'تم حفظ عنوانك.'],
  ]) {
    await page.goto(`/${language}/demo/form`);
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    // Use a fresh incomplete draft when switching languages in the same session.
    await page.locator('#location-name').fill('');
    await page.locator('#location-country').selectOption('0');
    await page.getByRole('button', { name: language === 'ku' ? 'پاشەکەوتکردنی ناونیشان' : 'حفظ العنوان' }).click();
    await expect(page.locator('#location-name-error')).toHaveText(error);
    await page.locator('label[for="location-name"]').click();
    await expect(page.locator('#location-name')).toBeFocused();
    await page.keyboard.type(language === 'ku' ? 'خەڵات جەلال' : 'خالد');
    await page.locator('#location-country').selectOption('1');
    await expect(page.locator('#location-city')).toBeEnabled();
    await page.locator('#location-city').selectOption('1');
    await page.locator('button[type="submit"]').click();
    await expect(page.locator('[data-saved]')).toHaveText(saved);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  }
});
