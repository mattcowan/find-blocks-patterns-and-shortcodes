# Releasing Find Blocks, Patterns & Shortcodes

How this plugin gets from a git commit to users' WordPress dashboards — and
why each piece exists. GitHub is the source of truth; WordPress.org is a
deploy target. This doc is GitHub-only (excluded from the distributed zip by
`.distignore`).

## 1. The mental model

```
main branch (git)
   │  you publish a GitHub Release with tag vX.Y.Z
   ▼
GitHub Actions (release-deploy.yml)
   │  version guard (scripts/check-versions.cjs)
   ▼
WordPress.org SVN  (https://plugins.svn.wordpress.org/find-blocks-patterns-shortcodes)
   ├─ trunk/        ← rsync of the repo minus .distignore
   ├─ tags/X.Y.Z/   ← snapshot copied from trunk
   └─ assets/       ← synced from .wordpress-org/ (banners, icons, screenshots)
   ▼
wp.org serves the version named by readme.txt's `Stable tag`
```

Things that follow from this model:

- **`Stable tag` in readme.txt is the actual "go live" switch.** wp.org
  serves whatever version `Stable tag` names, from `tags/X.Y.Z/`. Committing
  to trunk does nothing user-visible until the Stable tag points at a tag
  that exists. This is also the #1 way releases go wrong (tag pushed, Stable
  tag forgotten, or vice versa) — which is why CI runs
  `scripts/check-versions.cjs` before every deploy.
- **SVN here is not version control — it's a delivery mechanism.** Never
  hand-edit SVN trunk; the next CI deploy rsyncs over it (with deletion).
  History lives in git.
- **`.distignore` decides what ships.** The CI deploy and the beta/on-demand
  zip both read it. Dev files (build scripts, GitHub docs, `scripts/`) never
  reach users; new production files ship automatically. What ships:
  `find-blocks-patterns-shortcodes.php`, `readme.txt`, `assets/`, `LICENSE`.
- **No build step.** This is a single-file plugin — a plain checkout is
  already installable, so unlike a compiled plugin there is no `npm run
  build` in the pipeline. The `package.json` at the root exists only for
  the NVDA screen-reader test harness in `tests/e2e-sr/` (run on demand
  with `npm run test:sr`, never in CI); it and `node_modules/` are excluded
  from the shipped plugin by `.distignore`. The only tooling in the release
  path is the zero-dependency Node version guard.

## 2. Why GitHub Releases, not GitHub Packages

GitHub **Packages** is a set of package registries (npm, Docker, Maven, …). A
WordPress plugin zip is none of those; it's a downloadable artifact tied to a
version, which is exactly what a GitHub **Release asset** is. So "Packages"
stays empty by design and "Releases" fills up — one entry per version, with
human-written notes and an installable zip attached.

## 3. One-time setup

### 3a. WordPress.org slug (confirmed)

The plugin is live at
<https://wordpress.org/plugins/find-blocks-patterns-shortcodes/>, so the
wp.org **slug is `find-blocks-patterns-shortcodes`** — note this differs from
the GitHub repo name `find-blocks-patterns-and-shortcodes` (with "and"). That
slug is set as `SLUG:` in `release-deploy.yml` and `wporg-assets.yml`; the
stable deploy commits to
`https://plugins.svn.wordpress.org/find-blocks-patterns-shortcodes`. There is
also a local SVN working copy at
`C:\wamp64\www\wordpress-plugins\find-blocks-patterns-shortcodes` — that was
the old manual deploy path; keep it only as an emergency fallback, since the
next CI deploy rsyncs over trunk (with deletion).

### 3b. WordPress.org SVN credentials → GitHub secrets

**Repo → Settings → Secrets and variables → Actions → New repository secret**

| Secret | Value |
|---|---|
| `SVN_USERNAME` | your wordpress.org username |
| `SVN_PASSWORD` | your wp.org **SVN password** — see below |

**SVN password gotcha:** wordpress.org supports a separate SVN-specific
password so your main login never lives in CI (and it's *required* if the
account has 2FA enabled). Generate it at **profiles.wordpress.org → Edit
Profile → Account & Security → SVN password** *(verify the exact menu — this
UI has moved before)*. Use that value, not your login password.

