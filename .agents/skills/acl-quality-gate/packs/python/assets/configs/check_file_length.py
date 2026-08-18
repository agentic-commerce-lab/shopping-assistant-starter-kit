#!/usr/bin/env python3
"""Fail if any Python file exceeds the maximum line count (file-length gate).

The shared quality gate targets ~400 physical lines per file. Ruff has no
module-length rule and pylint's `C0302` is not ported, so this small,
dependency-free check fills the slot. Copy it into the gated project (e.g.
`scripts/check_file_length.py`) and wire it to the `quality:filesize` task.

    python scripts/check_file_length.py [--max N] [PATH ...]

Defaults: --max 400, paths default to "src". Prints offenders, exits 1 on any.
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

EXCLUDE_PARTS = {"__pycache__", ".venv", "build", "dist"}


def main() -> int:
    parser = argparse.ArgumentParser(description="Fail on over-long Python files.")
    parser.add_argument("--max", type=int, default=400, help="max physical lines per file")
    parser.add_argument("paths", nargs="*", default=["src"], help="paths to scan (default: src)")
    args = parser.parse_args()

    offenders: list[tuple[Path, int]] = []
    for root in args.paths:
        for path in Path(root).rglob("*.py"):
            if EXCLUDE_PARTS & set(path.parts):
                continue
            with path.open(encoding="utf-8") as handle:
                line_count = sum(1 for _ in handle)
            if line_count > args.max:
                offenders.append((path, line_count))

    for path, line_count in sorted(offenders):
        print(f"{path}: {line_count} lines (> {args.max})")
    if offenders:
        print(f"\n{len(offenders)} file(s) exceed {args.max} lines.")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
