#!/usr/bin/env python3
"""Publish one extension monotonically, retaining only its newest release."""

import argparse
import hashlib
import io
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile
import xml.etree.ElementTree as ElementTree


def run(*args):
    return subprocess.check_output(args, text=True).strip()


def version(value):
    if not re.fullmatch(r"(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)", value):
        raise ValueError(f"Unsupported release version: {value!r}; expected major.minor.patch")
    return tuple(map(int, value.split(".")))


def inventory(repo):
    refs = {}
    for line in run("git", "ls-remote", "--tags", "origin").splitlines():
        sha, ref = line.split()
        tag = ref.removeprefix("refs/tags/").removesuffix("^{}")
        if tag not in refs or ref.endswith("^{}"):
            refs[tag] = sha
    pages = json.loads(run("gh", "api", "--paginate", "--slurp", f"repos/{repo}/releases?per_page=100"))
    releases = {release["tag_name"]: release for page in pages for release in page}
    return refs, releases


def preflight(prefix, number, sha, refs, releases):
    current = version(number)
    tag = prefix + number
    older = []
    for other in sorted(refs.keys() | releases.keys()):
        if not other.startswith(prefix):
            continue
        candidate = version(other[len(prefix):])
        if candidate > current:
            raise ValueError(f"Obsolete publication {tag}: newer {other} already exists")
        if candidate < current:
            older.append(other)
    if tag in refs and refs[tag] != sha:
        raise ValueError(f"{tag} points to {refs[tag]}, not the built checkout {sha}")
    if tag in releases and tag not in refs:
        raise ValueError(f"Release {tag} has no remote tag; refusing to guess its built commit")
    return sorted(older, key=lambda item: version(item[len(prefix):]))


def zip_contents(path):
    with zipfile.ZipFile(path) as archive:
        if archive.testzip() is not None:
            raise ValueError(f"Corrupt package: {path}")
        names = archive.namelist()
        if len(set(names)) != len(names):
            raise ValueError(f"Duplicate ZIP entries: {path}")
        contents = {}
        for name in names:
            if name.endswith("/"):
                continue
            payload = archive.read(name)
            # Package extensions contain child ZIPs whose timestamps also vary on retries.
            contents[name] = (zip_contents(io.BytesIO(payload)) if name.endswith(".zip")
                              else hashlib.sha256(payload).hexdigest())
        if not contents:
            raise ValueError(f"Empty package: {path}")
        return contents


def verify_asset(repo, tag, package):
    with tempfile.TemporaryDirectory() as directory:
        run("gh", "release", "download", tag, "--repo", repo,
            "--pattern", package.name, "--dir", directory)
        if zip_contents(Path(directory) / package.name) != zip_contents(package):
            raise ValueError(f"Published {tag}/{package.name} differs from the built package")
        # Attestation runs after publication and must hash the downloadable ZIP,
        # not a timestamp-different rebuild of the same payload on a retry.
        shutil.copyfile(Path(directory) / package.name, package)


def validate_changelog(changelog, prefix, number):
    if changelog is None or not changelog.is_file():
        raise ValueError("Missing required Joomla changelog XML")
    try:
        document = ElementTree.parse(changelog)
    except ElementTree.ParseError as error:
        raise ValueError(f"Malformed Joomla changelog XML: {error}") from error
    root = document.getroot()
    if root.tag != "changelogs":
        raise ValueError("Changelog XML root must be <changelogs>")
    entries = root.findall("changelog")
    if not entries:
        raise ValueError("Changelog XML has no <changelog> entry")
    matching = []
    for entry in entries:
        version = entry.findtext("version")
        if version == number:
            matching.append(entry)
    if not matching:
        raise ValueError(f"Changelog XML has no entry matching version {number}")
    expected_tag = prefix + number
    expected_url = f"https://github.com/Advans-IT-Solutions-GmbH/Joomla/releases/tag/{expected_tag}"
    for entry in matching:
        for item in entry.findall(".//item"):
            if (item.text or "").strip() == f"Release notes: {expected_url}":
                return
    raise ValueError(f"Changelog XML has no release-notes link for {expected_tag}")


