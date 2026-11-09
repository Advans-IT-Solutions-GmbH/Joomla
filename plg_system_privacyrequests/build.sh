#!/bin/bash
set -e
cd "$(dirname "$0")"
exec bash ../shared/build/build.sh
