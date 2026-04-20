#!/bin/bash
# Wrapper script that calls shared test runner
exec ../../../shared/tests/run-tests.sh "$@"
