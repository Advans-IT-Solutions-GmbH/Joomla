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

    for tag in TAGS:
        (fixture / "language" / tag / "plg_example.ini").write_text(
            'PLG_EXAMPLE_EMAIL_BODY="Hello {name}"\n', encoding="utf-8"
        )
    manifest.write_text(xml.replace("PLG_EXAMPLE_TITLE", "PLG_EXAMPLE_EMAIL_BODY"), encoding="utf-8")
    check(command, ROOT, True, "Language files OK")
    german.write_text('PLG_EXAMPLE_EMAIL_BODY="Hello"\n', encoding="utf-8")
    check(command, ROOT, False, "named placeholders of PLG_EXAMPLE_EMAIL_BODY differ")
    german.write_text('PLG_EXAMPLE_EMAIL_BODY=""\n', encoding="utf-8")
    check(command, ROOT, False, "empty subject/body template")
    for tag in TAGS:
        (fixture / "language" / tag / "plg_example.ini").write_bytes(original)
    manifest.write_text(xml, encoding="utf-8")

    swiss = fixture / "language" / "de-CH"
    swiss.mkdir()
    check(command, ROOT, False, "unsupported language folder")
    (swiss / "plg_example.ini").write_bytes(original)
    check(command, ROOT, False, "unsupported language de-CH")
    (swiss / "plg_example.ini").unlink()
    swiss.rmdir()

    for unsupported in ("it-IT", "fil-PH", "unexpected"):
        folder = fixture / "language" / unsupported
        folder.mkdir()
        check(command, ROOT, False, "unsupported language folder")
        (folder / "plg_example.ini").write_bytes(original)
        check(command, ROOT, False, "unsupported language folder")
        shutil.rmtree(folder)

    manifest.write_text(
        xml.replace('<language tag="fr-FR">fr-FR/plg_example.ini</language>', ""),
        encoding="utf-8",
    )
    check(command, ROOT, False, "missing manifest language fr-FR")
    manifest.write_text(xml.replace('tag="fr-FR"', 'tag="de-CH"'), encoding="utf-8")
    check(command, ROOT, False, "unsupported manifest language tag de-CH")
    manifest.write_text(xml, encoding="utf-8")

    # Omitting an entire file family must fail, even though all remaining
    # manifest declarations still contain the same three languages.
    for tag in TAGS:
        (fixture / "language" / tag / "plg_example.sys.ini").write_bytes(original)
    check(command, ROOT, False, "language file is not installed")
    manifest.write_text(
        xml.replace("</languages>", declarations.replace("plg_example.ini", "plg_example.sys.ini") + "</languages>"),
        encoding="utf-8",
    )
    check(command, ROOT, True, "Language files OK")
    manifest.write_text(xml.replace(
        f'<languages folder="language">{declarations}</languages>',
        '<files><folder>language</folder></files>',
    ), encoding="utf-8")
    check(command, ROOT, True, "Language files OK")
    for tag in TAGS:
        (fixture / "language" / tag / "plg_example.sys.ini").unlink()
    manifest.write_text(xml, encoding="utf-8")

    nested = fixture / "plugins" / "system" / "example" / "language"
    shutil.copytree(fixture / "language", nested)
    (nested.parent / "example.xml").write_text(xml, encoding="utf-8")
    (nested / "fr-FR" / "plg_example.ini").unlink()
    check(command, ROOT, False, "missing fr-FR counterpart")
    (nested / "fr-FR" / "plg_example.ini").write_bytes(original)
    check(command, ROOT, True, "Language files OK")
    manifest.write_text(xml.replace("</extension>", "<files><folder>plugins</folder></files></extension>"), encoding="utf-8")
    (nested.parent / "example.xml").write_text(
        xml.replace(f'<languages folder="language">{declarations}</languages>', ""),
        encoding="utf-8",
    )
    check(command, ROOT, False, "language file is not installed")
    manifest.write_text(xml, encoding="utf-8")
    (nested.parent / "example.xml").write_text(xml, encoding="utf-8")

    nested_xml = xml.replace(
        f'<languages folder="language">{declarations}</languages>',
        '<files><folder>language</folder></files>',
    )
    (nested.parent / "example.xml").write_text(nested_xml, encoding="utf-8")
    manifest.write_text(xml.replace("</extension>", "<files><folder>plugins</folder></files></extension>"), encoding="utf-8")
    check(command, ROOT, True, "Language files OK")
    shutil.rmtree(nested)
    check(command, ROOT, False, "no language files found for extension manifest")
    check(["php", str(LINT), "--package", str(fixture)], ROOT, False,
          "no language files found for extension manifest")
    support = nested.parent / "advansinstaller" / "language"
    shutil.copytree(fixture / "language", support)
    check(["php", str(LINT), "--package", str(fixture)], ROOT, False,
          "no language files found for extension manifest")
    shutil.rmtree(support.parent)
    shutil.copytree(fixture / "language", nested)
    check(command, ROOT, True, "Language files OK")
    check(["php", str(LINT), "--package", str(fixture)], ROOT, True, "Language files OK")

    (fixture / "build.env").write_text(
        'EXTENSION_NAME="example"\nVERSION="1.0.0"\n', encoding="utf-8"
    )
    archive = fixture / "example_1.0.0.zip"
    # Git Bash understands drive-letter paths with forward slashes too.
    verify_command = ["bash", str(VERIFY).replace(os.sep, "/")]
    for kind in ("valid", "missing", "swiss", "unsupported", "three-letter", "nested", "empty", "bundled", "bundled-missing"):
        with zipfile.ZipFile(archive, "w") as package:
            package.writestr("index.html", b"")
            if kind != "empty":
                package.writestr("example.xml", xml.replace(
                    "</extension>", "<files><folder>plugins</folder></files></extension>"
                ) if kind.startswith("bundled") else xml)
                package.writestr("language/de-DE/plg_example.ini", original)
                package.writestr("language/en-GB/plg_example.ini", original)
                if kind != "missing":
                    package.writestr("language/fr-FR/plg_example.ini", original)
                if kind == "swiss":
                    package.writestr("language/de-CH/", b"")
                if kind == "unsupported":
                    package.writestr("plugins/system/example/language/it-IT/", b"")
                if kind == "three-letter":
                    package.writestr("language/fil-PH/", b"")
                if kind == "nested":
                    package.writestr(
                        "plugins/system/example/language/de-DE/plg_example.ini",
                        original,
                    )
                if kind.startswith("bundled"):
                    package.writestr("plugins/system/example/example.xml", nested_xml)
                    if kind == "bundled":
                        for tag in TAGS:
                            package.writestr(
                                f"plugins/system/example/language/{tag}/plg_example.ini",
                                original,
                            )
        check(
            verify_command,
            fixture,
            kind in ("valid", "bundled"),
            "Verified de-DE/en-GB/fr-FR counterparts" if kind in ("valid", "bundled") else
            "no language files found for extension manifest" if kind == "bundled-missing" else
            "contains no language files" if kind == "empty" else
            "contains an unsupported language folder" if kind in ("swiss", "unsupported", "three-letter") else
            "missing en-GB counterpart" if kind == "nested" else
            "missing fr-FR counterpart",
        )
