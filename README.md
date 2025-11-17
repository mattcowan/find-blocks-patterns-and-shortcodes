# Block Usage Finder

A WordPress plugin that helps administrators find which posts and pages use specific Gutenberg blocks.

## Description

Block Usage Finder adds an admin interface for searching posts and pages containing specific Gutenberg blocks. Enter a block name and get instant results showing where that block is used.

## Features

- Dynamic search with debouncing
- Shows post title, type, and direct edit links
- Simple admin interface
- Secure AJAX with nonces and capability checks

## Installation

1. Upload the `block-usage-finder` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu in WordPress
3. Access via Block Usage in the admin menu

## Usage

1. Navigate to Block Usage in your WordPress admin menu
2. Enter a block name (e.g., `core/paragraph`, `core/heading`)
3. Results appear automatically showing clickable links to edit the content

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher

## License

GPL v2 or later

## Author

Matthew Cowan
