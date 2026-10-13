// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.
// Shared helpers for the browser tests.

const fs = require('fs');
const { expect } = require('@playwright/test');
const { default: AxeBuilder } = require('@axe-core/playwright');

function fixture(testInfo) {
  const file = process.env.FIXTURES_FILE || `${__dirname}/fixtures.json`;
  const all = JSON.parse(fs.readFileSync(file, 'utf8'));
  return { ...all.projects[testInfo.project.name], project: testInfo.project.name };
}

function isArabic(testInfo) {
  return testInfo.project.name.startsWith('ar-');
}

/** Public form URL in the project's language (Arabic is the default; English via ?lang=en). */
function publicFormUrl(f, testInfo) {
  return `/r/${f.exhibitionToken}${isArabic(testInfo) ? '?lang=ar' : '?lang=en'}`;
}

async function signIn(page, f) {
  await page.goto('/staff/login');
  await page.locator('#email').fill(f.staffEmail);
  await page.locator('#password').fill(f.staffPassword);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/staff$/);
}

/** Records every request; the app must never load anything from another host. */
function watchRequests(page, baseURL) {
  const origin = new URL(baseURL).origin;
  const foreign = [];
  page.on('request', (request) => {
    const url = request.url();
    if (!url.startsWith(origin) && !url.startsWith('data:')) {
      foreign.push(url);
    }
  });
  return foreign;
}

/** axe-core with WCAG 2.1 A/AA rules; returns serious and critical violations only. */
async function seriousViolations(page) {
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  return results.violations
    .filter((v) => v.impact === 'serious' || v.impact === 'critical')
    .map((v) => `${v.id} (${v.impact}): ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`);
}

async function noHorizontalScroll(page) {
  const { scroll, width } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, width: window.innerWidth }));
  expect(scroll, 'page wider than the viewport').toBeLessThanOrEqual(width);
}

/** Count rows for a visitor name in the signed-in staff user's own entry list. */
async function countEntries(context, name) {
  const list = await context.newPage();
  await list.goto(`/staff/visitors?q=${encodeURIComponent(name)}`);
  const rows = await list.locator('table.visitors tbody tr').count();
  await list.close();
  return rows;
}

module.exports = { fixture, isArabic, publicFormUrl, signIn, watchRequests, seriousViolations, noHorizontalScroll, countEntries };
