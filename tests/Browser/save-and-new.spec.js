// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.
// Save & New in a real browser (work order §7, §10, §13): the full cycle, a forced 419 then Retry,
// a forced network failure then Retry, a response lost after the commit, and a session that
// ended while typing. Each case must leave exactly one row, and values are never lost.

const { test, expect } = require('@playwright/test');
const { fixture, signIn, watchRequests, noHorizontalScroll, countEntries, adminPage } = require('./helpers');

const ENDPOINT = /\/staff\/registrations$/;

// Signed in already: global-setup saved each project's staff session.
async function openEntry(page, f) {
  await page.goto(`/staff/entry?exhibition=${f.exhibitionId}`);
  await expect(page.locator('.entry-bar')).toContainText(f.exhibitionName);
}

function uniqueVisitor(testInfo, label) {
  const n = String(Date.now()).slice(-7);
  return { name: `Browser ${testInfo.project.name} ${label} ${n}`, phone: `0101${n}` };
}

async function type(page, visitor) {
  await page.locator('#f-name').fill(visitor.name);
  await page.locator('#f-phone').fill(visitor.phone);
  await page.locator('#f-governorate').selectOption('GZ');
}

async function expectSaved(page) {
  // Generous: the local server is shared with the other browser projects running in parallel.
  await expect(page.locator('#entry-status')).toContainText('Saved', { timeout: 15000 });
  await expect(page.locator('#f-name')).toHaveValue('');
  await expect(page.locator('#f-phone')).toHaveValue('');
  await expect(page.locator('#f-name')).toBeFocused();
}

test('full cycle: saved, fields cleared, exhibition kept, name focused, one row', async ({ page, context, baseURL }, testInfo) => {
  const f = fixture(testInfo);
  const foreign = watchRequests(page, baseURL);
  await openEntry(page, f);
  const visitor = uniqueVisitor(testInfo, 'cycle');
  const tokenBefore = await page.locator('input[name=submission_token]').inputValue();

  await type(page, visitor);
  await page.locator('[data-save]').click();

  await expectSaved(page);
  await expect(page.locator('.entry-bar')).toContainText(f.exhibitionName);
  await expect(page.locator('input[name=submission_token]')).not.toHaveValue(tokenBefore);
  await noHorizontalScroll(page);
  expect(await countEntries(context, visitor.name)).toBe(1);
  expect(foreign).toEqual([]);
});

test('forced 419: values kept, Retry gets a fresh token and saves once', async ({ page, context }, testInfo) => {
  const f = fixture(testInfo);
  await openEntry(page, f);
  const visitor = uniqueVisitor(testInfo, 'csrf');
  await type(page, visitor);

  // A CSRF token the server no longer accepts, as after a session renewal.
  await page.evaluate(() => {
    document.querySelector('meta[name=csrf-token]').setAttribute('content', 'expired-token');
    document.querySelector('form[data-entry] input[name=_token]').value = 'expired-token';
  });
  // Laravel 13 accepts same-origin requests by their Sec-Fetch-Site header alone, so current
  // browsers rarely meet a 419. Browsers without that header (older phones) still do: send the
  // first save as such a browser would, and hand the server's real answer back to the page.
  await page.route(ENDPOINT, async (route) => {
    const headers = { ...route.request().headers() };
    for (const name of Object.keys(headers)) {
      if (name.startsWith('sec-fetch-')) delete headers[name];
    }
    await route.fulfill({ response: await route.fetch({ headers }) });
  }, { times: 1 });
  const first = page.waitForResponse(ENDPOINT);
  await page.locator('[data-save]').click();
  expect((await first).status()).toBe(419);

  await expect(page.locator('#entry-status')).toContainText('You were signed out');
  await expect(page.locator('#f-name')).toHaveValue(visitor.name);
  await expect(page.locator('[data-retry]')).toBeVisible();

  await page.locator('[data-retry]').click();
  await expectSaved(page);
  expect(await countEntries(context, visitor.name)).toBe(1);
});

test('forced network failure: values kept, Retry saves once', async ({ page, context }, testInfo) => {
  const f = fixture(testInfo);
  await openEntry(page, f);
  const visitor = uniqueVisitor(testInfo, 'network');
  await type(page, visitor);

  await page.route(ENDPOINT, (route) => route.abort('internetdisconnected'), { times: 1 });
  await page.locator('[data-save]').click();

  await expect(page.locator('#entry-status')).toContainText('No answer from the server');
  await expect(page.locator('#f-name')).toHaveValue(visitor.name);
  await expect(page.locator('#f-phone')).toHaveValue(visitor.phone);
  expect(await countEntries(context, visitor.name)).toBe(0);

  await page.locator('[data-retry]').click();
  await expectSaved(page);
  expect(await countEntries(context, visitor.name)).toBe(1);
});

