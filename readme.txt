=== Find Blocks, Patterns & Shortcodes ===
Contributors: matthewneilcowan
Tags: gutenberg, blocks, search, admin, content
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find which posts and pages use specific Gutenberg blocks, patterns, and shortcodes with advanced search and CSV export functionality.

== Description ==

Find Blocks, Patterns & Shortcodes locates content containing specific Gutenberg blocks (including options to search by CSS class and HTML anchor attributes), patterns, and shortcodes, with a CSV export feature perfect for audits & analysis.

= Core Features =

* **Progressive search** with batch processing for large sites
* **Post type filtering** - search across posts, pages, or custom post types to find blocks, patterns, and shortcodes
* **CSV export** - export results for reporting, auditing, and analysis
* **Block dropdown** - select from all registered blocks
* **Attribute search** - find blocks by CSS class and HTML anchor attributes
* **Synced pattern search** - find usage of reusable blocks/patterns
* **Sortable results tables** for easy analysis
* **WP-CLI support** for automation

= Performance Optimized =

* Smart caching with 5-minute TTL
* Batch processing (100 posts per batch)
* Query optimization (IDs only fetch)
* Progress indicators for long operations
* Cancellable searches
* Hard limit protection (500-1000 posts)

= Security =

* Enhanced input validation with blacklisting
* Dual-layer rate limiting (user + IP)
* Timeout protection (25-second safeguard)
* Information disclosure prevention
* XSS and injection prevention
* Nonce auto-refresh for long sessions

= Accessibility =

* Screen reader compatible with ARIA live regions
* Full keyboard navigation support
* Visible focus indicators
* Form labels for all inputs
* Results count announcements
* Responsive design with 200% zoom support

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/find-blocks-patterns-shortcodes/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to Tools > Find Blocks, Patterns & Shortcodes
4. Start searching for blocks!

== Frequently Asked Questions ==

= What block name format should I use? =

Block names follow the format `namespace/block-name`. Examples:
* `core/paragraph`
* `core/image`
* `woocommerce/product-price`

= Can I search custom blocks? =

Yes! The plugin works with any registered Gutenberg block, including custom blocks from themes and plugins.

= How do I allow Editors to use this plugin? =

Add this filter to your theme's functions.php:
`add_filter('fbps_allow_editor_access', '__return_true');`

= Can I export the results? =

Yes! Click the "Export CSV" button after searching to download results as a spreadsheet.

= Does it work with WP-CLI? =

Yes! Use `wp block-usage search core/paragraph` for command-line searches.

= How do I search for synced patterns? =

Use the "Search for Synced Pattern Usage" section to find where reusable blocks/patterns are used.

== Screenshots ==

1. Main search interface with block dropdown and post type selection
2. Search results table with sortable columns and export csv button

== Changelog ==

= 1.1.0 =
* Added CSS class and HTML anchor search for block attributes
* Search by class/anchor alone or combined with block name
* Added configurable result table columns (Title, Type, Date shown by default; CSS Class and HTML Anchor optional)
* Column visibility toggles re-render results in real-time
* CSV export respects visible column selection
* Improved sorting arrow UX with larger Unicode indicators and hover states
* Refactored display functions to shared table builder for consistency

= 1.0.3 =
* Properly include assets (fixed version number)

= 1.0.2 =
* Properly include assets

= 1.0.1 =
* Refactored asset loading to use WordPress enqueue standards (wp_enqueue_style/wp_enqueue_script)
* Extracted inline CSS to separate file (assets/css/admin.css)
* Extracted inline JavaScript to separate file (assets/js/admin.js)
* Added version constant for consistent cache busting
* Improved code maintainability and browser caching
* Added translator comments for i18n best practices
* WordPress Plugin Review compliant

= 1.0.0 =
* Initial release
* Basic block search functionality
* Added progressive batch search for large sites
* Added post type filtering
* Added CSV export functionality
* Added synced pattern search
* Added WP-CLI support
* Added smart caching (5-minute TTL)
* Added cancel search functionality
* Added sortable results tables
* Enhanced security with IP-based rate limiting
* Performance optimizations

== WP-CLI Commands ==

Search for blocks:
`wp block-usage search core/paragraph --post-type=post,page --format=table`

Clear cache:
`wp block-usage clear-cache`

View security logs:
`wp block-usage logs --limit=100 --format=csv`

== Filters and Hooks ==

= Filters =

* `fbps_query_limit` - Adjust search limit (default: 500, max: 1000)
* `fbps_enable_security_logging` - Toggle security logging (default: true)
* `fbps_allow_editor_access` - Allow Editor role access (default: false)

= Actions =

* `fbps_security_event` - Hook into security event logging

== Privacy ==

This plugin:
* Does not collect any user data
* Does not make external API calls
* Stores security logs locally (last 1000 events)
* Logs include: timestamp, user ID, IP address, event type
* Security logs can be disabled via filter

== Support ==

For support, feature requests, or bug reports, please use the WordPress.org support forums.

== Credits ==

Developed by Matthew Cowan
