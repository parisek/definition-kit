# parisek/definition-kit

[![Packagist Version](https://img.shields.io/packagist/v/parisek/definition-kit.svg)](https://packagist.org/packages/parisek/definition-kit)
[![PHP Version](https://img.shields.io/packagist/php-v/parisek/definition-kit.svg)](https://packagist.org/packages/parisek/definition-kit)
[![ACF Pro](https://img.shields.io/badge/ACF_Pro-6.8.x-blue.svg)](https://www.advancedcustomfields.com/pro/)
[![Tests](https://github.com/parisek/definition-kit/actions/workflows/tests.yml/badge.svg)](https://github.com/parisek/definition-kit/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/parisek/definition-kit.svg)](LICENSE)

Authored per-component **definition** (`<name>.yaml`) → CMS **projection** generator + **drift-lint**.

A component's editable surface is authored once, as a human-readable semantic YAML definition. From it, definition-kit generates the CMS-specific implementation (WordPress ACF `acf.json` + Gutenberg `block.json` today; Drupal SDC/paragraphs planned) and a drift-lint fails CI whenever the committed projection stops matching `generate(<name>.yaml)`. The definition is the single source of truth; `acf.json`/`block.json` become generated artifacts.

Companion to [`parisek/acf-json-schema`](https://github.com/parisek/acf-json-schema) (which *validates* ACF JSON); definition-kit *authors and generates* it.

## Install

```bash
composer require --dev parisek/definition-kit
```

It's a build/lint tool — a dev dependency, not a runtime one. Requires PHP 8.3+.

## CLI

Four executables land in `vendor/bin/`:

| Command | Does |
| --- | --- |
| `fields-migrate` | Bootstrap: `acf.json` (+ sibling `block.json`, + `<name>.twig` front-comment for metadata) → authored `<name>.yaml`. |
| `fields-generate` | `<name>.yaml` → `acf.json` + `block.json` projection. |
| `fields-validate` | Validate `<name>.yaml` against the bundled JSON Schema (`page.schema.json` for a page, `doc.schema.json` for a doc, see below). |
| `fields-lint` | Drift-lint: fail when the committed projection differs from `generate(migrate(source))`. |

Each accepts a single component directory or `--root=<components-root>` to sweep every `component/*/` under it (`--dry-run` on `fields-migrate` writes nothing).

```bash
# one component
vendor/bin/fields-migrate path/to/component/service-feature

# whole tree
vendor/bin/fields-generate --root=path/to/components
vendor/bin/fields-lint --root=path/to/components
```

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

## The definition, briefly

`<name>.yaml` is an **authored semantic layer**, not a verbatim ACF mirror:

- **Abstract types** (`text`/`richtext`/`number`/`boolean`/`select`/`media`/`link`/`reference`/`group`/`repeater`/`date`) decouple the definition from ACF field-type names.
- Properties equal to the shared **type-defaults baseline** (`schemas/acf-defaults-baseline.yaml`) are dropped on migrate and re-added on generate — the definition holds only what's meaningful.
- Semantic annotations — `label`, `description` (editor instructions), `mcp` (AI-agent guidance), `translatable`, constraints (`maxlength`/`min`/`max`/`step`/`accept`), `visible_when`, `add_label`, `placeholder`, `options` — carry authored intent.
- **Root metadata:** `name`, `category` and `fields` are required. `asana` is an absolute http(s) URL whose host is asana.com or a subdomain. `web` and `drupal` are site-relative paths: they start with `/`, not `//`. Pages use the same formats for these keys.
- A per-field / root **`wp:` escape hatch** captures genuinely CMS-specific residue verbatim (e.g. block `postTypes`/`supports`, accordion `wpml`) so the round-trip stays lossless without polluting the semantic surface.

The round-trip contract: `generate(migrate(acf.json)) == acf.json`, modulo documented ACF-export-era residuals.

## Project settings — `definition-kit.yaml`

Optional. Place it next to the components root or one directory up (the same two locations the framework-props baseline is discovered in).

```yaml
key_style: snake   # slug (default) | snake
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