test('response lost after the commit: Retry is answered as a replay, still one row', async ({ page, context }, testInfo) => {
  const f = fixture(testInfo);
  await openEntry(page, f);
  const visitor = uniqueVisitor(testInfo, 'lost');
  await type(page, visitor);

  // The request reaches the server and is committed; the answer never reaches the page.
  let committedStatus = null;
  await page.route(ENDPOINT, async (route) => {
    const response = await route.fetch();
    committedStatus = response.status();
    await route.abort('connectionreset');
  }, { times: 1 });
  await page.locator('[data-save]').click();

  await expect(page.locator('#entry-status')).toContainText('No answer from the server');
  expect(committedStatus).toBe(201);
  await expect(page.locator('#f-name')).toHaveValue(visitor.name);

  const replay = page.waitForResponse(ENDPOINT);
  await page.locator('[data-retry]').click();
  expect((await replay).status()).toBe(200);
  await expectSaved(page);
  expect(await countEntries(context, visitor.name)).toBe(1);
});

test('signed out while typing: sign in in a new tab, Retry saves once', async ({ page, context }, testInfo) => {
  const f = fixture(testInfo);
  await openEntry(page, f);
  const visitor = uniqueVisitor(testInfo, 'signedout');
  await type(page, visitor);

  await context.clearCookies();
  await page.locator('[data-save]').click();
  await expect(page.locator('#entry-status')).toContainText('You were signed out');
  await expect(page.locator('#f-name')).toHaveValue(visitor.name);

  // Retry before signing in again keeps the values and says so.
  await page.locator('[data-retry]').click();
  await expect(page.locator('#entry-status')).toContainText('Still signed out');
  await expect(page.locator('#f-name')).toHaveValue(visitor.name);

  const tab = await context.newPage();
  await signIn(tab, f);
  await tab.close();

  await page.locator('[data-retry]').click();
  await expectSaved(page);
  expect(await countEntries(context, visitor.name)).toBe(1);
});

test('Retry and "Save as a new entry" are hidden until needed (M8 CO-2)', async ({ page }, testInfo) => {
  await openEntry(page, fixture(testInfo));
  for (const selector of ['[data-retry]', '[data-save-new]']) {
    expect(await page.locator(selector).evaluate((el) => getComputedStyle(el).display)).toBe('none');
  }
  await expect(page.locator('[data-save]')).toBeVisible();
});

/** Saves what is typed, then returns the name field's top edge 0.5 s and 5 s after the answer. */
async function saveAndWatch(page) {
  const answer = page.waitForResponse(ENDPOINT);
  await page.locator('[data-save]').click();
  expect([200, 201]).toContain((await answer).status());
  await page.waitForTimeout(500);
  const early = (await page.locator('#f-name').boundingBox()).y;
  await page.waitForTimeout(4500);
  const late = (await page.locator('#f-name').boundingBox()).y;
  return { early, late };
}

test('a plain save names the visitor, clears after 4 seconds and nothing moves (M8 CO-4)', async ({ page }, testInfo) => {
  await openEntry(page, fixture(testInfo));
  const visitor = uniqueVisitor(testInfo, 'plain');
  await type(page, visitor);

  const answer = page.waitForResponse(ENDPOINT);
  await page.locator('[data-save]').click();
  await answer;
  await expect(page.locator('#entry-status .notice-ok')).toHaveText(`Saved ${visitor.name}.`);
  await page.waitForTimeout(500);
  const early = (await page.locator('#f-name').boundingBox()).y;
  await page.waitForTimeout(4000);
  await expect(page.locator('#entry-status .notice-ok')).toBeHidden();
  expect((await page.locator('#f-name').boundingBox()).y).toBe(early);
  await expect(page.locator('[data-saved-count]')).toHaveText('1 saved in this session');
});

test('a possible duplicate is a warning that stays, names the visitor, and staff get no link (M8 CO-4)', async ({ page }, testInfo) => {
  await openEntry(page, fixture(testInfo));
  const first = uniqueVisitor(testInfo, 'dupA');
  await type(page, first);
  await page.locator('[data-save]').click();
  await expectSaved(page);

  const second = { name: `${first.name} again`, phone: first.phone };
  await type(page, second);
  const { early, late } = await saveAndWatch(page);

  const warning = page.locator('#entry-status .notice-warn');
  await expect(warning).toBeVisible();
  await expect(warning).toHaveText(`Saved ${second.name}. This phone number is already registered at this exhibition.`);
  await expect(warning.locator('a')).toHaveCount(0);
  expect(late).toBe(early);
  await expect(page.locator('[data-saved-count]')).toHaveText('2 saved in this session');

  // It goes when the next save starts.
  await type(page, uniqueVisitor(testInfo, 'dupC'));
  await page.locator('[data-save]').click();
  await expect(page.locator('#entry-status .notice-ok')).toBeVisible();
  await expect(warning).toHaveCount(0);
});

