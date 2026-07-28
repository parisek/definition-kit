# Release flow for parisek/definition-kit

## Prerequisites

- Push access to the `parisek/definition-kit` GitHub repository
- Packagist maintainer role (first release only — thereafter the webhook auto-updates)

## Steps

### 1. Run the full suite locally

```bash
composer check
```

PHPUnit must be green and PHPStan must report `[OK] No errors`. Unlike
`parisek/acf-json-schema`, there is no live-WordPress verification step — the
migration/generation round-trip is asserted entirely by the test suite against
committed fixtures.

### 2. Make sure changes sit under `[Unreleased]`

Behaviour-affecting changes belong under `## [Unreleased]` in `CHANGELOG.md`
(Keep a Changelog: `### Added`, `### Changed`, `### Fixed`, `### Removed`) —
normally added by their own PR. **Don't hand-stamp a version heading** — the
workflow does that.

**Put entries directly under the anchor comment**, and leave the comment where
it is. An empty `[Unreleased]` has no content of its own, so a branch whose
changelog edit sits right under that bare heading merges into whatever heading
occupies those lines by then — which, after a release, is the version just
stamped. That put a feature into the notes of three tags that did not contain
it before the anchor existed. The stamp keeps the anchor in the fresh
`[Unreleased]` and does not carry it into the stamped block.

### 3. Trigger the Stamp Release workflow

Actions tab → **Stamp Release** → Run workflow → enter `X.Y.Z` (no `v` prefix).

It validates the version, requires a non-empty `[Unreleased]`, runs
`composer test` + `composer phpstan` as guards, stamps `[Unreleased]` →
`[X.Y.Z] - DATE`, commits `Release X.Y.Z`, tags `vX.Y.Z`, pushes, and dispatches
`release.yml` — which builds the GitHub Release from the tag's CHANGELOG section.

### 4. Packagist

Packagist auto-updates via the GitHub webhook. Verify the new version appears at
`https://packagist.org/packages/parisek/definition-kit` within a few minutes.

For the **first** release, submit the package once at
`https://packagist.org/packages/submit` with the repo URL
`https://github.com/parisek/definition-kit`, then enable the GitHub service hook
(or the auto-update integration) so subsequent tags publish automatically.
