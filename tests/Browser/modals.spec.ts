import { test, expect, type Page } from '@playwright/test';

async function open(page: Page, key = 'catalog', id = 2) {
  await page.locator(`#rx-${key}-edit-${id}`).click();
  const dialog = page.locator(`#rx-${key}-editor`);
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('[data-rx-bind="editName"]')).toBeFocused();
  return dialog;
}

async function mutate(page: Page, change: string, id = 2) {
  const token = await page.locator('#rx-catalog').getAttribute('data-rx-token');
  const result = await page.request.post('/demo/mutate', { form: { _token: token!, id: String(id), change } });
  expect(result.status()).toBe(204);
}

test('keyboard opens a modal, native Tab stays inside, and Escape discards invalid edits', async ({ page }) => {
  await page.goto('/en/demo');
  const opener = page.locator('#rx-catalog-edit-2');
  await opener.focus();
  await page.keyboard.press('Enter');
  const dialog = page.getByRole('dialog', { name: 'Edit product' });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByLabel('Name', { exact: true })).toBeFocused();
  expect(await dialog.evaluate(dialog => dialog.matches(':modal'))).toBe(true);
  for (let i = 0; i < 8; i++) {
    await page.keyboard.press(i < 4 ? 'Tab' : 'Shift+Tab');
    expect(await page.evaluate(() => Boolean(document.activeElement?.closest('dialog[data-rx-dialog]')))).toBe(true);
  }
  await dialog.getByLabel('Name', { exact: true }).fill('');
  await dialog.getByLabel('Price', { exact: true }).fill('invalid');
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(opener).toBeFocused();
  const reopened = await open(page);
  await expect(reopened.getByLabel('Name', { exact: true })).toHaveValue('Keyboard');
  await expect(reopened.getByLabel('Price', { exact: true })).toHaveValue('50.00');
});

test('save closes the dialog, announces success, and refreshes both independent tables', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (/Content Security Policy|unsafe-eval|Refused to execute/.test(message.text())) errors.push(message.text()); });
  await page.goto('/en/demo');
  const dialog = await open(page);
  await dialog.getByLabel('Name', { exact: true }).fill('Keyboard updated');
  await dialog.getByLabel('Price', { exact: true }).fill('59.95');
  await dialog.getByRole('button', { name: 'Save changes' }).click();
  await expect(dialog).toBeHidden();
  await expect(page.locator('#rx-catalog [data-notice]')).toHaveText('Product changes saved.');
  await expect(page.locator('#rx-catalog-product-2')).toContainText('Keyboard updated');
  await expect(page.locator('#rx-featured-product-2')).toContainText('Keyboard updated');
  await expect(page.locator('#rx-featured-product-2')).toContainText('59.95');
  await expect(page.locator('#rx-catalog-edit-2')).toBeFocused();
  await page.reload();
  await expect(page.locator('#rx-catalog-product-2')).toContainText('59.95');
  expect(errors).toEqual([]);
});

test('editing from a filtered later page preserves independent search and pagination', async ({ page }) => {
  await page.goto('/en/demo?catalog_q=o&catalog_page=2');
  const dialog = await open(page, 'catalog', 10);
  await dialog.getByLabel('Name', { exact: true }).fill('Phone updated');
  await dialog.getByRole('button', { name: 'Save changes' }).click();
  await expect(dialog).toBeHidden();
  await expect(page.locator('#rx-catalog [data-page]')).toHaveText('2 / 2');
  await expect(page.locator('#rx-catalog').getByRole('searchbox')).toHaveValue('o');
  await expect(page.locator('#rx-featured [data-page]')).toHaveText('1 / 2');
  await expect(page).toHaveURL(/catalog_page=2/);
  await expect(page.locator('#rx-featured-product-10')).toContainText('Phone updated');
});

