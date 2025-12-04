@echo off
REM Block Usage Finder - Build Script (Windows)
REM Creates a release-ready zip file for WordPress.org submission

setlocal enabledelayedexpansion

set PLUGIN_SLUG=block-usage-finder
set BUILD_DIR=build
set RELEASE_DIR=%BUILD_DIR%\%PLUGIN_SLUG%

REM Extract version from plugin file
for /f "tokens=2" %%a in ('findstr /C:"Version:" block-usage-finder.php') do set PLUGIN_VERSION=%%a
set ZIP_FILE=%PLUGIN_SLUG%.zip

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
copy readme.txt "%RELEASE_DIR%\" > nul
copy README.md "%RELEASE_DIR%\" > nul
copy LICENSE "%RELEASE_DIR%\" > nul
copy CHANGELOG.md "%RELEASE_DIR%\" > nul

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
