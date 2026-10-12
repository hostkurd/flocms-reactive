import { test, expect } from '@playwright/test';

test('search, pagination, clear, and focused input work under strict CSP', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => {
    if (message.type() === 'error' && /Content Security Policy|unsafe-eval|Refused to execute/.test(message.text())) errors.push(message.text());
  });
  await page.goto('/en/demo');
  const catalog = page.locator('#rx-catalog');
  await expect(catalog.locator('[data-page]')).toHaveText('1 / 3');
  await catalog.getByRole('button', { name: 'Next', exact: true }).click();
  await expect(catalog.locator('[data-page]')).toHaveText('2 / 3');
  const search = catalog.getByRole('searchbox');
  await search.fill('Laptop');
  await expect(catalog.locator('tbody tr')).toHaveCount(1);
  await expect(search).toBeFocused();
  await expect(catalog.locator('[data-page]')).toHaveText('1 / 1');
  await expect(page).toHaveURL(/catalog_q=Laptop/);
  await expect(catalog.getByRole('button', { name: 'Next', exact: true })).toBeDisabled();
  await catalog.getByRole('button', { name: 'Clear', exact: true }).click();
  await expect(search).toHaveValue('');
  await expect(catalog.locator('[data-page]')).toHaveText('1 / 3');
  expect(errors).toEqual([]);
});

test('two tables keep independent state and URL parameters', async ({ page }) => {
  await page.goto('/en/demo');
  const catalog = page.locator('#rx-catalog');
  const featured = page.locator('#rx-featured');
  await catalog.getByRole('searchbox').fill('Laptop');
  await featured.getByRole('searchbox').fill('Keyboard');
  await expect(catalog.locator('tbody')).toContainText('Laptop');
  await expect(featured.locator('tbody')).toContainText('Keyboard');
  await expect(page).toHaveURL(/catalog_q=Laptop/);
  await expect(page).toHaveURL(/featured_q=Keyboard/);
  await page.reload();
  await expect(catalog.getByRole('searchbox')).toHaveValue('Laptop');
  await expect(featured.getByRole('searchbox')).toHaveValue('Keyboard');
});

test('newer edits supersede a slow result and show loading state', async ({ page }) => {
  await page.goto('/en/demo');
  await page.route('**/flo-reactive/update', async route => {
    const response = await route.fetch();
    await new Promise(resolve => setTimeout(resolve, 700));
    await route.fulfill({ response });
  });
  const catalog = page.locator('#rx-catalog');
  const search = catalog.getByRole('searchbox');
  await search.fill('Laptop');
  await expect(catalog).toHaveAttribute('aria-busy', 'true');
  await search.fill('Keyboard');
  await expect(catalog.locator('tbody')).toContainText('Keyboard');
  await expect(search).toHaveValue('Keyboard');
  await expect(catalog.locator('tbody')).not.toContainText('Laptop');
  await expect(search).toBeFocused();
});

test('empty result and URL reload remain consistent', async ({ page }) => {
  await page.goto('/en/demo?catalog_page=3');
  const catalog = page.locator('#rx-catalog');
  await expect(catalog.locator('[data-page]')).toHaveText('3 / 3');
  await catalog.getByRole('searchbox').fill('no-such-product');
  await expect(catalog.locator('[data-empty]')).toHaveText('No products found.');
  await expect(catalog.locator('[data-page]')).toHaveText('1 / 1');
});

test('Arabic and Kurdish direction and unicode searches work', async ({ page }) => {
  for (const [language, search] of [['ar', 'فیلا'], ['ku', 'ڕۆشنایی']]) {
    await page.goto(`/${language}/demo`);
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await page.locator('#rx-catalog').getByRole('searchbox').fill(search);
    await expect(page.locator('#rx-catalog tbody')).toContainText(search);
    await expect(page.locator('#rx-catalog tbody tr')).toHaveCount(1);
  }
});

test('permission loss is enforced on the next component request', async ({ page }) => {
  await page.goto('/en/demo');
  const token = await page.locator('#rx-catalog').getAttribute('data-rx-token');
  await page.request.post('/demo/permission', { form: { _token: token! } });
  await page.locator('#rx-catalog').getByRole('searchbox').fill('Laptop');
  await expect(page.locator('#rx-catalog [data-rx-status]')).toContainText('permission');
  await expect(page.locator('#rx-catalog [data-page]')).toHaveText('1 / 3');
});

test('expired session token displays a recoverable error', async ({ page }) => {
  await page.goto('/en/demo');
  const token = await page.locator('#rx-catalog').getAttribute('data-rx-token');
  await page.request.post('/demo/expire', { form: { _token: token! } });
  await page.locator('#rx-catalog').getByRole('searchbox').fill('Laptop');
  await expect(page.locator('#rx-catalog [data-rx-status]')).toContainText('expired');
});

test('transport failure can be retried without replaying an action', async ({ page }) => {
  await page.goto('/en/demo');
  await page.route('**/flo-reactive/update', route => route.abort());
  const catalog = page.locator('#rx-catalog');
  await catalog.getByRole('searchbox').fill('Laptop');
  await expect(catalog.locator('[data-rx-status]')).toContainText('failed');
  await page.unroute('**/flo-reactive/update');
  await catalog.getByRole('button', { name: 'Search products', exact: true }).click();
  await expect(catalog.locator('tbody tr')).toHaveCount(1);
});

test('compiled assets and update responses bypass the page layout', async ({ page }) => {
  await page.goto('/en/demo');
  const asset = await page.locator('script[src]').getAttribute('src');
  const response = await page.request.get(asset!);
  expect(response.headers()['content-type']).toContain('javascript');
  expect(response.headers()['cache-control']).toContain('immutable');
  const data = await page.locator('#rx-catalog').evaluate(el => ({ snapshot: (el as HTMLElement).dataset.rxSnapshot!, token: (el as HTMLElement).dataset.rxToken! }));
  const update = await page.request.post('/en/flo-reactive/update', { form: { _snapshot: data.snapshot, _token: data.token } });
  expect(update.headers()['cache-control']).toBe('no-store');
  expect(await update.text()).not.toContain('<html');
  expect((await page.request.get('/en/flo-reactive/asset/..%2Fcomposer.json')).status()).toBe(404);
});

test('invalid state returns a rendered validation error without running an action', async ({ page }) => {
  await page.goto('/en/demo');
  const catalog = page.locator('#rx-catalog');
  await catalog.getByRole('searchbox').evaluate(input => input.removeAttribute('maxlength'));
  const response = page.waitForResponse(response => response.url().includes('/flo-reactive/update'));
  await catalog.getByRole('searchbox').fill('x'.repeat(101));
  expect((await response).status()).toBe(422);
  await expect(catalog.getByRole('alert')).toBeVisible();
  await expect(catalog.locator('[data-total]')).toHaveText('12 products');
  await expect(catalog.getByRole('button', { name: 'Clear', exact: true })).toBeEnabled();
});

test('mobile RTL layout fits and search is keyboard accessible', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/ku/demo');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  const input = page.locator('#rx-catalog').getByRole('searchbox');
  await input.focus();
  await page.keyboard.type('Laptop');
  await expect(page.locator('#rx-catalog tbody tr')).toHaveCount(1);
  await expect(input).toBeFocused();
});
