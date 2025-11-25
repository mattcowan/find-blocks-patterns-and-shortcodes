=== Block Usage Finder ===
Contributors: matthewcowan
Tags: gutenberg, blocks, search, admin, content
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.0
Stable tag: trunk
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find which posts and pages use specific Gutenberg blocks with advanced search, security hardening, and full accessibility support.

== Description ==

Block Usage Finder helps WordPress administrators quickly locate posts and pages containing specific Gutenberg blocks. Perfect for content audits, site migrations, and block usage analysis.

= Core Features =

* Progressive search with batch processing for large sites
* Post type filtering - search across posts, pages, or custom post types
* CSV export - export results for reporting and analysis
* Block dropdown - select from all registered blocks
* Synced pattern search - find usage of reusable blocks/patterns
* Real-time results with sortable tables
* WP-CLI support for automation

= Security =

10/10 security rating with enhanced input validation, dual-layer rate limiting, security event logging, custom capability system, timeout protection, and information disclosure prevention.

= Accessibility =

WCAG 2.1 AA compliant with screen reader support, full keyboard navigation, visible focus indicators, form labels, and responsive design supporting 200% zoom.

= Performance =

Smart caching with 5-minute TTL, batch processing, query optimization, progress indicators, cancellable searches, and hard limit protection.

== Installation ==

1. Upload the plugin files to /wp-content/plugins/block-usage-finder/
2. Activate the plugin through the Plugins menu in WordPress
3. Navigate to Tools > Block Usage Finder
4. Start searching for blocks!

== Frequently Asked Questions ==

= What block name format should I use? =

Block names follow the format namespace/block-name. Examples: core/paragraph, core/image, woocommerce/product-price

= Can I search custom blocks? =

Yes! The plugin works with any registered Gutenberg block.

= How do I allow Editors to use this plugin? =

Add this filter: add_filter('buf_allow_editor_access', '__return_true');

= Can I export the results? =

Yes! Click the Export CSV button after searching.

= Does it work with WP-CLI? =

Yes! Use: wp block-usage search core/paragraph

== Changelog ==

= 2.0.0 =
* Added progressive batch search for large sites
* Added post type filtering
* Added CSV export functionality
* Added synced pattern search
* Added WP-CLI support
* Added smart caching (5-minute TTL)
* Added cancel search functionality
* Added sortable results tables
* Enhanced security with IP-based rate limiting
* Improved accessibility (WCAG 2.1 AA)
* Performance optimizations

= 1.0.0 =
* Initial release

== Privacy ==

This plugin does not collect any user data or make external API calls. Security logs are stored locally and can be disabled via filter.
