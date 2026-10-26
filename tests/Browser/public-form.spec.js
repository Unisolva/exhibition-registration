// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.
// Public QR form in a real browser (work order §6, §13): language and direction, keyboards,
// 44 px touch targets, no horizontal scroll at 360 px, no foreign requests, a full submission.

const { test, expect } = require('@playwright/test');
const { fixture, isArabic, publicFormUrl, watchRequests, noHorizontalScroll, adminPage, seriousViolations } = require('./helpers');

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

test('after an empty submit the first field has focus and the summary links each field (M8 CO-10)', async ({ page }, testInfo) => {
  const f = fixture(testInfo);
  await page.goto(publicFormUrl(f, testInfo));
  await page.locator('form button[type=submit]').click();

  await expect(page.locator('#f-name')).toHaveAttribute('aria-invalid', 'true');
  expect(await page.evaluate(() => document.activeElement && document.activeElement.id)).toBe('f-name');
  const links = page.locator('.error-summary a');
  await expect(links.first()).toHaveAttribute('href', '#f-name');
  await expect(links.nth(1)).toHaveAttribute('href', '#f-phone');
  await expect(page.locator('.error-summary p')).toHaveText(isArabic(testInfo) ? 'يرجى تصحيح الحقول التالية (2):' : 'Please fix 2 fields:');
});

test('Arabic at 360x740 with the five default fields and no logo: Send ends within the screen (M8 CO-14)', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'ar-360', 'the measured case is Arabic at 360 px');
  const f = fixture(testInfo);
  await page.goto(publicFormUrl(f, testInfo));
  await expect(page.locator('h1.exhibition-title')).toHaveText(f.exhibitionName);
  await expect(page.locator('.public-head img')).toHaveCount(0);
  await expect(page.locator('form .field')).toHaveCount(5);
  const bottom = await page.locator('form button[type=submit]').evaluate((b) => b.getBoundingClientRect().bottom);
  testInfo.annotations.push({ type: 'Send bottom at 360x740 Arabic (px)', description: String(Math.round(bottom)) });
  expect(bottom).toBeLessThanOrEqual(740);
});

test('thank-you with a welcome package: code and QR visible on a phone, the QR opens the hand-over page (M9 CO-6)', async ({ browser, baseURL }, testInfo) => {
  const f = fixture(testInfo);
  // CSP blocks injected scripts; the decoder is the test's own, so this one context bypasses it.
  const context = await browser.newContext({ ...testInfo.project.use, baseURL, bypassCSP: true, storageState: undefined });
  const page = await context.newPage();
  const lang = isArabic(testInfo) ? 'ar' : 'en';
  await page.goto(`/r/${f.packageExhibitionToken}?lang=${lang}`);
  await page.locator('#f-name').fill(`Package Visitor ${testInfo.project.name}`);
  await page.locator('#f-phone').fill(`0105${String(Date.now()).slice(-7)}`);
  await page.locator('form button[type=submit]').click();
  await expect(page).toHaveURL(/\/thanks$/);

  const code = (await page.locator('.package-code').textContent()).replace(/[^0-9A-Z]/g, '').slice(-6);
  expect(code).toMatch(/^[0-9A-Z]{6}$/);
  if (testInfo.project.name.endsWith('-360')) {
    for (const selector of ['.package-qr', '.package-code']) {
      const box = await page.locator(selector).boundingBox();
      expect(box.y, `${selector} top`).toBeGreaterThanOrEqual(0);
      expect(box.y + box.height, `${selector} bottom within 740 px`).toBeLessThanOrEqual(740);
    }
  }

  await page.addScriptTag({ path: require.resolve('jsqr/dist/jsQR.js') });
  const decoded = await page.evaluate(async () => {
    const svg = new XMLSerializer().serializeToString(document.querySelector('.package-qr svg'));
    const img = new Image();
    img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    await img.decode();
    const canvas = document.createElement('canvas');
    canvas.width = 480; canvas.height = 480;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, 480, 480);
    ctx.drawImage(img, 40, 40, 400, 400);
    const data = ctx.getImageData(0, 0, 480, 480);
    const result = window.jsQR(data.data, 480, 480);
    return result ? result.data : null;
  });
  expect(decoded).toBe(`${baseURL}/staff/packages/${code}`);
  await expect(page.getByText('Register another person')).toHaveCount(0);
  await context.close();
});

/** Decode the QR from a screenshot of the gift box as it is drawn (M11: on the accent colour). */
async function decodeBoxScreenshot(page) {
  const png = (await page.locator('.package-box').screenshot()).toString('base64');
  await page.addScriptTag({ path: require.resolve('jsqr/dist/jsQR.js') });
  return page.evaluate(async (b64) => {
    const img = new Image();
    img.src = 'data:image/png;base64,' + b64;
    await img.decode();
    const canvas = document.createElement('canvas');
    canvas.width = img.width; canvas.height = img.height;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(img, 0, 0);
    const data = ctx.getImageData(0, 0, img.width, img.height);
    const result = window.jsQR(data.data, img.width, img.height);
    return result ? result.data : null;
  }, png);
}

test('thank-you gift box with and without an accent colour, Arabic and English at 360 (M11 CO-2)', async ({ browser, baseURL }, testInfo) => {
  test.skip(testInfo.project.name !== 'en-360', 'one run covers both languages; it changes a global setting');
  test.setTimeout(120000);
  const f = fixture(testInfo);
  const admin = await adminPage(browser, testInfo);
  const setAccent = async (value) => {
    await admin.goto('/staff/settings/branding');
    await admin.locator('#accent_color').fill(value);
    await admin.getByRole('button', { name: 'Save' }).click();
    await expect(admin.locator('.alert-success')).toContainText('Branding saved.');
  };
  try {
    for (const accent of ['#FEB71F', '']) {
      await setAccent(accent);
      for (const lang of ['ar', 'en']) {
        const context = await browser.newContext({ ...testInfo.project.use, baseURL, bypassCSP: true, storageState: undefined });
        const page = await context.newPage();
        await page.goto(`/r/${f.packageExhibitionToken}?lang=${lang}`);
        await page.locator('#f-name').fill(`Accent ${lang} ${accent || 'none'}`);
        await page.locator('#f-phone').fill(`0109${String(Date.now()).slice(-7)}`);
        await page.locator('form button[type=submit]').click();
        await expect(page).toHaveURL(/\/thanks$/);
        const box = page.locator('.package-box');
        const look = await box.evaluate((el) => ({ bg: getComputedStyle(el).backgroundColor, ink: getComputedStyle(el).color, border: getComputedStyle(el).borderTopStyle }));
        if (accent) {
          expect(look).toEqual({ bg: 'rgb(254, 183, 31)', ink: 'rgb(27, 31, 36)', border: 'none' });
          expect(await page.locator('.package-qr').evaluate((el) => getComputedStyle(el).backgroundColor)).toBe('rgb(255, 255, 255)');
        } else {
          expect(look.border).toBe('dashed');
        }
        expect(await seriousViolations(page), `${lang} ${accent} axe`).toEqual([]);
        await noHorizontalScroll(page);
        const code = (await page.locator('.package-code').textContent()).replace(/[^0-9A-Z]/g, '').slice(-6);
        expect(await decodeBoxScreenshot(page)).toBe(`${baseURL}/staff/packages/${code}`);
        await context.close();
      }
    }
  } finally {
    await setAccent('');
    await admin.context().close();
  }
});
