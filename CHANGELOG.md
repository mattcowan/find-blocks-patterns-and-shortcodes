# Changelog

All notable changes to the Find Blocks, Patterns, and Shortcodes plugin will be documented in this file.

## [1.0.1] - 2024-12-18

### Changed
- **Asset Enqueuing Refactor**: Migrated from inline CSS/JS to properly enqueued assets using WordPress standards
  - Extracted CSS to `assets/css/admin.css` (125 lines)
  - Extracted JavaScript to `assets/js/admin.js` (685 lines)
  - Implemented `wp_enqueue_style()` and `wp_enqueue_script()` for proper asset loading
  - Added `wp_localize_script()` for passing data and translations to JavaScript
  - Assets now only load on plugin admin page (performance improvement)

### Fixed
- Added version constant (`FBPS_VERSION`) for consistent versioning across asset files
- Added translator comment for placeholder string (WordPress i18n best practices)
- Improved array alignment consistency in `wp_localize_script()` call
- Reduced main plugin file from 2047 to 1358 lines (-689 lines)

### Technical
- Better browser caching with separate asset files
- Improved code maintainability with separation of concerns
- All 23 translatable strings now properly passed via `wp_localize_script()`
- WordPress Plugin Review compliant asset handling

## [1.0.0] - Initial Release

### Features
- Search functionality for Gutenberg blocks, reusable patterns, and shortcodes
- Progressive batch search (100 posts per batch) for large sites
- Post type filtering (posts, pages, custom post types)
- CSV export functionality for reporting and analysis
- Block dropdown with all registered blocks
- Synced pattern search for reusable blocks/patterns
- Shortcode search and usage tracking
- Sortable results tables (by title, type, date)
- Cancellable searches with progress indicators
- Smart caching with 5-minute TTL
- WP-CLI support for automation
- Admin interface with search icon in WordPress menu
- Direct edit and view links in search results
- Keyboard navigation with Enter key support

### Security
- Custom capability system (`use_find_blocks_patterns_shortcodes`)
- Dual-layer rate limiting (user-based and IP-based)
- Security event logging and audit trail
- Enhanced input validation with dangerous pattern blacklisting
- Nonce verification and automatic regeneration
- Query timeout protection
- Information disclosure prevention
- Security headers implementation

### Accessibility
- Screen reader support with ARIA live regions
- Keyboard navigation throughout interface
- Form labels and focus management
- Visible focus indicators
- Results count announcements
- Responsive design with 200% zoom support

### Configuration
- `fbps_allow_editor_access` filter for Editor role access
- `fbps_query_limit` filter to adjust search limits
- `fbps_enable_security_logging` filter to toggle logging
- `fbps_security_event` action for external logging integration

For detailed security information, see [SECURITY.md](SECURITY.md)
