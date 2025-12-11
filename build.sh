#!/bin/bash
# Block Usage Finder - Build Script
# Creates a release-ready zip file for WordPress.org submission

set -e

PLUGIN_SLUG="find-blocks-patterns-shortcodes"
PLUGIN_VERSION=$(grep "Version:" find-blocks-patterns-shortcodes.php | awk '{print $3}')
BUILD_DIR="build"
RELEASE_DIR="$BUILD_DIR/$PLUGIN_SLUG"
ZIP_FILE="$PLUGIN_SLUG.zip"

echo "🔨 Building Block Usage Finder v$PLUGIN_VERSION"
echo "================================================"

# Clean up previous builds
if [ -d "$BUILD_DIR" ]; then
    echo "🧹 Cleaning previous build..."
    rm -rf "$BUILD_DIR"
fi

# Create build directory
echo "📁 Creating build directory..."
mkdir -p "$RELEASE_DIR"

# Copy plugin files
echo "📋 Copying plugin files..."
cp find-blocks-patterns-shortcodes.php "$RELEASE_DIR/"
cp readme.txt "$RELEASE_DIR/"
cp README.md "$RELEASE_DIR/"
cp LICENSE "$RELEASE_DIR/"
cp CHANGELOG.md "$RELEASE_DIR/"

# Create zip file
echo "📦 Creating release zip..."
cd "$BUILD_DIR"
zip -q -r "../$ZIP_FILE" "$PLUGIN_SLUG"
cd ..

# Calculate file size
FILE_SIZE=$(du -h "$ZIP_FILE" | cut -f1)

echo ""
echo "✅ Build complete!"
echo "================================================"
echo "📦 Package: $ZIP_FILE"
echo "💾 Size: $FILE_SIZE"
echo "📍 Location: $(pwd)/$ZIP_FILE"
echo ""
echo "🚀 Ready for WordPress.org submission!"
echo ""
echo "Next steps:"
echo "1. Test the plugin by installing $ZIP_FILE"
echo "2. Review readme.txt in $RELEASE_DIR/"
echo "3. Run plugin-check for final validation"
echo "4. Submit to WordPress.org"
