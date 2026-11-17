#!/usr/bin/env python3
"""Offline tests for create-verified-commit.py and the Release workflow contract.

A fake GitHub API applies the createCommitOnBranch file changes to the base tree in the
fixture repository itself, so the tests prove that the API commit reproduces exactly the
staged tree that a local `git commit` would have produced.
"""

import base64
import importlib.util
import os
import re
import subprocess
import tempfile
import unittest
from pathlib import Path

SCRIPTS = Path(__file__).resolve().parent
ROOT = SCRIPTS.parent.parent
spec = importlib.util.spec_from_file_location("create_verified_commit", SCRIPTS / "create-verified-commit.py")
cvc = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cvc)

REPO = "owner/repo"


def git(repo: Path, *args: str, env=None, stdin=None) -> str:
    return subprocess.run(["git", *args], cwd=repo, check=True, capture_output=True,
                          input=stdin, env=env).stdout.decode().strip()


class FakeGitHub:
    """Just enough of the refs, GraphQL and git commits API, backed by the fixture repo."""

    def __init__(self, repo: Path, refs=None, verified=True, corrupt=False):
        self.repo = repo
        self.refs = dict(refs or {})
        self.verified = verified
        self.corrupt = corrupt
        self.calls = []

    def ref_name(self, path: str) -> str:
        prefix = f"repos/{REPO}/git/refs/heads/"
        assert path.startswith(prefix), path
        return path[len(prefix):]

    def __call__(self, args, payload=None, allow_missing=False):
        self.calls.append((list(args), payload))
        method = args[args.index("--method") + 1] if "--method" in args else "GET"
        rest = [a for i, a in enumerate(args) if a != "--method" and (i == 0 or args[i - 1] != "--method")]
        target = rest[0]
        fields = dict(f.split("=", 1) for f in rest[1:] if "=" in f)
        if target == "graphql":
            return self.graphql(payload)
        if target.startswith(f"repos/{REPO}/git/commits/"):
            return self.commit(target.rsplit("/", 1)[1])
        if method == "POST" and target == f"repos/{REPO}/git/refs":
            name = fields["ref"][len("refs/heads/"):]
            assert name not in self.refs, f"ref {name} already exists"
            self.refs[name] = fields["sha"]
            return {}
        name = self.ref_name(target)
        if method == "GET":
            if name not in self.refs:
                if allow_missing:
                    return None
                raise cvc.CommitError("HTTP 404")
            return {"object": {"sha": self.refs[name]}}
        if method == "PATCH":
            assert fields.get("force") == "true"
            self.refs[name] = fields["sha"]
            return {}
        if method == "DELETE":
            if self.refs.pop(name, None) is None and not allow_missing:
                raise cvc.CommitError("HTTP 404")
            return None
        raise AssertionError(f"unexpected call {args}")

    def graphql(self, payload):
        data = payload["variables"]["input"]
        branch = data["branch"]["branchName"]
        assert data["branch"]["repositoryNameWithOwner"] == REPO
        assert self.refs[branch] == data["expectedHeadOid"], "expectedHeadOid must be the branch head"
        assert set(data["message"]) == {"headline"}, "no body, no trailers"
        index = Path(self.repo) / ".git" / "fake-api-index"
        env = {**os.environ, "GIT_INDEX_FILE": str(index)}
        git(self.repo, "read-tree", data["expectedHeadOid"], env=env)
        for addition in data["fileChanges"]["additions"]:
            content = base64.b64decode(addition["contents"])
            if self.corrupt:
                content += b"corrupted"
            blob = git(self.repo, "hash-object", "-w", "--stdin", stdin=content)
            git(self.repo, "update-index", "--add", "--cacheinfo", f"100644,{blob},{addition['path']}", env=env)
        for deletion in data["fileChanges"]["deletions"]:
            git(self.repo, "update-index", "--force-remove", deletion["path"], env=env)
        tree = git(self.repo, "write-tree", env=env)
        index.unlink()
        oid = git(self.repo, "commit-tree", tree, "-p", data["expectedHeadOid"], "-m", data["message"]["headline"])
        self.refs[branch] = oid
        return {"data": {"createCommitOnBranch": {"commit": {"oid": oid}}}}

    def commit(self, oid):
        tree, parents, message = git(self.repo, "show", "-s", "--format=%T%x00%P%x00%B", oid).split("\0")
        return {"tree": {"sha": tree}, "parents": [{"sha": p} for p in parents.split()],
                "message": message + "\n", "verification": {"verified": self.verified, "reason": "valid" if self.verified else "unsigned"}}


class CreateVerifiedCommitTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.repo = Path(self.temp.name)
        git(self.repo, "init", "-q", "-b", "main")
        git(self.repo, "config", "user.email", "fixture@example.invalid")
        git(self.repo, "config", "user.name", "Fixture")
        git(self.repo, "config", "core.autocrlf", "false")
        self.write("com_x/VERSION", "1.0.0\n")
        self.write("com_x/x.xml", "<version>1.0.0</version>\n")
        self.write("com_x/obsolete.txt", "old\n")
        self.write("other/file.txt", "untouched\n")
        git(self.repo, "add", "-A")
        git(self.repo, "commit", "-q", "-m", "feat(x): first")
        self.base = git(self.repo, "rev-parse", "HEAD")
        self.cwd = os.getcwd()
        os.chdir(self.repo)

    def tearDown(self):
        os.chdir(self.cwd)
        self.temp.cleanup()

    def write(self, name: str, content: str) -> None:
        path = self.repo / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content.encode())

    def stage_release(self):
        self.write("com_x/VERSION", "1.1.0\n")
        self.write("com_x/x.xml", "<version>1.1.0</version>\n")
        self.write("com_x/updates/update.xml", "<updates/>\n")
        (self.repo / "com_x/obsolete.txt").unlink()
        git(self.repo, "add", "com_x/")
        # Unstaged edits must not reach the commit, exactly as with `git commit`.
        self.write("com_x/VERSION", "9.9.9\n")
        return git(self.repo, "write-tree")

    def run_script(self, api, branch="release/x-v1.1.0"):
        return cvc.create_verified_commit(REPO, branch, "release: x v1.1.0", self.base, "tmp-branch", api=api)

    def test_new_branch_gets_the_exact_staged_tree_as_one_verified_commit(self):
        staged = self.stage_release()
        api = FakeGitHub(self.repo, refs={"main": self.base})
        oid = self.run_script(api)
        self.assertEqual(api.refs["release/x-v1.1.0"], oid)
        self.assertNotIn("tmp-branch", api.refs, "the temporary branch is deleted")
        self.assertEqual(git(self.repo, "rev-parse", f"{oid}^{{tree}}"), staged)
        self.assertEqual(git(self.repo, "rev-parse", f"{oid}^"), self.base)
        self.assertEqual(git(self.repo, "log", "-1", "--format=%B", oid), "release: x v1.1.0")
        payload = next(p for a, p in api.calls if a == ["graphql"])
        changes = payload["variables"]["input"]["fileChanges"]
        self.assertEqual(changes["deletions"], [{"path": "com_x/obsolete.txt"}])
        contents = {a["path"]: base64.b64decode(a["contents"]) for a in changes["additions"]}
        self.assertEqual(contents["com_x/VERSION"], b"1.1.0\n", "index content, not the working tree")
        self.assertEqual(set(contents), {"com_x/VERSION", "com_x/x.xml", "com_x/updates/update.xml"})

    def test_existing_branch_is_reset_like_a_force_push(self):
        self.stage_release()
        api = FakeGitHub(self.repo, refs={"main": self.base, "release/x-v1.1.0": "0" * 40})
        oid = self.run_script(api)
        self.assertEqual(api.refs["release/x-v1.1.0"], oid)
        self.assertTrue(any("PATCH" in a for a, _ in api.calls))

    def test_tree_mismatch_never_moves_the_release_branch(self):
        self.stage_release()
        api = FakeGitHub(self.repo, refs={"main": self.base}, corrupt=True)
        with self.assertRaisesRegex(cvc.CommitError, "differs from the staged tree"):
            self.run_script(api)
        self.assertNotIn("release/x-v1.1.0", api.refs)
        self.assertNotIn("tmp-branch", api.refs)

    def test_unverified_commit_never_moves_the_release_branch(self):
        self.stage_release()
        api = FakeGitHub(self.repo, refs={"main": self.base}, verified=False)
        with self.assertRaisesRegex(cvc.CommitError, "not verified"):
            self.run_script(api)
        self.assertNotIn("release/x-v1.1.0", api.refs)
        self.assertNotIn("tmp-branch", api.refs)

    def test_executable_file_is_refused_before_any_api_call(self):
        self.stage_release()
        git(self.repo, "update-index", "--chmod=+x", "com_x/x.xml")
        api = FakeGitHub(self.repo, refs={"main": self.base})
        with self.assertRaisesRegex(cvc.CommitError, "100755"):
            self.run_script(api)
        self.assertEqual(api.calls, [])

    def test_nothing_staged_is_an_error(self):
        api = FakeGitHub(self.repo, refs={"main": self.base})
        with self.assertRaisesRegex(cvc.CommitError, "No staged changes"):
            self.run_script(api)
        self.assertEqual(api.calls, [])

    def test_base_must_be_head_and_message_one_line(self):
        self.stage_release()
        api = FakeGitHub(self.repo, refs={"main": self.base})
        with self.assertRaisesRegex(cvc.CommitError, "single"):
            cvc.create_verified_commit(REPO, "b", "release: x v1.1.0\n\nbody", self.base, "t", api=api)
        with self.assertRaisesRegex(cvc.CommitError, "is not the base"):
            cvc.create_verified_commit(REPO, "b", "release: x v1.1.0", "1" * 40, "t", api=api)
        self.assertEqual(api.calls, [])


class ReleaseWorkflowContractTest(unittest.TestCase):
    """Every Release workflow creates its version commit through the API, nothing else changed."""

    CALL = 'python3 .github/scripts/create-verified-commit.py --branch "$BRANCH" --message "$TITLE" --base origin/main'

    def test_every_release_workflow_commits_through_the_api(self):
        workflows = sorted((ROOT / ".github/workflows").glob("release-*.yml"))
        self.assertEqual(len(workflows), 13, [w.name for w in workflows])
        for workflow in workflows:
            with self.subTest(workflow=workflow.name):
                text = workflow.read_text(encoding="utf-8").replace("\r\n", "\n")
                self.assertNotRegex(text, r"(?m)^\s*git (commit|push)\b", "no local commit or push")
                self.assertNotIn("git config user.", text)
                step = text[text.index("      - name: Create release PR\n"):]
                step = step[:step.index("\n      - name: ", 1)]
                self.assertIn("GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}", step)
                ext = re.search(r'(?m)^\s*EXT_PATH="([^"]+)"', text).group(1)
                order = [step.index('git switch -C "$BRANCH" origin/main'), step.index(f"git add {ext}\n"),
                         step.index("if git diff --staged --quiet; then"), step.index(self.CALL),
                         step.index("gh pr create")]
                self.assertEqual(order, sorted(order), "switch, add, empty check, API commit, PR")
                self.assertEqual(step.count(self.CALL), 1)
                self.assertRegex(text, r"(?m)^      contents: write$")


if __name__ == "__main__":
    unittest.main(verbosity=2)
