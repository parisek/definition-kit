# Changelog

All notable changes to `parisek/definition-kit` are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
### Added

- **A bare `mcp:` / `dev:` now validates and means "deliberately none".** 0.5.0
  gave a component two states — the key is absent, or it carries guidance — so
  a lint reporting un-annotated components had no way to stop reporting the ones
  where somebody had already decided none was needed. That leaves either a check
  that cries wolf until it is ignored, or filler text written purely to silence
  it. Three states are now distinguishable: key absent (not annotated yet), bare
  key (decided, none needed), key with content (annotated).

  An empty string and an empty array stay invalid on purpose. Both are almost
  always a half-finished edit, and accepting them would give one decision three
  spellings.

## [0.5.0] - 2026-07-27
### Added

- **`mcp:` and `dev:` at component root, and the whole `description:`/`mcp:`/`dev:`
  triad on flexible-content layouts.** The three-audience convention already
  existed at field level — `description:` for the editor (it becomes the ACF
  `instructions`), `mcp:` for an AI agent, `dev:` for the developer — but the
  component root offered only `description:` and layouts offered nothing at all.
  Because the root schema is `additionalProperties: false`, authoring `mcp:` on a
  component was a hard validation error rather than an ignored key.

  Root `description:` also gained the schema documentation it never had. It was
  defined as bare `{"type": "string"}` while every neighbouring key carried a
  paragraph, which is precisely why its audience read as ambiguous. It is the
  editor's copy and projects into `block.json`.


## [0.4.2] - 2026-07-26
### Added

- `fields-migrate` can now adopt a Gutenberg block that has no ACF fields —
  a component with a `block.json` and no `acf.json`. It previously refused
  outright, which locked out exactly the components 0.4.0 had just made
  expressible, and left hand-transcribing the block residual into `wp.block`
  as the only way to adopt one. Only the case where neither file exists is
  now an error.

  The generated definition carries `fields: {}` and does not guess. A
  component may take inputs from `timber_context()` or a query, but nothing
  on disk records that, and a wrong `role:` is worse than an honest empty map
  the author fills in.

### Fixed

