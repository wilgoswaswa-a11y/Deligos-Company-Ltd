/** Playwright test config for Deligos POS E2E */
const { devices } = require('@playwright/test');

module.exports = {
  testDir: 'tests/e2e',
  timeout: 60000,
  expect: { timeout: 5000 },
  fullyParallel: false,
  retries: 0,
  use: {
    headless: true,
    baseURL: process.env.BASE_URL || 'http://localhost/pos',
    viewport: { width: 1280, height: 720 },
    actionTimeout: 10000,
    trace: 'on-first-retry'
  }
};
