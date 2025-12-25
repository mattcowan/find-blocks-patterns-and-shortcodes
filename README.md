# Find Blocks, Patterns, and Shortcodes

A WordPress plugin that helps administrators find which posts and pages use specific Gutenberg blocks, reusable block patterns, and shortcodes.

## Description

Find Blocks, Patterns, and Shortcodes adds an admin interface for searching posts and pages containing specific Gutenberg blocks, patterns, or shortcodes. Enter a block name, pattern, or shortcode and get instant results showing where it is used.

## Features

### Core Features
- **Progressive batch search** for large sites (100 posts per batch)
- **Search results** showing post title, type, date, and direct edit/view links
- **Sortable results tables** for easy analysis
- **CSV export** functionality for reporting
- **Post type filtering** - search across posts, pages, or custom post types
- **Block dropdown** - select from all registered blocks
- **Synced pattern search** - find usage of reusable blocks/patterns
- **Shortcode search** - locate shortcode usage across content
- **Cancellable searches** with progress indicators
- **WP-CLI support** for automation
- **Keyboard accessible** with full Enter key support

### Security Features
- **Input validation and rate limiting** - Enhanced validation with dangerous pattern blacklisting and dual-layer rate limiting (user + IP based)
- **Security audit logging** - Event logging with full audit trail for monitoring access and abuse
- **Custom capability system** - Granular permissions with custom WordPress capability
- **Timeout and abuse prevention** - Protection against server overload, information disclosure, and injection attacks

### Accessibility Features
- **WCAG 2.1 Level AA compliant** with full accessibility implementation
- **Screen reader support** with ARIA live regions and result announcements
- **Keyboard navigation** throughout interface with Enter key support
- **Form labels** for all inputs with proper associations
- **Focus management** with visible focus indicators (2px outline)
- **Responsive design** supporting 200% zoom

## Installation

1. Upload the `find-blocks-patterns-shortcodes` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu in WordPress
3. The plugin automatically adds the `use_find_blocks_patterns_shortcodes` capability to Administrators
4. Access via **Find Blocks, Patterns & Shortcodes** menu item (with search icon) in the admin menu

## Usage

1. Navigate to **Find Blocks, Patterns & Shortcodes** in your WordPress admin menu (search icon)
2. Enter a block name or select from the dropdown (e.g., `core/paragraph`, `core/heading`, `core/gallery`)
3. Select post types to search (posts, pages, or custom post types)
4. Click **Search Block** or press **Enter** to start progressive batch search
5. View sortable results table with title, type, and date
6. Click **Edit** or **View** links to navigate to posts
7. Click **Export CSV** to download results for reporting
8. Use **Cancel** button to stop long-running searches

### Block Name Format
Block names must follow the format: `namespace/block-name`

Examples:
- `core/paragraph`
- `core/image`
- `core/heading`
- `woocommerce/product-price`

## Requirements

- **WordPress:** 5.0 or higher (Gutenberg blocks required)
- **PHP:** 7.0 or higher
- **Capability:** Administrator role (or custom role with `use_find_blocks_patterns_shortcodes` capability)

## Configuration

### Allow Editor Access
```php
add_filter('fbps_allow_editor_access', '__return_true');
```

### Adjust Search Limit
```php
add_filter('fbps_query_limit', function() {
    return 1000; // Default: 500, Max: 1000
});
```

### Disable Security Logging
```php
add_filter('fbps_enable_security_logging', '__return_false');
```

### External Security Monitoring
```php
add_action('fbps_security_event', function($log) {
    // Send to external logging service
    error_log(json_encode($log));
});
```

## Security

This plugin implements multiple security layers including rate limiting, input validation, output sanitization, audit logging, and abuse prevention.

For complete security details, see [SECURITY.md](SECURITY.md)

## Accessibility

Designed with **WCAG 2.1 Level AA** accessibility standards, implementing screen reader compatibility, keyboard navigation, focus indicators, ARIA labels and live regions, and responsive design with 200% zoom support.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history and detailed changes.

## Support

For issues, feature requests, or security concerns:
- Check existing documentation
- Review security logs: `get_option('fbps_security_logs')`
- Verify capability: User must have `use_find_blocks_patterns_shortcodes`

## License

GPL v2 or later - [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)

## Author

**Matthew Cowan**