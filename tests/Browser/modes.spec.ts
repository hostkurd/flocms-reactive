import { test, expect, type Page, type Request } from '@playwright/test';

const isUpdate = (request: Request) => request.url().includes('/flo-reactive/update') && request.method() === 'POST';
const action = (request: Request) => new URLSearchParams(request.postData() ?? '').get('_action');

function countUpdates(page: Page): { count: () => number } {
  let count = 0;
  page.on('request', request => { if (isUpdate(request) && action(request) !== '$renew') count++; });
  return { count: () => count };
}

test('live, debounced, lazy and blur bindings send at their own moment', async ({ page }) => {
  await page.goto('/en/demo/contact');
  const form = page.locator('#rx-contact');
  const updates = countUpdates(page);

  await page.getByLabel('Name').pressSequentially('Sara', { delay: 40 });
  await expect(form.locator('[data-server-name]')).toHaveText('Sara');
  expect(updates.count()).toBe(1);

  await page.getByLabel('Topic').pressSequentially('Villa', { delay: 40 });
  await page.waitForTimeout(450);
  expect(updates.count()).toBe(1);
  await expect(form.locator('[data-server-topic]')).toHaveText('Villa');
  expect(updates.count()).toBe(2);

  await page.getByLabel('Email').fill('sara@example.test');
  await page.waitForTimeout(500);
  expect(updates.count()).toBe(2);
  await page.getByLabel('Email').press('Tab');
  await expect(form.locator('[data-server-email]')).toHaveText('sara@example.test');
  expect(updates.count()).toBe(3);

  // Blur: only an edited field sends; moving through it does not.
  await page.getByLabel('City').focus();
  await page.getByLabel('City').press('Tab');
  await page.waitForTimeout(400);
  expect(updates.count()).toBe(3);
  await page.getByLabel('City').fill('Athens');
  await page.waitForTimeout(400);
  expect(updates.count()).toBe(3);
  await page.getByLabel('City').blur();
  await expect(form.locator('[data-server-city]')).toHaveText('Athens');
  expect(updates.count()).toBe(4);
  await expect(page.getByLabel('Name')).toHaveValue('Sara');
});

test('a deferred field travels with the next update and survives responses while unsent', async ({ page }) => {
  await page.goto('/en/demo/contact');
  const form = page.locator('#rx-contact');
  const updates = countUpdates(page);
  const message = page.getByLabel('Message');
  await message.fill('Hello');
  await message.press('Tab');
  await page.waitForTimeout(500);
  expect(updates.count()).toBe(0);

  // Slow the next response down and keep typing into the deferred field while it runs.
  await page.route('**/flo-reactive/update', async route => { await new Promise(done => setTimeout(done, 700)); await route.continue(); });
  const sent = page.waitForRequest(isUpdate);
  await page.getByLabel('Name').fill('Sara');
  expect(new URLSearchParams((await sent).postData() ?? '').get('state[message]')).toBe('Hello');
  await message.fill('Hello, is the villa free?');
  await expect(form.locator('[data-server-message]')).toHaveText('Hello');
  await expect(form).toHaveAttribute('aria-busy', 'false');
  await expect(message).toHaveValue('Hello, is the villa free?');
  await page.unroute('**/flo-reactive/update');

  await page.getByLabel('Email').fill('sara@example.test');
  await page.getByRole('button', { name: 'Send' }).click();
  await expect(form.locator('[data-sent]')).toHaveText('Message sent.');
  await expect(form.locator('[data-server-message]')).toHaveText('Hello, is the villa free?');
});

test('validation waits for Send, and Clear works while the form has errors', async ({ page }) => {
  await page.goto('/en/demo/contact');
  const form = page.locator('#rx-contact');
  await page.getByLabel('Name').fill('S');
  await expect(form.locator('[data-server-name]')).toHaveText('S');
  await expect(form.getByRole('alert')).toHaveCount(0);

  const response = page.waitForResponse(response => isUpdate(response.request()));
  await page.getByRole('button', { name: 'Send' }).click();
  expect((await response).status()).toBe(422);
  await expect(form.getByRole('alert')).toHaveText(['Enter an email address.', 'Write a message.']);
  await expect(page.getByLabel('Email')).toHaveAttribute('aria-invalid', 'true');

  await page.getByRole('button', { name: 'Clear' }).click();
  await expect(page.getByLabel('Name')).toHaveValue('');
  await expect(form.locator('[data-server-name]')).toHaveText('');
  await expect(form.getByRole('alert')).toHaveCount(0);
});

