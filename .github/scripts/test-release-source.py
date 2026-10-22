#!/usr/bin/env python3
"""Offline tests for release-source.py with real git fixture repositories."""

import importlib.util
import os
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parent / "release-source.py"
spec = importlib.util.spec_from_file_location("release_source", SCRIPT)
source = importlib.util.module_from_spec(spec)
spec.loader.exec_module(source)


def git(repo: Path, *args: str) -> str:
    return subprocess.run(["git", *args], cwd=repo, check=True, capture_output=True, text=True).stdout.strip()


class ReleaseSourceTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.repo = Path(self.temp.name)
        git(self.repo, "init", "-q", "-b", "main")
        git(self.repo, "config", "user.email", "fixture@example.invalid")
        git(self.repo, "config", "user.name", "Fixture")
        self.write("pkg_x/VERSION", "1.0.0\n")
        self.write("com_x/code.php", "released\n")
        self.write("shared/build/build.sh", "build\n")
        self.write(".gitignore", "*.zip\n")
        self.commit("feat(x): first")
        self.write("pkg_x/VERSION", "1.1.0\n")
        self.release = self.commit("release: pkgx v1.1.0 (#7)")
        # A later change on main touches a bundled child after the approved release.
        self.write("com_x/code.php", "changed after the release\n")
        self.later = self.commit("fix(x): later change")
        git(self.repo, "update-ref", "refs/remotes/origin/main", self.later)
        self.cwd = os.getcwd()
        os.chdir(self.repo)
        self.paths = ["pkg_x", "com_x", "shared/build"]

    def tearDown(self):
        os.chdir(self.cwd)
        self.temp.cleanup()

    def write(self, name: str, content: str) -> None:
        path = self.repo / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding="utf-8")

    def commit(self, subject: str) -> str:
        git(self.repo, "add", "-A")
        git(self.repo, "commit", "-q", "-m", subject)
        return git(self.repo, "rev-parse", "HEAD")

    def pr(self, **overrides):
        data = {"merged": True, "merge_commit_sha": self.release, "base": {"ref": "main"},
                "head": {"ref": "release/pkgx-v1.1.0"}, "title": "release: pkgx v1.1.0"}
        data.update(overrides)
        return data

    def test_manual_recovery_builds_the_release_commit_not_the_current_main(self):
        with patch.object(source, "pull_request", return_value=self.pr()):
            sha = source.release_commit_for_dispatch("pkgx", "1.1.0", "owner/repo")
        self.assertEqual(self.release, sha)
        source.check_out(sha)
        source.verify(sha, self.paths)
        self.assertEqual(self.release, git(self.repo, "rev-parse", "HEAD"))
        self.assertEqual("released\n", (self.repo / "com_x/code.php").read_text(encoding="utf-8"))

    def test_a_bundled_path_changed_after_the_release_commit_aborts(self):
        # The build source is the current main: com_x differs from the release commit.
        git(self.repo, "checkout", "-q", "--detach", self.later)
        with self.assertRaises(ValueError):
            source.verify(self.release, self.paths)

    def test_local_changes_or_extra_files_in_a_bundled_path_abort(self):
        source.check_out(self.release)
        self.write("com_x/code.php", "tampered\n")
        with self.assertRaisesRegex(ValueError, "local changes"):
            source.verify(self.release, self.paths)
        source.check_out(self.release)
        self.write("com_x/injected.php", "extra\n")
        with self.assertRaisesRegex(ValueError, "not part of the release commit"):
            source.verify(self.release, self.paths)
        # An ignored build output counts as foreign content too.
        source.check_out(self.release)
        self.write("shared/build/cached.zip", "stale build output\n")
        with self.assertRaisesRegex(ValueError, "not part of the release commit"):
            source.verify(self.release, self.paths)

    def test_check_out_removes_untracked_and_ignored_files(self):
        self.write("com_x/stale.zip", "stale\n")
        source.check_out(self.release)
        self.assertFalse((self.repo / "com_x/stale.zip").exists())
        source.verify(self.release, self.paths)

    def test_the_release_pr_must_confirm_the_commit(self):
        for overrides in [{"merged": False}, {"merge_commit_sha": self.later}, {"base": {"ref": "dev"}},
                          {"head": {"ref": "feature/pkgx"}}, {"title": "release: pkgx v1.1.1"}]:
            with self.subTest(overrides=overrides), patch.object(source, "pull_request", return_value=self.pr(**overrides)):
                with self.assertRaises(ValueError):
                    source.release_commit_for_dispatch("pkgx", "1.1.0", "owner/repo")

    def test_unknown_or_malformed_versions_are_refused(self):
        with patch.object(source, "pull_request", return_value=self.pr()):
            for version in ["1.2.0", "", "1.1", "1.1.0 (#7)", "1.1.0$(id)"]:
                with self.subTest(version=version), self.assertRaises(ValueError):
                    source.release_commit_for_dispatch("pkgx", version, "owner/repo")

    def test_push_requires_a_release_commit(self):
        self.assertEqual(self.release, source.release_commit_for_push("pkgx", self.release))
        with self.assertRaises(ValueError):
            source.release_commit_for_push("pkgx", self.later)
        with self.assertRaises(ValueError):
            source.release_commit_for_push("other", self.release)


if __name__ == "__main__":
    unittest.main(buffer=True)
