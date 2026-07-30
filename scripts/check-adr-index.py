#!/usr/bin/env python3
"""Validate docs/adr/ against the practice AGENTS.md enforces.

Shared verbatim across parisek/{styleguide,timber-kit,definition-kit,acf-json-schema}.
Change it in one and change it in all four.

An ADR that is not in the index is invisible: nothing links to it, grep finds it
only by accident, and the decision it records reads as unrecorded. That is the
failure this check exists to prevent — it has already happened once in the fleet
(a design draft sitting in docs/adr/ off-convention and absent from the index).

Exit codes: 0 clean, 1 findings. A repo with no docs/adr/ is clean, not broken —
not every package has needed a decision yet.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

# The leading zero is load-bearing, not decoration: without it a dated draft
# (`2026-05-24-breadcrumb-design.md`) parses as ADR number 2026 and the check
# waves it through as soon as someone adds it to the index. Sequential ADR
# numbers are 0001–0999; a repo reaching 1000 decisions can revisit this.
ADR_FILENAME = re.compile(r"^(0\d{3})-[a-z0-9]+(?:-[a-z0-9]+)*\.md$")


def main() -> int:
    adr_dir = Path(__file__).resolve().parent.parent / "docs" / "adr"
    if not adr_dir.is_dir():
        print("docs/adr/ absent — nothing to check.")
        return 0

    readme = adr_dir / "README.md"
    if not readme.is_file():
        print("ERROR docs/adr/README.md is missing — the practice has no entry point.")
        return 1

    index_text = readme.read_text(encoding="utf-8")
    # Every link to a sibling .md in this directory counts as an index entry,
    # regardless of which link style the repo uses ([0001](…) or [ADR-0001](…)).
    indexed = set(re.findall(r"\]\((\d{4}-[^)]+\.md)\)", index_text))

    findings: list[str] = []
    seen: dict[str, str] = {}

    for path in sorted(adr_dir.iterdir()):
        if not path.is_file() or path.name == "README.md":
            continue
        if path.suffix != ".md":
            findings.append(f"{path.name}: not a .md file — docs/adr/ holds ADRs only")
            continue

        match = ADR_FILENAME.match(path.name)
        if match is None:
            findings.append(
                f"{path.name}: off-convention filename — expected NNNN-kebab-title.md"
            )
            continue

        number = match.group(1)
        if number in seen:
            findings.append(f"{path.name}: duplicate number {number} (also {seen[number]})")
        seen[number] = path.name

        if path.name not in indexed:
            findings.append(f"{path.name}: missing from the Index in README.md")

    for entry in sorted(indexed):
        if not (adr_dir / entry).is_file():
            findings.append(f"{entry}: listed in the Index but the file does not exist")

    if findings:
        print(f"docs/adr/ — {len(findings)} finding(s):", file=sys.stderr)
        for finding in findings:
            print(f"  {finding}", file=sys.stderr)
        return 1

    print(f"docs/adr/ OK — {len(seen)} ADR(s), all indexed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
