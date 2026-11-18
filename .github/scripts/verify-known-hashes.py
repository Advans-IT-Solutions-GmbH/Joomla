#!/usr/bin/env python3
"""Prove that a built package recognises every file of the extension's published release.

The installer treats an existing file as untouched when its bytes appear in the package's
catalog of released file hashes (advansinstaller/known-hashes.php, written at build time by
rebuilding every earlier release). This check downloads the latest published release asset of
the extension and fails if any of its files (child packages included) is missing from the
catalog of the candidate package. It therefore verifies, against the bytes that sites actually
installed, that the rebuild of the history reproduces the stamped manifests and language files.
"""

import argparse
import hashlib
import io
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import zipfile

ENVELOPE = b"<?php exit; ?>\n"


def run(*args):
    return subprocess.check_output(args, text=True).strip()


def version(value):
    return tuple(int(part) for part in value.split("."))


def files(archive, prefix=""):
    """SHA-256 by entry name of every file in a ZIP, descending into child ZIPs."""
    result = {}
    with zipfile.ZipFile(archive) as package:
        for name in package.namelist():
            if name.endswith("/"):
                continue
            payload = package.read(name)
            if name.endswith("advansinstaller/known-hashes.php"):
                continue  # A catalog never lists itself; the installer's record covers installed catalogs.
            if name.lower().endswith(".zip"):
                result.update(files(io.BytesIO(payload), f"{prefix}{name}!"))
            else:
                result[prefix + name] = hashlib.sha256(payload).hexdigest()
    return result


def catalog(package):
    with zipfile.ZipFile(package) as archive:
        raw = archive.read("advansinstaller/known-hashes.php")
    if not raw.startswith(ENVELOPE):
        raise ValueError("The catalog has no state envelope")
    return json.loads(raw[len(ENVELOPE):])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--prefix", required=True, help="tag prefix without -v, as RELEASE_PREFIX in build.env")
    parser.add_argument("--package", required=True, type=Path, help="the candidate package")
    args = parser.parse_args()
    repo = os.environ["GITHUB_REPOSITORY"]
    data = catalog(args.package)
    print(f"Catalog of {data['extension']}: {len(data['hashes'])} distinct files; complete={data['complete']}")
    for note in data.get("unreproducible", []) + data.get("failed", []):
        print(f"  note: {note}")
    if not data["complete"]:
        raise ValueError("The catalog is incomplete: a release could not be rebuilt or the history is missing")

    pages = json.loads(run("gh", "api", "--paginate", "--slurp", f"repos/{repo}/releases?per_page=100"))
    pattern = re.compile(re.escape(args.prefix) + r"-v(\d+\.\d+\.\d+)$")
    published = sorted(
        ((version(match.group(1)), release) for page in pages for release in page
         if (match := pattern.fullmatch(release["tag_name"])) and not release["draft"]),
        key=lambda item: item[0])
    if not published:
        raise ValueError(f"No published release with the prefix {args.prefix}-v")
    release = published[-1][1]
    assets = [asset["name"] for asset in release["assets"] if asset["name"].endswith(".zip")]
    if len(assets) != 1:
        raise ValueError(f"Expected one ZIP asset in {release['tag_name']}, found {assets}")
    with tempfile.TemporaryDirectory() as directory:
        run("gh", "release", "download", release["tag_name"], "--repo", repo, "--pattern", assets[0], "--dir", directory)
        installed = files(Path(directory) / assets[0])
    known = set(data["hashes"])
    missing = sorted(name for name, digest in installed.items() if digest not in known)
    if missing:
        raise ValueError(f"{len(missing)} file(s) of the published {release['tag_name']} are not in the catalog: "
                         + ", ".join(missing[:20]))
    print(f"All {len(installed)} files of the published {release['tag_name']}/{assets[0]} are in the catalog.")


if __name__ == "__main__":
    try:
        main()
    except (ValueError, KeyError, OSError, subprocess.CalledProcessError, zipfile.BadZipFile) as error:
        print(f"::error::{error}", file=sys.stderr)
        sys.exit(1)
