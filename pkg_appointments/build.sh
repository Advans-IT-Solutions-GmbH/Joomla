#!/bin/bash
#
# Build script for pkg_appointments.
#
# The component, the dashboard module and the six plugins keep their sources where they always
# were (com_appointments/, its modules/ and plugins/ folders). The component's own build
# (shared/build/build.sh) stages the shared installer support of the component and of every
# plugin, builds Appointments Recovery and writes the catalog of every earlier Appointments release
# (which also lists the plugin and module files those releases shipped). This script splits that
# build into the child archives the package manifest declares, stages the module, adds the package
# metadata and zips the package. Appointments Recovery stays at the package root: it is installed on
# its own, when an update is paused for a file review.
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
SHARED_BUILD="$(cd ../shared/build && pwd)"

# Child source directory -> archive name inside packages/ (shared/tests/package-structure-check.php
# compares this list with the package manifest).
CHILDREN=(
    "../com_appointments:com_appointments.zip"
    "../com_appointments/modules/mod_appointments:mod_appointments.zip"
    "../com_appointments/plugins/task/appointments:plg_task_appointments.zip"
    "../com_appointments/plugins/privacy/appointments:plg_privacy_appointments.zip"
    "../com_appointments/plugins/content/appointmentsprivacy:plg_content_appointmentsprivacy.zip"
    "../com_appointments/plugins/system/appointmentsdav:plg_system_appointmentsdav.zip"
    "../com_appointments/plugins/system/appointmentsattribution:plg_system_appointmentsattribution.zip"
    "../com_appointments/plugins/user/appointments:plg_user_appointments.zip"
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

# Version rules: the package, its manifest and the component carry the same version. Sites that
# still run the standalone component find the package through the component's update channel,
# which announces the package under the component's version.
PACKAGE_VERSION="$(manifest_version pkg_appointments.xml)"
COMPONENT_VERSION="$(manifest_version ../com_appointments/appointments.xml)"
if [ "$PACKAGE_VERSION" != "$VERSION" ] || [ "$COMPONENT_VERSION" != "$VERSION" ] \
    || [ "$(tr -d '[:space:]' < VERSION)" != "$VERSION" ] || [ "$(tr -d '[:space:]' < ../com_appointments/VERSION)" != "$VERSION" ]; then
    echo -e "${RED}Error: build.env ($VERSION), VERSION, pkg_appointments.xml ($PACKAGE_VERSION) and the component ($COMPONENT_VERSION) must carry the same version${NC}"
    exit 1
fi

echo "Cleaning previous build..."
rm -rf "$BUILD_DIR"
rm -f "$PACKAGE_NAME"
mkdir -p "$STAGE_PACKAGES"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "Building the component with its plugins..."
rm -f ../com_appointments/com_appointments_*.zip
(
    cd ../com_appointments
    chmod +x build.sh
    ./build.sh
)
BUILT="../com_appointments/com_appointments_${COMPONENT_VERSION}.zip"
if [ ! -f "$BUILT" ]; then
    echo -e "${RED}Error: the component build produced no archive${NC}"
    exit 1
fi
unzip -q "$BUILT" -d "$WORK/component"
rm -f "$BUILT"

for entry in "${CHILDREN[@]}"; do
    dir="${entry%%:*}"
    archive="${entry##*:}"
    relative="${dir#../com_appointments}"
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
            # Staged by the component build (installer support and system check).
            child="$WORK/component/$relative"
            ;;
        modules/*)
            child="$WORK/$(basename "$relative")"
            cp -R "$WORK/component/$relative" "$child"
            php "$SHARED_BUILD/prepare-installer.php" "$child"
            ;;
        *)
            echo -e "${RED}Error: unknown child source $dir${NC}"
            exit 1
            ;;
    esac

    if [ ! -d "$child" ] || [ -z "$(find "$child" -maxdepth 1 -name '*.xml' -print -quit)" ]; then
        echo -e "${RED}Error: the component build did not contain $relative${NC}"
        exit 1
    fi

    strip_update_servers "$child"
    bash "$SHARED_BUILD/deterministic-zip.sh" "$child" "$STAGE_PACKAGES/$archive"
    echo "Staged $relative -> packages/$archive"
done

# What remains is the component itself; Appointments Recovery moves to the package root.
mv "$WORK/component/appointments-recovery.zip" "$BUILD_DIR/appointments-recovery.zip"
rm -rf "$WORK/component/plugins" "$WORK/component/modules"
strip_update_servers "$WORK/component"
bash "$SHARED_BUILD/deterministic-zip.sh" "$WORK/component" "$STAGE_PACKAGES/com_appointments.zip"
echo "Staged the component -> packages/com_appointments.zip"

echo ""
echo "Copying package metadata..."
cp pkg_appointments.xml script.php LICENSE.txt index.html "$BUILD_DIR/"
cp index.html "$STAGE_PACKAGES/"
rsync -a --exclude=".*" language "$BUILD_DIR/"

php "$SHARED_BUILD/prepare-installer.php" "$BUILD_DIR"

# Hashes of the files of every earlier release: the catalog of the bundled component lists every
# standalone release of Appointments (component, plugins, module and recovery), the package's own
# releases are rebuilt here.
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
