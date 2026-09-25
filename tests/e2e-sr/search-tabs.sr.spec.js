/**
 * Journey: the search-type tabs (issue #18), heard through a real NVDA.
 *
 * The page shows one search form at a time behind a Blocks / Patterns /
 * Shortcodes tab list. What this settles:
 *
 *  - Tab reaches the tab list, and only the selected tab is a Tab stop
 *    (roving tabindex).
 *  - Each tab announces its name and that it is selected.
 *  - Left and Right move between the tabs, wrapping at the ends, and show only
 *    that tab's form.
 *  - Switching tabs does not start a search and does not clear the results
 *    already on screen.
 *  - Tab from the tab list moves to the visible panel. The Patterns and
 *    Shortcodes panels open with a heading, so the panel itself is the stop
 *    (tabindex="0") and NVDA reads its name.
 *
 * NVDA may leave browse mode on when a tab takes focus, in which case its
 * arrow keys move the virtual cursor and never reach the page. The journey
 * records whether the first arrow press reached the tabs without help
 * (arrowReachedPageInDefaultMode in the log) and only then switches to focus
 * mode, so a change in NVDA's behavior shows up in the log, not as a hang.
 */
const { nvdaTest: test } = require('@guidepup/playwright');
const { expect } = require('@playwright/test');
const h = require('./helpers');

test.use({ nvdaStartOptions: { capture: true } });

/** Which tab is selected and which panels are visible, read from the DOM. */
async function readTabState(page) {
  return page.evaluate(() => ({
    selected: [...document.querySelectorAll('[role="tab"][aria-selected="true"]')].map((t) => t.id),
    tabStops: [...document.querySelectorAll('[role="tab"]')]
      .filter((t) => t.tabIndex === 0)
      .map((t) => t.id),
    visiblePanels: [...document.querySelectorAll('[role="tabpanel"]')]
      .filter((p) => !p.hidden)
      .map((p) => p.id),
    focused: document.activeElement ? document.activeElement.id : null,
  }));
}

