# Screen-reader journeys (NVDA + Guidepup)

These tests drive a real, headed Firefox with a portable NVDA attached. They
take the keyboard and the desktop while they run. Do not run them while you
are using the machine, and do not put them in CI.

The plugin itself has no build step. `package.json` and `node_modules` exist
only for this harness, and `.distignore` keeps all of it out of the plugin that
ships to WordPress.org.

## Install (once per machine)

```bash
npm install
npm run sr:setup
```

`sr:setup` installs the portable NVDA into `%LOCALAPPDATA%\guidepup\nvda\` and
the Playwright Firefox build. Check that folder first; it survives across
projects, so you can usually skip the NVDA step.

## Credentials

Create `.env` in the plugin root. It is gitignored.

```
WP_BASE_URL=http://typography-stylist:8080
WP_USERNAME=matt
WP_PASSWORD=pass
```

`global-setup.js` logs in with headless Chromium and saves the cookies to
`auth.json`. It refuses to post the password over plain HTTP unless the host
resolves to loopback, and pins the resolved address so a later DNS answer
cannot redirect the login. Set `WP_ALLOW_HTTP=1` to override for a trusted
intranet host.

## Run

```bash
npm run test:sr                 # all journeys
npm run test:sr -- -g "sort"    # one of them
npm run test:sr:report          # open the HTML report
```

Each journey takes one to two minutes. Spoken-phrase logs are written to
`tests/e2e-sr/logs/` as JSON; the QA report quotes them verbatim.

## The journeys

| File | What it settles |
|---|---|
| `block-search.sr.spec.js` | The primary flow. Every form control announces a name, the four field hints are read with their control (F8), and the results container does not dump the whole table into one phrase (F7). |
| `results-table.sr.spec.js` | Column toggles keep focus on the checkbox (F5); sortable headers are buttons in `th[scope][aria-sort]` and announce their direction (F6). |
| `landmarks-and-empty-state.sr.spec.js` | Empty results containers are not landmarks, and role and `aria-label` are applied together only when a container fills (F15). |

The screen-reader assertions are deliberately strict. A failure is a finding,
not a broken test — read the saved phrase log before changing an expectation.

## Traps worth knowing

- **An aborted run leaves NVDA running.** Quit it with
  `%LOCALAPPDATA%\guidepup\nvda\all\<version>\extracted\nvda.exe -q` before the
  next run.
- **Speech is captured only around a Guidepup command** (`nvda.press`,
  `nvda.perform`). Anything driven with `page.click` or `page.keyboard` is
  silent in the log. Build state with Playwright; listen through NVDA.
- **The window-title regex must not match your editor.** `helpers.TITLE` is
  scoped to the WordPress admin title for this screen for that reason.
- **An empty phrase is usually a capture miss**, not a finding. Re-run before
  reporting one.

Shared mechanics and the full trap list live in
`~/.claude/skills/shared/qa/nvda-guidepup.md`.
