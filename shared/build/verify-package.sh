#!/bin/bash
#
# Generic Package Verification Script
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
    echo -e "${RED}Error: build.env not found${NC}"
    exit 1
fi

source build.env

PACKAGE_NAME="${PACKAGE_NAME:-${EXTENSION_NAME}_${VERSION}.zip}"

if [ ! -f "$PACKAGE_NAME" ]; then
    echo -e "${RED}Error: Package $PACKAGE_NAME not found${NC}"
    exit 1
fi

echo -e "${BLUE}=========================================${NC}"
echo -e "${BLUE}Package Verification${NC}"
echo -e "${BLUE}=========================================${NC}"
echo ""

# List package contents
echo "Package contents:"
unzip -l "$PACKAGE_NAME"

if unzip -l "$PACKAGE_NAME" | awk '{print $4}' | grep -Eq '(^|/)tests($|/)|(^|/)tests-[^/]+($|/)'; then
    echo ""
    echo -e "${RED}Error: Package contains test directories${NC}"
    exit 1
fi

if unzip -l "$PACKAGE_NAME" | awk '{print $4}' | grep -Eq '(^|/)Dockerfile(\.[^/]*)?$|(^|/)(docker-compose|compose)[^/]*\.ya?ml$'; then
    echo ""
    echo -e "${RED}Error: Package contains Docker or Compose files${NC}"
    exit 1
fi

# Check the archive itself, including bundled plugins. Package children pass
# this check in their own build before the package embeds their archives.
LANGUAGE_WORK="$(mktemp -d)"
trap 'rm -rf "$LANGUAGE_WORK"' EXIT
unzip -q "$PACKAGE_NAME" -d "$LANGUAGE_WORK"
if find "$LANGUAGE_WORK" -type d -path '*/language/de-CH' -print -quit | grep -q .; then
    echo -e "${RED}Error: Package contains a de-CH language folder (Swiss text belongs in de-DE)${NC}"
    exit 1
fi

LANGUAGE_COUNT=0
while IFS= read -r -d '' language; do
    tag="$(basename "$(dirname "$language")")"
    case "$tag" in
        de-DE|en-GB|fr-FR) ;;
        *) echo -e "${RED}Error: unsupported language tag $tag${NC}"; exit 1 ;;
    esac
    for required in de-DE en-GB fr-FR; do
        counterpart="$(dirname "$(dirname "$language")")/$required/$(basename "$language")"
        counterpart="${counterpart//$tag./$required.}"
        if [ ! -f "$counterpart" ]; then
            echo -e "${RED}Error: missing $required counterpart: ${counterpart#"$LANGUAGE_WORK/"}${NC}"
            exit 1
        fi
    done
    LANGUAGE_COUNT=$((LANGUAGE_COUNT + 1))
done < <(find "$LANGUAGE_WORK" -type f -path '*/language/*/*.ini' -print0)

if [ "$LANGUAGE_COUNT" -eq 0 ]; then
    echo -e "${RED}Error: Package contains no language files${NC}"
    exit 1
fi

echo "Verified de-DE/en-GB/fr-FR counterparts for $LANGUAGE_COUNT language files"

echo ""
echo -e "${GREEN}Package verification complete${NC}"
