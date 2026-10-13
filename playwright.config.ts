import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: 'tests/Browser',
  workers: 1,
  use: { baseURL: 'http://127.0.0.1:8080', headless: true,
    launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE } : {},
  },
  webServer: {
    command: 'FLO_REACTIVE_TESTING=1 php -S 127.0.0.1:8080 examples/products/router.php',
    url: 'http://127.0.0.1:8080/en/demo', reuseExistingServer: false,
  },
});
