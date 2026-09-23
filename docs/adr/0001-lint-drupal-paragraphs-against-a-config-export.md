# 0001. Lint Drupal paragraphs against a config export, by convention

## Context

The first Drupal consumer builds each component from a paragraph type. The
Drupal admin UI creates the paragraph types and fields, and `drush
config:export` writes them to YAML. A PHP display class then copies field
values into the template's `content` array, often under another name
(`button` from `field_link`, `items[].name` from `field_title` on the child
paragraph).

The WordPress half of this package generates `acf.json` from `<name>.yaml` and
diffs the result against the committed file. For Drupal we chose to lint
first and to generate nothing: generation of paragraph config is phase 2.

Four facts shaped the design:

- The committed `config/sync` can lag the live database. A fresh export must
  be lintable without a code change.
- The root `drupal:` key of a definition already exists. It is the admin link
  that `parisek/styleguide` shows, and `ComponentParser` reads it as a string.
- In the first consumer, only one of 49 twig front-comments carries a
  `fields:` annotation. The config export is the only complete record of the
  fields.
- tailwind-base ADR-0005 derives Drupal machine names as
  `field_<component>_<name>`. The first consumer uses shared storage instead:
  `field_title`, `field_media` and `field_paragraphs` exist on many bundles, and
  its `.claude/rules/paragraphs.md` maps `heading.title` to `field_title` in the
  `group_heading` field group.

## Decision

**A separate binary, `fields-lint-drupal --drupal-config=<dir>`.** It does not
become a mode of `fields-lint`. `fields-lint` compares a projection the kit
generates with the committed projection. `fields-lint-drupal` compares a
definition with config the kit did not write, field by field. The inputs,
the skip rules and the fix hints differ. A mode would make every WordPress
rule in `fields-lint` branch on the CMS. The config directory is always an
argument, never a setting or a default path.

**The lint compares only:** the field set, the storage type, cardinality, the
required flag, reference targets (entity type and bundles) and nested
paragraph bundles. It does not compare labels, descriptions, translatability,
widget and formatter settings, weights, field_group layout, `uuid`, `_core` or
`dependencies`.

**The root `drupal:` key stays the admin link string.** Drupal residue goes in
a per-field `drupal:` block, the counterpart of `wp:`: `field`, `storage`,
`widget`, `formatter`, `target_type`, `target_bundles`. Unlike `wp:`, it is a
closed object, because the lint reads every key.

**Bundle resolution, in order:**

1. The paragraph type named by the root `drupal:` link
   (`/admin/structure/paragraphs_type/<bundle>/…`). It must exist.
2. Otherwise the component name in snake_case, when the export has that bundle.
3. Plus each bundle that `drupal.bundle_aliases` in `definition-kit.yaml` maps
   to the component. Several bundles can render one component.

`drupal.bundles_without_component` lists bundles that render no component on
purpose. `drupal.ignore_fields` lists framework fields that sit on many
bundles. A child bundle is reached through its parent's `list` field.

**Field naming.** The default, `drupal.field_naming: generic`, maps a field to
the shared storage `field_<leaf>`. An `object` without a `drupal.field` pin is
a field_group: its children are fields of the same bundle, named by their own
leaf (`heading.title` -> `field_title`). An `object` pinned to an
entity_reference_revisions field is one nested paragraph. A `list` expects
the child bundle `<bundle>_item`. A per-field pin covers each exception.
`field_naming: prefixed` restores `field_<bundle>_<leaf>`. For projects that
consume Drupal, this decision supersedes the machine-name rule of
tailwind-base ADR-0005. The rest of that ADR still holds.

**Migration reads the config export first.** `fields-migrate --drupal-config`
takes the fields of a component from its paragraph type and its metadata from
the twig front-comment. `--drupal-display` names the PHP display class. The
migration tokenizes it with `token_get_all()` and never loads or runs it, to
find the template prop name of each Drupal field and to write the pins. The
twig `fields:` annotation is the field source only when no export is given.

## Consequences

- `lint(migrate(export))` is clean by construction. `DrupalDriftLinterTest`
  asserts it for every fixture bundle, with and without display evidence. A
  change to one side needs the inverse change on the other.
- A definition that names fields after the template, as the kit's input
  contract wants, carries pins where the display class renames. In the first
  consumer, 41 of 92 Drupal-backed fields carry a `drupal.field` pin. Eight of
  them are `field_paragraphs` behind an `items` or `slides` list.
- The lint cannot see what the display class does with a field. A field that
  the template never receives is still clean if it exists in the definition.
  `fields-lint --contract-only` covers the template side.
- Phase 2, generation of paragraph config from the definition, can reuse
  `DrupalTypeMap` (canonical storage per kit type) and the `drupal:` block.
  `widget` and `formatter` exist for it. Neither the lint nor the migration
  reads them today.
- Guards: `DrupalDriftLinterTest`, `DrupalParagraphReaderTest` (golden
  definitions under `tests/fixtures/drupal/expected/`), the two CLI tests, and
  `DrupalOverlaySchemaTest`, which also pins the root `drupal:` key to a string
  and checks that the WordPress projection ignores the overlay.
