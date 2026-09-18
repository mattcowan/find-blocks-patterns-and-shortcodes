# NVDA journeys with Guidepup

This is the mechanics-and-traps reference for the journeys in this folder. It is a copy of a document maintained outside the repository; if you change how the harness works, change this file too.

Guidepup drives a portable NVDA that speaks only into a log. The same mechanics apply to a WordPress editor and to a plain web app; only what is on the page differs.

## Machine setup (once)

```bash
npm i -D @playwright/test @guidepup/guidepup @guidepup/playwright
npx @guidepup/setup setup --ci
npx @guidepup/setup install nvda
npx playwright install firefox
```

The portable NVDA lands in `%LOCALAPPDATA%\guidepup\nvda\`. Check that folder before re-running the installer; it survives across projects.

## Project files

If the project's package.json has `"type": "module"`, save every file below with a `.cjs` extension (`playwright.nvda.config.cjs`, `tests/e2e-sr/sr-core.cjs`, `helpers.cjs`, `*.sr.spec.cjs`) and adjust `testMatch`; the templates are CommonJS.

In this folder:

- `playwright.nvda.config.js` — own `testDir` (`tests/e2e-sr`), `workers: 1`, headed Firefox, `screenReaderConfig` from `@guidepup/playwright`. Base URL and optional `storageState` / `globalSetup` / `webServer` come from env or from the small project block at the top of the file.
- `sr-core.js` — copy to `tests/e2e-sr/sr-core.js`. The skill-specific `helpers.js` requires it and adds what the product needs (a WordPress login and block helpers; an app's route/state seeding).

Add `"test:sr": "playwright test -c playwright.nvda.config.js"` to `package.json`. Gitignore `tests/e2e-sr/logs/`, `playwright-report-sr/`, `test-results/` and any saved auth state.

## How Guidepup captures speech (this shapes every journey)

Read from `@guidepup/guidepup`'s NVDA client: a phrase is logged **only around a Guidepup command** (`nvda.press`, `nvda.perform`, `nvda.click`). Before each command it cancels current speech, sends the keys, then collects what NVDA says until speech goes quiet. Speech caused by a Playwright click or `page.keyboard` is never captured, and the default `capture: "initial"` keeps only the first chunk.

So:

- Start NVDA with `test.use({ nvdaStartOptions: { capture: true } })`.
- Build state with Playwright (navigate, seed storage, click through setup). Nothing to hear there.
- For every step whose announcement matters: focus the control with Playwright, then **activate or navigate through `nvda.press`**.
- Bring the browser to the front with `focusBrowser(page, nvda, /title regex/)` from `sr-core.js`. Guidepup's own `navigateToWebContent()` clicks the page body and presses Ctrl+Home, which destroys any selection made before it; call it only before building state, or not at all.

## What a journey records

- `openPhrase` — what a dialog or page announces when it opens or when focus lands on it.
- Every Tab stop: the element that had focus (`describeFocus`) and the phrase.
- The phrase for each arrow, Enter and Space.
- Whether focus is still *inside* the surface under test after each action (`focusInside(page, selector)`; containment, not "not the iframe").
- How many Escapes it took to close (`closeModalWithEscape`).

Save the log as JSON with `saveSpeechLog` outside `test-results/` (Playwright wipes that folder). The report quotes phrases verbatim: "combo box, — Select a style —, collapsed" says more than "select has no name".

## Assertions

Two kinds:

- **Product** — the state changed as the UI promised; the dialog closed; focus returned to the trigger.
- **Screen reader** — a name was announced; no single stop reads the whole dialog (`longestPhrase < ~400`); no silent focusable stops; a live region announcement was heard when the UI relies on one.

It is fine for the screen-reader assertions to fail on the first run; they encode the findings.

## Traps

- **A dialog that opens with a large control set can be silent on entry.** NVDA on Firefox read nothing when a `<dialog>` held ~100 buttons at `showModal()` time, but read the full name and description with 10 (POURcast, 2026-09-17). The focus report (NVDA+Tab) still worked, so it is not a focus bug. Fix on the app side: mount the grid ~250 ms after open (a 0 ms deferral was not enough), then move focus into it. Diagnose by temporarily rendering fewer controls.
- **The title regex must not match your editor's window.** VS Code titles itself "<file> - <folder> - Visual Studio Code", so a loose `/pourcast/i` matched the editor and every NVDA keystroke went into the Claude chat box (one Enter was sent before the run was stopped, 2026-09-16). Match a phrase only the page title has (its tagline), and rely on `focusBrowser`'s second check, `document.hasFocus()`. Send keys only through `press(page, nvda, key)`, which refuses when the page has lost OS focus.
- **`document.hasFocus()` is not a reliable "browser is in front" signal under Playwright.** Headed Firefox reported `true` while VS Code and Chrome were the foreground windows (2026-09-16). `focusBrowser` therefore requires the NVDA-reported window title to match; `press()`'s hasFocus check is a cheap extra, not the protection. If the desktop may be in use, do not run journeys at all.
- **An aborted run leaves the portable NVDA running** (Guidepup only stops the instance it spawned; a killed runner cannot). Quit it with `%LOCALAPPDATA%\guidepup\nvda\all\<version>\extracted\nvda.exe -q` before the next run; `nvda.stop()` reports "NVDA is not running" for an orphan.
- **The mode toggle (NVDA+Space) is silent in Guidepup's profile** (audio indication only). Do not branch on its phrase; probe the widget instead (did ArrowRight move the selection?). `ensureFocusMode` in `sr-core.js` does this.
- **NVDA consumes the first Escape** when it uses it to leave focus mode; the dialog closes on the second. Record how many it took rather than asserting one.
- **A phrase can come back empty** when NVDA had not finished the previous one within Guidepup's 1 s debounce. Add ~600 ms before the next key when the exact phrase matters; treat an isolated "" as a capture miss, not a finding, and re-run.
- **Playwright wipes `test-results/`** at the start of every run. Speech logs go to `tests/e2e-sr/logs/`.
- **The desktop is in use while a journey runs.** Any typing or clicking during the run corrupts it. Journeys take 1–2 minutes each. Tell the user before starting.
- **Guidepup's `applicationNameMap` calls Playwright's Firefox "Nightly"**; window titles read "… — Nightly". Match the page title, not the browser name.
- **Firefox can move `document.activeElement` without firing focus events** when a widget re-applies a selection, and then ignores `focus()` on the element it still records as focused until another node takes focus first. Detecting it needs a poll while the surface is open; fixing it needs "focus another node, then the target". Mouse clicks hide the problem.
- **Reloading the page mid-journey resets module-level state** in single-page apps (for example a "has the user navigated yet" flag that decides whether headings receive focus). Decide up front whether a journey starts from a fresh load or from an in-app navigation, and say which in the log.
