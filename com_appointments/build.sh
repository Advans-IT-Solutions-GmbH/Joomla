#!/bin/bash
# Wrapper script that calls shared build script
cd "$(dirname "${BASH_SOURCE[0]}")"
exec ../shared/build/build.sh "$@"
