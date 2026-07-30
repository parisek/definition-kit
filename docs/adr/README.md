# Architecture Decision Records

Short, immutable notes on decisions that shaped this repo — the *why* behind
choices a future reader would otherwise have to reverse-engineer from the code.

This practice is shared verbatim with `parisek/styleguide`, `parisek/timber-kit`
and `parisek/acf-json-schema` — four Composer packages, one set of rules. Change
it in one and change it in all four.

## When to write one

Offer an ADR **sparingly** — only when **all three** are true:

1. **Hard to reverse** — the cost of changing your mind later is meaningful.
2. **Surprising without context** — a future reader will wonder *"why did they
   do it this way?"*
3. **The result of a real trade-off** — there were genuine alternatives and one
   was picked for specific reasons.

Most changes are none of these. A routine helper tweak, a typo fix, a test — no
ADR. If you're unsure, it probably doesn't need one.

Propose the ADR, get a yes, *then* write it. Don't auto-create.

The ADR lands in the **same PR** as the work it describes — a merge gate, not a
follow-up.

## Format

Classic Nygard triad — Context / Decision / Consequences. No status line, no
ceremony. Keep it to what the three headings demand.

- One file per decision: `NNNN-kebab-title.md`, zero-padded, sequential.
- Numbers are permanent — never renumber or reuse, even if an ADR is later
  superseded. To reverse a decision, write a new ADR and link back to the old
  one (leave the old file in place as history).

```markdown
# NNNN. Short title in the imperative

## Context

What forces are at play — the problem, constraints, and what made the obvious
path unworkable.

## Decision

What we decided, stated plainly.

## Consequences

What follows — the good, the bad, and what now has to stay true. Name the
guard (test, CI check, convention) that keeps it from drifting, if any.
```

**Citing a sibling repo's ADR**: always qualify it with the repo —
`tailwind-base ADR-0007`, never a bare `ADR 0007`. The numbering spaces are
independent, so a bare number sends the reader to this repo's `docs/adr/`, where
it either does not exist or is a different decision entirely. This package
consumes `tailwind-base ADR-0012` (component `kind` taxonomy) without holding a
copy of it.

**Every ADR is listed in the Index below.** `scripts/check-adr-index.py`
(`composer adr`, and a CI job) fails the build otherwise: an ADR nothing links to
reads as a decision nobody recorded.

## Index

*No decisions recorded yet.* This package's architecture has so far followed
from `parisek/acf-json-schema` and the tailwind-base ADRs it consumes; the
practice is set up here so the first genuine trade-off has somewhere to land —
not so the directory can be filled.