test('search tabs: arrow keys switch forms, tabs announce state, results stay', async ({
  page,
  nvda,
}) => {
  await h.gotoAdminPage(page, nvda);

  // --- Load state: Blocks selected, one panel visible, one Tab stop ---
  const initial = await readTabState(page);
  expect(initial.selected).toEqual(['fbps-tab-block']);
  expect(initial.tabStops).toEqual(['fbps-tab-block']);
  expect(initial.visiblePanels).toEqual(['fbps-panel-block']);

  // Put results on screen first, so the journey can check they survive.
  await h.runSearch(page, 'block', h.fillBlockName('core/paragraph'));
  const rowsBefore = await page.locator('#fbps-search-results tbody tr').count();
  expect(rowsBefore, 'need results before tab switching can be checked').toBeGreaterThan(0);

  // --- Tab from the heading into the tab list ---
  await page.locator('h1').first().evaluate((el) => {
    el.setAttribute('tabindex', '-1');
    el.focus();
  });
  await h.delay(300);
  const stops = await h.tabUntil(page, nvda, (el) => el.role === 'tab', 3);
  const tabStop = stops[stops.length - 1];
  expect(tabStop.el && tabStop.el.id, 'Tab never reached the tab list').toBe('fbps-tab-block');

  // --- Right arrow: to Patterns ---
  await h.press(page, nvda, 'ArrowRight');
  await h.delay(450);
  let afterRight = await readTabState(page);
  const arrowReachedPageInDefaultMode = afterRight.focused === 'fbps-tab-pattern';
  let focusModeHeard = null;
  if (!arrowReachedPageInDefaultMode) {
    // Browse mode swallowed the arrow. Put focus back on the selected tab,
    // switch NVDA to focus mode, and try again.
    await page.locator('#fbps-tab-block').focus();
    await h.delay(300);
    focusModeHeard = await h.ensureFocusMode(nvda, async () => {
      await h.press(page, nvda, 'ArrowRight');
      await h.delay(300);
      return (await readTabState(page)).focused === 'fbps-tab-pattern';
    });
    if ((await readTabState(page)).focused !== 'fbps-tab-pattern') {
      await h.press(page, nvda, 'ArrowRight');
      await h.delay(450);
    }
    afterRight = await readTabState(page);
  }
  const patternPhrase = await nvda.lastSpokenPhrase();

  // --- Right twice more: Shortcodes, then wrap to Blocks ---
  await h.press(page, nvda, 'ArrowRight');
  await h.delay(450);
  const shortcodePhrase = await nvda.lastSpokenPhrase();
  const afterSecondRight = await readTabState(page);

  await h.press(page, nvda, 'ArrowRight');
  await h.delay(450);
  const wrapPhrase = await nvda.lastSpokenPhrase();
  const afterWrap = await readTabState(page);

  // --- Left: wrap back to Shortcodes ---
  await h.press(page, nvda, 'ArrowLeft');
  await h.delay(450);
  const leftPhrase = await nvda.lastSpokenPhrase();
  const afterLeft = await readTabState(page);

  // --- Nothing searched, nothing cleared ---
  const rowsAfter = await page.locator('#fbps-search-results tbody tr').count();
  const searchState = await page.evaluate(() => ({
    patternButtonBusy: document.getElementById('fbps-pattern-search-button').disabled,
    shortcodeButtonBusy: document.getElementById('fbps-shortcode-search-button').disabled,
    patternRows: document.querySelectorAll('#fbps-pattern-search-results tbody tr').length,
    shortcodeRows: document.querySelectorAll('#fbps-shortcode-search-results tbody tr').length,
  }));

  // --- Tab out of the tab list lands on the visible panel ---
  await h.press(page, nvda, 'Tab');
  await h.delay(450);
  const intoPanelPhrase = await nvda.lastSpokenPhrase();
  const focusInPanel = await h.describeFocus(page);

  const log = await h.saveSpeechLog(nvda, 'search-tabs', {
    initial,
    stops,
    arrowReachedPageInDefaultMode,
    focusModeHeard,
    afterRight,
    patternPhrase,
    afterSecondRight,
    shortcodePhrase,
    afterWrap,
    wrapPhrase,
    afterLeft,
    leftPhrase,
    rowsBefore,
    rowsAfter,
    searchState,
    intoPanelPhrase,
    focusInPanel,
  });

  // --- Product assertions: the tabs switch the forms ---
  expect(afterRight.selected).toEqual(['fbps-tab-pattern']);
  expect(afterRight.visiblePanels).toEqual(['fbps-panel-pattern']);
  expect(afterRight.tabStops).toEqual(['fbps-tab-pattern']);
  expect(afterSecondRight.visiblePanels).toEqual(['fbps-panel-shortcode']);
  expect(afterWrap.focused).toBe('fbps-tab-block');
  expect(afterWrap.visiblePanels).toEqual(['fbps-panel-block']);
  expect(afterLeft.focused).toBe('fbps-tab-shortcode');
  expect(afterLeft.visiblePanels).toEqual(['fbps-panel-shortcode']);

  // Switching tabs neither ran a search nor cleared the results.
  expect(rowsAfter).toBe(rowsBefore);
  expect(searchState).toEqual({
    patternButtonBusy: false,
    shortcodeButtonBusy: false,
    patternRows: 0,
    shortcodeRows: 0,
  });

  // Tab leaves the tab list for the visible panel itself, not past its heading.
  expect(focusInPanel && focusInPanel.id).toBe('fbps-panel-shortcode');

  // --- Screen-reader assertions ---
  // Each tab says its name, that it is a tab, and that it is selected.
  expect(tabStop.phrase).toMatch(/Blocks/);
  expect(tabStop.phrase).toMatch(/\btab\b/i);
  expect(tabStop.phrase).toMatch(/selected/i);
  expect(patternPhrase).toMatch(/Patterns/);
  expect(shortcodePhrase).toMatch(/Shortcodes/);
  expect(wrapPhrase).toMatch(/Blocks/);
  expect(leftPhrase).toMatch(/Shortcodes/);
  expect(intoPanelPhrase).toMatch(/Shortcode/);

  expect(log.length).toBeGreaterThan(0);
});
