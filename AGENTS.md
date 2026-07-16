# AGENTS.md

Operational notes for AI coding agents (Claude Code, Codex, Cursor, …) working on this repo. Treat as authoritative — overrides default assumptions where they conflict.

Tool-specific entrypoint files (`CLAUDE.md`, `.cursorrules`, etc.) just point here so the source of truth stays in one place.

## Maintaining this file

Go-style brevity. Bullets, not paragraphs. Add only what saves the next session real time:

- **Add** a note when you hit a non-obvious gotcha, or pin a convention the codebase relies on.
- **Don't add** restatement of README content or one-off task context. README owns "what the project does"; this file owns "how to work on it".
- **Cap ~150 lines.** Past that the file gets skimmed instead of read.

## Project shape

Authored `<name>.yaml` definition → CMS projection (`acf.json` + `block.json`) generator + drift-lint, distributed via Composer (`parisek/definition-kit`). Pure PHP, no WordPress runtime dependency — the tool operates on JSON/YAML files. PHP 8.3 minimum, PHPStan **level 8**.

- `src/Migration/` — `acf.json` (+ twig front-comment + sibling `block.json`) → definition tree. `AcfJsonReader` (orchestrator), `AbstractTypeMapper`, `WpmlTranslatableMapper`, `VisibleWhenMapper`, `BlockResidualCapturer`, `MigrationCompletenessAuditor`, `FieldsYamlWriter`.
- `src/Generator/` — definition tree → `acf.json` + `block.json`. `FieldsGenerator`, `FieldReconstructor`, `RootFieldGroupBuilder`, `BlockJsonGenerator`, `AcfJsonWriter`, `AbstractTypeReverseMapper`, `ConstraintSentinels`. Migration and Generation are inverses; keep them so.
- `src/Lint/` — `DriftLinter` + `DriftAllowlist` + `DriftResult`; `bin/fields-lint`.
- `src/Schema/` — opis/json-schema validation of `<name>.yaml` and the JSON outputs.
- `src/Support/StructuralDiff.php` — order-insensitive structural diff shared by the linter and the migration self-diff.
- `schemas/` — **shipped, runtime-read**: `component.fields.schema.json` (the `<name>.yaml` contract), `acf-defaults-baseline.yaml` (type-defaults dropped on migrate / re-added on generate), `*.output.schema.json`, `constraint-sentinels.yaml`, `drift-lint-allowlist.yaml`, `block-icon.svg`. NOT export-ignored.

## Commands

```bash
composer test        # phpunit
composer phpstan     # phpstan analyse --memory-limit=512M (level 8)
composer check       # both
```

Run inside DDEV where a project provides one (`ddev composer …`, `ddev exec vendor/bin/phpunit`).

## Conventions & gotchas

- **All source-visible text is English** — code, comments, commit-visible text, docs. Czech only in conversation.
- **The round-trip is the contract:** `generate(migrate(acf.json)) == acf.json` (modulo documented ACF-export-era residuals). Every migration change needs the inverse generation change + a round-trip test. `MigrationCompletenessAuditor` guards against silently-dropped props.
- **Drop-defaults rule:** a value equal to the type-defaults baseline is omitted from the definition (migrate) and re-added (generate). Don't emit baseline values into `<name>.yaml`.
- **`wp:` escape hatch is for genuinely non-derivable CMS residue only** (block `postTypes`/`supports`/`attributes`, accordion `wpml`, anomalous WPML modes). Anything with an abstract-vocabulary home belongs in a semantic key, not `wp:`.
- **`schemas/` paths are resolved `__DIR__ . '/../../schemas/…'` from `src/*/`** — `src/` and `schemas/` are siblings at the repo root; keep them so.
- **`bin/*` autoload discovery** tries `__DIR__/../vendor/autoload.php` (standalone) then `__DIR__/../../../autoload.php` (installed in a consumer's `vendor/`). Preserve both.

## Upstream-first

This package is the single upstream owner of the definition-kit tooling. Downstream projects (tailwind-base, individual WordPress/Drupal sites) consume it via Composer and must not fork it in-tree. Fix here, tag a release, `composer update` downstream.

## Releasing

`## [Unreleased]` in `CHANGELOG.md` accumulates changes; the **Stamp Release** Action cuts the version. See `RELEASING.md`. Never hand-stamp a version heading.
