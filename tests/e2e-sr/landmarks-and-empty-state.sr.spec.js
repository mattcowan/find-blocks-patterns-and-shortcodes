/**
 * Journey: landmarks and the empty state.
 *
 * Pins F15. All three results containers exist from first paint. Before the
 * fix each was a permanent role="region" landmark with an aria-label, so a
 * screen-reader user navigating by landmark hit two or three empty regions
 * that promised results and held nothing.
 *
 * admin.js now applies role and aria-label together, and only while the
 * container holds results. This journey checks both halves: nothing before a
 * search, exactly one afterwards.
 */
const { nvdaTest: test } = require('@guidepup/playwright');
const { expect } = require('@playwright/test');
const h = require('./helpers');

test.use({ nvdaStartOptions: { capture: true } });

test('empty results containers are not landmarks; a filled one is', async ({ page, nvda }) => {
  await h.gotoAdminPage(page, nvda);

  // --- Empty state: none of the three may be a landmark ---
  const before = {
    block: await h.describeResultsRegion(page, 'block'),
    pattern: await h.describeResultsRegion(page, 'pattern'),
    shortcode: await h.describeResultsRegion(page, 'shortcode'),
  };

  for (const [name, r] of Object.entries(before)) {
    expect(r.textLength, `${name} container should start empty`).toBe(0);
    expect(r.role, `empty ${name} container must not be a landmark`).toBeNull();
    // aria-label is prohibited on a div with no role - they must come and go together.
    expect(r.ariaLabel, `empty ${name} container must not carry aria-label`).toBeNull();
  }

  // --- Run a block search ---
  await h.runSearch(page, 'block', h.fillBlockName('core/paragraph'));

  const after = {
    block: await h.describeResultsRegion(page, 'block'),
    pattern: await h.describeResultsRegion(page, 'pattern'),
    shortcode: await h.describeResultsRegion(page, 'shortcode'),
  };

  // Only the container that holds results is a landmark - and it must hold
  // them, or an empty container wrongly given the role would pass.
  expect(after.block.rows).toBeGreaterThan(0);
  expect(after.block.role).toBe('region');
  expect(after.block.ariaLabel).toBeTruthy();
  expect(after.pattern.role).toBeNull();
  expect(after.pattern.ariaLabel).toBeNull();
  expect(after.shortcode.role).toBeNull();
  expect(after.shortcode.ariaLabel).toBeNull();

  // --- Count what NVDA can reach by landmark ---
  const landmarkCount = await page.evaluate(() =>
    document.querySelectorAll(
      '.wrap [role="region"], .wrap [role="search"], .wrap main, .wrap nav'
    ).length
  );

  const log = await h.saveSpeechLog(nvda, 'landmarks-and-empty-state', {
    before,
    after,
    landmarkCount,
  });

  // Three search landmarks plus one populated results region.
  expect(landmarkCount).toBe(4);

  expect(log).toBeDefined();
});
