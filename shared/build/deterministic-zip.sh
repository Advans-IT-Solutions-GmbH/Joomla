#!/usr/bin/env bash
#
# Create a reproducible ZIP archive of a directory.
# Usage: deterministic-zip.sh SOURCE_DIRECTORY ZIP_FILE
#
# Fixed file modes and timestamps and a sorted entry list make the archive bytes depend on the
# file contents only. The release workflow publishes the SHA-256 of the package in update.xml,
# and the publish workflow rebuilds the same commit and must arrive at the same bytes.
#
set -euo pipefail

if [ "$#" -ne 2 ] || [ ! -d "$1" ]; then
    echo "Usage: deterministic-zip.sh SOURCE_DIRECTORY ZIP_FILE" >&2
    exit 1
fi

source_dir="$1"
case "$2" in
    /*) archive="$2" ;;
    *) archive="$(pwd)/$2" ;;
esac

rm -f "$archive"
find "$source_dir" -name '.DS_Store' -type f -delete
chmod -R u=rwX,go=rX "$source_dir"
# 2024-01-01T00:00:00Z; ZIP stores local time, so the time zone is fixed as well.
find "$source_dir" -exec touch -h -d '@1704067200' {} +
(
    cd "$source_dir"
    find . -mindepth 1 -print | LC_ALL=C sort | sed 's#^\./##' | TZ=UTC zip -X -q "$archive" -@
)
