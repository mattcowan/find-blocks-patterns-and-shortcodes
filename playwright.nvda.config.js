/**
 * Playwright config for the screen-reader (NVDA) journeys in tests/e2e-sr.
 *
 * Separate from any main Playwright config on purpose: these tests drive a
 * real, headed Firefox with a silent portable NVDA attached (Guidepup), so
 * they cannot run in parallel, cannot run headless, and take the keyboard
 * while they run. Run them with `npm run test:sr` on demand.
 *
 * Prerequisites (once per machine):
 *   npx @guidepup/setup setup --ci
 *   npx @guidepup/setup install nvda
 *   npx playwright install firefox
 *
 * Project block: edit these few lines per project instead of the config body.
 *   baseURL     — the app or site under test (QA_BASE_URL overrides)
 *   storageState — a saved login (WordPress: written by global-setup.js), or null
 *   globalSetup — a login script, or null
 *   webServer   — how to start the app if it is not already running, or null
 */
try { require('dotenv').config(); } catch (e) { /* dotenv is optional */ }
const { defineConfig, devices } = require('@playwright/test');
const { screenReaderConfig } = require('@guidepup/playwright');

const project = {
  // The WAMP site this plugin is developed against. WP_BASE_URL overrides it.
  baseURL: process.env.QA_BASE_URL || process.env.WP_BASE_URL || 'http://typography-stylist:8080',
  storageState: 'tests/e2e-sr/auth.json',
  globalSetup: require.resolve('./tests/e2e-sr/global-setup.js'),
  // WAMP is already running; there is no dev server to start.
  webServer: null,
};

module.exports = defineConfig({
  ...screenReaderConfig,
  testDir: './tests/e2e-sr',
  testMatch: /.*\.sr\.spec\.js/,
  timeout: 5 * 60 * 1000,
  reportSlowTests: null,
  retries: 0,
  forbidOnly: !!process.env.CI,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report-sr' }]],

  use: {
    ...screenReaderConfig.use,
    baseURL: project.baseURL,
    ...(project.storageState ? { storageState: project.storageState } : {}),
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
    viewport: { width: 1400, height: 900 },
  },

  projects: [
    {
      name: 'firefox-nvda',
      use: { ...devices['Desktop Firefox'], headless: false },
    },
  ],

  ...(project.globalSetup ? { globalSetup: project.globalSetup } : {}),
  ...(project.webServer ? { webServer: project.webServer } : {}),
});
