"""Regression checks for the uniform language set and Swiss German wording."""

import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import zipfile


ROOT = Path(__file__).resolve().parents[2]
LINT = ROOT / "shared" / "tests" / "lang-lint.php"
VERIFY = ROOT / "shared" / "build" / "verify-package.sh"
TAGS = ("de-DE", "en-GB", "fr-FR")


def check(command, directory, succeeds, diagnostic):
    result = subprocess.run(
        command, cwd=directory, text=True, capture_output=True, check=False
    )
    output = result.stdout + result.stderr
    assert (result.returncode == 0) == succeeds, f"exit {result.returncode}\n{output}"
    assert diagnostic in output, output
    print(f"PASS {diagnostic}")


with tempfile.TemporaryDirectory(prefix="joomla-language-") as directory:
    fixture = Path(directory)
    for tag in TAGS:
        folder = fixture / "language" / tag
        folder.mkdir(parents=True)
        (folder / "plg_example.ini").write_text(
            'PLG_EXAMPLE_TITLE="Example"\n', encoding="utf-8"
        )

    declarations = "".join(
        f'<language tag="{tag}">{tag}/plg_example.ini</language>' for tag in TAGS
    )
    manifest = fixture / "example.xml"
    xml = (
        '<extension type="plugin"><name>PLG_EXAMPLE_TITLE</name>'
        f'<languages folder="language">{declarations}</languages></extension>'
    )
    manifest.write_text(xml, encoding="utf-8")
    command = ["php", str(LINT), str(fixture)]
    check(command, ROOT, True, "Language files OK")

    german = fixture / "language" / "de-DE" / "plg_example.ini"
    original = german.read_bytes()
    german.unlink()
    check(command, ROOT, False, "missing de-DE counterpart")
    german.write_bytes(original + b'PLG_EXAMPLE_EXTRA="Extra"\n')
    check(command, ROOT, False, "extra key PLG_EXAMPLE_EXTRA")
    german.write_text('PLG_EXAMPLE_TITLE="Stra\u00dfe"\n', encoding="utf-8")
    check(command, ROOT, False, "use 'ss' for Swiss High German")
    german.write_bytes(original)

    swiss = fixture / "language" / "de-CH"
    swiss.mkdir()
    check(command, ROOT, False, "unsupported language folder")
    (swiss / "plg_example.ini").write_bytes(original)
    check(command, ROOT, False, "unsupported language de-CH")
    (swiss / "plg_example.ini").unlink()
    swiss.rmdir()

    manifest.write_text(
        xml.replace('<language tag="fr-FR">fr-FR/plg_example.ini</language>', ""),
        encoding="utf-8",
    )
    check(command, ROOT, False, "missing manifest language fr-FR")
    manifest.write_text(xml.replace('tag="fr-FR"', 'tag="de-CH"'), encoding="utf-8")
    check(command, ROOT, False, "unsupported manifest language tag de-CH")
    manifest.write_text(xml, encoding="utf-8")

    nested = fixture / "plugins" / "system" / "example" / "language"
    shutil.copytree(fixture / "language", nested)
    (nested / "fr-FR" / "plg_example.ini").unlink()
    check(command, ROOT, False, "missing fr-FR counterpart")
    (nested / "fr-FR" / "plg_example.ini").write_bytes(original)
    check(command, ROOT, True, "Language files OK")

    (fixture / "build.env").write_text(
        'EXTENSION_NAME="example"\nVERSION="1.0.0"\n', encoding="utf-8"
    )
    archive = fixture / "example_1.0.0.zip"
    # Git Bash understands drive-letter paths with forward slashes too.
    verify_command = ["bash", str(VERIFY).replace(os.sep, "/")]
    for kind in ("valid", "missing", "swiss", "nested", "empty"):
        with zipfile.ZipFile(archive, "w") as package:
            package.writestr("index.html", b"")
            if kind != "empty":
                package.writestr("language/de-DE/plg_example.ini", original)
                package.writestr("language/en-GB/plg_example.ini", original)
                if kind != "missing":
                    package.writestr("language/fr-FR/plg_example.ini", original)
                if kind == "swiss":
                    package.writestr("language/de-CH/", b"")
                if kind == "nested":
                    package.writestr(
                        "plugins/system/example/language/de-DE/plg_example.ini",
                        original,
                    )
        check(
            verify_command,
            fixture,
            kind == "valid",
            "Verified de-DE/en-GB/fr-FR counterparts" if kind == "valid" else
            "contains no language files" if kind == "empty" else
            "contains a de-CH language folder" if kind == "swiss" else
            "missing en-GB counterpart" if kind == "nested" else
            "missing fr-FR counterpart",
        )
