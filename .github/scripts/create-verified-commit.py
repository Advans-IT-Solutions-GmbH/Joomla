#!/usr/bin/env python3
"""Commit the staged changes to a branch through the GitHub API, so GitHub signs the commit.

A Release workflow used to run `git commit` and `git push --force-with-lease`. Such a commit
carries no signature, while the organisation ruleset requires a verified signature on every
commit of a pull request. A commit created with the GraphQL mutation `createCommitOnBranch`
and the workflow's `GITHUB_TOKEN` is signed by GitHub and shows as "Verified".

The script mirrors the old local commit and force push exactly:

* the parent is the given base commit (the fetched `origin/main`);
* the file changes are the staged changes of the index, read from the index blobs, so the
  commit contains exactly what `git commit` would have committed;
* the message is the given subject, without a body or trailers;
* the branch ends up at that one commit, whether it existed before or not.

The commit is first created on a temporary branch. Only after GitHub confirms that its tree
equals the local index tree (`git write-tree`), its parent is the base, its message is the
subject and its signature is verified does the release branch move to it. The temporary
branch is always deleted again.

The GraphQL API cannot set file modes, so a staged executable file, symbolic link or
submodule is refused instead of being committed with a different mode.

Needs `gh` with GH_TOKEN and GITHUB_REPOSITORY in the environment.
"""

import argparse
import base64
import json
import os
import subprocess
import sys
import urllib.parse

MUTATION = """
mutation($input: CreateCommitOnBranchInput!) {
  createCommitOnBranch(input: $input) {
    commit { oid }
  }
}
""".strip()

REGULAR_FILE = "100644"


class CommitError(RuntimeError):
    pass


def git(*args: str) -> str:
    return subprocess.run(["git", *args], check=True, capture_output=True, text=True).stdout


def git_bytes(*args: str) -> bytes:
    return subprocess.run(["git", *args], check=True, capture_output=True).stdout


def gh_api(args: list, payload=None, allow_missing: bool = False):
    """Call `gh api` and return the decoded JSON body (None for an allowed 404 or no body)."""
    command = ["gh", "api", *args]
    if payload is not None:
        command += ["--input", "-"]
    result = subprocess.run(command, input=json.dumps(payload) if payload is not None else None,
                            capture_output=True, text=True)
    if result.returncode != 0:
        if allow_missing and "HTTP 404" in result.stderr:
            return None
        raise CommitError(f"gh api {' '.join(args)} failed: {result.stderr.strip()}")
    return json.loads(result.stdout) if result.stdout.strip() else None


def ref_path(repo: str, branch: str) -> str:
    return f"repos/{repo}/git/refs/heads/{urllib.parse.quote(branch, safe='/')}"


def staged_changes() -> tuple:
    """Return (additions, deletions) of the index against HEAD, refusing non-regular files."""
    raw = git_bytes("diff", "--staged", "--raw", "--no-renames", "-z").split(b"\0")
    additions, deletions = [], []
    i = 0
    while i < len(raw) - 1:
        meta = raw[i].decode()
        path = raw[i + 1].decode()
        i += 2
        _, new_mode, _, _, status = meta.lstrip(":").split(" ")
        if status == "D":
            deletions.append({"path": path})
            continue
        if new_mode != REGULAR_FILE:
            raise CommitError(f"{path}: mode {new_mode} cannot be committed through the API "
                              f"(only regular, non-executable files {REGULAR_FILE})")
        contents = git_bytes("show", f":{path}")
        additions.append({"path": path, "contents": base64.b64encode(contents).decode()})
    return additions, deletions


def create_verified_commit(repo: str, branch: str, message: str, base: str, temp_branch: str,
                           api=gh_api) -> str:
    if not message or "\n" in message:
        raise CommitError("The commit message must be a single, non-empty subject line.")
    head = git("rev-parse", "HEAD").strip()
    if head != base:
        raise CommitError(f"HEAD {head} is not the base {base}; the staged changes must be relative to the base.")

    additions, deletions = staged_changes()
    if not additions and not deletions:
        raise CommitError("No staged changes to commit.")
    expected_tree = git("write-tree").strip()

    api(["--method", "POST", f"repos/{repo}/git/refs", "-f", f"ref=refs/heads/{temp_branch}",
         "-f", f"sha={base}"])
    try:
        result = api(["graphql"], {"query": MUTATION, "variables": {"input": {
            "branch": {"repositoryNameWithOwner": repo, "branchName": temp_branch},
            "message": {"headline": message},
            "expectedHeadOid": base,
            "fileChanges": {"additions": additions, "deletions": deletions},
        }}})
        if result.get("errors"):
            raise CommitError(f"createCommitOnBranch failed: {result['errors']}")
        oid = result["data"]["createCommitOnBranch"]["commit"]["oid"]

        commit = api([f"repos/{repo}/git/commits/{oid}"])
        problems = []
        if commit["tree"]["sha"] != expected_tree:
            problems.append(f"tree {commit['tree']['sha']} differs from the staged tree {expected_tree}")
        if [parent["sha"] for parent in commit["parents"]] != [base]:
            problems.append(f"parents {[p['sha'] for p in commit['parents']]} are not [{base}]")
        if commit["message"].rstrip("\n") != message:
            problems.append(f"message {commit['message']!r} is not {message!r}")
        if not (commit.get("verification") or {}).get("verified"):
            problems.append(f"signature is not verified ({(commit.get('verification') or {}).get('reason')})")
        if problems:
            raise CommitError(f"The API commit {oid} does not match the staged changes: " + "; ".join(problems))

        # Same outcome as `git push --force-with-lease`: the branch points at exactly this commit.
        if api([ref_path(repo, branch)], allow_missing=True) is None:
            api(["--method", "POST", f"repos/{repo}/git/refs", "-f", f"ref=refs/heads/{branch}",
                 "-f", f"sha={oid}"])
        else:
            api(["--method", "PATCH", ref_path(repo, branch), "-f", f"sha={oid}", "-F", "force=true"])
        return oid
    finally:
        api(["--method", "DELETE", ref_path(repo, temp_branch)], allow_missing=True)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--branch", required=True, help="branch that ends up at the new commit")
    parser.add_argument("--message", required=True, help="commit subject, e.g. 'release: x v1.2.3'")
    parser.add_argument("--base", required=True, help="parent commit, the fetched origin/main")
    args = parser.parse_args()

    repo = os.environ.get("GITHUB_REPOSITORY", "")
    if not repo:
        print("::error::GITHUB_REPOSITORY is not set.", file=sys.stderr)
        return 1
    base = git("rev-parse", "--verify", f"{args.base}^{{commit}}").strip()
    temp = f"{args.branch}-api-commit-{os.environ.get('GITHUB_RUN_ID', 'local')}-{os.environ.get('GITHUB_RUN_ATTEMPT', '1')}"
    try:
        oid = create_verified_commit(repo, args.branch, args.message, base, temp)
    except (CommitError, subprocess.CalledProcessError) as error:
        print(f"::error::{error}", file=sys.stderr)
        return 1
    print(f"Created verified commit {oid} on {args.branch}: {args.message}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
