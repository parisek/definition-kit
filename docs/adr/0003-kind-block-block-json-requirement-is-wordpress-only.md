# 0003. `kind: block` requires block.json only on WordPress

## Context

`KindLinter` (`fields-validate`) errors when a `kind: block` definition has no
sibling `block.json`. That check assumes every `kind: block` component is a
Gutenberg block — true on WordPress, where `block.json` is the editor's own
registration file, but false on Drupal, which has no such file at all.

The first production Drupal adoption (arkero, ~49 components) hit this
directly: `fields-validate` refused roughly half its `kind: block` components
for missing a file that will never exist on that CMS.

Drupal already has its own check for the same intent — whether a `kind:
block` component is backed by a real paragraph type — in `DrupalDriftLinter`
(`fields-lint-drupal`, ADR 0001). Duplicating it inside `KindLinter` would
need the same `--drupal-config` export directory `fields-validate` is never
handed, and would split the paragraph-mapping check across two tools that
could disagree.

## Decision

`KindLinter` skips the block.json requirement entirely when the project is a
Drupal one. "Drupal project" is the same signal `DrupalSettings::discoverFor()`
already establishes for every other target-conditional behaviour in this
package: a `drupal:` section in the nearest `definition-kit.yaml`.
`bin/fields-validate` resolves this per file (from the component's own
components root) and constructs `KindLinter` with it.

The WordPress requirement is unchanged: `kind: block` without `block.json`
still errors there, and a `block.json` next to a non-`block` kind still
errors on both targets (that half of the check is CMS-agnostic — a stray
file is a stray file).

A Drupal `kind: block` component's real check — does it map to a paragraph
type — stays exactly where it already lived: `fields-lint-drupal`.

## Consequences

- `fields-validate` no longer needs a Drupal project's `kind: block`
  components to carry a WordPress-only file.
- The paragraph-mapping check for Drupal is not duplicated; a Drupal project
  still needs `fields-lint-drupal` in its pipeline to get that guarantee —
  `fields-validate` alone no longer implies it on Drupal, the way it never
  implied ACF group correctness on WordPress either (that's `fields-lint`).
- `KindLinter`'s constructor takes an optional `isDrupalProject` flag,
  defaulting to `false` (WordPress behaviour), so every existing call site
  keeps its current behaviour unless it opts in.
