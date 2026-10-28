#!/usr/bin/env python3
"""Standard-library regression fixtures, executed by GitHub's package-check job.

These fixtures do not bootstrap WordPress or replace workflow-generated PHP tests.
All mutation is confined to a temporary fixture repository on the runner.
"""

from __future__ import annotations

import contextlib
import importlib.util
import io
import json
import os
import re
import shutil
import subprocess  # nosec B404
import tempfile
import textwrap
import unittest
from pathlib import Path
from typing import TYPE_CHECKING
from unittest.mock import patch
from zipfile import ZipFile

if TYPE_CHECKING:
    from types import ModuleType

# Bandit: every subprocess call in this file runs a fixed argument list without a shell.


def load_helper(filename: str) -> ModuleType:
    """Load a script of this directory as a module; its name has hyphens, so it cannot be imported."""
    spec = importlib.util.spec_from_file_location(filename, Path(__file__).with_name(filename))
    if spec is None or spec.loader is None:
        raise ImportError(f"Cannot load {filename}.")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


metadata = load_helper("check-wordpress-tested-up-to.py")
package = load_helper("check-plugin-package.py")
WORKFLOWS = Path(__file__).resolve().parents[1] / "workflows"


def workflow_step(filename: str, name: str) -> str:
    """Read a named inline shell step, so fixtures exercise the shipped logic."""
    section = (WORKFLOWS / filename).read_text().split(f"      - name: {name}\n", 1)[1]
    section = section.split("\n      - name:", 1)[0]
    return textwrap.dedent(section.split("        run: |\n", 1)[1])


def first_group(pattern: str, text: str) -> str:
    """Return the first group of a pattern, which must match."""
    match = re.search(pattern, text, re.DOTALL)
    if match is None:
        raise AssertionError(f"Pattern not found: {pattern}")
    return match.group(1)


class AutomationTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory(prefix="sse-automation-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        previous = Path.cwd()
        os.chdir(self.root)
        self.addCleanup(os.chdir, previous)
        subprocess.run(["git", "init", "--quiet"], check=True)  # nosec B603 B607
        self.write("enginescript-site-exporter.php", "<?php\n/**\n * Tested up to: 6.8\n */\n")
        self.write("readme.txt", "=== Fixture ===\nTested up to: 6.8\n\nTested up to: historical prose\n")
        self.write("README.md", "Tested up to: historical prose\n")
        self.write(".private/review.md", "Tested up to: historical prose\n")
        subprocess.run(["git", "add", "--", "enginescript-site-exporter.php", "readme.txt", "README.md"], check=True)  # nosec B603 B607

    def write(self, name: str, content: str) -> None:
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding="utf-8")

    def findings(self) -> list[dict[str, object]]:
        entries: list[dict[str, object]] = metadata.find_tested_up_to_entries(set())
        return entries

    def test_metadata_changes_only_authoritative_headers(self) -> None:
        findings = self.findings()
        self.assertEqual(2, len(findings))
        before = {name: (self.root / name).read_bytes() for name in ("README.md", ".private/review.md")}
        self.assertEqual(
            ["enginescript-site-exporter.php", "readme.txt"], metadata.update_tested_up_to_entries(findings, "9.9")
        )
        self.assertEqual([], metadata.get_failures("9.9", self.findings()))
        for name, content in before.items():
            self.assertEqual(content, (self.root / name).read_bytes())
        self.assertIn("Tested up to: historical prose", (self.root / "readme.txt").read_text())
        self.assertEqual([], metadata.update_tested_up_to_entries(self.findings(), "9.9"))

    def test_invalid_metadata_is_not_rewritten(self) -> None:
        self.write("readme.txt", "Tested up to: invalid label\n\n")
        before = {path: path.read_bytes() for path in metadata.METADATA_PATHS}
        with (
            patch.object(metadata, "WORDPRESS_LATEST_VERSION", "9.9"),
            patch("sys.argv", ["metadata", "--fix"]),
            self.assertRaises(ValueError),
        ):
            metadata.main()
        for path, content in before.items():
            self.assertEqual(content, path.read_bytes())

    def test_duplicate_missing_untracked_and_linked_metadata_fail(self) -> None:
        for content in ("Tested up to: 6.8\nTested up to: 6.8\n\n", "No header\n\n"):
            self.write("readme.txt", content)
            with self.assertRaises(ValueError):
                self.findings()
        self.write("readme.txt", "Tested up to: 6.8\n\n")
        subprocess.run(["git", "rm", "--cached", "--force", "--quiet", "readme.txt"], check=True)  # nosec B603 B607
        with self.assertRaises(ValueError):
            self.findings()
        subprocess.run(["git", "add", "readme.txt"], check=True)  # nosec B603 B607
        (self.root / "readme.txt").unlink()
        (self.root / "readme.txt").symlink_to("README.md")
        with self.assertRaises(ValueError):
            self.findings()

    def test_release_offer_validation(self) -> None:
        cases = [
            ([], False),
            (["9.9", "9.8"], False),
            (["invalid"], False),
            (["9.9.1"], True),
            (["9.9.1", "9.9.1"], True),
        ]
        for versions, valid in cases:
            with self.subTest(versions=versions), patch.object(metadata, "WORDPRESS_LATEST_VERSION", None):
                self.write(
                    "wordpress-version-check.json",
                    json.dumps({"offers": [{"response": "upgrade", "current": version} for version in versions]}),
                )
                if valid:
                    self.assertEqual("9.9", metadata.get_latest_wordpress_major_minor())
                else:
                    with self.assertRaises((ValueError, RuntimeError)):
                        metadata.get_latest_wordpress_major_minor()

    def test_annotation_and_version_validation(self) -> None:
        self.assertEqual("9.9", metadata.normalize_major_minor("9.9.1"))
        with self.assertRaises(ValueError):
            metadata.normalize_major_minor("9.9\n::error::injected")
        self.assertEqual("a%0Ab%25", metadata.escape_github_command_data("a\nb%"))

    def test_release_requires_exact_commit_and_every_quality_job(self) -> None:
        script = workflow_step("release.yml", "Require successful quality checks for this commit")
        run_filter = first_group(r"RUN_ID=\$\(jq\b.*?'\n(.*?)\n\s*' \"\$RUNNER_TEMP/quality-runs.json\"\)", script)
        jobs_filter = first_group(r"jq -e '\n(.*?)\n\s*' \"\$RUNNER_TEMP/quality-jobs.json\"", script)
        run = {
            "id": 1,
            "head_sha": "abc",
            "head_branch": "main",
            "head_repository": {"full_name": "fixture/repo"},
            "event": "push",
            "status": "completed",
            "conclusion": "success",
        }

        def accepts_run(runs: list[dict[str, object]]) -> bool:
            result = subprocess.run(  # nosec B603 B607
                [
                    "jq",
                    "-er",
                    "--arg",
                    "sha",
                    "abc",
                    "--arg",
                    "repo",
                    "fixture/repo",
                    "--arg",
                    "branch",
                    "main",
                    run_filter,
                ],
                input=json.dumps({"workflow_runs": runs}),
                text=True,
                capture_output=True,
                check=False,
            )
            return result.returncode == 0

        self.assertTrue(accepts_run([run]))
        self.assertFalse(accepts_run([]))
        for changes in (
            {"head_sha": "other"},
            {"event": "pull_request"},
            {"head_branch": "other"},
            {"head_repository": {"full_name": "fork/repo"}},
            {"conclusion": "failure"},
            {"conclusion": "cancelled"},
            {"status": "in_progress"},
        ):
            self.assertFalse(accepts_run([dict(run, **changes)]))
        self.assertFalse(accepts_run([run, dict(run, id=2, conclusion="failure")]))
        workflow = (WORKFLOWS / "wp-compatibility-test.yml").read_text()
        names = [name for name in re.findall(r"^    name: (.+)$", workflow, re.MULTILINE) if "${{" not in name]
        names += [
            f"Test WordPress {wp} with PHP {php} (highest deps)"
            for php in ("8.3", "8.4", "8.5")
            for wp in ("7.0", "latest", "nightly")
        ]
        names.append("Test WordPress latest with PHP 8.3 (lowest deps)")
        jobs = [{"name": name, "status": "completed", "conclusion": "success"} for name in names]

        def accepts_jobs(records: list[dict[str, str]]) -> bool:
            return (
                subprocess.run(  # nosec B603 B607
                    ["jq", "-e", jobs_filter],
                    input=json.dumps([{"jobs": records}]),
                    text=True,
                    capture_output=True,
                    check=False,
                ).returncode
                == 0
            )

        self.assertEqual(24, len(jobs))
        self.assertTrue(accepts_jobs(jobs))
        for index in range(len(jobs)):
            self.assertFalse(accepts_jobs(jobs[:index] + jobs[index + 1 :]))
            for conclusion in ("skipped", "failure", "cancelled"):
                changed = [dict(job) for job in jobs]
                changed[index]["conclusion"] = conclusion
                self.assertFalse(accepts_jobs(changed))
        self.assertFalse(accepts_jobs([*jobs, jobs[0]]))

    def test_release_lookup_fails_closed_on_http_and_transport_errors(self) -> None:
        script = workflow_step("release.yml", "Check if release exists")
        self.write("bin/curl", '#!/bin/sh\nprintf "%s" "$FIXTURE_HTTP_STATUS"\nexit "$FIXTURE_CURL_EXIT"\n')
        (self.root / "bin/curl").chmod(0o700)
        output = self.root / "release-output"
        for status, code, expected in (
            ("200", "0", "true"),
            ("404", "0", "false"),
            ("403", "0", None),
            ("429", "0", None),
            ("500", "0", None),
            ("000", "28", None),
        ):
            with self.subTest(status=status):
                output.write_text("")
                env = dict(
                    os.environ,
                    PATH=f"{self.root / 'bin'}:{os.environ['PATH']}",
                    GH_TOKEN="fixture-only",  # noqa: S106  # nosec B106
                    GITHUB_OUTPUT=str(output),
                    VERSION="1.2.3",
                    GITHUB_API_URL="https://api.invalid",
                    GITHUB_REPOSITORY="fixture/repo",
                    FIXTURE_HTTP_STATUS=status,
                    FIXTURE_CURL_EXIT=code,
                )
                result = subprocess.run(
                    ["bash", "-e", "-o", "pipefail", "-c", script],  # nosec B603 B607
                    env=env,
                    capture_output=True,
                    text=True,
                    check=False,
                )
                if expected is None:
                    self.assertNotEqual(0, result.returncode)
                    self.assertEqual("", output.read_text())
                else:
                    self.assertEqual(0, result.returncode)
                    self.assertEqual(f"exists={expected}\n", output.read_text())

    def add_release_sources(self) -> None:
        """Track one file of every kind the package ships, and the real .distignore."""
        for name in (
            "CHANGELOG.md",
            "LICENSE",
            "includes/fixture.php",
            "css/admin.css",
            "js/admin.js",
            "languages/enginescript-site-exporter.pot",
        ):
            self.write(name, "fixture\n")
        self.write(".distignore", (WORKFLOWS.parents[1] / ".distignore").read_text(encoding="utf-8"))
        subprocess.run(["git", "add", "--", ".distignore"], check=True)  # nosec B603 B607

    def test_distignore_must_keep_exactly_the_release_allowlist(self) -> None:
        self.add_release_sources()
        subprocess.run(
            ["git", "add", "--", "CHANGELOG.md", "LICENSE", "includes", "css", "js", "languages"], check=True
        )  # nosec B603 B607
        # README.md and CHANGELOG.md are tracked in this fixture and do not ship.
        shipped = [
            "LICENSE",
            "css/admin.css",
            "enginescript-site-exporter.php",
            "includes/fixture.php",
            "js/admin.js",
            "languages/enginescript-site-exporter.pot",
            "readme.txt",
        ]
        self.assertEqual(shipped, sorted(package.expected_contents(self.root)))
        rules = (self.root / ".distignore").read_text(encoding="utf-8")
        # A tracked file that neither list names would ship through .distignore alone.
        self.write("new-tool.json", "{}\n")
        subprocess.run(["git", "add", "--", "new-tool.json"], check=True)  # nosec B603 B607
        with self.assertRaisesRegex(ValueError, "new-tool.json"):
            package.expected_contents(self.root)
        self.write(".distignore", rules + "/new-tool.json\n")
        self.assertEqual(shipped, sorted(package.expected_contents(self.root)))
        # Inside a shipped directory, a file that the WordPress.org plugin directory
        # does not accept is refused by name, and so is a Markdown file.
        refused = {
            "includes/notes.md": "file type",
            "includes/tool.sh": "file type",
            "js/library.zip": "file type",
            "includes/archive.phar": "file type",
            "includes/.hidden.php": "hidden",
            "css/.cache/x.css": "hidden",
            "includes/two words.php": "space",
            "includes/odd(name).php": "special character",
            # The message names the second of the two files in sorted order.
            "includes/Fixture.php": "includes/fixture.php (differs from includes/Fixture.php only by case",
        }
        for name, reason in refused.items():
            expected = reason if "only by case" in reason else f"{name} ({reason}"
            with self.subTest(name=name):
                self.write(name, "fixture\n")
                subprocess.run(["git", "add", "--force", "--", name], check=True)  # nosec B603 B607
                try:
                    with self.assertRaisesRegex(ValueError, re.escape(expected)):
                        package.expected_contents(self.root)
                finally:
                    subprocess.run(["git", "rm", "--cached", "--force", "--quiet", "--", name], check=True)  # nosec B603 B607
                    (self.root / name).unlink()
                self.assertEqual(shipped, sorted(package.expected_contents(self.root)))
        # A rule that drops a shipped file, and a missing file, are refused too.
        self.write(".distignore", rules + "/new-tool.json\n/languages\n")
        with self.assertRaisesRegex(ValueError, "languages/enginescript-site-exporter.pot"):
            package.expected_contents(self.root)
        (self.root / ".distignore").unlink()
        with self.assertRaisesRegex(ValueError, "missing"):
            package.expected_contents(self.root)

    def test_package_rejects_missing_extra_changed_and_linked_members(self) -> None:
        self.add_release_sources()
        subprocess.run(
            ["git", "add", "--", "CHANGELOG.md", "LICENSE", "includes", "css", "js", "languages"], check=True
        )  # nosec B603 B607
        build = self.root / "build" / package.SLUG
        for name, content in package.expected_contents(self.root).items():
            destination = build / name
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_bytes(content)
        archive = self.root / "fixture.zip"
        with ZipFile(archive, "w") as zipped:
            for path in build.rglob("*"):
                if path.is_file():
                    zipped.write(path, f"{package.SLUG}/{path.relative_to(build).as_posix()}")
        with contextlib.redirect_stdout(io.StringIO()):
            self.assertGreater(package.validate_package(self.root, build, archive), 0)
        asset = build / "js/admin.js"
        for replacement in (None, b"changed", "symlink"):
            asset.unlink()
            if replacement == "symlink":
                asset.symlink_to(self.root / "js/admin.js")
            elif replacement is not None:
                asset.write_bytes(replacement)
            with self.assertRaises(ValueError):
                package.validate_package(self.root, build)
            if asset.is_symlink() or asset.exists():
                asset.unlink()
            shutil.copyfile(self.root / "js/admin.js", asset)
        (build / "secret.txt").write_text("must not ship")
        with self.assertRaises(ValueError):
            package.validate_package(self.root, build)
        (build / "secret.txt").unlink()
        with ZipFile(archive, "a") as zipped:
            zipped.writestr(f"{package.SLUG}/../secret.txt", "must not ship")
        with self.assertRaises(ValueError):
            package.validate_package(self.root, build, archive)


if __name__ == "__main__":
    if os.environ.get("GITHUB_ACTIONS") != "true":
        raise SystemExit("Run these regression fixtures in GitHub Actions, not locally.")
    unittest.main(verbosity=2)