### 3c. GitHub Actions settings

Repo → Settings → Actions → General: "Allow all actions and reusable
workflows" (or allowlist `10up/*` and `shivammathur/*`). Default workflow
permissions can stay **read-only** — `release-deploy.yml` requests
`contents: write` for itself to attach the zip.

## 4. Standard release checklist (stable)

1. **Bump the version in all four places** (check-versions.cjs enforces this):
   - `find-blocks-patterns-shortcodes.php` — plugin header `Version:`
   - `find-blocks-patterns-shortcodes.php` — `define('FBPS_VERSION', ...)`
   - `readme.txt` — `Stable tag:`
   - `package.json` — `version` (dev-only file, but the guard checks it so it
     cannot drift from the other three)
2. Add a changelog entry in `readme.txt` under `== Changelog ==` (the wp.org
   listing renders it via the assets sync).
3. Push to `main`; wait for **CI** to go green (version consistency check +
   PHP lint).
4. **GitHub → Releases → Draft a new release**: create tag `vX.Y.Z` on
   `main`, title it, write the release notes (users see these). Leave "Set as
   a pre-release" UNCHECKED.
5. **Publish.** Watch the **Release Deploy** run in the Actions tab.
6. Verify: wp.org listing shows the new version; the zip is attached to the
   GitHub Release; install/update on a test site works.

Tag format is always `vX.Y.Z` (the workflow strips the `v` when comparing
against the plugin's version strings).

## 5. Beta releases

Betas live on GitHub only — wp.org has no beta channel and the deploy
workflow never touches SVN for a pre-release.

1. Bump the plugin header and `FBPS_VERSION` to the **next** version (e.g.
   `1.2.0`). **Leave `Stable tag` at the current stable release** — the
   version guard fails the build if a beta tries to move it.
2. Draft a release with tag `vX.Y.Z-beta.N` (e.g. `v1.2.0-beta.1`) and
   **check "Set as a pre-release"**.
3. Publish. CI attaches an installable zip to the pre-release; testers
   download and install it manually (Plugins → Add New → Upload Plugin).
   wp.org users are unaffected.

## 6. Hotfixes

If `main` has moved past the release you need to patch: branch from the
release tag (`git switch -c hotfix/1.1.3 v1.1.2`), apply the fix, bump to
`X.Y.Z+1` in all four places, merge back to `main`, and release as normal.
If `main` hasn't diverged, a hotfix is just a small stable release.

## 7. Readme / assets updates without a release

Edit `readme.txt` or files in `.wordpress-org/` (banners, icons, screenshots)
and push to `main` — the **WP.org Readme/Assets Sync** workflow updates wp.org
directly, no version bump needed. Use it for bumping `Tested up to:` after a
new WordPress release, readme typos/FAQ additions, or swapping screenshots
(files deleted from `.wordpress-org/` are deleted from wp.org too).

`.wordpress-org/` is already seeded with the current live assets (banners,
icons, `icon.svg`, screenshots), byte-identical to what wp.org serves — so
the first sync is a no-op. Note these use hyphen names (`banner-772-250.png`,
`icon-128-128.png`) rather than the more common `x` form (`banner-772x250.png`);
wp.org serves both, and the live listing already renders them, so they are
left as-is. Captions come from readme.txt's `== Screenshots ==` list
(N ↔ screenshot-N). The sync is destructive: a file removed from
`.wordpress-org/` is removed from wp.org too.

## 8. On-demand builds (and why there's no nightly)

Need an installable zip from any branch without cutting a release?
**Actions → CI → Run workflow** (pick the branch) → download the
`find-blocks-patterns-shortcodes-<sha>` artifact. Locally, `build.ps1`
(Windows) or `build.sh` (Linux/Mac) produce a comparable zip.

There is deliberately no scheduled nightly build: a nightly with no consumers
is CI noise, and the on-demand button plus `-beta.N` pre-releases cover every
real "give someone a build" case with better traceability.

## 9. Local build scripts

`build.ps1` / `build.sh` / `build.bat` still exist for building a zip on your
own machine, but the **release path is now CI → wp.org**, not a hand-built
zip. Prefer the on-demand CI build (§8) for anything you hand to someone, so
the artifact is traceable to a commit.