test('field validation keeps a fresh editable dialog and focuses the first invalid input', async ({ page }) => {
  await page.goto('/en/demo');
  const dialog = await open(page);
  await dialog.getByLabel('Name', { exact: true }).fill('');
  await dialog.getByLabel('Price', { exact: true }).fill('1.234');
  const response = page.waitForResponse(response => response.url().includes('/flo-reactive/update') && Boolean(response.request().postData()?.includes('_action=save')));
  await dialog.getByRole('button', { name: 'Save changes' }).click();
  expect((await response).status()).toBe(422);
  await expect(dialog.getByRole('alert')).toHaveCount(2);
  await expect(dialog.getByLabel('Name', { exact: true })).toHaveAttribute('aria-invalid', 'true');
  await expect(dialog.getByLabel('Name', { exact: true })).toBeFocused();
  await dialog.getByLabel('Name', { exact: true }).fill('Corrected');
  await dialog.getByLabel('Price', { exact: true }).fill('1.23');
  await dialog.getByRole('button', { name: 'Save changes' }).click();
  await expect(dialog).toBeHidden();
  await expect(page.locator('#rx-catalog-product-2')).toContainText('Corrected');
  await expect(page.locator('#rx-catalog-product-2')).toContainText('1.23');
});

test('stale versions retain the draft, explain the conflict, and reload current values on reopening', async ({ page }) => {
  await page.goto('/en/demo');
  const dialog = await open(page);
  await mutate(page, 'conflict');
  await dialog.getByLabel('Name', { exact: true }).fill('My stale draft');
  await dialog.getByRole('button', { name: 'Save changes' }).click();
  await expect(dialog.getByRole('alert')).toContainText('changed elsewhere');
  await expect(dialog.locator('[data-rx-error-summary]')).toBeFocused();
  await expect(dialog.getByLabel('Name', { exact: true })).toHaveValue('My stale draft');
  await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
  await expect(dialog).toBeHidden();
  const reopened = await open(page);
  await expect(reopened.getByLabel('Name', { exact: true })).toHaveValue('Changed elsewhere');
});

test('deleted records are not recreated and closing falls back to search when the opener disappeared', async ({ page }) => {
  await page.goto('/en/demo');
  const dialog = await open(page);
  await mutate(page, 'delete');
  await dialog.getByRole('button', { name: 'Save changes' }).click();
  await expect(dialog.getByRole('alert')).toContainText('was deleted');
  await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
  await expect(dialog).toBeHidden();
  await expect(page.locator('#rx-catalog-search')).toBeFocused();
  await expect(page.locator('#rx-catalog-product-2')).toHaveCount(0);
  await page.reload();
  await expect(page.locator('#rx-catalog [data-total]')).toHaveText('11 products');
});

test('revoked save permission is visible inside the dialog and cancellation remains available', async ({ page }) => {
  await page.goto('/en/demo');
  const dialog = await open(page);
  const token = await page.locator('#rx-catalog').getAttribute('data-rx-token');
  await page.request.post('/demo/readonly', { form: { _token: token! } });
  await dialog.getByLabel('Name', { exact: true }).fill('Denied');
  await dialog.getByRole('button', { name: 'Save changes' }).click();
  await expect(dialog.locator('[data-rx-status]')).toContainText('permission');
  await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
  await expect(dialog).toBeHidden();
  await expect(page.locator('#rx-catalog-search')).toBeFocused();
  await expect(page.locator('#rx-catalog-product-2')).toContainText('Keyboard');
  await expect(page.locator('#rx-catalog-edit-2')).toBeDisabled();
});

test('record ownership changes block writes and the draft can still be discarded', async ({ page }) => {
  await page.goto('/en/demo');
  const dialog = await open(page);
  await mutate(page, 'owner');
  await dialog.getByRole('button', { name: 'Save changes' }).click();
  await expect(dialog.locator('[data-rx-status]')).toContainText('permission');
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(page.locator('#rx-catalog-edit-2')).toBeDisabled();
});

test('failed cancel closes locally and restores focus without persisting the draft', async ({ page }) => {
  await page.goto('/en/demo');
  const dialog = await open(page);
  await page.route('**/flo-reactive/update', async route => {
    if (new URLSearchParams(route.request().postData()!).get('_action') === 'cancel') await route.abort();
    else await route.continue();
  });
  await dialog.getByLabel('Name', { exact: true }).fill('Discard this');
  await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
  await expect(dialog).toBeHidden();
  await expect(page.locator('#rx-catalog > [data-rx-status]')).toContainText('failed');
  await expect(page.locator('#rx-catalog-edit-2')).toBeFocused();
  await page.unroute('**/flo-reactive/update');
  await page.reload();
  await expect(page.locator('#rx-catalog-product-2')).toContainText('Keyboard');
});

