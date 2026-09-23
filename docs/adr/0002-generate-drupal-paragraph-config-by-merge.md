# 0002. Generate Drupal paragraph config by merge, never by overwrite

## Context

ADR 0001 made the kit read and lint Drupal paragraph config, and left
generation for phase 2 (issue #81). Phase 2 writes config from `<name>.yaml`
into a `drush config:export` directory. Unlike `acf.json`, that directory is
not the kit's own output:

- The Drupal admin UI edits the same files. Widget settings, form weights,
  field_group styling, contrib third-party settings (`paragraphs_ee`,
  `paragraphs_library`, `media_library_edit`) and `uuid` belong to the site,
  not to the definition.
- Storage is shared. With `field_naming: generic`, `field_title` and
  `field_media` serve many bundles. A change to a storage changes every
  bundle, and a change of storage type or cardinality on a table with data
  needs a data migration, which config import cannot do.
- The migration (`fields-migrate --drupal-config`) reads only part of the
  config. Keys it does not read (`translatable`, link `title` beyond url or
  link, `max_length`, `allowed_formats`, the paragraph type label) cannot be
  owned by the generator without breaking `generate(migrate(export))`: the
  generator would rewrite what the definition never said.
- Production gets config through a create-only deploy step (drupal-kit
  `kit:config-apply`), not `drush config:import`. A file the generator writes
  must be safe to apply on a site that already has data.

The coordinator of this work asked for this ADR explicitly as part of the
task. That instruction is the "yes" that `docs/adr/README.md` asks for before
an ADR is written.

## Decision

**One command, planned before it writes.** `fields-generate --target=drupal
--drupal-config=<dir>` builds a plan with one line per config entity:

- `REUSE`: the entity exists and every owned key already matches.
- `CREATE`: the entity does not exist. The generator writes it whole.
- `UPDATE`: the entity exists and an owned key differs. The generator changes
  only that key.
- `REFUSE`: the change needs a data migration or contradicts itself. If the
  plan has one `REFUSE`, the command writes nothing and exits 1.

`--dry-run` prints the plan and writes nothing. `--names-out=<file>` lists the
created and changed config names, one per line, for the deploy step.

**Owned keys.** The definition owns these, and the generator rewrites them on
merge:

- structure: which paragraph types and field instances exist, and which
  field storage each instance uses;
- instance `required`, and instance `label` and `description` on the
  bundles a component describes first-hand (the bundle it names, and its
  nested bundles). An alias bundle (`drupal.bundle_aliases`) gets the
  structure and `required`, not the text;
- `translatable`, only when the definition sets it on the field;
- effective cardinality, per instance;
- storage type and storage settings the type implies (`target_type`,
  `allowed_values` of a select);
- reference targets (`handler_settings.target_bundles`), when the definition
  names them (`drupal.target_bundles`, `of:`, `list`, `object`, layouts);
- link `title` 0 for `shape: url`, not 0 for `shape: link`;
- display membership of a new field instance: the generator puts it on the
  default form display, and on the default view display in the region that
  `drupal.view_display` names.

**Baseline on create, preserve on merge.** Everything else comes from
`schemas/drupal-defaults-baseline.yaml` (plus the project file that
`drupal.baseline` names) when the entity is created, and stays as the export
has it after that: `uuid`, `_core`, the paragraph type label and description,
widget and formatter types and settings, weights, field_group groups,
third-party settings the kit does not own, `translatable` that the definition
does not set, `max_length`, `allowed_formats`, link `title` 1 or 2, and the
form or view placement of an existing field.

**Refusal policy.** The generator refuses, and never writes:

- a different storage type on an existing storage;
- a different `target_type` on an existing storage;
- a cardinality the existing storage cannot hold. An instance can narrow an
  unlimited or larger storage through `field_config_cardinality` when
  `drupal.field_config_cardinality` is on. Only a change to the storage itself
  is refused;
- a select that drops an allowed value an existing storage has;
- two bundles in one run that need different storage for one shared field.

**Never delete.** The generator writes no deletions: not a file, not a field
instance, not a target bundle it does not own, not a group. A field that the
definition drops stays in the export. `fields-lint-drupal` reports it.

**UUIDs.** A new file has no `uuid` and no `_core`. Drupal assigns them on
import. An existing file keeps both.

**Settings.** `definition-kit.yaml` `drupal:` adds `langcode`, `text_format`,
`media_bundles`, `host_fields`, `translation`, `view_display` (`hidden` or
`content`), `field_config_cardinality` and `baseline`. `host_fields` names
the fields (for example `field.field.node.page.field_paragraphs`) that get a
newly created top-level paragraph type added to their targets. The generator
never removes a target from them.

## Consequences

- The round trip is the contract, as it is for ACF:
  `generate(migrate(export))` merged into the same export plans no `CREATE`
  and no `UPDATE`. `DrupalGenerateRoundTripTest` asserts it, with and without
  display evidence. A new owned key needs the matching migration read, or the
  round trip breaks.
- A bundle created from nothing is lint-clean: `fields-lint-drupal` reports
  the generator's output clean against the definition that made it.
- The definition cannot rename a paragraph type, restyle a form, or remove a
  field. Those stay manual, in the Drupal UI, then `drush config:export`.
- Storage type and cardinality changes stay manual and need a data migration.
  The `REFUSE` line says which storage and why.
- `core.base_field_override.*` and field_group layout of existing bundles are
  not generated. A new bundle with `drupal.translation` gets
  `language.content_settings`; Drupal writes the base field overrides when an
  editor changes them.
- Guards: `DrupalTypeMapTest`, `DrupalBaselineTest`, `DrupalConfigPlannerTest`,
  `DrupalConfigMergeTest`, `DrupalGenerateRoundTripTest` and
  `FieldsGenerateDrupalCliTest`.
