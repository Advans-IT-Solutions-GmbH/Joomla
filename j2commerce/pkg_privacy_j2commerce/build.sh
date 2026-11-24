#!/bin/bash
#
# Build script for pkg_privacy_j2commerce.
#
# The privacy plugin and its two bundled plugins keep their sources where they always were
# (j2commerce/plg_privacy_j2commerce/ and its plugins/ folder). The privacy plugin's own build
# (shared/build/build.sh) stages the shared installer support of the plugin and of both bundled
# plugins and writes the catalog of every earlier release (which also lists the files of the
# bundled plugins those releases shipped). This script splits that build into the child archives
# the package manifest declares, adds the package metadata and zips the package.
#
set -e

cd "$(dirname "${BASH_SOURCE[0]}")"

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

if [ ! -f "build.env" ]; then
    echo -e "${RED}Error: build.env not found${NC}"
    exit 1
fi

source build.env

PACKAGE_NAME="${PACKAGE_NAME:-${EXTENSION_NAME}_${VERSION}.zip}"
BUILD_DIR="${BUILD_DIR:-build}"
STAGE_PACKAGES="$BUILD_DIR/packages"
SHARED_BUILD="$(cd ../../shared/build && pwd)"
PLUGIN_DIR="../plg_privacy_j2commerce"

# Child source directory -> archive name inside packages/ (shared/tests/package-structure-check.php
# compares this list with the package manifest).
CHILDREN=(
    "../plg_privacy_j2commerce:plg_privacy_j2commerce.zip"
    "../plg_privacy_j2commerce/plugins/system/j2commerceprivacy:plg_system_j2commerceprivacy.zip"
    "../plg_privacy_j2commerce/plugins/task/j2commerceprivacy:plg_task_j2commerceprivacy.zip"
)

manifest_version() {
    grep -oP '(?<=<version>)[^<]+' "$1" | head -1
}

# Remove the <updateservers> block from every manifest of a staged child: the package is the
# only update channel, a child that kept its own could be updated out of band.
strip_update_servers() {
    while IFS= read -r -d '' manifest; do
        if grep -q "<updateservers>" "$manifest"; then
            perl -0pi -e 's{[ \t]*<updateservers>.*?</updateservers>\s*}{}gs' "$manifest"
        fi
    done < <(find "$1" -maxdepth 1 -name '*.xml' -print0)
}

echo -e "${BLUE}=========================================${NC}"
echo -e "${BLUE}${EXTENSION_NAME} Builder${NC}"
echo -e "${BLUE}=========================================${NC}"
echo ""
echo "Version: ${VERSION}"
echo ""

# Version rule: build.env, VERSION, the package manifest, the privacy plugin (build.env, VERSION,
# manifest) and both bundled plugins carry the same version. Sites that still run the standalone
# privacy plugin find the package through the plugin's update channel, which announces the
# package under the plugin's version.
PACKAGE_VERSION="$(manifest_version pkg_privacy_j2commerce.xml)"
PLUGIN_VERSION="$(manifest_version "$PLUGIN_DIR/j2commerce.xml")"
SYSTEM_VERSION="$(manifest_version "$PLUGIN_DIR/plugins/system/j2commerceprivacy/j2commerceprivacy.xml")"
TASK_VERSION="$(manifest_version "$PLUGIN_DIR/plugins/task/j2commerceprivacy/j2commerceprivacy.xml")"
PLUGIN_ENV_VERSION="$(grep -oP '(?<=^VERSION=")[^"]+' "$PLUGIN_DIR/build.env" | head -1)"
for candidate in "$PACKAGE_VERSION" "$PLUGIN_VERSION" "$SYSTEM_VERSION" "$TASK_VERSION" "$PLUGIN_ENV_VERSION" \
    "$(tr -d '[:space:]' < VERSION)" "$(tr -d '[:space:]' < "$PLUGIN_DIR/VERSION")"; do
    if [ "$candidate" != "$VERSION" ]; then
        echo -e "${RED}Error: build.env ($VERSION), VERSION, pkg_privacy_j2commerce.xml ($PACKAGE_VERSION), the privacy plugin ($PLUGIN_VERSION, build.env $PLUGIN_ENV_VERSION), the system plugin ($SYSTEM_VERSION) and the task plugin ($TASK_VERSION) must carry the same version${NC}"
        exit 1
    fi
done

echo "Cleaning previous build..."
rm -rf "$BUILD_DIR"
rm -f "$PACKAGE_NAME"
mkdir -p "$STAGE_PACKAGES"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "Building the privacy plugin with its bundled plugins..."
rm -f "$PLUGIN_DIR"/plg_privacy_j2commerce_*.zip
(
    cd "$PLUGIN_DIR"
    chmod +x build.sh
    ./build.sh
)
BUILT="$PLUGIN_DIR/plg_privacy_j2commerce_${PLUGIN_VERSION}.zip"
if [ ! -f "$BUILT" ]; then
    echo -e "${RED}Error: the privacy plugin build produced no archive${NC}"
    exit 1
fi
unzip -q "$BUILT" -d "$WORK/plugin"
rm -f "$BUILT"

for entry in "${CHILDREN[@]}"; do
    dir="${entry%%:*}"
    archive="${entry##*:}"
    relative="${dir#../plg_privacy_j2commerce}"
    relative="${relative#/}"

    if [ ! -d "$dir" ]; then
        echo -e "${RED}Error: child source $dir not found${NC}"
        exit 1
    fi

    case "$relative" in
        '')
            continue
            ;;
        plugins/*)
            # Staged by the privacy plugin build (installer support and system check).
            child="$WORK/plugin/$relative"
            ;;
        *)
            echo -e "${RED}Error: unknown child source $dir${NC}"
            exit 1
            ;;
    esac

    if [ ! -d "$child" ] || [ -z "$(find "$child" -maxdepth 1 -name '*.xml' -print -quit)" ]; then
        echo -e "${RED}Error: the privacy plugin build did not contain $relative${NC}"
        exit 1
    fi

    strip_update_servers "$child"
    bash "$SHARED_BUILD/deterministic-zip.sh" "$child" "$STAGE_PACKAGES/$archive"
    echo "Staged $relative -> packages/$archive"
done

# What remains is the privacy plugin itself (its manifest never installed the plugins/ folder).
rm -rf "$WORK/plugin/plugins"
strip_update_servers "$WORK/plugin"
bash "$SHARED_BUILD/deterministic-zip.sh" "$WORK/plugin" "$STAGE_PACKAGES/plg_privacy_j2commerce.zip"
echo "Staged the privacy plugin -> packages/plg_privacy_j2commerce.zip"

echo ""
echo "Copying package metadata..."
cp pkg_privacy_j2commerce.xml script.php LICENSE.txt index.html "$BUILD_DIR/"
cp index.html "$STAGE_PACKAGES/"
rsync -a --exclude=".*" language "$BUILD_DIR/"

php "$SHARED_BUILD/prepare-installer.php" "$BUILD_DIR"

# Hashes of the files of every earlier release: the catalog of the bundled privacy plugin lists
# every standalone release (privacy plugin and both bundled plugins), the package's own releases
# are rebuilt here.
php "$SHARED_BUILD/known-hashes.php" . "$BUILD_DIR/advans-known-hashes.php" "$BUILD_DIR"

echo "Creating installation package..."
bash "$SHARED_BUILD/deterministic-zip.sh" "$BUILD_DIR" "$PACKAGE_NAME"

PACKAGE_SIZE=$(du -h "$PACKAGE_NAME" | cut -f1)

bash "$SHARED_BUILD/verify-package.sh"

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
