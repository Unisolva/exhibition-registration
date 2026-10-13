// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.
// Signs each project's staff user in once and saves the session, so the tests stay well inside
// the login rate limits (work order §7: 5 per minute per email and address).

const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');

module.exports = async (config) => {
  const baseURL = config.projects[0].use.baseURL;
  const file = process.env.FIXTURES_FILE || path.join(__dirname, 'fixtures.json');
  const fixtures = JSON.parse(fs.readFileSync(file, 'utf8'));
  fs.mkdirSync(path.join(__dirname, '.auth'), { recursive: true });

  const browser = await chromium.launch();
  for (const [name, f] of Object.entries(fixtures.projects)) {
    const context = await browser.newContext({ baseURL });
    const page = await context.newPage();
    await page.goto('/staff/login');
    await page.locator('#email').fill(f.staffEmail);
    await page.locator('#password').fill(f.staffPassword);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL(/\/staff$/);
    await context.storageState({ path: path.join(__dirname, '.auth', `${name}.json`) });
    await context.close();
  }
  await browser.close();
};
