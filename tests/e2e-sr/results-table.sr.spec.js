/**
 * Journey: the results table controls - column toggles and sortable headers.
 *
 * These pin the two accessibility fixes that are easiest to regress:
 *
 *  - F5: changing a column checkbox re-renders the table. It must NOT pull
 *    focus out of the fieldset, or a keyboard user is ejected on every toggle.
 *  - F6: sortable headers are now <button> inside <th scope="col" aria-sort>.
 *    NVDA should say the column is sortable and which way it is sorted; before
 *    the fix the direction existed only as a CSS class and an arrow glyph.
 */
const { nvdaTest: test } = require('@guidepup/playwright');
const { expect } = require('@playwright/test');
const h = require('./helpers');

test.use({ nvdaStartOptions: { capture: true } });

test('column toggles keep focus, and sort headers announce their direction', async ({
  page,
  nvda,
}) => {
  await h.gotoAdminPage(page, nvda);
  await h.runSearch(page, 'block', h.fillBlockName('core/paragraph'));

  const rows = await page.locator('#fbps-search-results tbody tr').count();
  expect(rows, 'need results before the table controls can be tested').toBeGreaterThan(0);

  // --- F5: toggle a column through NVDA and see where focus ends up ---
  const cssClassToggle = page.locator('.fbps-col-toggle[value="className"]');
  const togglePhrase = await h.activate(page, nvda, cssClassToggle, 'Space');
  await h.delay(600);

  const focusAfterToggle = await h.describeFocus(page);
  const focusStayedOnToggle =
    focusAfterToggle && focusAfterToggle.tag === 'INPUT' && focusAfterToggle.type === 'checkbox';

  // --- F6: sort state before and after activating a header ---
  const sortBefore = await h.readSortState(page, 'block');

  const titleSortButton = page.locator(
    '#fbps-search-results th[data-column="title"] .fbps-sort-button'
  );
  const sortPhrase = await h.activate(page, nvda, titleSortButton, 'Enter');
  await h.delay(600);

  const sortAfter = await h.readSortState(page, 'block');

  // Note on probing sort state: an NVDA+Tab "report current focus" after the
  // sort is NOT a reliable probe here. On 2026-09-18 it returned
  // "column 2, Type, button" while focus was on the Title button - NVDA
  // reported the cell its review cursor sat in after reading the table, not
  // the focused control. Type's aria-sort is "none", so NVDA was correctly
  // silent about sorting and the probe looked like a product failure.
  //
  // The sort announcement is asserted on `sortPhrase` instead, which is
  // captured around the Guidepup command that actually performs the sort.

  const log = await h.saveSpeechLog(nvda, 'results-table', {
    togglePhrase,
    focusAfterToggle,
    sortBefore,
    sortAfter,
    sortPhrase,
  });

  // --- Product assertions ---

  // F5: focus must still be on the checkbox the user just operated.
  expect(
    focusStayedOnToggle,
    `column toggle moved focus to ${focusAfterToggle && focusAfterToggle.tag}#${focusAfterToggle && focusAfterToggle.id}`
  ).toBe(true);

  // F6: every sortable header is a button in a th with scope and aria-sort.
  const sortable = sortAfter.filter((c) => c.control !== 'none');
  expect(sortable.length).toBeGreaterThan(0);
  for (const col of sortable) {
    expect(col.control, `${col.text} should be a button, not a link`).toBe('button');
    expect(col.scope, `${col.text} needs scope="col"`).toBe('col');
    expect(col.ariaSort, `${col.text} needs an aria-sort value`).toBeTruthy();
  }

  // Activating Title sorts it ascending and clears the others.
  const title = sortAfter.find((c) => c.text.startsWith('Title'));
  expect(title.ariaSort).toBe('ascending');
  expect(sortAfter.filter((c) => c.ariaSort === 'ascending' || c.ariaSort === 'descending'))
    .toHaveLength(1);

  // --- Screen-reader assertions ---

  // NVDA must announce the new sort state when the sort happens (F6).
  expect(sortPhrase, 'NVDA did not announce the sort direction').toMatch(/sorted ascending/i);

  // Neither action may dump the whole table into one phrase. Ticking a single
  // checkbox used to read all nine rows before saying "checked" (F7).
  expect((togglePhrase || '').length).toBeLessThan(400);
  expect((sortPhrase || '').length).toBeLessThan(400);

  expect(log.length).toBeGreaterThan(0);
});
