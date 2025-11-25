# Block Usage Finder

A WordPress plugin that helps administrators find which posts and pages use specific Gutenberg blocks.

## Description

Block Usage Finder adds an admin interface for searching posts and pages containing specific Gutenberg blocks. Enter a block name and get instant results showing where that block is used.

## Features

### Core Features
- **Dynamic search** with intelligent debouncing (500ms)
- **Real-time results** showing post title, type, and direct edit links
- **Clean admin interface** with search icon in WordPress menu
- **Keyboard accessible** with full Enter key support

### Security Features
- **Enhanced input validation** with dangerous pattern blacklisting
- **Dual-layer rate limiting** (user + IP based)
- **Security event logging** with full audit trail
- **Custom capability system** for granular permissions
- **Nonce regeneration** for long admin sessions
- **Timeout protection** preventing server overload
- **Information disclosure prevention** with generic error messages
- **Security headers** (XSS Protection, Frame Options, CSP)
- **Object injection prevention** with strict type validation

### Accessibility Features 
- **Screen reader compatible** with ARIA live regions
- **Keyboard navigation** support throughout
- **Form labels** for all inputs
- **Focus management** and visible focus indicators
- **Results count announcements** for assistive technology
- **Accessible error messages** with proper ARIA roles
- **Responsive design** supporting 200% zoom

## Installation

1. Upload the `block-usage-finder` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu in WordPress
3. The plugin automatically adds the `use_block_usage_finder` capability to Administrators
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
- **Capability:** Administrator role (or custom role with `use_block_usage_finder` capability)

## Configuration

### Allow Editor Access
```php
add_filter('buf_allow_editor_access', '__return_true');
```

### Adjust Search Limit
```php
add_filter('buf_query_limit', function() {
    return 1000; // Default: 500, Max: 1000
});
```

### Disable Security Logging
```php
add_filter('buf_enable_security_logging', '__return_false');
```

### External Security Monitoring
```php
add_action('buf_security_event', function($log) {
    // Send to external logging service
    error_log(json_encode($log));
});
```

## Security

- Rate limiting and abuse prevention
- Input validation and output sanitization
- Information disclosure prevention

For complete security details, see [SECURITY.md](SECURITY.md)

## Accessibility

Fully compliant with **WCAG 2.1 Level AA** standards:
- ✅ Screen reader compatible
- ✅ Keyboard navigation
- ✅ Focus indicators
- ✅ ARIA labels and live regions
- ✅ Responsive design (200% zoom support)

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history and detailed changes.

## Support

For issues, feature requests, or security concerns:
- Check existing documentation
- Review security logs: `get_option('buf_security_logs')`
- Verify capability: User must have `use_block_usage_finder`

## License

GPL v2 or later - [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)

## Author

**Matthew Cowan**