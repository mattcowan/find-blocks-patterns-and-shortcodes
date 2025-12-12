# Changelog

All notable changes to the Find Blocks, Patterns, and Shortcodes plugin will be documented in this file.

## [1.0.0] - Initial Release

### Features
- Search functionality for Gutenberg blocks, reusable patterns, and shortcodes
- Dynamic search with 500ms debouncing
- Admin interface with search icon in WordPress menu
- Direct edit links in search results
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
