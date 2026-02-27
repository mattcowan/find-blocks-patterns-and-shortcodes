# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Find Blocks, Patterns & Shortcodes** is a WordPress plugin that audits site content by locating which posts/pages use specific Gutenberg blocks, synced patterns, or shortcodes. It provides an admin UI with progressive batch search, sortable results, CSV export, and WP-CLI commands.

- **WordPress:** 5.0+, **PHP:** 7.0+, **Tested up to:** 6.9
- **Text Domain:** `find-blocks-patterns-shortcodes`
- **Prefix:** `fbps_` for all functions, transients, and identifiers
- **Custom Capability:** `use_find_blocks_patterns_shortcodes`

## Build & Release

No build step for development. The plugin runs directly from the source files.

To create a release zip for WordPress.org submission:
```powershell
# Windows (recommended)
powershell -ExecutionPolicy Bypass -File build.ps1

# Linux/Mac
chmod +x build.sh && ./build.sh
```

To validate before submission:
```bash
wp plugin-check find-blocks-patterns-shortcodes
```

## Testing

No automated test suite. Manual testing against a local WordPress install (WAMP at `c:\wamp64\www\typography-stylist`).

WP-CLI commands for testing:
```bash
wp fbps search core/paragraph --post-type=post,page --format=table
wp fbps clear-cache
wp fbps logs --limit=100 --format=csv
```

## Architecture

This is a single-file PHP plugin with separated CSS/JS assets. All PHP lives in [find-blocks-patterns-shortcodes.php](find-blocks-patterns-shortcodes.php).

### File Structure
- `find-blocks-patterns-shortcodes.php` — All PHP: activation/deactivation hooks, admin menu, AJAX handlers, WP_Query logic, security (rate limiting, validation, audit logging), WP-CLI commands, admin page rendering
- `assets/js/admin.js` — jQuery-based frontend: batch AJAX search, results rendering, table sorting, CSV export, nonce refresh
- `assets/css/admin.css` — Admin page styles with WCAG 2.1 AA focus indicators
- `readme.txt` — WordPress.org plugin directory listing

### Three Search Types

Each search type follows the same pattern: validate input → check rate limit → batch query posts (100 per batch) → check content for match → return results with pagination.

1. **Block search** (`fbps_ajax_search_block`) — Searches `post_content` for `<!-- wp:namespace/block-name` comments using `has_block()` plus regex variation detection
2. **Pattern search** (`fbps_ajax_search_pattern`) — Finds posts containing `wp:block {"ref":ID}` for synced/reusable patterns
3. **Shortcode search** (`fbps_ajax_search_shortcode`) — Uses `has_shortcode()` to find shortcode usage

### AJAX Actions (all require nonce `fbps_search_nonce`)
- `fbps_search_block` — Block batch search
- `fbps_search_pattern` — Pattern batch search
- `fbps_search_shortcode` — Shortcode batch search
- `fbps_refresh_nonce` — Nonce refresh for long sessions

### Security Layers
- Input validation with dangerous pattern blacklisting (`fbps_validate_block_name`, `fbps_validate_shortcode_name`)
- Dual-layer rate limiting: per-user and per-IP (`fbps_check_rate_limit`)
- 25-second timeout safeguard per batch
- Security audit logging to `fbps_security_logs` option (last 1000 events)
- All output escaped with `esc_html`, `esc_attr`, `esc_url`

### Key Filters
- `fbps_query_limit` — Max posts to search (default: 500, max: 1000)
- `fbps_allow_editor_access` — Grant Editor role access (default: false)
- `fbps_enable_security_logging` — Toggle audit logging (default: true)

### Caching
Results are cached as WordPress transients with 5-minute TTL. Cache keys are hashed from search parameters. The `wp fbps clear-cache` CLI command purges all plugin transients.

### WP-CLI
Class `FBPS_CLI` registered as `wp fbps` with subcommands: `search`, `clear-cache`, `logs`.

### Frontend (admin.js)
Uses jQuery with the `fbpsData` localized object for AJAX URL, nonce, and i18n strings. All user-facing strings come from `fbpsData.i18n` for translation support. The JS handles recursive batch fetching — each AJAX response includes `has_more` and `next_offset` to continue pagination.

## Conventions

- All functions use the `fbps_` prefix
- WordPress coding standards (PHPCS with WordPress ruleset)
- Admin page is registered under Tools menu (`tools_page_find-blocks-patterns-shortcodes`)
- Assets load only on the plugin's admin page via hook check
- Multisite aware: activation iterates all sites, hooks `wp_initialize_site` for new sites
- Version managed via `FBPS_VERSION` constant and plugin header (both must match)
