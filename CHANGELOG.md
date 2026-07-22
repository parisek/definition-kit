# Changelog

All notable changes to `parisek/definition-kit` are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-07-22

### Added

- Component `kind` — a closed enum (`block`, `section`, `element`, `part`,
  `utility`) declaring what a component IS, as distinct from `category`, the
  styleguide sidebar bucket. Drives visual-baseline inclusion, catalogue
  presentation and Gutenberg eligibility. See tailwind-base ADR 0012.
- `fields-validate` checks it: presence (warning, until the downstream backfill
  lands) and `block` <-> `block.json` consistency (error, both directions).

### Changed

- **BREAKING:** `render` is now constrained to `inset`/`bleed`/`chrome`/`overlay`
  — the modes `parisek/styleguide` has always enforced. It previously accepted
  any non-empty string while the package silently rewrote unknown values to the
  default, so a typo produced a wrong preview with no signal anywhere. A
  definition carrying an invalid `render` stops validating.

### Notes

- `kind` is NOT in the schema's `required` array. Existing definitions keep
  validating; presence is reported, not enforced.

## [0.1.4] - 2026-07-21

### Added

- **ACF `checkbox` and `taxonomy` field types are now migratable.** Both previously threw
  `Unsupported ACF field type` and aborted the whole component, blocking any project that uses
  them (found on the keypers migration: 2 of 40 components dead in the water).
  - `checkbox` → `select` + `multiple: true`, disambiguated from a multiple `select` by
    `wp.acf_type: checkbox`. ACF's checkbox has no `multiple` prop of its own, so the reverse
    mapping deliberately emits none.
  - `taxonomy` → `reference` + `of: "term:<taxonomy>"`, mirroring the existing
    `of: "post:<type>"` / `of: "geo"` targets. `field_type` (the ACF-only editor-UI cardinality
    axis) is left unconsumed: `select` falls out via the type-defaults baseline, the other three
    values survive verbatim in the field's `wp:` bag.
  - Type-defaults baseline gains `checkbox:` and `taxonomy:` blocks.
  - A taxonomy field with no `taxonomy` target (migration) and a `term:` reference with an empty
    taxonomy name (generation) both fail loudly instead of emitting a dead ACF field.

### Fixed

- **`fields-generate` no longer overwrites a project's block icon.** An existing `block.json`'s
  `icon` is now preserved verbatim (exactly like `example`); the bundled `schemas/block-icon.svg`
  is only a cold-start default for a first-time generation. The block icon is a project-level
  brand asset — every block in a theme shares one icon derived from that project's favicon — not
  a package constant. Found on the keypers migration, where the first `fields-generate --root`
  would have silently rewritten all 38 blocks' icons to the packaged one, buried inside an
  otherwise-mechanical normalisation diff.

  Note that `icon`, like `example`, can therefore never surface as drift — neither is derivable
  from the definition. Their shape is validated one layer up by `acf-lint`
  (`parisek/acf-json-schema`) against the block schema.

## [0.1.3] - 2026-07-16

### Fixed

- **Generated `block.json` no longer emits `"attributes": null`** — non-bleed blocks (and a
  captured `wp.block.attributes: null`) now omit the key entirely, matching real ACF exports and
  `parisek/acf-json-schema`'s block schema (`attributes` must be an object when present). Found on
  the first full downstream migration (mairateam, 49 blocks): 9 non-bleed blocks failed
  `acf-lint --strict` on the freshly generated files.

### Removed

- **`mcp-defaults.yaml` + `Mcp\McpDefaultsLibrary`** — the per-abstract-type default AI-guidance
  library was seeded but never wired into migration or generation (referenced only by its own
  test). The per-field `mcp` annotation (schema + migration capture) stays; **type-level MCP
  guidance now lives in the consumer (the portadesign-mcp plugin)**, where it applies to every
  ACF field of a type across *all* blocks — not only definition-kit-generated ones. definition-kit
  remains the authoritative schema/validator for the authored `<name>.yaml`; it does not own the
  type-default guidance.

## [0.1.2] - 2026-07-16

### Fixed

- **`fields-generate` preserves the existing acf.json `modified` timestamp** — it stamped
  `time()` on every run, so regenerating churned the `modified` field on every component (git
  noise that defeats committing acf.json as a generated artifact). It now reads the current
  acf.json's `modified` and reuses it, mirroring how `DriftLinter` injects the committed value;
  only a brand-new component (no existing acf.json) falls back to the current time. Regeneration
  is now idempotent — an unchanged component produces a byte-identical acf.json.

## [0.1.1] - 2026-07-16

### Fixed

- **Accordion residual is captured generically via self-diff** — real accordions carry section
  `instructions` (and other non-baseline props) that v0.1.0 dropped on round-trip, because
  accordion capture kept only `{key, label, open}` plus a wpml-only special case. Migration now
  self-diffs each accordion against the generator's baseline pseudo-field (new
  `Migration\AccordionResidualCapturer`, the accordion analogue of `BlockResidualCapturer`) and
  captures **every** deviating prop verbatim, keyed by its real ACF name (`instructions`,
  `wpml_cf_preferences`, `multi_expand`, …); `RootFieldGroupBuilder` overlays them on replay.
  This subsumes and removes the v0.1.0 `wpml` special case (accordion residual now stores the
  real `wpml_cf_preferences` key, not the `wpml` alias) — no per-prop special case can accumulate
  again. Fully-baseline accordions capture nothing (golden fixtures unchanged). Fixes the last
  known round-trip data loss on the mairateam corpus (page-header-service now lints clean).

### Added

- **Initial extraction to a standalone Composer package.** The definition-kit tooling —
  developed in-tree at `portadesign/tailwind-base` `static/tools/definition-kit/` across the
  sync-fields dávky — is extracted verbatim to `parisek/definition-kit`, mirroring the
  `parisek/acf-json-schema` package pattern so downstream projects consume it via Composer
  instead of a vendored copy. No behaviour change from the in-tree tool. Surface:
  - **`bin/fields-migrate`** — ACF field group (`acf.json` + twig front-comment + sibling
    `block.json`) → authored semantic definition `<name>.yaml`.
  - **`bin/fields-generate`** — `<name>.yaml` → `acf.json` + `block.json` projection.
  - **`bin/fields-validate`** — validate a `<name>.yaml` against the JSON Schema.
  - **`bin/fields-lint`** — drift-lint: fail when committed `acf.json`/`block.json` differs
    from `generate(migrate(source))`.
  - Abstract type model, WPML/translatable mapping, `visible_when`/conditional-logic mapping,
    the ACF type-defaults baseline, block.json non-derivable-prop capture, and the `wp:`
    escape hatch.

[Unreleased]: https://github.com/parisek/definition-kit/commits/main
