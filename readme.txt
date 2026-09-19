=== Find Blocks, Patterns & Shortcodes ===
Contributors: matthewneilcowan
Tags: gutenberg, blocks, search, admin, content
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.0
Stable tag: 1.1.3
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

= 1.1.3 =
* Fixed synced pattern search, which could not find any content. The search now uses the WordPress block parser instead of a text match, so it also finds patterns that use pattern overrides or a renamed instance.
* Fixed posts with no title being left out of the results table. The result count and the CSV export included them, so the three did not agree. Posts with no title now show as "(no title)".
* Fixed the Export CSV button, which did not appear after a pattern search or a shortcode search.
* A failed batch no longer discards the results that were already found. The results stay on screen with a message that the search stopped early.
* The column checkboxes no longer move the keyboard focus to the results when you change them.
* The field hints are now read out with the field they describe.
* Empty result areas are no longer announced as landmarks.
* The Title column in the CSV export now says "(no title)" for a post with no title, the same as the table.
* A screen reader no longer reads the full results table each time you change a column or sort it. The plugin now says only how many results it found.
* The search results no longer take the keyboard focus when they load.
* The version check script now also checks package.json.
* The security headers for the plugin page are now sent. They were attached to a hook that runs before WordPress knows which screen is loading, so they never were.
* The results column that showed the last modified date is now labeled "Modified", not "Date".
* Dates in the results table now use the date format and language set in Settings > General.
* The CSV export header row is now translated, the same as the table.
* A new synced pattern now appears in the pattern list at once. Before, it could take up to five minutes.
* The View and Edit links in all three results tables now show the same focus outline.
* The rate limit now allows a full search of up to 1000 posts. Before, it counted each batch of a search against a limit of 30 a minute, so three or four large searches could lock you out. It also no longer extends your wait time each time you try again.
* A search now stops at the post limit (500 by default, 1000 at most, set with the `fbps_query_limit` filter). The limit was documented but not applied. This also applies to `wp fbps search`.
* A shortcode from a plugin you activate now appears in the shortcode list at once.
* The plugin no longer sends the X-XSS-Protection header. Browsers no longer use it.
* Sortable column headers are now buttons, they tell a screen reader the sort direction, and results start sorted by date, newest first. The CSV export uses the same order as the table, also after you sort by a column.

= 1.1.2 =
* Added `rel="noopener noreferrer"` to the View and Edit result links to harden against reverse-tabnabbing

= 1.1.1 =
* Fixed Edit links in search results not opening the post editor (URLs were double-HTML-encoded, leaving a literal `&#038;` in the query string so `action=edit` was never parsed)
* Edit links now open in a new tab, matching the View link behavior, so search results are preserved

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
