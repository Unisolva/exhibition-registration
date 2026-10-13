// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.
// axe-core scans (work order §10): no serious or critical WCAG 2.1 A/AA issue on the public form
// (empty, with errors, thank-you page) and on the staff Save & New screen.

const { test, expect } = require('@playwright/test');
const { fixture, publicFormUrl, seriousViolations } = require('./helpers');

test('public form has no serious or critical accessibility issues', async ({ page }, testInfo) => {
  const f = fixture(testInfo);
  await page.goto(publicFormUrl(f, testInfo));
  expect(await seriousViolations(page)).toEqual([]);
});

test('public form with errors has no serious or critical accessibility issues', async ({ page }, testInfo) => {
  const f = fixture(testInfo);
  await page.goto(publicFormUrl(f, testInfo));
  await page.locator('#f-phone').fill('1');
  await page.waitForTimeout(3200);
  await page.locator('form button[type=submit]').click();
  await expect(page.locator('#f-name')).toHaveAttribute('aria-invalid', 'true');
  expect(await seriousViolations(page)).toEqual([]);
});

test('Save & New screen has no serious or critical accessibility issues', async ({ page }, testInfo) => {
  const f = fixture(testInfo);
  await page.goto(`/staff/entry?exhibition=${f.exhibitionId}`);
  await expect(page.locator('form[data-entry]')).toBeVisible();
  expect(await seriousViolations(page)).toEqual([]);
});
