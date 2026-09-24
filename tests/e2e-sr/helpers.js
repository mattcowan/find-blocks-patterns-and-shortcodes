/**
 * Project helpers for the Find Blocks, Patterns & Shortcodes screen-reader
 * journeys. Generic Guidepup mechanics live in sr-core.js; everything here
 * knows about this plugin's admin page.
 *
 * The page under test is a single Tools submenu screen with three search forms
 * (block, synced pattern, shortcode) behind a tab list. Only the selected
 * tab's form is visible; Blocks is selected on load. Each form renders into
 * its own results container. There are no dialogs and no iframes, so the
 * journeys are flat: load the page, select a tab, drive its form, listen.
 */
const core = require('./sr-core');

/** Path of the plugin's admin screen, relative to the site root. */
const ADMIN_PATH = '/wp-admin/tools.php?page=find-blocks-patterns-shortcodes';

/**
 * The page title regex used to confirm the browser is really in front.
 *
 * Matched against the window title as NVDA SPEAKS it, which is not the literal
 * document title. On this page NVDA says:
 *
 *   "Find Blocks, Patterns and Shortcodes < Typography Stylist - Word Press - Nightly"
 *
 * Two transformations to allow for: "&" is spoken as "and", and NVDA splits
 * camel case, so "WordPress" comes through as "Word Press". Guidepup also
 * appends "- Nightly" because Playwright's Firefox build is named that.
 *
 * Still deliberately specific. A loose /find blocks/i would also match an
 * editor window with this plugin's folder open ("find-blocks-patterns-and-
 * shortcodes - C: wamp 64 www ..."), and every NVDA keystroke would then be
 * typed into the editor. Requiring the comma, the spaced words and the
 * WordPress admin suffix keeps it to the browser. The site name is NOT part
 * of the match, because the harness lets WP_BASE_URL point at any site.
 */
const TITLE = /Find Blocks, Patterns (?:&|and) Shortcodes.*Word ?Press/i;

/** Selectors for the three search surfaces, kept in one place. */
const SURFACES = {
  block: {
    tab: '#fbps-tab-block',
    panel: '#fbps-panel-block',
    form: '.fbps-search-section[aria-label*="block usage"]',
    results: '#fbps-search-results',
    progress: '#fbps-progress',
    searchButton: '#fbps-search-button',
    cancelButton: '#fbps-cancel-button',
  },
  pattern: {
    tab: '#fbps-tab-pattern',
    panel: '#fbps-panel-pattern',
    form: '.fbps-search-section[aria-label*="synced pattern"]',
    results: '#fbps-pattern-search-results',
    progress: '#fbps-pattern-progress',
    searchButton: '#fbps-pattern-search-button',
    cancelButton: '#fbps-pattern-cancel-button',
  },
  shortcode: {
    tab: '#fbps-tab-shortcode',
    panel: '#fbps-panel-shortcode',
    form: '.fbps-search-section[aria-label*="shortcode usage"]',
    results: '#fbps-shortcode-search-results',
    progress: '#fbps-shortcode-progress',
    searchButton: '#fbps-shortcode-search-button',
    cancelButton: '#fbps-shortcode-cancel-button',
  },
};

/**
 * Open the plugin's admin screen and bring the browser to the front.
 *
 * Always a fresh load: the page holds all of its state in closure variables in
 * admin.js, so a reload is the only reliable reset between journeys.
 */
async function gotoAdminPage(page, nvda) {
  await page.goto(ADMIN_PATH, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#fbps-search-button');
  await core.focusBrowser(page, nvda, TITLE);
}

/**
 * Select a surface's tab so its form is visible, and wait for the panel.
 *
 * Setup only, like runSearch: a Playwright click, not an NVDA key, so nothing
 * is captured. search-tabs.sr.spec.js covers the tabs as NVDA hears them.
 */
async function selectTab(page, surface) {
  const s = SURFACES[surface];
  await page.click(s.tab);
  await page.waitForSelector(s.panel, { state: 'visible' });
}

/**
 * Run a search and wait for it to finish. Selects the surface's tab first.
 *
 * Setup only - nothing here is captured, because speech is logged only around
 * a Guidepup command. Journeys that care about what a search announces should
 * activate the button through `core.activate` instead.
 */
async function runSearch(page, surface, fill) {
  const s = SURFACES[surface];
  await selectTab(page, surface);
  await fill(page);
  await page.click(s.searchButton);
  await page.waitForFunction(
    (sel) => !document.querySelector(sel).disabled,
    s.searchButton,
    { timeout: 60000 }
  );
  await page.waitForTimeout(300);
}

/** Type a block name into the block form. */
function fillBlockName(name) {
  return async (page) => {
    await page.fill('#fbps-block-name', name);
  };
}

/** Select the first real option in one of the dropdowns. */
function selectFirstOption(selector) {
  return async (page) => {
    const value = await page.evaluate((sel) => {
      const d = document.querySelector(sel);
      const opt = [...d.options].find((o) => o.value && !o.disabled);
      if (!opt) return null;
      d.value = opt.value;
      d.dispatchEvent(new Event('change', { bubbles: true }));
      return opt.value;
    }, selector);
    if (!value) throw new Error(`No selectable option in ${selector}`);
    return value;
  };
}

/**
 * Read back how a results container is exposed right now.
 *
 * Used to pin the F15 fix: role and aria-label are applied by admin.js only
 * while the container holds results, so an empty one must report neither.
 */
async function describeResultsRegion(page, surface) {
  return page.evaluate((sel) => {
    const el = document.querySelector(sel);
    return {
      role: el.getAttribute('role'),
      ariaLabel: el.getAttribute('aria-label'),
      ariaLive: el.getAttribute('aria-live'),
      ariaAtomic: el.getAttribute('aria-atomic'),
      rows: el.querySelectorAll('tbody tr').length,
      textLength: el.textContent.trim().length,
    };
  }, SURFACES[surface].results);
}

/** Read the aria-sort state of every column header in a results table. */
async function readSortState(page, surface) {
  return page.evaluate((sel) => {
    return [...document.querySelectorAll(sel + ' th')].map((th) => ({
      text: th.textContent.trim(),
      scope: th.getAttribute('scope'),
      ariaSort: th.getAttribute('aria-sort'),
      control: th.querySelector('button') ? 'button' : th.querySelector('a') ? 'link' : 'none',
    }));
  }, SURFACES[surface].results);
}

/** The column visibility checkboxes, in DOM order. */
function columnToggles(page) {
  return page.locator('.fbps-col-toggle');
}

module.exports = {
  ...core,
  ADMIN_PATH,
  TITLE,
  SURFACES,
  gotoAdminPage,
  selectTab,
  runSearch,
  fillBlockName,
  selectFirstOption,
  describeResultsRegion,
  readSortState,
  columnToggles,
};
