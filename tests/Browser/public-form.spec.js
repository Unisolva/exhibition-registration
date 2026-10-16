// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.
// Public QR form in a real browser (work order §6, §13): language and direction, keyboards,
// 44 px touch targets, no horizontal scroll at 360 px, no foreign requests, a full submission.

const { test, expect } = require('@playwright/test');
const { fixture, isArabic, publicFormUrl, watchRequests, noHorizontalScroll } = require('./helpers');

test('renders in its language and direction with the right keyboards and touch targets', async ({ page, baseURL }, testInfo) => {
  const f = fixture(testInfo);
  const foreign = watchRequests(page, baseURL);

  await page.goto(publicFormUrl(f, testInfo));

  const html = page.locator('html');
  await expect(html).toHaveAttribute('lang', isArabic(testInfo) ? 'ar' : 'en');
  await expect(html).toHaveAttribute('dir', isArabic(testInfo) ? 'rtl' : 'ltr');
  await expect(page.locator('#f-phone')).toHaveAttribute('inputmode', 'tel');
  await expect(page.locator('#f-phone')).toHaveAttribute('type', 'tel');
  await expect(page.locator('#f-email')).toHaveAttribute('type', 'email');
  await expect(page.getByText(isArabic(testInfo) ? 'شركة زراعية تجريبية' : 'Sample Agri Company').first()).toBeVisible();

  for (const selector of ['#f-name', '#f-phone', '#f-email', '#f-governorate', 'form button[type=submit]', 'a.lang']) {
    const box = await page.locator(selector).boundingBox();
    expect(box, `${selector} is visible`).not.toBeNull();
    expect(box.height, `${selector} touch target height`).toBeGreaterThanOrEqual(44);
  }

  await noHorizontalScroll(page);
  expect(foreign, 'requests to other hosts').toEqual([]);
});

test('a visitor typing Arabic digits is registered and sees the thank-you page', async ({ page, baseURL }, testInfo) => {
  const f = fixture(testInfo);
  const foreign = watchRequests(page, baseURL);
  await page.goto(publicFormUrl(f, testInfo));

  await page.locator('#f-name').fill(`زائر تجريبي ${testInfo.project.name}`);
  await page.locator('#f-phone').fill('٠١٠٠ ٢٢٢ ٣٣٣٣');
  await page.locator('#f-governorate').selectOption('ALX');
  await page.locator('form button[type=submit]').click();

  await expect(page).toHaveURL(/\/thanks$/);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(isArabic(testInfo) ? 'شكرًا لك' : 'Thank you');
  await expect(page.getByText('٠١٠٠')).toHaveCount(0);
  await noHorizontalScroll(page);
  expect(foreign).toEqual([]);
});

test('errors are shown next to the fields and the typed values stay', async ({ page }, testInfo) => {
  const f = fixture(testInfo);
  await page.goto(publicFormUrl(f, testInfo));

  await page.locator('#f-name').fill('Kept Value');
  await page.locator('#f-phone').fill('12');
  await page.locator('#f-email').fill('not-an-email');
  await page.locator('form button[type=submit]').click();

  await expect(page.locator('#f-phone')).toHaveAttribute('aria-invalid', 'true');
  await expect(page.locator('#f-phone-error')).toBeVisible();
  await expect(page.locator('#f-email-error')).toBeVisible();
  await expect(page.locator('#f-name')).toHaveValue('Kept Value');
  await expect(page.locator('#f-email')).toHaveValue('not-an-email');
  await noHorizontalScroll(page);
});
