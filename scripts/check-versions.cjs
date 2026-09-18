/**
 * Version-consistency guard. Zero dependencies.
 *
 * The plugin's version lives in FOUR places that must always agree:
 *   1. find-blocks-patterns-shortcodes.php - plugin header `Version:`
 *   2. find-blocks-patterns-shortcodes.php - `define('FBPS_VERSION', '...')`
 *   3. readme.txt                          - `Stable tag:`
 *   4. package.json                        - `version`
 *
 * The plugin still has no build step; package.json exists only for the NVDA
 * screen-reader harness in tests/e2e-sr and never ships (see .distignore).
 * It is checked anyway, because a version that drifts from the other three is
 * a bug whether or not the file is distributed. It is treated as optional: if
 * package.json is ever removed, the guard falls back to three sources rather
 * than failing.
 *
 * Modes:
 *   node scripts/check-versions.cjs
 *     Consistency mode (runs in CI on every push): every source must match
 *     each other. Exits 1 with a table of mismatches.
 *
 *   node scripts/check-versions.cjs v1.1.3
 *     Tag mode (runs before a wp.org deploy): all three must equal the tag
 *     (leading "v" stripped). This is what stops a GitHub Release tagged
 *     v1.1.3 from deploying files that still say 1.1.2 — the #1 wp.org
 *     release mistake, because `Stable tag` decides what wp.org serves.
 *
 *   node scripts/check-versions.cjs v1.2.0-beta.1 --prerelease
 *     Pre-release mode: header/constant must equal the BASE version (1.2.0),
 *     and Stable tag must NOT equal it — during a beta the Stable tag must
 *     keep pointing at the last stable release so wp.org never serves
 *     unreleased code.
 *
 * Also warns (never fails) when `Tested up to:` includes a patch version —
 * wp.org convention is major.minor (e.g. 6.9, not 6.9.4).
 */
const fs = require('fs');
const path = require('path');

const rootDir = path.join(__dirname, '..');
const read = f => fs.readFileSync(path.join(rootDir, f), 'utf8');

const args = process.argv.slice(2);
const prerelease = args.includes('--prerelease');
const tagArg = args.find(a => !a.startsWith('--')) || null;

function extract(pattern, text, label, file) {
  const m = text.match(pattern);
  if (!m) {
    console.error(`✗ Could not find ${label} in ${file}`);
    process.exit(1);
  }
  return m[1].trim();
}

const mainPhp = read('find-blocks-patterns-shortcodes.php');
const readmeTxt = read('readme.txt');

const versions = {
  'plugin header (find-blocks-patterns-shortcodes.php)': extract(/^\s*\*\s*Version:\s*(.+)$/m, mainPhp, 'plugin header Version', 'find-blocks-patterns-shortcodes.php'),
  'FBPS_VERSION (find-blocks-patterns-shortcodes.php)': extract(/define\(\s*'FBPS_VERSION'\s*,\s*'([^']+)'/, mainPhp, 'FBPS_VERSION', 'find-blocks-patterns-shortcodes.php'),
  'Stable tag (readme.txt)': extract(/^Stable tag:\s*(.+)$/m, readmeTxt, 'Stable tag', 'readme.txt')
};

// package.json is dev-only tooling and may legitimately not exist; when it
// does, its version is held to the same standard as the other three.
const pkgPath = path.join(rootDir, 'package.json');
if (fs.existsSync(pkgPath)) {
  let pkg;
  try {
    pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf8'));
  } catch (e) {
    console.error(`\u2717 package.json exists but is not valid JSON: ${e.message}`);
    process.exit(1);
  }
  if (typeof pkg.version !== 'string' || !pkg.version.trim()) {
    console.error('\u2717 package.json exists but has no "version" field.');
    process.exit(1);
  }
  versions['version (package.json)'] = pkg.version.trim();
}

function table(expectedByKey) {
  const width = Math.max(...Object.keys(versions).map(k => k.length));
  for (const [key, value] of Object.entries(versions)) {
    const expected = expectedByKey ? expectedByKey[key] : null;
    const marker = expected === null || expected === undefined
      ? ''
      : (expected === 'must-differ'
        ? (value !== expectedByKey.base ? '  ✓' : `  ✗ must NOT be ${expectedByKey.base}`)
        : (value === expected ? '  ✓' : `  ✗ expected ${expected}`));
    console.log(`  ${key.padEnd(width)}  ${value}${marker}`);
  }
}

let failed = false;

if (!tagArg) {
  // Consistency mode
  const values = Object.values(versions);
  const allMatch = values.every(v => v === values[0]);
  console.log('Version consistency check:');
  table(null);
  if (!allMatch) {
    console.error(`\n✗ Version mismatch — all ${Object.keys(versions).length} version sources must agree.`);
    failed = true;
  } else {
    console.log(`\n✓ All version sources agree: ${values[0]}`);
  }
} else {
  const tag = tagArg.replace(/^v/, '');
  if (!prerelease) {
    // Stable tag mode: everything must equal the tag exactly.
    console.log(`Release version check against tag ${tagArg}:`);
    const expected = {};
    for (const key of Object.keys(versions)) expected[key] = tag;
    table(expected);
    if (!Object.values(versions).every(v => v === tag)) {
      console.error(`\n✗ One or more version sources do not match release tag ${tagArg}. Aborting before any deploy.`);
      failed = true;
    } else {
      console.log(`\n✓ All version sources match release tag ${tagArg}`);
    }
  } else {
    // Pre-release mode: base version everywhere except Stable tag, which
    // must still point at the previous stable release.
    const base = tag.replace(/[-+].*$/, '');
    if (base === tag) {
      console.error(`✗ --prerelease given but tag ${tagArg} has no pre-release suffix (expected e.g. v1.2.0-beta.1).`);
      process.exit(1);
    }
    console.log(`Pre-release version check against tag ${tagArg} (base ${base}):`);
    const expected = { base };
    for (const key of Object.keys(versions)) {
      expected[key] = key.startsWith('Stable tag') ? 'must-differ' : base;
    }
    table(expected);
    const stable = versions['Stable tag (readme.txt)'];
    const others = Object.entries(versions)
      .filter(([k]) => !k.startsWith('Stable tag'))
      .map(([, v]) => v);
    if (!others.every(v => v === base)) {
      console.error(`\n✗ Plugin header / FBPS_VERSION must equal the base version ${base} for a pre-release.`);
      failed = true;
    }
    if (stable === base) {
      console.error(`\n✗ Stable tag equals ${base} — during a beta, Stable tag must keep pointing at the last STABLE release, or wp.org will try to serve unreleased code.`);
      failed = true;
    }
    if (!failed) {
      console.log(`\n✓ Pre-release versions OK (Stable tag stays at ${stable})`);
    }
  }
}

// Advisory: Tested up to should be major.minor
const testedUpTo = readmeTxt.match(/^Tested up to:\s*(.+)$/m);
if (testedUpTo && /^\d+\.\d+\.\d+/.test(testedUpTo[1].trim())) {
  console.warn(`⚠ Tested up to: ${testedUpTo[1].trim()} — wp.org convention is major.minor (e.g. ${testedUpTo[1].trim().split('.').slice(0, 2).join('.')}).`);
}

process.exit(failed ? 1 : 0);
