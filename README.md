# parisek/definition-kit

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
| `fields-validate` | Validate `<name>.yaml` against the bundled JSON Schema. |
| `fields-lint` | Drift-lint: fail when the committed projection differs from `generate(migrate(source))`. |

Each accepts a single component directory or `--root=<components-root>` to sweep every `component/*/` under it (`--dry-run` on `fields-migrate` writes nothing).

```bash
# one component
vendor/bin/fields-migrate path/to/component/service-feature

# whole tree
vendor/bin/fields-generate --root=path/to/components
vendor/bin/fields-lint --root=path/to/components
```

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
- A per-field / root **`wp:` escape hatch** captures genuinely CMS-specific residue verbatim (e.g. block `postTypes`/`supports`, accordion `wpml`) so the round-trip stays lossless without polluting the semantic surface.

The round-trip contract: `generate(migrate(acf.json)) == acf.json`, modulo documented ACF-export-era residuals.

## Development

```bash
composer install
composer check   # phpunit + phpstan (level 8)
```

## Releasing

See [`RELEASING.md`](RELEASING.md). Behaviour-affecting changes accumulate under `## [Unreleased]` in `CHANGELOG.md`; the **Stamp Release** GitHub Action cuts the version, tags, and publishes the GitHub Release. Packagist auto-updates via webhook.

## License

GPL-3.0-or-later.
