@echo off
REM Block Usage Finder - Build Script (Windows)
REM Creates a release-ready zip file for WordPress.org submission

setlocal enabledelayedexpansion

set PLUGIN_SLUG=find-blocks-patterns-shortcodes
set BUILD_DIR=build
set RELEASE_DIR=%BUILD_DIR%\%PLUGIN_SLUG%

REM Extract version from plugin file
for /f "tokens=2" %%a in ('findstr /C:"Version:" find-blocks-patterns-shortcodes.php') do set PLUGIN_VERSION=%%a
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
copy find-blocks-patterns-shortcodes.php "%RELEASE_DIR%\" > nul
copy readme.txt "%RELEASE_DIR%\" > nul
xcopy assets "%RELEASE_DIR%\assets" /E /I /Q > nul
if exist README.md copy README.md "%RELEASE_DIR%\" > nul
if exist LICENSE copy LICENSE "%RELEASE_DIR%\" > nul

REM Create zip file using PowerShell
REM Note: Compress-Archive writes backslash paths which break WordPress's unzipper.
REM Build entries manually with forward-slash paths via ZipArchive API.
echo Creating release zip...
powershell -NoProfile -Command ^
  "if (Test-Path '%ZIP_FILE%') { Remove-Item '%ZIP_FILE%' -Force };" ^
  "Add-Type -AssemblyName System.IO.Compression;" ^
  "Add-Type -AssemblyName System.IO.Compression.FileSystem;" ^
  "$src = (Resolve-Path '%BUILD_DIR%').Path;" ^
  "$zs = [System.IO.File]::Open((Join-Path (Get-Location).Path '%ZIP_FILE%'), [System.IO.FileMode]::Create);" ^
  "$a = New-Object System.IO.Compression.ZipArchive($zs, [System.IO.Compression.ZipArchiveMode]::Create);" ^
  "try { Get-ChildItem -Path $src -Recurse -File | ForEach-Object {" ^
  "  $rel = $_.FullName.Substring($src.Length + 1).Replace('\\','/');" ^
  "  $e = $a.CreateEntry($rel, [System.IO.Compression.CompressionLevel]::Optimal);" ^
  "  $es = $e.Open();" ^
  "  try { $fs = [System.IO.File]::OpenRead($_.FullName); try { $fs.CopyTo($es) } finally { $fs.Dispose() } }" ^
  "  finally { $es.Dispose() }" ^
  "} } finally { $a.Dispose(); $zs.Dispose() }"

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