test('slow saves disable mutation controls, ignore Escape, and do not replay duplicate submits', async ({ page }) => {
  await page.goto('/en/demo');
  const dialog = await open(page);
  let saves = 0;
  await page.route('**/flo-reactive/update', async route => {
    const action = new URLSearchParams(route.request().postData()!).get('_action');
    const response = await route.fetch();
    if (action === 'save') { saves++; await new Promise(resolve => setTimeout(resolve, 700)); }
    await route.fulfill({ response });
  });
  await dialog.getByRole('button', { name: 'Save changes' }).click();
  await expect(dialog.getByLabel('Name', { exact: true })).toBeDisabled();
  await expect(dialog.getByRole('button', { name: 'Cancel', exact: true })).toBeDisabled();
  await page.keyboard.press('Escape');
  await expect(dialog).toBeVisible();
  await dialog.locator('form').evaluate(form => (form as HTMLFormElement).requestSubmit());
  await expect(dialog).toBeHidden();
  expect(saves).toBe(1);
});

test('each table has its own dialog identity and saving featured refreshes catalog', async ({ page }) => {
  await page.goto('/en/demo');
  const catalog = await open(page);
  // Close the visible modal, then mount another component's editor normally.
  await catalog.getByRole('button', { name: 'Cancel', exact: true }).click();
  await expect(catalog).toBeHidden();
  const featured = await open(page, 'featured');
  await featured.getByLabel('Name', { exact: true }).fill('From featured');
  await featured.getByRole('button', { name: 'Save changes' }).click();
  await expect(featured).toBeHidden();
  await expect(page.locator('#rx-catalog-product-2')).toContainText('From featured');
  await expect(page.locator('#rx-featured-edit-2')).toBeFocused();
});

test('refreshing an open editor preserves its draft and sealed version while the table loads newer data', async ({ page }) => {
  await page.goto('/en/demo');
  const dialog = await open(page, 'featured');
  await dialog.getByLabel('Name', { exact: true }).fill('Keep my draft');
  await mutate(page, 'conflict');
  expect(await page.evaluate(() => (window as any).FloReactive.refresh('featured'))).toBe(true);
  await expect(page.locator('#rx-featured-product-2')).toContainText('Changed elsewhere');
  await expect(dialog).toBeVisible();
  await expect(dialog.getByLabel('Name', { exact: true })).toHaveValue('Keep my draft');
  await dialog.getByRole('button', { name: 'Save changes' }).click();
  await expect(dialog.getByRole('alert')).toContainText('changed elsewhere');
});

test('removing a component closes its native dialog and restores page scrolling', async ({ page }) => {
  await page.goto('/en/demo');
  const dialog = await open(page);
  expect(await page.evaluate(() => getComputedStyle(document.documentElement).overflow)).toBe('hidden');
  await page.locator('#rx-catalog').evaluate(root => root.remove());
  await expect(dialog).toHaveCount(0);
  await expect.poll(() => page.evaluate(() => getComputedStyle(document.documentElement).overflow)).not.toBe('hidden');
  await page.getByRole('link', { name: 'English', exact: true }).focus();
  await expect(page.getByRole('link', { name: 'English', exact: true })).toBeFocused();
});

test('mobile Kurdish and Arabic modal labels, field errors, layout and focus restoration work', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  for (const [language, name, error, save, cancel] of [
    ['ku','ناو','ناوی بەرهەم بنووسە.','پاشەکەوتکردنی گۆڕانکارییەکان','پاشگەزبوونەوە'],
    ['ar','الاسم','أدخل اسم المنتج.','حفظ التغييرات','إلغاء'],
  ]) {
    await page.goto(`/${language}/demo`);
    const dialog = await open(page);
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await dialog.getByLabel(name, { exact: true }).fill('');
    await dialog.getByRole('button', { name: save }).click();
    await expect(dialog.getByRole('alert')).toHaveText(error);
    expect(await dialog.evaluate(dialog => dialog.scrollWidth <= dialog.clientWidth)).toBe(true);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await dialog.getByRole('button', { name: cancel, exact: true }).click();
    await expect(dialog).toBeHidden();
    await expect(page.locator('#rx-catalog-edit-2')).toBeFocused();
  }
});
