#!/bin/bash
set -e
cd "$(dirname "$0")"
exec bash ../../shared/tests/run-tests.sh "$@"
