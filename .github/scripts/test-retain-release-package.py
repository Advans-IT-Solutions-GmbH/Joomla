#!/usr/bin/env python3
"""Offline tests for the release-package retention action."""

import hashlib
import os
import re
import subprocess
import sys
import tempfile
import textwrap
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent.parent
ACTION = ROOT / ".github/actions/retain-release-package/action.yml"


class RetainReleasePackageTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.package = Path(self.temp.name) / "package.zip"
        self.feed = Path(self.temp.name) / "update.xml"
        self.package.write_bytes(b"prepared package")
        self.digest = hashlib.sha256(self.package.read_bytes()).hexdigest()
        action = ACTION.read_text(encoding="utf-8")
        match = re.search(r"(?ms)^        python3 - <<'PY'\n(.*?)^        PY$", action)
        self.assertIsNotNone(match, "Verifier heredoc not found")
        self.verifier = textwrap.dedent(match.group(1))

    def verify(self, xml):
        self.feed.write_text(xml, encoding="utf-8")
        return subprocess.run(
            [sys.executable, "-c", self.verifier],
            env={**os.environ, "PACKAGE": str(self.package), "FEED": str(self.feed)},
            capture_output=True,
            text=True,
        )

    def test_matching_feed_entries_pass(self):
        entry = f"<update><sha256>{self.digest}</sha256></update>"
        self.assertEqual(0, self.verify(f"<updates>{entry}{entry}</updates>").returncode)

    def test_invalid_feeds_fail_closed(self):
        good = f"<update><sha256>{self.digest}</sha256></update>"
        cases = {
            "second mismatch": good + f"<update><sha256>{'0' * 64}</sha256></update>",
            "missing digest": "<update/>",
            "duplicate digest": f"<update><sha256>{self.digest}</sha256><sha256>{self.digest}</sha256></update>",
            "malformed digest": "<update><sha256>invalid</sha256></update>",
            "empty digest": "<update><sha256/></update>",
            "no entries": "",
        }
        for name, entries in cases.items():
            with self.subTest(name=name):
                result = self.verify(f"<updates>{entries}</updates>")
                self.assertNotEqual(0, result.returncode)
                self.assertIn("::error::", result.stderr)
        self.assertNotEqual(0, self.verify("<updates>").returncode)

    def test_missing_package_and_feed_fail(self):
        self.package.unlink()
        self.assertNotEqual(0, self.verify("<updates/>").returncode)
        self.package.write_bytes(b"prepared package")
        self.feed.unlink()
        result = subprocess.run(
            [sys.executable, "-c", self.verifier],
            env={**os.environ, "PACKAGE": str(self.package), "FEED": str(self.feed)},
            capture_output=True,
            text=True,
        )
        self.assertNotEqual(0, result.returncode)

    def test_upload_is_limited_to_the_verified_package(self):
        action = ACTION.read_text(encoding="utf-8")
        upload = action.split("    - name: Upload hash-verified release package\n", 1)[1]
        self.assertEqual(
            "      uses: actions/upload-artifact@cf430e030ddbb5b0abf93d22962f4752f3646cd9 # v7\n"
            "      with:\n"
            "        name: ${{ inputs.artifact-name }}\n"
            "        path: ${{ inputs.package }}\n"
            "        if-no-files-found: error\n"
            "        retention-days: 3\n",
            upload,
        )


if __name__ == "__main__":
    unittest.main()
