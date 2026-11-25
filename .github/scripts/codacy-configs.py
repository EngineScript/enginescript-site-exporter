#!/usr/bin/env python3
"""Regenerate the lint configuration files that Codacy reads from the full ones that CI runs.

Codacy reads phpcs.xml and .stylelintrc.json, but it installs no WordPress coding standards, no
PHPCSExtra, and none of the WordPress Stylelint packages, so a file that names them makes the
tool fail. The full standards stay in phpcs-wordpress.xml and stylelint-wordpress.json, which
composer, npm, and CI run; this script copies into the Codacy files every rule of the full
standards that Codacy can run, with the settings the full standards give it.

    python .github/scripts/codacy-configs.py phpcs [--check]
    python .github/scripts/codacy-configs.py stylelint [--check]

With --check, nothing is written; the script fails when the file is out of date. phpcs needs
`composer install`; stylelint needs `npm ci`.
"""

from __future__ import annotations

import argparse
import json
import shutil
import subprocess  # nosec B404
import sys
import tempfile
from pathlib import Path
from typing import Any
from xml.sax.saxutils import escape, quoteattr  # nosec B406

# Bandit: every subprocess call in this file runs a fixed argument list without a shell, and
# xml.sax.saxutils only escapes the text this file writes; nothing here parses XML.

ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = Path(__file__).resolve().parent

# PHPCS standards that Codacy installs. PHPCompatibilityWP is referenced as a whole standard in
# phpcs.xml's hand-written part, so its sniffs are not listed one by one.
CODACY_PHPCS_STANDARDS = ("Generic", "PEAR", "PSR1", "PSR2", "PSR12", "Squiz", "Zend", "VariableAnalysis")
BEGIN = "    <!-- BEGIN GENERATED RULES: python .github/scripts/codacy-configs.py phpcs -->"
END = "    <!-- END GENERATED RULES -->"
# A message code (Standard.Category.Sniff.Code) has three dots; a sniff code has two.
MESSAGE_CODE_DOTS = 3


def run(command: list[str]) -> str:
    """Run a command from the repository root and return its standard output."""
    result = subprocess.run(  # nosec B603
        command, cwd=ROOT, capture_output=True, text=True, encoding="utf-8", check=False
    )
    if not result.stdout.strip():
        sys.exit(f"{' '.join(command)} printed nothing:\n{result.stderr}")
    return result.stdout


def property_xml(name: str, value: object, pad: str) -> list[str]:
    """Write one PHPCS sniff property, a scalar or an array."""
    if isinstance(value, dict):
        items = [f"{pad}    <element key={quoteattr(str(k))} value={quoteattr(str(v))}/>" for k, v in value.items()]
    elif isinstance(value, list):
        items = [f"{pad}    <element value={quoteattr(str(v))}/>" for v in value]
    else:
        return [f"{pad}<property name={quoteattr(name)} value={quoteattr(str(value))}/>"]
    return [f'{pad}<property name={quoteattr(name)} type="array">', *items, f"{pad}</property>"]


def rule_xml(code: str, settings: dict[str, Any]) -> list[str]:
    """Write one PHPCS rule with its severity, type, message, and properties."""
    inner = [f"        <{key}>{settings[key]}</{key}>" for key in ("severity", "type") if key in settings]
    if "message" in settings:
        inner.append(f"        <message>{escape(settings['message'])}</message>")
    if settings.get("properties"):
        inner.append("        <properties>")
        for name, value in settings["properties"].items():
            inner += property_xml(name, value, "            ")
        inner.append("        </properties>")
    if not inner:
        return [f"    <rule ref={quoteattr(code)}/>"]
    return [f"    <rule ref={quoteattr(code)}>", *inner, "    </rule>"]


def phpcs_rules() -> str:
    """Return the rules of phpcs-wordpress.xml that come from standards Codacy installs."""
    with tempfile.TemporaryDirectory() as tmp:
        probe = Path(tmp) / "probe.php"
        probe.write_text("<?php\n", encoding="utf-8")
        report = SCRIPTS / "phpcs-ruleset-dump.php"
        command = ["php", "vendor/bin/phpcs", "--standard=phpcs-wordpress.xml", "-q", "--no-colors"]
        dump = json.loads(run([*command, f"--report={report}", str(probe)]))
    lines: list[str] = []
    for sniff in sorted(dump["sniffs"]):
        if sniff.split(".", 1)[0] not in CODACY_PHPCS_STANDARDS:
            continue
        lines += rule_xml(sniff, dump["rules"].get(sniff, {}))
        for code, settings in sorted(dump["rules"].items()):
            if code.startswith(sniff + ".") and code.count(".") == MESSAGE_CODE_DOTS and settings:
                lines += rule_xml(code, settings)
    return "\n".join(lines)


def update_phpcs(*, check: bool) -> bool:
    """Rewrite the generated part of phpcs.xml."""
    path = ROOT / "phpcs.xml"
    text = path.read_text(encoding="utf-8")
    head, rest = text.split(BEGIN + "\n", 1)
    _, tail = rest.split(END, 1)
    return write(path, text, f"{head}{BEGIN}\n{phpcs_rules()}\n{END}{tail}", check=check)


def update_stylelint(*, check: bool) -> bool:
    """Rewrite .stylelintrc.json with the plain Stylelint rules of stylelint-wordpress.json."""
    npx = shutil.which("npx") or "npx"
    sample = min(path for path in ROOT.glob("**/*.css") if not {"node_modules", "vendor"} & set(path.parts))
    command = [npx, "--no-install", "stylelint", "--config", "stylelint-wordpress.json", "--print-config"]
    resolved = json.loads(run([*command, sample.relative_to(ROOT).as_posix()]))
    # Rules with a "/" come from plugins, which Codacy does not install.
    rules = {name: value for name, value in sorted(resolved["rules"].items()) if "/" not in name}
    path = ROOT / ".stylelintrc.json"
    text = path.read_text(encoding="utf-8") if path.exists() else ""
    return write(path, text, json.dumps({"rules": rules}, indent=2) + "\n", check=check)


def write(path: Path, old: str, new: str, *, check: bool) -> bool:
    """Write a regenerated file, or with check only report whether it is up to date."""
    if old == new:
        return True
    if check:
        print(f"{path.name} is out of date: run python .github/scripts/codacy-configs.py", file=sys.stderr)
        return False
    path.write_text(new, encoding="utf-8", newline="\n")
    print(f"updated {path.name}")
    return True


def main() -> int:
    """Regenerate or check one Codacy configuration file."""
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("tool", choices=("phpcs", "stylelint"))
    parser.add_argument("--check", action="store_true", help="fail when the file is out of date; write nothing")
    args = parser.parse_args()
    update = update_phpcs if args.tool == "phpcs" else update_stylelint
    return 0 if update(check=args.check) else 1


if __name__ == "__main__":
    sys.exit(main())
