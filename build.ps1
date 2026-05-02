# Block Usage Finder - Build Script (PowerShell)
# Creates a release-ready zip file for WordPress.org submission

$ErrorActionPreference = "Stop"

$PLUGIN_SLUG = "find-blocks-patterns-shortcodes"
$BUILD_DIR = "build"
$RELEASE_DIR = "$BUILD_DIR\$PLUGIN_SLUG"

# Extract version from plugin file
$pluginContent = Get-Content "find-blocks-patterns-shortcodes.php" -Raw
if ($pluginContent -match 'Version:\s+(\d+\.\d+\.\d+)') {
    $PLUGIN_VERSION = $matches[1]
} else {
    Write-Error "Could not extract version from plugin file"
    exit 1
}

$ZIP_FILE = "$PLUGIN_SLUG.zip"

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
Copy-Item "find-blocks-patterns-shortcodes.php" -Destination $RELEASE_DIR
Copy-Item "readme.txt" -Destination $RELEASE_DIR
Copy-Item "assets" -Destination "$RELEASE_DIR\assets" -Recurse
if (Test-Path "README.md") { Copy-Item "README.md" -Destination $RELEASE_DIR }
if (Test-Path "LICENSE") { Copy-Item "LICENSE" -Destination $RELEASE_DIR }

# Create zip file
# Note: Compress-Archive and ZipFile.CreateFromDirectory on Windows PowerShell 5.x
# write backslash paths which violate the ZIP spec and break WordPress's unzipper.
# Build entries manually with forward-slash paths.
Write-Host "Creating release zip..." -ForegroundColor Green
if (Test-Path $ZIP_FILE) {
    Remove-Item $ZIP_FILE -Force
}
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$absoluteBuildDir = (Resolve-Path $BUILD_DIR).Path
$absoluteZipPath = Join-Path (Get-Location).Path $ZIP_FILE

$zipStream = [System.IO.File]::Open($absoluteZipPath, [System.IO.FileMode]::Create)
$archive = New-Object System.IO.Compression.ZipArchive($zipStream, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    Get-ChildItem -Path $absoluteBuildDir -Recurse -File | ForEach-Object {
        $relativePath = $_.FullName.Substring($absoluteBuildDir.Length + 1).Replace('\', '/')
        $entry = $archive.CreateEntry($relativePath, [System.IO.Compression.CompressionLevel]::Optimal)
        $entryStream = $entry.Open()
        try {
            $fileStream = [System.IO.File]::OpenRead($_.FullName)
            try { $fileStream.CopyTo($entryStream) } finally { $fileStream.Dispose() }
        } finally { $entryStream.Dispose() }
    }
} finally {
    $archive.Dispose()
    $zipStream.Dispose()
}

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