test('long sessions renew the snapshot in the background and keep working', async ({ page }) => {
  await page.clock.install();
  await page.goto('/en/demo/contact');
  const form = page.locator('#rx-contact');
  const renewed = page.waitForResponse(response => isUpdate(response.request()) && action(response.request()) === '$renew');
  await page.clock.fastForward('16:00');
  const response = await renewed;
  expect(response.status()).toBe(200);
  expect(typeof (await response.json()).snapshot).toBe('string');
  // Renewal does not re-render or touch what is on screen.
  await expect(form.locator('[data-updates]')).toHaveText('1');

  const next = page.waitForRequest(request => isUpdate(request) && action(request) === 'refresh');
  await page.getByLabel('Name').fill('Sara');
  await page.clock.runFor(400);
  const snapshot = new URLSearchParams((await next).postData() ?? '').get('_snapshot');
  expect(snapshot).toBe((await response.json()).snapshot);
  await expect(form.locator('[data-server-name]')).toHaveText('Sara');
});

test('runtime messages follow the page language when a session cannot be renewed', async ({ page }) => {
  await page.clock.install();
  await page.goto('/ku/demo/contact');
  const form = page.locator('#rx-contact');
  const token = await form.getAttribute('data-rx-token');
  await page.request.post('/demo/expire', { form: { _token: token! } });
  const renewed = page.waitForResponse(response => isUpdate(response.request()) && action(response.request()) === '$renew');
  await page.clock.fastForward('16:00');
  expect((await renewed).status()).toBe(419);
  await expect(form.locator(':scope > [data-rx-status]')).toHaveText('کاتی ئەم پەڕەیە بەسەرچوو. پەڕەکە دووبارە بار بکەرەوە.');
});

test('clicking Send straight from a lazy field queues Send once behind that field update', async ({ page }) => {
  await page.goto('/en/demo/contact');
  const form = page.locator('#rx-contact');
  await page.getByLabel('Name').fill('Sara');
  await page.getByLabel('Message').fill('Hello');
  await expect(form.locator('[data-server-name]')).toHaveText('Sara');
  const sends: string[] = [];
  page.on('request', request => { if (isUpdate(request)) sends.push(action(request) ?? ''); });
  await page.route('**/flo-reactive/update', async route => { await new Promise(done => setTimeout(done, 500)); await route.continue(); });
  await page.getByLabel('Email').fill('sara@example.test');
  // Leaving Email starts its lazy update; both clicks land while it runs.
  await page.getByRole('button', { name: 'Send' }).dblclick();
  await expect(form.locator('[data-sent]')).toHaveText('Message sent.');
  await expect(form).toHaveAttribute('aria-busy', 'false');
  expect(sends).toEqual(['refresh', 'send']);
});

test('data-rx-ignore keeps widget DOM across updates until its key changes', async ({ page }) => {
  await page.goto('/en/demo/contact');
  const form = page.locator('#rx-contact');
  await page.locator('[data-widget]').evaluate(element => { const mark = document.createElement('span'); mark.dataset.mark = ''; mark.textContent = 'Added by a widget'; element.append(mark); });
  await page.getByLabel('Name').fill('Sara');
  await expect(form.locator('[data-server-name]')).toHaveText('Sara');
  await expect(page.locator('[data-widget] [data-mark]')).toHaveText('Added by a widget');
  await page.getByLabel('Email').fill('sara@example.test');
  await page.getByLabel('Message').fill('Hello');
  await page.getByRole('button', { name: 'Send' }).click();
  await expect(form.locator('[data-sent]')).toBeVisible();
  await expect(page.locator('[data-widget]')).toHaveAttribute('data-rx-ignore', 'sent');
  await expect(page.locator('[data-widget] [data-mark]')).toHaveCount(0);
});
