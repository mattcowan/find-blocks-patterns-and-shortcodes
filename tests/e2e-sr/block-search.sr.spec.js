/**
 * Journey: the block search form, from a fresh page load through a completed
 * search, heard through a real NVDA in headed Firefox.
 *
 * This is the plugin's primary flow. What it is here to settle:
 *
 *  - Does every form control announce a name? (F8 wired the four visible hints
 *    to their controls with aria-describedby; this checks NVDA reads them.)
 *  - Is the search outcome announced, and briefly? The results container used
 *    to be aria-live + aria-atomic AND take focus, so NVDA read the entire
 *    table on every batch - 1778 characters for nine rows, measured 2026-09-18.
 *    It is no longer a live region; the small role="status" progress region
 *    carries a short "N results found" instead. The 400-character ceiling below
 *    is the regression guard on that.
 */
const { nvdaTest: test } = require('@guidepup/playwright');
const { expect } = require('@playwright/test');
const h = require('./helpers');

test.use({ nvdaStartOptions: { capture: true } });

test('block search: form controls announce, and results announce when they arrive', async ({
  page,
  nvda,
}) => {
  await h.gotoAdminPage(page, nvda);

  // --- Walk the block search form ---
  await page.locator('h1').first().evaluate((el) => {
    el.setAttribute('tabindex', '-1');
    el.focus();
  });
  await h.delay(300);

  const stops = await h.tabUntil(
    page,
    nvda,
    (el) => el.id === 'fbps-search-button',
    12
  );

  const summary = h.summarizeStops(stops);

  // --- Run the search through NVDA so the announcement is captured ---
  await page.fill('#fbps-block-name', 'core/paragraph');
  const searchPhrase = await h.activate(
    page,
    nvda,
    page.locator('#fbps-search-button')
  );

  // Let the batches finish, then read what was said last.
  await page.waitForFunction(
    () => !document.getElementById('fbps-search-button').disabled,
    null,
    { timeout: 60000 }
  );
  await h.delay(1200);
  const afterSearchPhrase = await nvda.lastSpokenPhrase();

  const region = await h.describeResultsRegion(page, 'block');
  const focusLanded = await h.describeFocus(page);

  const log = await h.saveSpeechLog(nvda, 'block-search', {
    stops,
    summary,
    searchPhrase,
    afterSearchPhrase,
    region,
    focusLanded,
  });

  // --- Product assertions: the search worked ---
  expect(region.rows).toBeGreaterThan(0);
  expect(region.role).toBe('region'); // F15: role applied only once populated

  // --- Screen-reader assertions. These encode the findings; a failure here
  //     is a result, not a broken test. ---

  // Every form control reached by Tab must have an accessible name.
  expect(summary.unnamedControls).toEqual([]);

  // No focusable stop may be silent.
  expect(summary.silentStops).toEqual([]);

  // F7: if aria-atomic re-reads the whole table, a single phrase will be huge.
  // 400 characters is the shared threshold for "this stop read the whole thing".
  expect(summary.longestPhrase).toBeLessThan(400);
  expect((afterSearchPhrase || '').length).toBeLessThan(400);

  // The multi-select hint is only useful if it is actually announced (F8).
  const postTypesStop = stops.find((s) => s.el && s.el.id === 'fbps-post-types');
  expect(postTypesStop, 'Tab walk never reached the post types select').toBeTruthy();
  expect(postTypesStop.phrase).toMatch(/multiple|Ctrl|Cmd/i);

  expect(log.length).toBeGreaterThan(0);
});
