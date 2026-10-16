// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.
// Every staff screen at 360 and 1280 px (staff screens are English only), with the TEST block active:
// axe finds no serious or critical issue and no landmark ("region") issue (M8 CO-5), and no visible
// text-like control is under 44 px tall or 16 px text (M8 CO-3).

const { test, expect } = require('@playwright/test');
const { fixture, isArabic, adminPage, axeIssues, smallControls } = require('./helpers');

/** [name, path] for every staff screen a super admin can open with a GET. */
function adminScreens(f) {
  return [
    ['home', '/staff'],
    ['entry', `/staff/entry?exhibition=${f.exhibitionId}`],
    ['entry-choose', '/staff/entry?change=1'],
    ['visitors', '/staff/visitors'],
    ['visitor-show-dup', `/staff/visitors/${f.duplicateVisitorId}`],
    ['visitor-show-locked', `/staff/visitors/${f.lockedVisitorId}`],
    ['visitor-edit', `/staff/visitors/${f.visitorId}/edit`],
    ['visitor-trash-confirm', `/staff/visitors/${f.visitorId}/delete`],
    ['trash', '/staff/trash'],
    ['users', '/staff/users'],
    ['user-form', '/staff/users/create'],
    ['user-edit', `/staff/users/${f.staffId}/edit`],
    ['exhibitions', '/staff/exhibitions'],
    ['exhibition-form', '/staff/exhibitions/create'],
    ['exhibition-show', `/staff/exhibitions/${f.exhibitionId}`],
    ['exhibition-edit', `/staff/exhibitions/${f.exhibitionId}/edit`],
    ['poster', `/staff/exhibitions/${f.exhibitionId}/poster`],
    ['batches', '/staff/batches'],
    ['batch-show', `/staff/batches/${f.batchId}`],
    ['erp', '/staff/erp'],
    ['system', '/staff/system'],
    ['settings', '/staff/settings'],
    ['branding', '/staff/settings/branding'],
    ['fields', '/staff/settings/fields'],
    ['field-create', '/staff/settings/fields/create'],
    ['field-edit', `/staff/settings/fields/${f.fieldId}/edit`],
    ['password', '/staff/password'],
  ];
}

const STAFF_SCREENS = [['staffrole-home', '/staff'], ['staffrole-entry', '/staff/entry?change=1'], ['staffrole-visitors', '/staff/visitors']];

// The A4 poster is a print page without the staff header.
const WITHOUT_HEADER = ['poster'];

async function checkScreen(page, name, url) {
  const response = await page.goto(url);
  expect(response.status(), `${name} status`).toBe(200);
  if (!WITHOUT_HEADER.includes(name)) {
    await expect(page.locator('header .test-banner'), `${name} TEST banner`).toBeVisible();
  }
  expect(await axeIssues(page), `${name} axe`).toEqual([]);
  expect(await smallControls(page), `${name} controls`).toEqual([]);
}

test('every staff screen as super admin: no serious, critical or landmark issue, controls at least 44 px and 16 px', async ({ browser }, testInfo) => {
  test.skip(isArabic(testInfo), 'staff screens are English only');
  test.setTimeout(240000);
  const f = fixture(testInfo);
  const page = await adminPage(browser, testInfo);
  for (const [name, url] of adminScreens(f)) {
    await checkScreen(page, name, url);
  }
  await page.context().close();
});

test('staff-role screens: the same checks', async ({ page }, testInfo) => {
  test.skip(isArabic(testInfo), 'staff screens are English only');
  for (const [name, url] of STAFF_SCREENS) {
    await checkScreen(page, name, url);
  }
});