test('a manager sees "View earlier entry" on a possible duplicate (M8 CO-4)', async ({ browser }, testInfo) => {
  const f = fixture(testInfo);
  const page = await adminPage(browser, testInfo);
  await openEntry(page, f);
  const first = uniqueVisitor(testInfo, 'mgrA');
  await type(page, first);
  await page.locator('[data-save]').click();
  await expectSaved(page);

  await type(page, { name: `${first.name} again`, phone: first.phone });
  await page.locator('[data-save]').click();
  const link = page.locator('#entry-status .notice-warn a');
  await expect(link).toHaveText('View earlier entry');
  await expect(link).toHaveAttribute('href', /\/staff\/visitors\/\d+$/);
  await page.context().close();
});

test('on a phone the message after a save is not hidden under the sticky exhibition bar (M8 CO-4)', async ({ page }, testInfo) => {
  test.skip(!testInfo.project.name.endsWith('-360'), 'phone layout');
  await openEntry(page, fixture(testInfo));
  const first = uniqueVisitor(testInfo, 'barA');
  await type(page, first);
  await page.locator('[data-save]').click();
  await expectSaved(page);
  await type(page, { name: `${first.name} again`, phone: first.phone });
  await page.locator('[data-save]').click();
  // The warning itself, not the "Saving…" notice shown while the request runs.
  await expect(page.locator('#entry-status .notice-warn')).toContainText('already registered', { timeout: 15000 });
  const gap = await page.evaluate(() => document.querySelector('#entry-status .notice').getBoundingClientRect().top
    - document.querySelector('.entry-bar').getBoundingClientRect().bottom);
  expect(gap).toBeGreaterThanOrEqual(0);
});

test('a radio group: arrow keys move the choice, rows are 44 px, it comes back on its default after a save (M9 CO-3, M10.1 CO-1)', async ({ page }, testInfo) => {
  await openEntry(page, fixture(testInfo));
  const group = page.locator('fieldset#f-visitor_type');
  await expect(group.locator('legend')).toHaveText('Visitor type');
  await expect(group.locator('input[type=radio]')).toHaveCount(3);
  // "Not set" is offered, "Regular" (the default) is selected.
  await expect(group.locator('#f-visitor_type-0')).not.toBeChecked();
  await expect(group.locator('#f-visitor_type-2')).toBeChecked();
  for (const row of await group.locator('label.radio').all()) {
    expect((await row.boundingBox()).height).toBeGreaterThanOrEqual(44);
  }

  await group.locator('#f-visitor_type-2').focus();
  await page.keyboard.press('ArrowUp');
  await expect(group.locator('#f-visitor_type-1')).toBeChecked();
  await expect(group.locator('#f-visitor_type-1')).toBeFocused();

  const visitor = uniqueVisitor(testInfo, 'radio');
  await type(page, visitor);
  const request = page.waitForRequest(ENDPOINT);
  await page.locator('[data-save]').click();
  expect(JSON.parse((await request).postData()).visitor_type).toBe('vip');
  await expectSaved(page);
  // M10.1: back on the default, ready for the next visitor, with the name field focused.
  await expect(group.locator('#f-visitor_type-2')).toBeChecked();
  await expect(page.locator('#f-name')).toBeFocused();
});

test('a radio error is tied to the group and focuses its first radio (M9 CO-3)', async ({ page }, testInfo) => {
  await openEntry(page, fixture(testInfo));
  await type(page, uniqueVisitor(testInfo, 'radioerr'));
  await page.route(ENDPOINT, (route) => route.fulfill({
    status: 422, contentType: 'application/json',
    body: JSON.stringify({ errors: { visitor_type: 'Choose one.' }, next_token: null }),
  }), { times: 1 });
  await page.locator('[data-save]').click();

  const group = page.locator('fieldset#f-visitor_type');
  await expect(group).toHaveAttribute('aria-describedby', 'f-visitor_type-error');
  await expect(page.locator('#f-visitor_type-error')).toHaveText('Choose one.');
  await expect(group.locator('#f-visitor_type-0')).toBeFocused();
  await expect(group.locator('#f-visitor_type-0')).toHaveAttribute('aria-invalid', 'true');
});

test('on a phone only one line is kept free above the form (M9 CO-13)', async ({ page }, testInfo) => {
  test.skip(!testInfo.project.name.endsWith('-360'), 'phone layout');
  await openEntry(page, fixture(testInfo));
  const gap = await page.evaluate(() => document.querySelector('form[data-entry]').getBoundingClientRect().top
    - document.querySelector('#entry-status').getBoundingClientRect().top);
  expect(gap).toBeLessThanOrEqual(32);
});
