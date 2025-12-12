# Find Blocks, Patterns, and Shortcodes

A WordPress plugin that helps administrators find which posts and pages use specific Gutenberg blocks, reusable block patterns, and shortcodes.

## Description

Find Blocks, Patterns, and Shortcodes adds an admin interface for searching posts and pages containing specific Gutenberg blocks, patterns, or shortcodes. Enter a block name, pattern, or shortcode and get instant results showing where it is used.

## Features

### Core Features
- **Dynamic search** with 500ms debouncing
- **Search results** showing post title, type, and direct edit links
- **Clean admin interface** with search icon in WordPress menu
- **Keyboard accessible** with full Enter key support

### Security Features
- **Input validation and rate limiting** - Enhanced validation with dangerous pattern blacklisting and dual-layer rate limiting (user + IP based)
- **Security audit logging** - Event logging with full audit trail for monitoring access and abuse
- **Custom capability system** - Granular permissions with custom WordPress capability
- **Timeout and abuse prevention** - Protection against server overload, information disclosure, and injection attacks

### Accessibility Features
Implements accessibility features including screen reader support with ARIA live regions, keyboard navigation, form labels, focus management with visible indicators, results announcements for assistive technology, and responsive design supporting 200% zoom.

## Installation

1. Upload the `find-blocks-patterns-shortcodes` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu in WordPress
3. The plugin automatically adds the `use_find_blocks_patterns_shortcodes` capability to Administrators
4. Access via **Block Usage** menu item (with search icon) in the admin menu

## Usage

1. Navigate to **Block Usage** in your WordPress admin menu
2. Enter a block name (e.g., `core/paragraph`, `core/heading`, `core/gallery`)
3. Results appear automatically as you type (with 500ms debounce)
4. Click any result to edit that post or page
5. Press **Enter** to search immediately without waiting for debounce

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