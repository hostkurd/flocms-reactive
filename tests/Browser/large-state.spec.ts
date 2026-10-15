import { test, expect } from '@playwright/test';
test('explicit larger state travels through the actual browser transport and renewed snapshot', async ({page}) => {
  await page.goto('/en/demo/large');
  const text = 'ک"'.repeat(16000);
  await page.getByLabel('Description').fill(text);
  await page.getByRole('button',{name:'Accept draft',exact:true}).click();
  await expect(page.locator('[data-bytes]')).toHaveText('48000');
  await expect(page.getByLabel('Description')).toHaveValue(text);
  await page.getByRole('button',{name:'Accept draft',exact:true}).click();
  await expect(page.locator('[data-rx-component]')).toHaveAttribute('aria-busy','false');
  await expect(page.getByLabel('Description')).toHaveValue(text);
});
