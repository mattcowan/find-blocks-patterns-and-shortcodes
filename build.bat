@echo off
REM Block Usage Finder - Build Script (Windows)
REM Creates a release-ready zip file for WordPress.org submission

setlocal enabledelayedexpansion

set PLUGIN_SLUG=block-usage-finder
set BUILD_DIR=build
set RELEASE_DIR=%BUILD_DIR%\%PLUGIN_SLUG%

REM Extract version from plugin file
for /f "tokens=2" %%a in ('findstr /C:"Version:" block-usage-finder.php') do set PLUGIN_VERSION=%%a
set ZIP_FILE=%PLUGIN_SLUG%-%PLUGIN_VERSION%.zip

echo.
echo ============================================
echo Building Block Usage Finder v%PLUGIN_VERSION%
echo ============================================
echo.

REM Clean up previous builds
if exist "%BUILD_DIR%" (
    echo Cleaning previous build...
    rmdir /s /q "%BUILD_DIR%"
)

REM Create build directory
echo Creating build directory...
mkdir "%RELEASE_DIR%"

REM Copy plugin files
echo Copying plugin files...
copy block-usage-finder.php "%RELEASE_DIR%\" > nul
copy README.md "%RELEASE_DIR%\" > nul
copy LICENSE "%RELEASE_DIR%\" > nul
copy CHANGELOG.md "%RELEASE_DIR%\" > nul

