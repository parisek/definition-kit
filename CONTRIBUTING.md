# Contributing

Thank you for helping. AI coding agents: read [AGENTS.md](AGENTS.md) first. It
is the source of truth for how to work on this repository.

## Set up

```bash
composer install
```

PHP 8.3 or newer is required.

## Checks

CI runs these commands. Run them before you open a pull request.

```bash
composer test                  # PHPUnit
composer phpstan               # PHPStan, level 8
composer adr                   # docs/adr/ index is in sync
composer validate --strict
composer normalize --dry-run
composer audit
```

`composer check` runs `test`, `phpstan` and `adr`.

The migration and the generation are inverses. A change to one needs the
matching change to the other, and a round-trip test.

## Commits and pull requests

- Use [Conventional Commits](https://www.conventionalcommits.org/) for commit
  messages and for the pull request title, for example `fix(lint): ...`.
- Maintainers squash-merge. The squash commit title ends with `(#N)`, the
  pull request number.
- Write all source-visible text in English.

## Changelog

Add your entry directly under the anchor comment in `## [Unreleased]` in
`CHANGELOG.md`. Do not write a version heading. The Stamp Release workflow
does that. See [RELEASING.md](RELEASING.md).

## Decisions

Record a significant decision as an ADR in `docs/adr/`. Ask a maintainer
first. See `docs/adr/README.md`.

## Security

Do not report vulnerabilities in a public issue. See
[SECURITY.md](SECURITY.md).