- `BlockResidualCapturer` now captures a deliberate `example: null`. That
  value means "this block has no meaningful preview" — a component mounting a
  JS app that needs live data — and nothing else on disk records the
  decision. Preservation-from-disk hid the gap: it only fires when a
  `block.json` is already there, so the wrong, derived preview appeared the
  first time a project regenerated without one.

  Only `null` is captured. A non-null example is sample data owned by the
  `sync-gutenberg-block-examples` skill and living in `block.json`; capturing
  it would duplicate that ownership. Measured against a real 40-component
  project, the broader rule captured on every single component and would have
  changed the migration output of all of them.
  ([#18](https://github.com/parisek/definition-kit/issues/18))


## [0.4.1] - 2026-07-26

### Fixed

- `block.json` no longer emits `"postTypes": {}` where it should emit
  `"postTypes": []`. 0.4.0 encoded the whole document through the
  object-for-empty-map conversion built for schema validation, which is right
  for `example.attributes.data` and wrong for `acf.postTypes` — a genuine
  list. 18 components across the downstream fleet were affected.

  PHP cannot tell an empty list from an empty map (both are `[]`), so the
  emptiness is now resolved per key against what block.json actually defines
  as an object, rather than inferred from the value. Both polarities are
  pinned by a test, since fixing one by breaking the other is how the
  regression arose. ([#16](https://github.com/parisek/definition-kit/issues/16))
## [0.4.0] - 2026-07-26
### Added

- `role:` and `acf:` implement the two facts a field carries, which earlier
  drafts kept collapsing into one. `role:` (`field` / `query` / `computed` /
  `global`) says where the runtime value comes from; `acf:` says whether an
  ACF field is projected for it. They are independent: `benefits-list.columns`
  in a real downstream project is a genuine ACF `select` whose saved value a
  block filter post-processes — editor-backed *and* computed — while
  `faq.title` is computed and purely synthetic, lifted from `heading.title`
  with no backing field. A single discriminator cannot express both.

  `role:` defaults to `field` and `acf:` derives from it — `field` projects,
  `global` and `query` do not. `computed` occurs with both polarities, so
  there `acf:` is **required**; the author states it rather than inheriting a
  coin flip. Every existing component is unaffected: omitting `role:` means
  `field`, which is what they already are.

  Both properties were already declared in the schema and consumed by nothing.
  This wires them up rather than adding a third vocabulary for the same idea.

- The root `fields:` map may now be empty (`fields: {}`) for a component with
  no inputs at all. The key itself stays required, so a typo like `feilds:`
  still fails loudly.

### Changed

- `fields-generate` no longer writes an `acf.json` when nothing projects. An
  ACF field group with `fields: []` is a real artifact that gets registered —
  "no ACF layer" and "an empty ACF layer" are different statements.
- `DriftLinter` treats a leftover `acf.json` as drift, and its absence as
  clean when nothing projects. Generation deliberately does not delete the
  stale file: reporting it is safer than removing something a user may have
  hand-maintained. The two had to change together — fixing only one leaves
  "generate does nothing, lint still fails".
- Non-projecting fields are stripped before key derivation, so they never
  influence key or name uniqueness. `conditional_logic` is validated against
  the filtered tree, so a `visible_when` pointing at a stripped field is
  reported instead of shipping as a dangling reference.
- `fields-migrate` sets `role: field` on everything. Provenance cannot be
  inferred from an ACF export; anything else needs a manual audit.


## [0.3.1] - 2026-07-25

### Changed

- **`flexible_content` now reconstructs `wpml_cf_preferences: 3`**, exactly
  like `group`/`repeater` already did. Regenerating an existing project's
  `acf.json` (`fields-generate`) will add this key to every
  `flexible_content` field that lacked it or carried a different value —
  unless the field's own `wp.wpml_cf_preferences` overlay is set, which
  still wins (the escape hatch is applied last and is unchanged).

  The value is not a doctrine guess: ACFML's
  `field_should_be_set_to_copy_once()`
  (`acfml/classes/class-wpml-acf-field-settings.php`) forces copy-once
  identically for `repeater` and `flexible_content` at runtime, and `3` is
  WPML's own `WPML_COPY_ONCE_CUSTOM_FIELD` constant
  (`sitepress-multilingual-cms/inc/constants.php`). The generated JSON now
  states what ACFML actually does, instead of omitting the key because
  real-world hand-maintained exports were inconsistent about it (see issue
  [#11](https://github.com/parisek/definition-kit/issues/11) for the
  corpus census). `NO_AUTO_WPML_TYPES` is removed; `flexible_content`
  joins `FieldReconstructor::CONTAINER_ACF_TYPES` and
  `WpmlTranslatableMapper::CONTAINER_TYPES`.

  Migration direction (`fields-migrate`, `AcfJsonReader`,
  `MigrationCompletenessAuditor`) is unaffected — a source `acf.json`'s
  `flexible_content.wpml_cf_preferences`, however inconsistent, still
  round-trips verbatim into `wp.wpml_cf_preferences` on that side; only
  generation (`yaml` -> `acf.json`) changed.

- `fields-validate` now WARNs when `translatable:` is declared on a
  `group`/`repeater`/`flexible_content` field. The property has always
  been silently ignored for these container types (their
  `wpml_cf_preferences` is always the container value, not derived from
  `translatable`) — it was previously dropped without comment. The new
  `TranslatableInertLinter` surfaces it instead of staying silent, without
  failing validation (`translatable:` there doesn't produce an incorrect
  `acf.json`, it just does nothing).

## [0.3.0] - 2026-07-25

### Added

- `flexible_content` field support end-to-end: migration (`fields-migrate`)
  lifts raw ACF `layouts` into a `layouts:` map keyed by layout name, and
  generation (`fields-generate`) replays it back into ACF's raw `layouts`
  list. Layout-level `label`/`min`/`max` and non-default `display`/`location`
  round-trip verbatim; a layout's own `fields` recurse through the same
  nesting machinery as an ordinary `group`/`repeater`, including
  `flexible_content` nested inside another `flexible_content`'s layout.

### Changed

- **Breaking.** The `wp:` escape hatch no longer accepts properties that carry
  a node's identity or its cross-references. `key`, `name`, `type`, `fields`,
  `sub_fields`, `layouts` and `parent_repeater` are rejected inside any `wp:`
  block — at the root group, on a field at any nesting depth, and on a
  flexible_content layout. Enforced identically by the JSON Schema
  (`wpOverlay` `$def`) and by the generator (`RESERVED_WP_PROPS`), since
  `fields-lint` and `fields-generate` take different code paths.

  `wp:` is merged last with highest precedence, so any property it could
  override became a way to desynchronise the generated tree from what the
  generator derived. Identity now has exactly one path. The sanctioned
  alternatives are unchanged and validated: top-level `key:` (`^field_` /
  `^layout_` / `^group_`), `name` derived from the YAML map key, the semantic
  `type:` enum, and `wp.acf_type` as the ACF-type disambiguator.

  No migration needed: verified against every committed definition in the
  downstream fleet (174 across five projects) — none authors any reserved
  property inside `wp:`. `wp.accordions` is deliberately untouched (38 uses in
  that fleet); its residual overlay now excludes the same reserved set.

### Fixed

- Generator now enforces GLOBAL key uniqueness across an entire generated
  field group — including flexible_content layouts and their sub-fields —
  and throws a `GenerationValidationException` instead of silently emitting
  two ACF fields that alias the same WordPress postmeta key. Underscore-joined
  name-chain derivation could otherwise collide across unrelated
  fields/layouts (e.g. layout `a_b` + field `c` vs. layout `a` + field `b_c`).
- `AcfJsonReader::readLayouts()` now throws on duplicate layout names instead
  of silently overwriting the earlier layout (and its key) with no
  diagnostic. `MigrationCompletenessAuditor` independently detects the same
  duplicate in the raw ACF source — it previously could mask the collision
  entirely when both duplicate layouts happened to share an identical
  sub-field shape.
- Layout `display` (`block`/`table`/`row`) and `location` are now captured
  verbatim by the migration reader (into the layout's `wp:` escape hatch when
  non-default) and replayed by the generator, instead of the generator
  hardcoding `display: block` / `location: null` unconditionally.
- `component.fields.schema.json`'s `layout` `$defs` now requires `label` (not
  just `fields`) and constrains layout map keys to `^[a-z][a-z0-9_]*$` —
  bringing it into parity with `parisek/acf-json-schema`'s
  `field-flexible_content.schema.json`, which already rejected the empty
  `label: ""` a label-less layout would generate.
- `acf-lint` (from `parisek/acf-json-schema`) is now wired into this
  project's own test suite, validating every generated `acf.json` fixture
  against the ecosystem's canonical ACF-shape validator — closing the gap
  that let the `display`/`location` and schema-parity regressions above ship
  unnoticed.
- `wp.key` no longer desynchronises `conditional_logic`. The sibling
  name-to-key map was built from pre-overlay keys, so a field pinning its key
  through `wp:` emitted a `conditional_logic` reference to a key present
  nowhere in the output — silent, shipped, and simply never fired in the
  editor.
- A root `wp: {fields: []}` no longer silently discards the entire generated
  field tree, emitting an empty block with no error.
- `wp: {sub_fields: [...]}` / `wp: {layouts: [...]}` can no longer smuggle
  unvalidated nodes one level deeper, and `wp: {parent_repeater: ...}` can no
  longer overwrite a derived cross-reference.
- Sibling `name` collisions are caught by field shape rather than by an empty
  name, so an ordinary field can no longer take the accordion carve-out.
- `wp.conditional_logic` — the migration reader's documented fallback for ACF
  conditional logic too complex to express as `visible_when` — is now
  validated rather than trusted: the canonical OR-of-AND-rules shape is
  enforced, and every referenced key must resolve in the generated tree. A
  dangling reference throws instead of shipping a broken editor condition.
- `fields-validate` and `fields-generate` no longer disagree. The
  conditional-logic checks lived only in the generator's semantic pass, so a
  definition could validate clean and then fail to generate.

## [0.2.1] - 2026-07-23

### Fixed

- `fields-migrate` now carries the twig front-comment `kind:` into the generated
  `<name>.yaml` root. v0.2.0 added `kind` to the schema and `KindLinter`, but the
  migration reader's metadata passthrough still omitted it, so every migrated
  definition lost its `kind` and — because `parisek/styleguide` is YAML-first —
  tripped `KindLinter`'s "declares no kind" warning. Completes the `kind` feature
  (PR #7 tasks 1-3); no schema or projection change.

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
