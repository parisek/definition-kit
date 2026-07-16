# Changelog

All notable changes to `parisek/definition-kit` are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
