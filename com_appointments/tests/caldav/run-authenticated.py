#!/usr/bin/env python3
"""Run the same authenticated real-client phases against an isolated native Joomla.

The supplied installation must be disposable, serve trusted HTTPS and contain
the candidate extension. Never use a development site with real accounts.
"""
import argparse
import json
import os
import pathlib
import shutil
import subprocess
import sys
from urllib.parse import urlparse


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=pathlib.Path, required=True)
    parser.add_argument("--base", required=True)
    parser.add_argument("--ca", type=pathlib.Path, required=True)
    parser.add_argument("--php", default="php")
    parser.add_argument("--php-ini", type=pathlib.Path, required=True)
    parser.add_argument("--admin-username", required=True)
    parser.add_argument("--work", type=pathlib.Path, required=True)
    args = parser.parse_args()
    parsed = urlparse(args.base)
    if parsed.scheme != "https" or parsed.hostname not in ("localhost", "127.0.0.1", "::1") or parsed.path not in ("", "/"):
        parser.error("--base must be a loopback HTTPS origin of a disposable installation")
    if args.work.exists():
        parser.error("--work must be a new private directory")
    args.work.mkdir(parents=True, mode=0o700)
    shutil.copyfile(args.ca, args.work / "ca.pem")
    env = os.environ | {
        "APPOINTMENTS_TEST_ROOT": str(args.root),
        "APPOINTMENTS_TEST_DAV_URL": args.base.rstrip("/") + "/dav/",
        "APPOINTMENTS_FIXTURE_STATE": str(args.work / "fixture-private.json"),
        "APPOINTMENTS_CLIENT_WORK": str(args.work),
        "JOOMLA_ADMIN_USERNAME": args.admin_username,
        "PYTHONIOENCODING": "utf-8",
        "PYTHONDONTWRITEBYTECODE": "1",
    }
    here = pathlib.Path(__file__).resolve().parent
    php = [args.php, "-c", str(args.php_ini), str(here / "fixture.php")]

    def fixture(phase):
        result = subprocess.run(php + [phase], env=env, capture_output=True, text=True, timeout=180)
        if result.returncode:
            diagnostic = args.work / ("private-" + phase + ".txt")
            diagnostic.write_text(result.stderr, encoding="utf-8")
            diagnostic.chmod(0o600)
            raise RuntimeError("Server fixture failed: " + phase + " (diagnostics remain private)")
        return result.stdout

    def client(script, phase):
        result = subprocess.run([sys.executable, str(here / script), phase], env=env, timeout=600)
        if result.returncode:
            raise RuntimeError("Real client phase failed: " + phase)

    try:
        state = fixture("setup")
        json.loads(state)
        target = args.work / "state.json"
        target.write_text(state, encoding="utf-8")
        target.chmod(0o600)
        client("clients.py", "seed")
        client("authenticated.py", "login")
        fixture("authenticated-ready")
        print("PASS authenticated: first key created after password/TOTP, no pre-existing devices", flush=True)
        client("authenticated.py", "clients")
        fixture("authenticated-block")
        client("authenticated.py", "blocked")
        fixture("authenticated-revoke")
        client("authenticated.py", "revoked")
    finally:
        # Preserve state if teardown fails, so a failed clean-up can be retried.
        fixture("teardown")
    for name in ("state.json", "authenticated.json", "clients.json"):
        (args.work / name).unlink(missing_ok=True)
    print("PASS authenticated: native real-client flow and authorized teardown complete")


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print("FAIL authenticated native:", str(error), file=sys.stderr)
        sys.exit(1)