def verify_remote_changelog(repo, tag, changelog, prefix, number):
    with tempfile.TemporaryDirectory() as directory:
        run("gh", "release", "download", tag, "--repo", repo,
            "--pattern", changelog.name, "--dir", directory)
        downloaded = Path(directory) / changelog.name
        if not downloaded.is_file():
            raise ValueError(f"Downloaded changelog asset is missing: {tag}/{changelog.name}")
        validate_changelog(downloaded, prefix, number)


def publish(repo, prefix, number, sha, package, title, notes, changelog=None):
    # Repeat the remote preflight immediately before writes, not against stale checkout tags.
    refs, releases = inventory(repo)
    older = preflight(prefix, number, sha, refs, releases)
    tag = prefix + number
    zip_contents(package)
    if changelog is None:
        candidate = package.parent / "updates" / "changelog.xml"
        if candidate.is_file():
            changelog = candidate
    validate_changelog(changelog, prefix, number)
    print(f"Publishing {tag} from built checkout {sha}", flush=True)
    if tag not in refs:
        run("git", "tag", "-a", tag, sha, "-m", f"Release {tag}")
        run("git", "push", "origin", f"refs/tags/{tag}")
    if tag not in releases:
        run("gh", "release", "create", tag, str(package), "--repo", repo,
            "--verify-tag", "--draft", "--title", title, "--notes", notes)
    elif not any(asset["name"] == package.name for asset in releases[tag]["assets"]):
        run("gh", "release", "upload", tag, str(package), "--repo", repo)
    if changelog is not None:
        changelog = changelog.resolve()
        if not changelog.is_file():
            raise ValueError(f"Changelog XML does not exist: {changelog}")
        published_assets = releases[tag]["assets"] if tag in releases else []
        if any(asset["name"] == changelog.name for asset in published_assets):
            verify_remote_changelog(repo, tag, changelog, prefix, number)
        else:
            run("gh", "release", "upload", tag, str(changelog), "--repo", repo)
    # Never delete a working release or clobber an existing asset on a retry.
    # ZIP timestamps may change between builds; compare unpacked payloads instead.
    verify_asset(repo, tag, package)
    run("gh", "release", "edit", tag, "--repo", repo, "--draft=false", "--latest=false")
    published = json.loads(run("gh", "api", f"repos/{repo}/releases/tags/{tag}"))
    required_assets = {package.name}
    if changelog is not None:
        required_assets.add(changelog.name)
    published_names = {asset["name"] for asset in published["assets"]}
    if published["draft"] or not required_assets.issubset(published_names):
        raise ValueError(f"Release {tag} was not published with its expected asset")
    print(f"Verified published download: {tag}/{package.name}", flush=True)
    # A failure above leaves every previous download intact. Delete only strictly older
    # versions of this family after the replacement has been downloaded and verified.
    for old in older:
        if old in releases:
            run("gh", "release", "delete", old, "--repo", repo, "--yes")
        if old in refs:
            run("git", "push", "origin", "--delete", f"refs/tags/{old}")
        print(f"Removed older release/tag {old}", flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=("preflight", "publish"))
    parser.add_argument("--prefix", required=True)
    parser.add_argument("--version", required=True)
    parser.add_argument("--package", type=Path)
    parser.add_argument("--changelog", type=Path)
    parser.add_argument("--title")
    args = parser.parse_args()
    if not re.fullmatch(r"[a-z][a-z0-9-]*-v", args.prefix):
        parser.error("prefix must be an extension tag prefix ending in -v")
    repo = os.environ["GITHUB_REPOSITORY"]
    sha = run("git", "rev-parse", "HEAD")
    if args.mode == "preflight":
        refs, releases = inventory(repo)
        older = preflight(args.prefix, args.version, sha, refs, releases)
        print(f"Publication preflight passed: {args.prefix}{args.version}, built checkout {sha}")
        with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as output:
            output.write(f"sha={sha}\nprevious_tag={older[-1] if older else ''}\n")
    else:
        if sha != os.environ["BUILT_SHA"]:
            raise ValueError("Checkout changed since the build preflight")
        if not args.package or not args.title:
            parser.error("publish requires --package and --title")
        publish(repo, args.prefix, args.version, sha, args.package, args.title, os.environ["NOTES"], args.changelog)


if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError, subprocess.CalledProcessError, zipfile.BadZipFile) as error:
        print(f"::error::{error}", file=sys.stderr)
        sys.exit(1)
