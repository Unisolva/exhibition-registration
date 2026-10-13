// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.
// Browser tests (work order §10): 360 px and 1280 px, Arabic and English, against a running local server.
// BASE_URL is the app's APP_URL; FIXTURES_FILE is the output of tests/Browser/setup.php.

const path = require('path');
const { defineConfig, devices } = require('@playwright/test');

const project = (name, width, locale) => ({
  name,
  use: {
    ...devices['Desktop Chrome'],
    viewport: { width, height: width === 360 ? 740 : 900 },
    locale,
    storageState: path.join(__dirname, '.auth', `${name}.json`),
  },
});

module.exports = defineConfig({
  testDir: '.',
  testMatch: /.*\.spec\.js/,
  globalSetup: require.resolve('./global-setup.js'),
  timeout: 60000,
  fullyParallel: false,
  workers: 2,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:8000',
    trace: 'retain-on-failure',
  },
  projects: [
    project('ar-360', 360, 'ar-EG'),
    project('en-360', 360, 'en-GB'),
    project('ar-1280', 1280, 'ar-EG'),
    project('en-1280', 1280, 'en-GB'),
  ],
});
