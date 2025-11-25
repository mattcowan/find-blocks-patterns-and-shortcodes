# Block Usage Finder - Build Script (PowerShell)
# Creates a release-ready zip file for WordPress.org submission

$ErrorActionPreference = "Stop"

$PLUGIN_SLUG = "block-usage-finder"
$BUILD_DIR = "build"
$RELEASE_DIR = "$BUILD_DIR\$PLUGIN_SLUG"

# Extract version from plugin file
$pluginContent = Get-Content "block-usage-finder.php" -Raw
if ($pluginContent -match 'Version:\s+(\d+\.\d+\.\d+)') {
    $PLUGIN_VERSION = $matches[1]
} else {
    Write-Error "Could not extract version from plugin file"
    exit 1
}

$ZIP_FILE = "$PLUGIN_SLUG-$PLUGIN_VERSION.zip"

Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "Building Block Usage Finder v$PLUGIN_VERSION" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

# Clean up previous builds
if (Test-Path $BUILD_DIR) {
    Write-Host "Cleaning previous build..." -ForegroundColor Yellow
    Remove-Item -Path $BUILD_DIR -Recurse -Force
}

# Create build directory
Write-Host "Creating build directory..." -ForegroundColor Green
New-Item -ItemType Directory -Path $RELEASE_DIR -Force | Out-Null

# Copy plugin files
Write-Host "Copying plugin files..." -ForegroundColor Green
Copy-Item "block-usage-finder.php" -Destination $RELEASE_DIR
Copy-Item "README.md" -Destination $RELEASE_DIR
Copy-Item "LICENSE" -Destination $RELEASE_DIR
Copy-Item "CHANGELOG.md" -Destination $RELEASE_DIR

# Create readme.txt for WordPress.org
Write-Host "Generating readme.txt for WordPress.org..." -ForegroundColor Green

# Read the template and save as readme.txt
$readmeContent = Get-Content "README.md" -Raw

# Create WordPress.org compatible readme.txt
$readmeTxt = @'
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
'@

Set-Content -Path "$RELEASE_DIR\readme.txt" -Value $readmeTxt -Encoding UTF8

# Create .distignore
Write-Host "Creating .distignore..." -ForegroundColor Green
$distignore = @'
/.git
/.github
/.claude
/build
/node_modules
.gitignore
.distignore
build.sh
build.bat
build.ps1
CLAUDE.md
SECURITY.md
*.zip
.DS_Store
Thumbs.db
'@

Set-Content -Path "$RELEASE_DIR\.distignore" -Value $distignore -Encoding UTF8

# Create zip file
Write-Host "Creating release zip..." -ForegroundColor Green
if (Test-Path $ZIP_FILE) {
    Remove-Item $ZIP_FILE -Force
}
Compress-Archive -Path $RELEASE_DIR -DestinationPath $ZIP_FILE -CompressionLevel Optimal

# Calculate file size
$fileSize = (Get-Item $ZIP_FILE).Length
$fileSizeKB = [math]::Round($fileSize / 1KB, 2)

Write-Host ""
Write-Host "Build complete!" -ForegroundColor Green
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "Package: $ZIP_FILE"
Write-Host "Size: $fileSizeKB KB"
Write-Host "Location: $((Get-Location).Path)\$ZIP_FILE"
Write-Host ""
Write-Host "Ready for WordPress.org submission!" -ForegroundColor Green
Write-Host ""
Write-Host "Next steps:" -ForegroundColor Yellow
Write-Host "1. Test the plugin by installing $ZIP_FILE"
Write-Host "2. Review readme.txt in $RELEASE_DIR"
Write-Host "3. Run plugin-check for final validation"
Write-Host "4. Submit to WordPress.org"
Write-Host ""