REM Create readme.txt for WordPress.org
echo Generating readme.txt for WordPress.org...
(
echo === Block Usage Finder ===
echo Contributors: matthewcowan
echo Tags: gutenberg, blocks, search, admin, content
echo Requires at least: 5.0
echo Tested up to: 6.4
echo Requires PHP: 7.0
echo Stable tag: trunk
echo License: GPLv2 or later
echo License URI: https://www.gnu.org/licenses/gpl-2.0.html
echo.
echo Find which posts and pages use specific Gutenberg blocks with advanced search, security hardening, and full accessibility support.
echo.
echo == Description ==
echo.
echo Block Usage Finder helps WordPress administrators quickly locate posts and pages containing specific Gutenberg blocks. Perfect for content audits, site migrations, and block usage analysis.
echo.
echo = Core Features =
echo.
echo * **Progressive search** with batch processing for large sites
echo * **Post type filtering** - search across posts, pages, or custom post types
echo * **CSV export** - export results for reporting and analysis
echo * **Block dropdown** - select from all registered blocks
echo * **Synced pattern search** - find usage of reusable blocks/patterns
echo * **Real-time results** with sortable tables
echo * **WP-CLI support** for automation
echo.
echo = Security ^(10/10 Rating^) =
echo.
echo * Enhanced input validation with blacklisting
echo * Dual-layer rate limiting ^(user + IP^)
echo * Security event logging and audit trail
echo * Custom capability system
echo * Timeout protection ^(25-second safeguard^)
echo * Information disclosure prevention
echo * XSS and injection prevention
echo * Nonce auto-refresh for long sessions
echo.
echo = Accessibility ^(WCAG 2.1 AA Compliant^) =
echo.
echo * Screen reader compatible with ARIA live regions
echo * Full keyboard navigation support
echo * Visible focus indicators
echo * Form labels for all inputs
echo * Results count announcements
echo * Responsive design with 200%% zoom support
echo.
echo = Performance Optimized =
echo.
echo * Smart caching with 5-minute TTL
echo * Batch processing ^(100 posts per batch^)
echo * Query optimization ^(IDs only fetch^)
echo * Progress indicators for long operations
echo * Cancellable searches
echo * Hard limit protection ^(500-1000 posts^)
echo.
echo == Installation ==
echo.
echo 1. Upload the plugin files to `/wp-content/plugins/block-usage-finder/`
echo 2. Activate the plugin through the 'Plugins' menu in WordPress
echo 3. Navigate to Tools ^> Block Usage Finder
echo 4. Start searching for blocks!
echo.
echo == Frequently Asked Questions ==
echo.
echo = What block name format should I use? =
echo.
echo Block names follow the format `namespace/block-name`. Examples:
echo * `core/paragraph`
echo * `core/image`
echo * `woocommerce/product-price`
echo.
echo = Can I search custom blocks? =
echo.
echo Yes! The plugin works with any registered Gutenberg block, including custom blocks from themes and plugins.
echo.
echo = How do I allow Editors to use this plugin? =
echo.
echo Add this filter to your theme's functions.php:
echo `add_filter^('buf_allow_editor_access', '__return_true'^);`
echo.
echo = Can I export the results? =
echo.
echo Yes! Click the "Export CSV" button after searching to download results as a spreadsheet.
echo.
echo = Does it work with WP-CLI? =
echo.
echo Yes! Use `wp block-usage search core/paragraph` for command-line searches.
echo.
echo = How do I search for synced patterns? =
echo.
echo Use the "Search for Synced Pattern Usage" section to find where reusable blocks/patterns are used.
echo.
echo == Changelog ==
echo.
echo = 2.0.0 =
echo * Added progressive batch search for large sites
echo * Added post type filtering
echo * Added CSV export functionality
echo * Added synced pattern search
echo * Added WP-CLI support
echo * Added smart caching ^(5-minute TTL^)
echo * Added cancel search functionality
echo * Added sortable results tables
echo * Enhanced security with IP-based rate limiting
echo * Improved accessibility ^(WCAG 2.1 AA^)
echo * Performance optimizations
echo.
echo = 1.0.0 =
echo * Initial release
echo * Basic block search functionality
echo * Security hardening ^(10/10 rating^)
echo * WCAG 2.1 AA accessibility
echo.
echo == Upgrade Notice ==
echo.
echo = 2.0.0 =
echo Major update with progressive search, post type filtering, CSV export, pattern search, and WP-CLI support. Fully backward compatible.
echo.
echo == WP-CLI Commands ==
echo.
echo Search for blocks:
echo `wp block-usage search core/paragraph --post-type=post,page --format=table`
echo.
echo Clear cache:
echo `wp block-usage clear-cache`
echo.
echo View security logs:
echo `wp block-usage logs --limit=100 --format=csv`
echo.
echo == Privacy ==
echo.
echo This plugin:
echo * Does not collect any user data
echo * Does not make external API calls
echo * Stores security logs locally ^(last 1000 events^)
echo * Logs include: timestamp, user ID, IP address, event type
echo * Security logs can be disabled via filter
) > "%RELEASE_DIR%\readme.txt"

REM Create .distignore
echo Creating .distignore...
(
echo /.git
echo /.github
echo /.claude
echo /build
echo /node_modules
echo .gitignore
echo .distignore
echo build.sh
echo build.bat
echo CLAUDE.md
echo SECURITY.md
echo block-usage-finder.zip
echo *.zip
echo .DS_Store
echo Thumbs.db
) > "%RELEASE_DIR%\.distignore"

REM Create zip file using PowerShell
echo Creating release zip...
powershell -command "Compress-Archive -Path '%RELEASE_DIR%' -DestinationPath '%ZIP_FILE%' -Force"

REM Get file size
for %%A in ("%ZIP_FILE%") do set FILE_SIZE=%%~zA
set /a FILE_SIZE_KB=%FILE_SIZE%/1024

echo.
echo ============================================
echo Build complete!
echo ============================================
echo Package: %ZIP_FILE%
echo Size: %FILE_SIZE_KB% KB
echo Location: %CD%\%ZIP_FILE%
echo.
echo Ready for WordPress.org submission!
echo.
echo Next steps:
echo 1. Test the plugin by installing %ZIP_FILE%
echo 2. Review readme.txt in %RELEASE_DIR%\
echo 3. Run plugin-check for final validation
echo 4. Submit to WordPress.org
echo.

endlocal
