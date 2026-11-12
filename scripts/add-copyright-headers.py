#!/usr/bin/env python3
"""
Idempotent copyright-header inserter for PHP source files.

For each .php file under the given root (excluding vendor/, .git/, node_modules/,
.phpunit.cache/, generated files, and any file already containing 'detain@interserver.net'):
  - If the file starts with <?php and lacks the copyright notice, insert the
    docblock between <?php and the next line.
  - If the file already has the copyright notice, leave it unchanged.
  - Running this script twice produces identical output (idempotent).

The @copyright year is taken from the current date at run time (date.today()),
so headers inserted in later years carry the year they were written.
"""

import os
import re
import sys
from datetime import date
from pathlib import Path

# Walk from the repo root (the directory containing this script's parent, or the CWD when running standalone)
# Resolve by walking up from this script until we find .git (repo root)
SCRIPT_DIR = Path(__file__).parent.resolve()
REPO_ROOT = SCRIPT_DIR.parent  # script is at <repo>/scripts/add-copyright-headers.py
ROOT = REPO_ROOT
COPYRIGHT_MARKER = "detain@interserver.net"

# Docblock inserted after <?php; {description} and {year} are filled per run.
HEADER_TEMPLATE = """\
/**
 * {description}.
 *
 * @copyright {year} Joe Huss <detain@interserver.net>
 * @license   MIT
 */
"""

# Word boundaries inside a camelCase / PascalCase identifier: lower/digit -> upper
# ("MetadataProvider" -> "Metadata Provider") and the end of an acronym run
# ("HTTPClient" -> "HTTP Client").
_CAMEL_BOUNDARY = re.compile(r"(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])")


def split_camel_case(segment: str) -> str:
    """Split a camelCase/PascalCase segment into space-separated words.

    Each word's first letter is upper-cased; the rest of the word is kept as-is
    so acronyms survive (str.title() would turn "MetadataProvider" into
    "Metadataprovider" and "HTTP" into "Http").
    """
    spaced = _CAMEL_BOUNDARY.sub(" ", segment).replace("_", " ").replace("-", " ")
    return " ".join(w[:1].upper() + w[1:] for w in spaced.split())

# Extensions / paths to exclude
EXCLUDE_DIRS = {"vendor", ".git", "node_modules", ".phpunit.cache", ".caliber", ".remember"}
EXCLUDE_NAMES = {"composer.lock"}


def one_line_description(file_path: Path) -> str:
    """Derive a best-effort one-line description from the file path."""
    rel = file_path.relative_to(ROOT)
    parts = rel.parts
    if "src" in parts:
        idx = parts.index("src")
        remainder = parts[idx + 1:]
    elif "tests" in parts:
        idx = parts.index("tests")
        remainder = parts[idx + 1:]
    else:
        remainder = parts

    # Build a name from the file / directory structure
    name = ".".join(remainder).replace(".php", "") if remainder else rel.stem
    name = name.replace("/", ".").replace("\\", ".")
    if name.startswith("."):
        name = name[1:]
    # Clean up any double dots or leading dots
    name = re.sub(r"\.+", ".", name).strip(".")
    if not name:
        name = rel.stem or "source"
    # Split camelCase per path segment before capitalising (MAL-18)
    return " ".join(split_camel_case(seg) for seg in name.split("."))


def needs_header(content: str) -> bool:
    """Return True if content lacks the copyright marker."""
    return COPYRIGHT_MARKER not in content


def inject_header(content: str, file_path: Path) -> str:
    """
    Insert the copyright docblock after <?php and any following blank line.

    Handles:
      <?php
      <?php declare(strict_types=1);
      <?php
      declare(strict_types=1);
    """
    marker = COPYRIGHT_MARKER
    if marker in content:
        return content  # idempotent: already has it

    # Detect shebang or <?php opening
    if content.startswith("#!"):
        # Shell / other script: skip (not a PHP module)
        return content

    if not content.startswith("<?php"):
        return content

    # Strip the opening <?php line
    rest = content[5:]  # everything after '<?php'
    if rest.startswith("\r\n"):
        rest = rest[2:]
    elif rest.startswith("\n"):
        rest = rest[1:]

    # Determine indentation / spacing after <?php
    # Look at what comes before the next non-empty line to preserve alignment
    # We'll inject a blank line after <?php then the block then a blank line

    description = one_line_description(file_path)

    header_block = HEADER_TEMPLATE.format(description=description, year=date.today().year)

    # Build the new content
    if rest.startswith("\n") or rest.startswith("\r"):
        # blank line already present after <?php
        new_content = "<?php\n\n" + header_block + "\n" + rest.lstrip("\n\r")
    else:
        # no blank line — insert one
        new_content = "<?php\n\n" + header_block + "\n" + rest

    return new_content


def is_excluded(path: Path) -> bool:
    """Return True if the path should be skipped."""
    parts = path.parts
    for d in EXCLUDE_DIRS:
        if d in parts:
            return True
    if path.name in EXCLUDE_NAMES:
        return True
    if path.suffix not in {".php"}:
        return True
    return False


def process_file(file_path: Path) -> bool:
    """Process one file. Returns True if it was modified."""
    if is_excluded(file_path):
        return False
    try:
        content = file_path.read_text(encoding="utf-8")
    except Exception as e:
        print(f"  [WARN] Could not read {file_path}: {e}", file=sys.stderr)
        return False

    if not needs_header(content):
        return False

    new_content = inject_header(content, file_path)

    if new_content == content:
        return False  # no change needed

    file_path.write_text(new_content, encoding="utf-8")
    return True


def main() -> None:
    modified = []
    checked = 0

    for root_dir, dirs, files in os.walk(ROOT):
        # Prune excluded directories in-place so os.walk doesn't descend
        dirs[:] = [d for d in dirs if d not in EXCLUDE_DIRS]

        for filename in files:
            file_path = Path(root_dir) / filename
            checked += 1
            if process_file(file_path):
                rel = file_path.relative_to(ROOT)
                modified.append(str(rel))

    if modified:
        print(f"Inserted copyright headers in {len(modified)} file(s):")
        for f in sorted(modified):
            print(f"  + {f}")
    else:
        print("All files already have copyright headers (or none need updating).")

    print(f"Checked {checked} PHP file(s).")


if __name__ == "__main__":
    main()
