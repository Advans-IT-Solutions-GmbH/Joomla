import importlib.util
import io
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import zipfile

spec = importlib.util.spec_from_file_location("publisher", Path(__file__).with_name("publish-release.py"))
publisher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)


class PublishTests(unittest.TestCase):
    def test_numeric_order_and_family_isolation(self):
        refs = {"privacy-v1.9.0": "a", "privacy-v1.10.0": "b", "other-v99.0.0": "c"}
        self.assertEqual(publisher.preflight("privacy-v", "1.11.0", "d", refs, {}),
                         ["privacy-v1.9.0", "privacy-v1.10.0"])

    def test_obsolete_and_conflicting_commit_fail(self):
        for refs, releases in [
            ({"privacy-v2.0.0": "a"}, {}),
            ({}, {"privacy-v2.0.0": {}}),
            ({"privacy-v1.0.0": "other"}, {}),
            ({}, {"privacy-v1.0.0": {}}),
        ]:
            with self.subTest(refs=refs, releases=releases), self.assertRaises(ValueError):
                publisher.preflight("privacy-v", "1.0.0", "built", refs, releases)

    def test_same_version_same_commit_is_idempotent(self):
        self.assertEqual(publisher.preflight("privacy-v", "1.0.0", "built",
                                            {"privacy-v1.0.0": "built"}, {}), [])

    def test_invalid_versions_fail_closed(self):
        for number in ["1.0", "01.0.0", "1.0.0-rc1", "1.0.0\n", "a.b.c"]:
            with self.subTest(number=number), self.assertRaises(ValueError):
                publisher.version(number)

    def test_peeled_tag_and_release_pagination(self):
        with patch.object(publisher, "run", side_effect=[
            "tagobject refs/tags/privacy-v1.0.0\nbuilt refs/tags/privacy-v1.0.0^{}",
            '[[{"tag_name":"privacy-v1.0.0"}], [{"tag_name":"other-v1.0.0"}]]',
        ]):
            refs, releases = publisher.inventory("owner/repo")
        self.assertEqual(refs["privacy-v1.0.0"], "built")
        self.assertEqual(len(releases), 2)

    def publication(self, failed_command=None, existing=False, bad_download=False):
        refs = {"privacy-v0.9.0": "old", "other-v9.0.0": "unrelated"}
        releases = {"privacy-v0.9.0": {}}
        if existing:
            refs["privacy-v1.0.0"] = "built"
            releases["privacy-v1.0.0"] = {"assets": [{"name": "extension.zip"}]}
        calls = []

        def run(*args):
            calls.append(args)
            if failed_command and args[:len(failed_command)] == failed_command:
                raise subprocess.CalledProcessError(1, args)
            if args[:2] == ("gh", "api"):
                return '{"draft":false,"assets":[{"name":"extension.zip"}]}'
            return ""

        with patch.object(publisher, "inventory", return_value=(refs, releases)), \
                patch.object(publisher, "zip_contents", return_value={}), \
                patch.object(publisher, "verify_asset", side_effect=ValueError("Bad download") if bad_download else None) as verify, \
                patch.object(publisher, "run", side_effect=run):
            if failed_command or bad_download:
                with self.assertRaises((subprocess.CalledProcessError, ValueError)):
                    publisher.publish("owner/repo", "privacy-v", "1.0.0", "built",
                                      Path("extension.zip"), "Title", "Notes")
            else:
                publisher.publish("owner/repo", "privacy-v", "1.0.0", "built",
                                  Path("extension.zip"), "Title", "Notes")
                verify.assert_called_once()
        return calls

    def test_failures_preserve_previous_release(self):
        for command in [("git", "push", "origin", "refs/tags/privacy-v1.0.0"),
                        ("gh", "release", "create"), ("gh", "release", "edit"), ("gh", "api")]:
            with self.subTest(command=command):
                calls = self.publication(command)
                self.assertFalse(any(call[:3] == ("gh", "release", "delete") for call in calls))
                self.assertFalse(any("--delete" in call for call in calls))

    def test_cleanup_only_after_verified_publication(self):
        calls = self.publication()
        self.assertGreater(next(i for i, call in enumerate(calls) if "delete" in call),
                           next(i for i, call in enumerate(calls) if call[:2] == ("gh", "api")))
        self.assertFalse(any("other-v9.0.0" in call for call in calls))

    def test_verification_failure_never_deletes_previous_download(self):
        calls = self.publication(bad_download=True)
        self.assertFalse(any("delete" in call or "--delete" in call or "edit" in call for call in calls))

    def test_cleanup_errors_are_not_silenced(self):
        self.publication(("gh", "release", "delete"))
        self.publication(("git", "push", "origin", "--delete"))

    def test_retry_never_deletes_or_clobbers_current_release(self):
        calls = self.publication(existing=True)
        self.assertFalse(any("create" in call or "upload" in call or "--clobber" in call for call in calls))
        self.assertFalse(any("privacy-v1.0.0" in call and "delete" in call for call in calls))
        edit = next(call for call in calls if call[:3] == ("gh", "release", "edit"))
        self.assertNotIn("--notes", edit)
        self.assertNotIn("--title", edit)

    def test_zip_comparison_ignores_container_timestamps(self):
        with tempfile.TemporaryDirectory() as directory:
            paths = [Path(directory) / name for name in ("one.zip", "two.zip")]
            for year, path in zip((2025, 2026), paths):
                with zipfile.ZipFile(path, "w") as archive:
                    info = zipfile.ZipInfo("payload.txt", (year, 1, 1, 0, 0, 0))
                    archive.writestr(info, "same payload")
            self.assertEqual(publisher.zip_contents(paths[0]), publisher.zip_contents(paths[1]))

    def test_nested_packages_ignore_child_zip_timestamps(self):
        with tempfile.TemporaryDirectory() as directory:
            paths = [Path(directory) / name for name in ("one.zip", "two.zip")]
            for year, path in zip((2025, 2026), paths):
                child = io.BytesIO()
                with zipfile.ZipFile(child, "w") as archive:
                    archive.writestr(zipfile.ZipInfo("payload.txt", (year, 1, 1, 0, 0, 0)), "payload")
                with zipfile.ZipFile(path, "w") as archive:
                    archive.writestr("packages/child.zip", child.getvalue())
            self.assertEqual(publisher.zip_contents(paths[0]), publisher.zip_contents(paths[1]))

    def test_attestation_input_matches_downloaded_bytes_on_retry(self):
        with tempfile.TemporaryDirectory() as directory:
            package = Path(directory) / "extension.zip"
            with zipfile.ZipFile(package, "w") as archive:
                archive.writestr(zipfile.ZipInfo("payload", (2025, 1, 1, 0, 0, 0)), "same")
            downloaded = io.BytesIO()
            with zipfile.ZipFile(downloaded, "w") as archive:
                archive.writestr(zipfile.ZipInfo("payload", (2026, 1, 1, 0, 0, 0)), "same")

            def download(*args):
                target = Path(args[args.index("--dir") + 1]) / package.name
                target.write_bytes(downloaded.getvalue())
                return ""

            with patch.object(publisher, "run", side_effect=download):
                publisher.verify_asset("owner/repo", "privacy-v1.0.0", package)
            self.assertEqual(package.read_bytes(), downloaded.getvalue())

    def test_obsolete_publish_does_not_write(self):
        with patch.object(publisher, "inventory", return_value=({"privacy-v2.0.0": "newer"}, {})), \
                patch.object(publisher, "run") as run, self.assertRaises(ValueError):
            publisher.publish("owner/repo", "privacy-v", "1.0.0", "old",
                              Path("extension.zip"), "Title", "Notes")
        run.assert_not_called()

    def test_preflight_records_actual_checkout_not_event_sha(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / "output"
            with patch.dict(os.environ, {"GITHUB_REPOSITORY": "owner/repo", "GITHUB_SHA": "event",
                                        "GITHUB_OUTPUT": str(output)}), \
                    patch("sys.argv", ["publish-release.py", "preflight", "--prefix", "privacy-v",
                                       "--version", "1.0.0"]), \
                    patch.object(publisher, "run", return_value="built"), \
                    patch.object(publisher, "inventory", return_value=({}, {})):
                publisher.main()
            self.assertIn("sha=built\n", output.read_text())

    def test_changed_checkout_is_rejected_before_publication(self):
        with patch.dict(os.environ, {"GITHUB_REPOSITORY": "owner/repo", "BUILT_SHA": "built"}), \
                patch("sys.argv", ["publish-release.py", "publish", "--prefix", "privacy-v",
                                   "--version", "1.0.0", "--package", "extension.zip", "--title", "Title"]), \
                patch.object(publisher, "run", return_value="changed"), \
                patch.object(publisher, "publish") as publish, self.assertRaises(ValueError):
            publisher.main()
        publish.assert_not_called()

    def test_all_publish_workflows_use_the_guarded_helper(self):
        workflows = Path(__file__).resolve().parent.parent / "workflows"
        paths = list(workflows.glob("publish-*.yml"))
        self.assertEqual(len(paths), 12)
        for path in paths:
            text = path.read_text(encoding="utf-8")
            with self.subTest(workflow=path.name):
                self.assertIn("publish-release.py preflight", text)
                self.assertIn("publish-release.py publish", text)
                self.assertIn("BUILT_SHA: ${{ steps.preflight.outputs.sha }}", text)
                self.assertIn("cancel-in-progress: false", text)
                self.assertNotIn("gh release delete", text)
                self.assertNotIn("--clobber", text)
                self.assertLess(text.index("publish-release.py preflight"), text.index("      - name: Build"))
                # Every run, also a manual recovery on main, builds only the merged release
                # commit and checks the trees of the bundled paths before anything else.
                self.assertIn("  workflow_dispatch:\n", text.replace("\r\n", "\n"))
                self.assertIn("github.ref == 'refs/heads/main' && (github.event_name == 'workflow_dispatch' ||", text)
                source = text.index("      - name: Check out exactly the released source")
                self.assertLess(source, text.index("      - name: Read version from manifest"))
                self.assertLess(source, text.index("publish-release.py preflight"))
                step = text[source:text.index("      - name: Read version from manifest")]
                self.assertIn('cp .github/scripts/release-source.py "$RUNNER_TEMP/release-source.py"', step)
                self.assertIn("RELEASE_VERSION: ${{ inputs.version }}", step)
                self.assertIn("--path shared/build", step)
                self.assertNotIn("${{ inputs.version }}", text.replace(step, ""))
                if path.name == "publish-pkg-dmarcreports.yml":
                    for child in ["com_dmarcreports", "mod_dmarcreports", "plg_task_dmarcreports", "plg_system_dmarcmtasts"]:
                        self.assertIn(f"--path {child}", step)
                # Tag, release notes and attestation name the verified release commit.
                self.assertIn('--provenance-out "$RUNNER_TEMP/provenance.json"', step)
                confirm = text.index("      - name: Confirm that the build source is the verified release commit")
                self.assertLess(text.index("publish-release.py preflight"), confirm)
                self.assertLess(confirm, text.index("      - name: Build"))
                self.assertIn("SOURCE_SHA: ${{ steps.source.outputs.sha }}", text[confirm:confirm + 400])
                self.assertIn("BUILT_SHA: ${{ steps.preflight.outputs.sha }}", text[confirm:confirm + 400])
                self.assertIn("Built from the release commit ${{ steps.source.outputs.sha }}.", text)
                pushed = text.index("      - name: Attest build provenance")
                self.assertIn("if: github.event_name == 'push'", text[pushed:pushed + 120])
                recovered = text.index("      - name: Attest the provenance of the recovered release commit")
                block = text[recovered:recovered + 700]
                self.assertIn("if: github.event_name == 'workflow_dispatch'", block)
                self.assertIn("predicate-type: https://slsa.dev/provenance/v1", block)
                self.assertIn("predicate-path: ${{ runner.temp }}/provenance.json", block)
                self.assertEqual(2, text.count("uses: actions/attest-build-provenance@"))
                # Nothing outside the release commit may reach the package.
                for forbidden in ["actions/cache", "download-artifact", "curl ", "wget ", "composer ", "npm "]:
                    self.assertNotIn(forbidden, text)

    def test_both_builders_require_package_verification(self):
        root = Path(__file__).resolve().parent.parent.parent
        for filename in ["shared/build/build.sh", "pkg_dmarcreports/build.sh"]:
            text = (root / filename).read_text(encoding="utf-8")
            with self.subTest(builder=filename):
                self.assertIn('bash "$VERIFY_SCRIPT"', text)
                self.assertNotIn('[ -x "$VERIFY_SCRIPT" ]', text)


if __name__ == "__main__":
    unittest.main(buffer=True)
