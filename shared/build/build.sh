#!/bin/bash
#
# Generic Build Script for Joomla/J2Commerce Extensions
# Usage: Run from extension directory
#

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

# Check if build.env exists
if [ ! -f "build.env" ]; then
    echo -e "${RED}Error: build.env not found in current directory${NC}"
    echo "Please create build.env with required variables:"
    echo "  EXTENSION_NAME=\"plg_privacy_j2commerce\""
    echo "  VERSION=\"1.0.0\""
    echo "  EXTENSION_TYPE=\"plugin\""
    exit 1
fi

# Load configuration
source build.env

# Validate required variables
if [ -z "$EXTENSION_NAME" ]; then
    echo -e "${RED}Error: EXTENSION_NAME not set in build.env${NC}"
    exit 1
fi

if [ -z "$VERSION" ]; then
    echo -e "${RED}Error: VERSION not set in build.env${NC}"
    exit 1
fi

if [ -z "$EXTENSION_TYPE" ]; then
    echo -e "${RED}Error: EXTENSION_TYPE not set in build.env${NC}"
    exit 1
fi

# Set defaults
BUILD_DIR="${BUILD_DIR:-build}"
PACKAGE_NAME="${PACKAGE_NAME:-${EXTENSION_NAME}_${VERSION}.zip}"

echo -e "${BLUE}=========================================${NC}"
echo -e "${BLUE}${EXTENSION_NAME} Builder${NC}"
echo -e "${BLUE}=========================================${NC}"
echo ""
echo "Version: ${VERSION}"
echo ""

# Clean previous build
echo "Cleaning previous build..."
rm -rf "$BUILD_DIR"
rm -f "$PACKAGE_NAME"

# Create build directory
echo "Creating build directory..."
mkdir -p "$BUILD_DIR"

# Copy files based on extension type
echo "Copying files..."

# Common files for all extensions
rsync -av --exclude="$BUILD_DIR" \
    --exclude="*.zip" \
    --exclude="build.env" \
    --exclude="tests" \
    --exclude="tests-*" \
    --exclude="Dockerfile" \
    --exclude="Dockerfile.*" \
    --exclude="docker-compose*.yml" \
    --exclude="docker-compose*.yaml" \
    --exclude="compose*.yml" \
    --exclude="compose*.yaml" \
    --exclude=".git" \
    --exclude=".gitignore" \
    --exclude="node_modules" \
    --exclude=".phpunit.cache" \
    --exclude="vendor" \
    --exclude="phpunit.xml" \
    --exclude="composer.json" \
    --exclude="/updates" \
    ./ "$BUILD_DIR/"

# updates/update.xml is the update server's file, not part of the extension. Leaving it out of
# the package keeps the package bytes independent of the SHA-256 that update.xml announces.
SHARED_BUILD="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
php "$SHARED_BUILD/prepare-installer.php" "$BUILD_DIR"

# Hashes of the files of every earlier release, so that updates recognise untouched files.
php "$SHARED_BUILD/known-hashes.php" . "$BUILD_DIR/advansinstaller/known-hashes.php"

# Create installation package
echo "Creating installation package..."
bash "$SHARED_BUILD/deterministic-zip.sh" "$BUILD_DIR" "$PACKAGE_NAME"

# Get package size
PACKAGE_SIZE=$(du -h "$PACKAGE_NAME" | cut -f1)

# Verification enforces the supported language set in the archive.
VERIFY_SCRIPT="$SHARED_BUILD/verify-package.sh"
bash "$VERIFY_SCRIPT"

echo ""
echo -e "${GREEN}Build successful${NC}"
echo "Package: $PACKAGE_NAME"
echo "Size: $PACKAGE_SIZE"
echo ""

# Clean build directory
echo "Cleaning build directory..."
rm -rf "$BUILD_DIR"

echo -e "${BLUE}=========================================${NC}"
echo -e "${GREEN}Build complete${NC}"
echo -e "${BLUE}=========================================${NC}"
