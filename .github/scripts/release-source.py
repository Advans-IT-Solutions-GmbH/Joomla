#!/usr/bin/env python3
"""Check out exactly the released source before a Publish workflow builds anything.

A publication must ship the content of the merged release commit and nothing else.

* push (the normal path): the pushed commit itself must be the release commit.
* workflow_dispatch (recovering a publication that never ran): the release commit is
  taken from the merged release PR "release: <prefix> v<version> (#N)" on the
  first-parent history of main. GitHub must confirm that PR #N is merged into main,
  comes from release/<prefix>-v<version> and produced exactly that commit. The run then
  checks out that commit, never the current main.

In both cases the repository is checked out at the release commit, cleaned of every
untracked and ignored file, and the tree of every bundled path (the extension, the
children of a package, the build scripts) is compared between the release commit and
the build source. Any difference, local modification or extra file aborts the run.

With --provenance-out it also writes the SLSA provenance predicate that a recovery run
attests, naming the verified release commit as the built source.

Run a copy of this file from $RUNNER_TEMP: the checkout replaces the repository's files.
"""

import argparse
import json
import os
import re
import subprocess
import sys

VERSION = re.compile(r"\A[0-9]+\.[0-9]+\.[0-9]+\Z")
PREFIX = re.compile(r"\A[a-z0-9][a-z0-9-]*\Z")


def git(*args: str) -> str:
    return subprocess.run(["git", *args], check=True, capture_output=True, text=True).stdout.strip()


def pull_request(repo: str, number: int) -> dict:
    output = subprocess.run(["gh", "api", f"repos/{repo}/pulls/{number}"], check=True,
                            capture_output=True, text=True).stdout
    return json.loads(output)


def release_commit_for_push(prefix: str, sha: str) -> str:
    subject = git("log", "-1", "--format=%s", sha)
    if not subject.startswith(f"release: {prefix} v"):
        raise ValueError(f"The pushed commit is not a release commit of {prefix}: {subject}")
    return sha


def release_commit_for_dispatch(prefix: str, version: str, repo: str, main_ref: str = "origin/main") -> str:
    if not VERSION.match(version or ""):
        raise ValueError("A manual run needs the released version as x.y.z.")
    subject_pattern = re.compile(rf"\Arelease: {re.escape(prefix)} v{re.escape(version)} \(#([0-9]+)\)\Z")
    for line in git("log", "--first-parent", "--format=%H%x00%s", main_ref).splitlines():
        sha, _, subject = line.partition("\0")
        match = subject_pattern.match(subject)
        if match:
            break
    else:
        raise ValueError(f"No merged release PR for {prefix} v{version} on main.")

    pr = pull_request(repo, int(match.group(1)))
    expected = {
        "merged": True,
        "merge_commit_sha": sha,
        "base": "main",
        "head": f"release/{prefix}-v{version}",
        "title": f"release: {prefix} v{version}",
    }
    actual = {
        "merged": pr.get("merged") is True,
        "merge_commit_sha": pr.get("merge_commit_sha"),
        "base": (pr.get("base") or {}).get("ref"),
        "head": (pr.get("head") or {}).get("ref"),
        "title": pr.get("title"),
    }
    if actual != expected:
        raise ValueError(f"PR #{match.group(1)} does not confirm {sha} as the release of {prefix} v{version}.")
    return sha


def check_out(sha: str) -> None:
    git("checkout", "--force", "--detach", sha)
    git("clean", "-ffdx")


def verify(sha: str, paths: list) -> None:
    """The build source must equal the release commit on every bundled path."""
    if git("rev-parse", "HEAD") != git("rev-parse", f"{sha}^{{commit}}"):
        raise ValueError(f"The build source is not the release commit {sha}.")
    for path in paths:
        released = git("rev-parse", f"{sha}:{path}")
        built = git("rev-parse", f"HEAD:{path}")
        if released != built:
            raise ValueError(f"{path} differs from the release commit ({built} instead of {released}).")
        if subprocess.run(["git", "diff", "--quiet", sha, "--", path]).returncode != 0:
            raise ValueError(f"{path} has local changes against the release commit.")
        if git("ls-files", "--others", "--", path):
            raise ValueError(f"{path} contains files that are not part of the release commit.")


def provenance(sha: str, environ: dict) -> dict:
    """SLSA v1 provenance predicate for a recovery run.

    A recovery run starts from the current main, so the provenance GitHub derives from
    the run would name that commit as the source. This predicate names the verified
    release commit as the resolved source instead and keeps the commit of the workflow
    definition separately, so the signed statement says exactly what was built.
    """
    server = environ.get("GITHUB_SERVER_URL", "https://github.com")
    repo = environ["GITHUB_REPOSITORY"]
    run = f"{server}/{repo}/actions/runs/{environ['GITHUB_RUN_ID']}/attempts/{environ.get('GITHUB_RUN_ATTEMPT', '1')}"
    return {
        "buildDefinition": {
            "buildType": "https://actions.github.io/buildtypes/workflow/v1",
            "externalParameters": {
                "workflow": {
                    "ref": environ["GITHUB_REF"],
                    "repository": f"{server}/{repo}",
                    "path": environ["GITHUB_WORKFLOW_REF"].split("@", 1)[0].split("/", 2)[2],
                },
                "releaseVersion": environ.get("RELEASE_VERSION", ""),
            },
            "internalParameters": {
                "github": {
                    "event_name": environ["GITHUB_EVENT_NAME"],
                    "repository_id": environ.get("GITHUB_REPOSITORY_ID", ""),
                    "repository_owner_id": environ.get("GITHUB_REPOSITORY_OWNER_ID", ""),
                    "workflow_sha": environ["GITHUB_SHA"],
                },
            },
            "resolvedDependencies": [{
                "uri": f"git+{server}/{repo}@refs/heads/main",
                "digest": {"gitCommit": sha},
            }],
        },
        "runDetails": {
            "builder": {"id": f"{server}/{environ['GITHUB_WORKFLOW_REF']}"},
            "metadata": {"invocationId": run},
        },
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--prefix", required=True)
    parser.add_argument("--path", action="append", required=True, dest="paths")
    parser.add_argument("--provenance-out", help="write the SLSA predicate of the verified release commit here")
    args = parser.parse_args()
    if not PREFIX.match(args.prefix):
        raise ValueError("Invalid release prefix.")

    event = os.environ.get("GITHUB_EVENT_NAME", "")
    if event == "push":
        sha = release_commit_for_push(args.prefix, os.environ["GITHUB_SHA"])
    elif event == "workflow_dispatch":
        sha = release_commit_for_dispatch(args.prefix, os.environ.get("RELEASE_VERSION", ""),
                                          os.environ["GITHUB_REPOSITORY"])
    else:
        raise ValueError(f"Unsupported event: {event}")

    check_out(sha)
    verify(sha, args.paths)
    if args.provenance_out:
        # Written only after the checkout and the tree comparison, from the same verified sha.
        with open(args.provenance_out, "w", encoding="utf-8") as handle:
            json.dump(provenance(git("rev-parse", "HEAD"), dict(os.environ)), handle, indent=2)
    print(f"Building the released source {sha}.")
    output = os.environ.get("GITHUB_OUTPUT")
    if output:
        with open(output, "a", encoding="utf-8") as handle:
            handle.write(f"sha={sha}\n")


if __name__ == "__main__":
    try:
        main()
    except (ValueError, subprocess.CalledProcessError, KeyError) as error:
        print(f"::error::{error}", file=sys.stderr)
        sys.exit(1)
