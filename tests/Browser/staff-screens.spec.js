// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.
// Every staff screen at 360 and 1280 px (staff screens are English only), with the TEST block active (M8 §4):
// axe finds no serious or critical issue and no landmark ("region") issue (CO-5); no horizontal overflow;
// no interactive element under 24 x 24 px; no visible text-like control under 44 px tall or 16 px text (CO-3).
// Plus the navigation (CO-13), the stacked tables (CO-12) and the focus ring (CO-11).

const { test, expect } = require('@playwright/test');
const { fixture, isArabic, adminPage, axeIssues, smallControls, smallTargets, noHorizontalScroll } = require('./helpers');

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
    ['erp-activate-confirm', '/staff/erp/activate?kind=live'],
    ['exhibition-close-confirm', `/staff/exhibitions/${f.exhibitionId}/close`],
    ['user-reset-confirm', `/staff/users/${f.staffId}/reset-password`],
    // M9: hand-over screens, and Save & New at an exhibition with a package.
    ['packages-choose', '/staff/packages?change=1'],
    ['entry-package', `/staff/entry?exhibition=${f.packageExhibitionId}`],
    ['packages', '/staff/packages'],
    ['package-found', `/staff/packages/${f.packageCode}`],
    ['package-received', `/staff/packages/${f.receivedPackageCode}`],
    ['package-phone', `/staff/packages/phone?phone=${encodeURIComponent(f.packageVisitorPhone)}`],
    ['package-not-found', '/staff/packages/ZZZZZZ'],
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
  expect(await smallTargets(page), `${name} targets`).toEqual([]);
  const { scroll, width } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, width: window.innerWidth }));
  expect(scroll, `${name} overflow`).toBeLessThanOrEqual(width);
}

