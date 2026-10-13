#!/bin/bash
# Wrapper script that calls the shared test runner.
exec ../../shared/tests/run-tests.sh "$@"
