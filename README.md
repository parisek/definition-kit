# parisek/definition-kit

[![Packagist Version](https://img.shields.io/packagist/v/parisek/definition-kit.svg)](https://packagist.org/packages/parisek/definition-kit)
[![PHP Version](https://img.shields.io/packagist/php-v/parisek/definition-kit.svg)](https://packagist.org/packages/parisek/definition-kit)
[![ACF Pro](https://img.shields.io/badge/ACF_Pro-6.8.x-blue.svg)](https://www.advancedcustomfields.com/pro/)
[![Tests](https://github.com/parisek/definition-kit/actions/workflows/tests.yml/badge.svg)](https://github.com/parisek/definition-kit/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/parisek/definition-kit.svg)](LICENSE)

Authored per-component **definition** (`<name>.yaml`) → CMS **projection** generator + **drift-lint**.

A component's editable surface is authored once, as a human-readable semantic YAML definition. From it, definition-kit generates the CMS-specific implementation (WordPress ACF `acf.json` + Gutenberg `block.json`) and a drift-lint fails CI whenever the committed projection stops matching `generate(<name>.yaml)`. For Drupal it migrates definitions from the paragraph config, lints them against a config export, and generates paragraph config back into that export by merge (see [Drupal paragraphs](#drupal-paragraphs)). The definition is the single source of truth; `acf.json`/`block.json` become generated artifacts.

Companion to [`parisek/acf-json-schema`](https://github.com/parisek/acf-json-schema) (which *validates* ACF JSON); definition-kit *authors and generates* it.

## Install

```bash
composer require --dev parisek/definition-kit
```

It's a build/lint tool — a dev dependency, not a runtime one. Requires PHP 8.3+.

## CLI

Five executables land in `vendor/bin/`:

| Command | Does |
| --- | --- |
| `fields-migrate` | Bootstrap: `acf.json` (+ sibling `block.json`, + `<name>.twig` front-comment for metadata) → authored `<name>.yaml`. |
| `fields-generate` | `<name>.yaml` → `acf.json` + `block.json` projection, for `kind: block` and for a definition with no `kind`. With `--target=drupal`, paragraph config merged into a Drupal config export instead. |
| `fields-validate` | Validate `<name>.yaml` against the bundled JSON Schema (`page.schema.json` for a page, `doc.schema.json` for a doc, see below). |
| `fields-lint` | Drift-lint: fail when the committed projection differs from `generate(migrate(source))`. |
| `fields-lint-drupal` | Drift-lint against a Drupal config export: fail when a definition and its paragraph type differ. See [Drupal paragraphs](#drupal-paragraphs). |

Each accepts a single component directory or `--root=<components-root>` to sweep every `component/*/` under it (`--dry-run` on `fields-migrate` writes nothing).

```bash
# one component
vendor/bin/fields-migrate path/to/component/service-feature

# whole tree
vendor/bin/fields-generate --root=path/to/components
vendor/bin/fields-lint --root=path/to/components
```

`fields-generate` writes projections only for `kind: block`. A component with another `kind` (`section`, `element`, `part`, `utility`) registers no Gutenberg block, so it prints `SKIP <name>: kind <kind> has no CMS projection` and gets neither file. The generator never deletes or rewrites an `acf.json` or `block.json` that is already on disk. A definition with no `kind` still gets both files, because the `kind` backfill may not have reached it yet. `--dry-run` writes nothing and reports the same lines.

The last line reads `N component(s), N failed`, and ends in `, N skipped` when a component was skipped. A page or doc is not in either count. The exit code is 1 when anything failed, and 0 when the rest only skipped.

## Pages — `page/<id>/<id>.yaml`

A styleguide page is not a component. It composes components, has no editor fields and no CMS projection. Its metadata lives in `page/<id>/<id>.yaml` and validates against its own schema, `schemas/page.schema.json`:

```yaml
# yaml-language-server: $schema=../../../../vendor/parisek/definition-kit/schemas/page.schema.json
name: 'Hlavní strana'
usage:
  - page-header-image
  - gallery-slider
weight: 1
```

- **Required:** `name`. **Allowed:** `usage`, `category`, `render`, `web`, `asana`, `figma`, `drupal`, `description`, `dev`, `weight`, `responsive`, `body_class`, `variants` (legacy). **Refused:** `fields`, `kind`, `wp`, `key`, `mcp`.
- **A file is a page when it is `<id>.yaml` in a directory below `page/`** (nearest `page` or `component` ancestor decides; nested `page/_partials/` and `page/<group>/<id>/` count) — the same rule `parisek/styleguide` types an entry by. The `$schema` comment is an editor hint, not the rule.
- `fields-validate` checks a page against `page.schema.json`. `fields-lint`, `fields-generate` and `fields-roles` report it as `SKIP`.
- `fields-migrate page/<id>` (or `--root=path/to/page`, which also finds nested `page/<group>/<id>/` and skips `_`-prefixed partial directories) moves the twig front-comment into `<id>.yaml` and removes the comment from the twig. It refuses a comment with a component-only key and never overwrites an existing `<id>.yaml` (unless `--force`).

## Docs — `doc/<id>/<id>.yaml`

A styleguide doc is prose, not a widget. Its metadata lives in `doc/<id>/<id>.yaml` and validates against `schemas/doc.schema.json`:

```yaml
# yaml-language-server: $schema=../../../../vendor/parisek/definition-kit/schemas/doc.schema.json
name: Typografie
description: 'Škála a próza na jednom místě.'
weight: 98
```

- **Required:** `name`. **Allowed:** `description`, `dev`, `weight`, `body_class`, `variants` (legacy). **Refused:** `fields`, `kind`, `wp`, `key`, `mcp`, and the keys `parisek/styleguide` gives no effect on a doc: `render` (forwarded only for a component), `responsive` (forced to `false` for every doc), `usage`, `category`, `web`, `asana`, `figma`, `drupal` (the SPA shows none of them on a doc route).
- **A file is a doc when it is `<id>.yaml` in a directory below `doc/`.** The nearest `page`, `doc` or `component` ancestor decides, as for pages.
- The CLI treats a doc like a page: `fields-validate` checks it against `doc.schema.json` and names a refused key; `fields-lint`, `fields-generate` and `fields-roles` report it as `SKIP`; `fields-migrate doc/<id>` (or `--root=path/to/doc`) moves the twig front-comment into `<id>.yaml`.

## Wire the drift-lint into CI

Add a composer script and a CI step so a hand-edit to a generated `acf.json`/`block.json` (or a stale definition) fails the build:

```json
{
  "scripts": {
    "lint:fields-drift": "fields-lint --root=path/to/components"
  }
}
```

Output, one line per component:

- `OK` — the projection matches the definition.
- `DRIFT` — the projection differs; the lines under it show where.
- `FAIL` — an error, such as an invalid definition or a missing `acf.json`.
- `SKIP` — nothing to compare: no `<name>.yaml` yet, a page or doc, or a component whose `kind` is not `block` and which has no `acf.json` or `block.json`. Only `kind: block` needs a projection. A component with no `kind` is not skipped.

A non-block component with a committed `acf.json` is still compared. When it drifts, the fix line tells you to delete the file or change the `kind`, because `fields-generate` does not rewrite it. A non-block component with a `block.json` still fails, because that file is stale.

The last line reads `N component(s), N failed`. When a component was skipped it ends in `, N skipped`. A page or doc is not in either count. The exit code is 1 when anything failed, and 0 when the rest only skipped.

## The definition, briefly

`<name>.yaml` is an **authored semantic layer**, not a verbatim ACF mirror:

- **Abstract types** (`text`/`richtext`/`number`/`boolean`/`select`/`media`/`link`/`reference`/`object`/`list`/`flexible_content`/`date`) decouple the definition from ACF field-type names.
- **`object` and `list` are the structural types.** `object` is one nested object. `list` is a list of objects — never a list of scalars. Both enumerate `fields:`. A field that projects becomes an ACF `group` or `repeater`. `group` and `repeater` stay valid aliases with the same keys and byte-identical output; `fields-migrate` writes the canonical names. Rename existing definitions in one reviewed commit:

  ```bash
  vendor/bin/fields-migrate --rename-structural-types --root=path/to/component          # prints what would change
  vendor/bin/fields-migrate --rename-structural-types --write --root=path/to/component  # rewrites type: lines only
  ```

  The rewrite changes only the value of a `type:` line. Comments, quoting and key order stay. A field named `group` or a `wp.acf_type` marker is not touched, and a second run changes nothing.
- Properties equal to the shared **type-defaults baseline** (`schemas/acf-defaults-baseline.yaml`) are dropped on migrate and re-added on generate — the definition holds only what's meaningful.
- Semantic annotations — `label`, `description` (editor instructions), `mcp` (AI-agent guidance), `translatable`, constraints (`maxlength`/`min`/`max`/`step`/`accept`), `visible_when`, `add_label`, `placeholder`, `options` — carry authored intent.
- **Root metadata:** `name`, `category` and `fields` are required. `asana` is an absolute http(s) URL whose host is asana.com or a subdomain. `web` and `drupal` are a site-relative path (starts with `/`, not `//`) or an absolute http(s) URL with a host. Pages use the same formats for these keys.
- A per-field / root **`wp:` escape hatch** captures genuinely CMS-specific residue verbatim (e.g. block `postTypes`/`supports`, accordion `wpml`) so the round-trip stays lossless without polluting the semantic surface.
- A per-field **`drupal:` block** does the same for Drupal (`field`, `storage`, `widget`, `formatter`, `target_type`, `target_bundles`, `bundles`). It is closed: the Drupal lint reads every key. The root `drupal:` key is something else, the admin link, and stays a string.

The round-trip contract: `generate(migrate(acf.json)) == acf.json`, modulo documented ACF-export-era residuals.

## Drupal paragraphs

A Drupal component is built from a paragraph type. `drush config:export` writes the paragraph types and fields to YAML. definition-kit reads that export, lints definitions against it, and merges generated config back into it. [ADR 0001](docs/adr/0001-lint-drupal-paragraphs-against-a-config-export.md) records the lint and the migration; [ADR 0002](docs/adr/0002-generate-drupal-paragraph-config-by-merge.md) records the generator.

```bash
# bootstrap the definitions from the export (fields) and the twig front-comments (metadata)
vendor/bin/fields-migrate --drupal-config=config/sync \
  --drupal-display=web/modules/custom/<module>/src/Plugin/ExtraField/Display/<Class>.php \
  --root=path/to/component

# then lint them in CI; point --drupal-config at a fresh export when config/sync lags the database
vendor/bin/fields-lint-drupal --drupal-config=config/sync --root=path/to/component

# after a definition changes: see the plan, then merge it into the export
vendor/bin/fields-generate --target=drupal --drupal-config=config/sync --root=path/to/component --dry-run
vendor/bin/fields-generate --target=drupal --drupal-config=config/sync --root=path/to/component \
  --names-out=config-names.txt
```

**Which paragraph type a component describes.** First the bundle in the root `drupal:` admin link (`/admin/structure/paragraphs_type/<bundle>/fields`). Otherwise the component name in snake_case, when the export has that bundle. Plus every bundle that `drupal.bundle_aliases` maps to the component. A component with none of these is not a paragraph: the lint prints `SKIP`.

A field the aliased bundles do not all share names the ones that do have it in a `drupal.bundles` list. Without it, a field is expected on every aliased bundle; with it, the lint and the generator only look for/create it on the bundles listed, and skip it entirely on the rest. `fields-migrate --drupal-config` writes it automatically when it finds a field on only some of a component's aliased bundles in the export.

**Which Drupal field a definition field is.** Only `role: field` (the default) maps to a Drupal field. Its name is `drupal.field` when pinned, otherwise `field_<leaf>`. An `object` without a pin is a field_group: `heading.title` maps to `field_title` on the same bundle. An `object` pinned to an entity_reference_revisions field is one nested paragraph. A `list` is an entity_reference_revisions field and expects the child bundle `<bundle>_item`, unless `drupal.target_bundles` names another. A `flexible_content` has one layout per target bundle.

**Types.**

| Kit type | Drupal storage (first is the default) |
| --- | --- |
| `text` | `string`, `string_long`, `email`, `telephone` |
| `text` + `multiline: true` | `string_long`, `text_long`, `text` |
| `richtext` | `text_long`, `text`, `text_with_summary` |
| `number` | `integer`, `decimal`, `float` |
| `boolean` | `boolean` |
| `select` | `list_string`, `list_integer`, `list_float` |
| `media` | `entity_reference` to media, `image`, `file` |
| `link` | `link` |
| `reference` | `entity_reference` |
| `date` | `datetime`, `daterange`, `timestamp` |
| `list`, `flexible_content`, pinned `object` | `entity_reference_revisions` |

`drupal.storage` pins one exact type. The migration writes it wherever the export uses a type other than the default.

A `reference` targeting an entity type outside `term:`/`post:`/media (a config entity such as `webform`, or a content entity Drupal-side only) uses `of: entity:<target_type>[:<bundle>,…]` — omitting the bundle list means no restriction, Drupal's own `target_bundles: null`. See [ADR 0009](docs/adr/0009-generic-drupal-entity-reference-target-vocabulary.md).

**What the lint compares.** The field set in both directions, the storage type, cardinality (one value or several, and a fixed limit against `max:`), the required flag, reference targets and nested paragraph bundles. Not labels, descriptions, translatability, widgets, formatters, weights or field_group layout. After a `--root` run it lists every paragraph type that no component claimed.

**`fields-generate --target=drupal`.** It plans the whole export in one pass, because components share field storage, and prints one line per config entity:

- `REUSE`: the entity exists and every key the definition owns already matches.
- `CREATE`: the entity does not exist. The generator writes it whole, from the type map (`schemas/drupal-type-map.yaml`) and the defaults baseline (`schemas/drupal-defaults-baseline.yaml`). A new file has no `uuid`.
- `UPDATE`: an owned key differs. Only that key changes; the rest of the file stays byte for byte.
- `REFUSE`: the change needs a data migration, or two definitions contradict each other. Then nothing is written and the exit code is 1.

The definition owns the field set, instance label, description and required flag, `translatable` when it sets it, effective cardinality, storage type and the storage settings the type implies (target type, select options), reference targets, the link title mode (`shape: url`), and where a new field goes on the displays. The site keeps everything else: `uuid`, `_core`, the paragraph type label and description, widget and formatter settings, weights, field_group groups, contrib third-party settings. The generator never deletes a file, a field or a display entry; `fields-lint-drupal` reports what a definition dropped.

It refuses a storage type change, a target type change, a dropped select option, and a cardinality the existing storage cannot hold. An instance can narrow an unlimited (or larger) storage when `drupal.field_config_cardinality` is on. A new paragraph type needs a root `drupal:` link that names it; the snake_case convention only finds a type that exists. `--names-out=<file>` lists the created and changed config names, one per line, for a deploy step that imports only those. It is written on `--dry-run` too, and it is empty when the plan refuses or a component fails. `--dry-run` writes no config.

**`fields-migrate` with `--drupal-config`.** A component whose paragraph type exists in the export gets `kind: block` and its fields from the export. When `drupal.bundle_aliases` maps more than one bundle in the export onto the component, every one of them is read and merged: a field present on all of them is written once, unscoped; a field present on only some gets `drupal.bundles` naming that subset. Its metadata still comes from the twig front-comment. `--drupal-display` names the PHP class that copies field values into the template's `content` array. The migration tokenizes it (it never runs it) to name each field after the template prop and to pin `drupal.field` where the two differ. Without `--drupal-config`, a `drupal:` link to a paragraph type still sets `kind: block`, and the twig `fields:` annotation becomes editor-authored (`role: field`). `--default-category=<name>` fills `category:` where a front-comment has none.

**Settings**, in the `drupal:` section of `definition-kit.yaml` (all optional):

```yaml
drupal:
  field_naming: generic       # generic: field_<leaf> (default) | prefixed: field_<bundle>_<leaf>
  bundle_aliases:             # bundle => component directory
    html: content
  bundles_without_component:  # bundles that render no component on purpose
    - from_library
  ignore_fields:              # framework fields on many bundles: not migrated, not reported as extra
    - field_wrapper_id
    - field_wrapper_classes
  # fields-generate --target=drupal only:
  langcode: cs                # langcode of new config entities (default en)
  text_format: basic          # the allowed format of a new richtext field
  media_bundles:              # media kind => media types of a new media field without drupal.target_bundles
    image: [image]
  host_fields:                # fields that get each new top-level paragraph type as a target
    - field.field.node.page.field_paragraphs
  translation: true           # a new paragraph type gets language.content_settings and the translation form entry
  view_display: hidden        # a new field on the view display: content (default) | hidden
  field_config_cardinality: true  # an instance may narrow its storage's cardinality (contrib module)
  baseline: drupal-baseline.yaml  # deep-merged over schemas/drupal-defaults-baseline.yaml, relative to this file
```

The project baseline carries what a new entity needs beyond the kit's defaults, typically contrib third-party settings. A module named as a `third_party_settings` key becomes a module dependency:

```yaml
paragraphs_type:
  third_party_settings:
    paragraphs_library: {allow_library_conversion: true}
widget_third_party_settings:
  media_library_widget:
    media_library_edit: {show_edit: '1', edit_form_mode: default}
view_display:
  content:                    # always rendered, for example an extra field that renders the component
    extra_field_default_paragraph_display: {settings: {}, third_party_settings: {}, weight: 0, region: content}
```

## Project settings — `definition-kit.yaml`

Optional. Place it next to the components root or one directory up (the same two locations the framework-props baseline is discovered in).

```yaml
key_style: snake   # slug (default) | snake
drupal: {}         # see Drupal paragraphs
```

**`key_style`** decides how a component slug is spelled inside a *derived* ACF key. A component directory named `article-list` produces:

| `key_style` | Group key | Field key |
| --- | --- | --- |
| `slug` (default) | `group_article-list` | `field_article-list_title` |
| `snake` | `group_article_list` | `field_article_list_title` |

Both work — an ACF key is an opaque identifier and templates read fields by `name`, never by key — so this is a spelling convention, not a correctness question. It exists because projects already disagree and neither side can be migrated cheaply: **renaming a key orphans stored content** (block attributes bind `_<field>` to the key string), so an existing spelling is frozen wherever content exists. Without the setting, a snake_case project had to pin `key:` on every field of every multi-word component, forever — boilerplate encoding no design intent.

Three things worth knowing:

- **It governs keys only.** The Gutenberg block name (`acf/<slug>`) and the field group's `location` param stay verbatim under every style — they are the block's identity in WordPress, and folding them would point the group at a block that does not exist.
- **`slug` is the default and stays it.** Components whose committed keys match today's derivation carry no `key:` *because* they match; a changed default would spuriously pin every one of them on the next migrate.
- **Changing it on a project that already has content is not a config change.** It is a content migration — the setting decides what new keys are derived, it does not rewrite what is stored.

An unrecognised value throws and names the file. Falling back to the default would rewrite every key on the next generate, and the drift-lint would report it as your doing rather than as a typo.

## Development

```bash
composer install
composer check   # phpunit + phpstan (level 8)
```

## Releasing

See [`RELEASING.md`](RELEASING.md). Behaviour-affecting changes accumulate under `## [Unreleased]` in `CHANGELOG.md`; the **Stamp Release** GitHub Action cuts the version, tags, and publishes the GitHub Release. Packagist auto-updates via webhook.

## License

GPL-3.0-or-later.
