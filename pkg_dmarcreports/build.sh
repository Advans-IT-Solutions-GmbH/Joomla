#!/bin/bash
#
# Custom build script for pkg_dmarcreports.
#
# The generic shared build script excludes *.zip during rsync, so it cannot
# bundle the sub-extension archives this package ships. This script therefore
# builds every sub-extension with its own build.sh, stages the resulting
# archives under packages/ with stable, version-less names (the names the
# package manifest declares), copies the package metadata and zips the whole
# tree. It reuses shared/build/verify-package.sh for the final sanity check.
#
set -e

cd "$(dirname "${BASH_SOURCE[0]}")"

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

# Remove the <updateservers> block from every manifest inside a staged child
# archive. The package ships a single update channel (its own <updateservers>
# in pkg_dmarcreports.xml); a bundled child that kept its own block would
# advertise an independent update stream and could be updated out of band,
# drifting from the package. unzip/zip/perl are present on the CI runner.
strip_update_servers() {
    local archive
    archive="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
    local work
    work="$(mktemp -d)"

    unzip -q "$archive" -d "$work"

    # Child manifests live at the archive root; maxdepth 2 covers nested layouts.
    while IFS= read -r -d '' manifest; do
        if grep -q "<updateservers>" "$manifest"; then
            perl -0pi -e 's{[ \t]*<updateservers>.*?</updateservers>\s*}{}gs' "$manifest"
        fi
    done < <(find "$work" -maxdepth 2 -name '*.xml' -print0)

    rm -f "$archive"
    (
        cd "$work"
        zip -r "$archive" . -x "*.DS_Store" > /dev/null
    )

    rm -rf "$work"
}

if [ ! -f "build.env" ]; then
    echo -e "${RED}Error: build.env not found${NC}"
    exit 1
fi

source build.env

PACKAGE_NAME="${PACKAGE_NAME:-${EXTENSION_NAME}_${VERSION}.zip}"
BUILD_DIR="${BUILD_DIR:-build}"
STAGE_PACKAGES="$BUILD_DIR/packages"

# Sub-extension directory -> stable archive name inside packages/
SUB_DIRS=(
    "../com_dmarcreports:com_dmarcreports.zip"
    "../mod_dmarcreports:mod_dmarcreports.zip"
    "../plg_task_dmarcreports:plg_task_dmarcreports.zip"
    "../plg_system_dmarcmtasts:plg_system_dmarcmtasts.zip"
)

echo -e "${BLUE}=========================================${NC}"
echo -e "${BLUE}${EXTENSION_NAME} Builder${NC}"
echo -e "${BLUE}=========================================${NC}"
echo ""
echo "Version: ${VERSION}"
echo ""

echo "Cleaning previous build..."
rm -rf "$BUILD_DIR"
rm -f "$PACKAGE_NAME"

mkdir -p "$STAGE_PACKAGES"

echo "Building sub-extensions..."
for entry in "${SUB_DIRS[@]}"; do
    dir="${entry%%:*}"
    stable="${entry##*:}"

    if [ ! -d "$dir" ]; then
        echo -e "${RED}Error: sub-extension directory $dir not found${NC}"
        exit 1
    fi

    echo ""
    echo "--- Building $dir ---"
    (
        cd "$dir"
        chmod +x build.sh
        ./build.sh
    )

    built=$(ls -1t "$dir"/*.zip 2>/dev/null | head -1)
    if [ -z "$built" ]; then
        echo -e "${RED}Error: no archive produced by $dir/build.sh${NC}"
        exit 1
    fi

    cp "$built" "$STAGE_PACKAGES/$stable"
    strip_update_servers "$STAGE_PACKAGES/$stable"
    echo "Staged $(basename "$built") -> packages/$stable"
done

echo ""
echo "Copying package metadata..."
cp pkg_dmarcreports.xml "$BUILD_DIR/"
cp script.php "$BUILD_DIR/"
cp LICENSE.txt "$BUILD_DIR/"
cp index.html "$BUILD_DIR/"
cp index.html "$STAGE_PACKAGES/"
rsync -a --exclude=".*" language "$BUILD_DIR/"

echo "Creating installation package..."
(
    cd "$BUILD_DIR"
    zip -r "../$PACKAGE_NAME" . -x "*.DS_Store" > /dev/null
)

PACKAGE_SIZE=$(du -h "$PACKAGE_NAME" | cut -f1)

VERIFY_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/../shared/build/verify-package.sh"
bash "$VERIFY_SCRIPT"

echo ""
echo -e "${GREEN}Build successful${NC}"
echo "Package: $PACKAGE_NAME"
echo "Size: $PACKAGE_SIZE"
echo ""

echo "Cleaning build directory..."
rm -rf "$BUILD_DIR"

echo -e "${BLUE}=========================================${NC}"
echo -e "${GREEN}Build complete${NC}"
echo -e "${BLUE}=========================================${NC}"