test('every staff screen as super admin: axe, overflow, target size and control size', async ({ browser }, testInfo) => {
  test.skip(isArabic(testInfo), 'staff screens are English only');
  test.setTimeout(300000);
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

test('navigation marks the current page; settings pages share one sub-nav (M8 CO-13)', async ({ browser }, testInfo) => {
  test.skip(isArabic(testInfo), 'staff screens are English only');
  const page = await adminPage(browser, testInfo);
  const nav = testInfo.project.name.endsWith('-360') ? '.nav-narrow' : '.nav-wide';

  await page.goto('/staff/visitors');
  if (nav === '.nav-narrow') { await page.locator('.menu summary').click(); }
  await expect(page.locator(`${nav} a[aria-current=page]`)).toHaveText('Visitors');
  expect(await page.locator(`${nav} a[aria-current=page]`).evaluate((a) => getComputedStyle(a).fontWeight)).toBe('700');

  for (const [path, current] of [['/staff/settings', 'Settings'], ['/staff/settings/branding', 'Branding'], ['/staff/settings/fields', 'Form fields'],
    ['/staff/erp', 'ERP'], ['/staff/system', 'System']]) {
    await page.goto(path);
    await expect(page.locator('nav.subnav a'), path).toHaveText(['Settings', 'Branding', 'Form fields', 'ERP', 'System']);
    await expect(page.locator('nav.subnav a[aria-current=page]'), path).toHaveText(current);
  }
  await page.context().close();
});

test('on a phone the TEST banner and header end within 140 px; the menu holds the other links (M8 CO-13)', async ({ browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'en-360', 'phone layout, staff screens are English only');
  const page = await adminPage(browser, testInfo);
  await page.goto('/staff/visitors');
  const bottom = await page.locator('header.topbar').evaluate((el) => el.getBoundingClientRect().bottom);
  testInfo.annotations.push({ type: 'header bottom at 360x740 (px)', description: String(Math.round(bottom)) });
  expect(bottom).toBeLessThanOrEqual(140);

  // M9 §3a.7: Packages sits beside Save & New (a manager always has it).
  await expect(page.locator('.nav-narrow > a')).toHaveText(['Save & New', 'Packages']);
  await expect(page.locator('.menu-links a').first()).toBeHidden();
  await page.locator('.menu summary').click();
  await expect(page.locator('.menu-links')).toContainText('Exhibitions');
  await expect(page.locator('.menu-links')).toContainText('Sign out');
  await page.context().close();
});

test('stacked visitor rows name their columns on a phone (M8 CO-12)', async ({ browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'en-360', 'phone layout, staff screens are English only');
  const page = await adminPage(browser, testInfo);
  await page.goto('/staff/visitors');
  const erpCell = page.locator('table.visitors tbody tr').first().locator('td[data-label="ERP"]');
  expect(await erpCell.evaluate((td) => getComputedStyle(td, '::before').content)).toBe('"ERP: "');
  const box = await page.locator('table.visitors [data-select-row]').first().boundingBox();
  expect(box.width).toBeGreaterThanOrEqual(24);
  expect(box.height).toBeGreaterThanOrEqual(24);
  await expect(page.locator('table.visitors .row-actions a', { hasText: 'Delete' })).toHaveCount(0);
  await page.context().close();
});

test('the focus ring is two-tone: 2 px yellow inside 3 px ink (M8 CO-11)', async ({ browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'en-1280', 'one width is enough for computed styles');
  const page = await adminPage(browser, testInfo);
  await page.goto('/staff/settings');
  await page.locator('#retention_months').focus();
  await page.keyboard.press('Shift+Tab');
  await page.keyboard.press('Tab');
  const ring = await page.locator('#retention_months').evaluate((el) => {
    const cs = getComputedStyle(el);
    return { outline: `${cs.outlineStyle} ${cs.outlineWidth} ${cs.outlineColor}`, offset: cs.outlineOffset, shadow: cs.boxShadow };
  });
  expect(ring.outline).toBe('solid 3px rgb(27, 31, 36)');
  expect(ring.offset).toBe('2px');
  expect(ring.shadow).toBe('rgb(242, 183, 5) 0px 0px 0px 5px');
  await page.context().close();
});

test('the poster prints on one A4 page (M8 CO-15)', async ({ browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'en-1280', 'one print run is enough');
  const f = fixture(testInfo);
  const page = await adminPage(browser, testInfo);
  await page.goto(`/staff/exhibitions/${f.exhibitionId}/poster`);
  await page.emulateMedia({ media: 'print' });
  const pdf = (await page.pdf({ format: 'A4', preferCSSPageSize: true })).toString('latin1');
  const pages = (pdf.match(/\/Type\s*\/Page(?!s)/g) || []).length;
  testInfo.annotations.push({ type: 'poster PDF pages', description: String(pages) });
  expect(pages).toBe(1);
  await page.context().close();
});

test('on a phone the first visitor row starts above the fold (M8 CO-19)', async ({ browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'en-360', 'phone layout');
  const page = await adminPage(browser, testInfo);
  await page.goto('/staff/visitors');
  await expect(page.locator('.filter-details')).not.toHaveAttribute('open', '');
  const top = await page.locator('table.visitors tbody tr').first().evaluate((tr) => tr.getBoundingClientRect().top);
  testInfo.annotations.push({ type: 'first visitor row top at 360x740 (px)', description: String(Math.round(top)) });
  expect(top).toBeLessThan(740);
  await expect(page.locator('.result-count')).toHaveText(/^\d+ visitors?$/);
  await page.context().close();
});

test('password fields have a Show button; the field form hides Choices for short text (M8 L13, L3)', async ({ browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'en-1280', 'one width is enough');
  const page = await adminPage(browser, testInfo);
  await page.goto('/staff/password');
  const input = page.locator('#current_password');
  await input.fill('typed-secret');
  await page.locator('#current_password + .password-toggle').click();
  await expect(input).toHaveAttribute('type', 'text');
  await expect(page.locator('#current_password + .password-toggle')).toHaveText('Hide');
  await page.locator('#current_password + .password-toggle').click();
  await expect(input).toHaveAttribute('type', 'password');

  await page.goto('/staff/settings/fields/create');
  await expect(page.locator('[data-choices]')).toBeHidden();
  await page.locator('#type').selectOption('select');
  await expect(page.locator('[data-choices]')).toBeVisible();
  await page.context().close();
});

test('hand-over on a phone: a staff member marks a package, then may undo it (M9 CO-9, CO-11)', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'en-360', 'phone layout');
  const f = fixture(testInfo);
  await page.goto(`/staff/packages?exhibition=${f.packageExhibitionId}`);
  await expect(page.locator('.nav-narrow > a')).toHaveText(['Save & New', 'Packages']);
  const bottom = await page.locator('header.topbar').evaluate((el) => el.getBoundingClientRect().bottom);
  expect(bottom).toBeLessThanOrEqual(140);

  await page.locator('#code').fill(f.packageCode.toLowerCase());
  await page.getByRole('button', { name: 'Find' }).click();
  await expect(page.locator('.package-card h2')).toHaveText('Package Visitor One');
  await page.getByRole('button', { name: 'Mark package received' }).click();
  await expect(page.locator('.package-received')).toContainText('Package already received');
  await page.getByRole('button', { name: 'Undo' }).click();
  await expect(page.getByRole('button', { name: 'Mark package received' })).toBeVisible();
});
