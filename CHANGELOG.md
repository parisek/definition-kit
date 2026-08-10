# Changelog

All notable changes to `parisek/definition-kit` are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
<!-- New entries go directly under this line. It is the anchor that keeps a branch's
     changelog edit from merging into a version that shipped without it. -->

### Fixed

- **`block.json` no longer overwrites `description` and `keywords` with null.**
  Both are editor-facing metadata — the description panel in the block inserter
  and its search aliases — so they are authored per block and cannot be derived.
  `BlockJsonGenerator` hardcoded both to `null` and omitted them from the
  `wp.block` overlay list, so a project that authored either lost it on every
  regeneration. The input side already accepted them and the output schema never
  forbade them; only the mapping between the two was missing.

  Found on `sloneek`, where four `block.json` files had to be excluded from the
  generator to keep their copy — which re-seeds exactly the hand-maintained-
  generated-file drift the tool exists to remove.

  The class docblock claimed `description` was fixed boilerplate, "byte-identical
  across the whole corpus". That census was one corpus (49 mairateam blocks), not
  a property of the format; the claim is corrected rather than deleted, so the
  next reader does not restore the old list.

  `BlockResidualCapturer` gained the same two sections. The generator fix alone
  only helped components whose definition already carried the props; a project
  migrating an existing `block.json` still lost its editor copy at capture time
  and again on the next regeneration.

  The schema's own `description` documentation promised a projection into
  `block.json.description` that was never implemented. It is corrected rather
  than implemented: the two fields serve different audiences and different
  length budgets — the inserter panel needs one short sentence, while the
  component-level field is where full editorial context lives — so projecting
  one into the other would push paragraphs into the editor UI.

## [0.8.0] - 2026-08-09

### Added

- **`bin/fields-fixtures` — the fixture-coverage axis.** The mirror of what
  `fields-lint --contract-only` answers. That one asks whether a template reads a
  prop its definition omits; this asks whether any fixture ever *supplies* what a
  definition declares and a template reads. A `{% if content.x %}` branch that no
  fixture satisfies renders nothing, so no check asserting on rendered output can
  reach it — the class of defect this closes was invisible to visual regression,
  behaviour contracts and render-integrity alike.

  Renders every fixture through `parisek/styleguide`'s observation API and
  compares what each component was actually handed against what its template
  reads and its `<name>.yaml` declares. Reports notices, never errors.

  **`parisek/styleguide` therefore moves from `require-dev` to `require`.** In a
  consuming project it is already a production dependency while this package is
  dev-only, so the direction adds nothing to production. This is not a decision
  to merge the two packages.


- **`key_style: slug | snake` — a project-level setting for how a component
  slug is spelled inside a derived ACF key.** `article-list` can now produce
  either `field_article-list_title` (`slug`, the default and previous
  behaviour) or `field_article_list_title` (`snake`). Declared in
  `definition-kit.yaml` next to the components root or one directory up — the
  same two locations `FrameworkProps::discoverFor()` already searches.

  Before this, a project whose convention was snake_case had exactly one way to
  express it: pin `key:` on every field of every multi-word component, forever.
  One downstream measured 35 of its 42 multi-word components carrying pins that
  encoded no design intent — they existed solely to override the derivation.

  Both the generate side and the migrate side read the setting, so `migrate`
  keeps omitting `key:` when a committed key matches the project's declared
  style. Getting only one side would have been worse than not shipping it: a
  snake_case project would migrate to definitions that pin every key, which is
  the boilerplate the setting exists to remove.

  **`slug` stays the default and the behaviour is unchanged without a config
  file.** Components whose committed keys already match today's derivation
  carry no `key:` precisely *because* they match it, so a changed default would
  spuriously pin every one of them on the next migrate.

  Scope is keys only. The Gutenberg block name (`acf/<slug>`) and the field
  group's `location` param are the block's real identity in WordPress, not a
  spelling choice, and stay verbatim under every style — folding them would
  point a field group at a block that does not exist, and the fields would stop
  appearing in the editor with no error anywhere.

  An unrecognised `key_style` value throws and names the file rather than
  falling back to the default: a silent fallback would rewrite every key in the
  project on the next generate, and the drift-lint would report it as the
  project's own doing rather than as a typo.

### Fixed

- **`TwigPropExtractor` sees the props a macro reads.** It had no macro handling
  at all: a template handing the whole `content` object to a macro reported only
  the props read outside it — and reported `isFullyAnalysed() === true`. On a real
  23-line component that meant four reads out of nineteen, with no note.

  The silence is what made it a bug rather than a limitation. `ContractLinter`
  states its own soundness as "notes can only HIDE reads, never invent them […]
  the notes ride along so a clean result is not mistaken for a complete one" —
  which holds only while an unfollowed construct leaves a note. This one left
  none.

  Macros are followed now, and a hand-off that cannot be followed emits
  `NOTE_UNANALYSED_MACRO`. Where a mapping from a call site to a parameter, or
  from an alias to a template, cannot be established with certainty, the
  extractor declines rather than guessing: a declared gap is survivable, an
  invented read is not, and neither is a silent one.

- **`ContractLinter::templateResolver()` resolves `@namespace/…` paths.** It
  walked up four directories and tried `$dir . '/' . $path`, which never matched
  a leading Twig namespace — and `@macro/…`, `@component/…` are the only forms a
  real theme writes, so macro following was dead code in practice.

  Resolution is now namespace-aware, derived from the components root, with an
  explicit override for layouts that do not match. An unknown namespace declines
  rather than falling through to the directory walk, which could otherwise
  resolve `@vendor/parts.twig` against an unrelated ancestor and attribute reads
  to a file the template never referenced.

  It also stays inside its namespace. The old resolver was safe by accident — its
  four-level walk bounded its reach — while the namespace branch jumped straight
  to an absolute directory and read `../../../../etc/hosts` happily. Both root and
  target are `realpath()`d and the target must be at or below the root, which
  covers `..` and symlinks with one mechanism.


## [0.7.6] - 2026-08-03

### Added

- **ADR practice, matching the three sibling Composer packages.** This was the
  only one of `parisek/{styleguide, timber-kit, definition-kit, acf-json-schema}`
  with no `docs/adr/` at all. Adds `docs/adr/README.md` and an `AGENTS.md`
  § *Architecture decisions* section carrying the same rules as the siblings:
  record sparingly (three conditions), propose before writing, permanent
  sequential numbering, Nygard triad, supersede by linking, qualified cross-repo
  citations, and a mandatory index entry.

  `scripts/check-adr-index.py` (`composer adr`, CI job *docs/adr/ index is in
  sync*, also folded into `composer check`) enforces the last one.

  **No ADR is written as part of this.** The index says so plainly — the
  practice is set up so the first real trade-off has somewhere to land, not so
  the directory can be filled. Writing one to justify the directory is exactly
  what the "record sparingly" rule forbids.
<!-- New entries go directly under this line. It is the anchor that keeps a branch's
     changelog edit from merging into a version that shipped without it. -->

### Fixed

- **`fields-generate` no longer writes a `block.json` for a non-block `kind`.**
  It called `BlockJsonWriter` unconditionally, so every
  `section`/`element`/`part`/`utility` component got a `block.json` it has no
  use for — the exact state `KindLinter` already reports as an error. One
  `--root=` run over a 67-component downstream theme produced 28 spurious
  files.

  The gate fires only on a **present**, non-block `kind`. A definition with no
  `kind` keeps the previous behaviour: absence means "the backfill has not
  reached this file", not "not a block", which is why `KindLinter` warns rather
  than errors there too.

- **`fields-lint` now reports a leftover `block.json` on a non-block `kind` as
  a stale file, not as drift.** Since generation no longer rewrites that file
  and never deletes it, the old output printed a diff under the remediation
  "run `fields-generate`" — the one command that provably could not fix it, so
  re-running it returned the identical diff forever. This is the `block.json`
  half of the Rule 4 (#13) treatment `acf.json` already had.

## [0.7.5] - 2026-07-30
### Changed

- **The stale-`acf.json` drift error now says that having no ACF-backed fields
  is a valid end state.** The message told you to delete the leftover file but
  not whether the fieldless block itself was broken, so the answer had to be
  re-derived from ACF's source each time — and the tempting wrong fix, adding a
  placeholder field to keep the group alive, looked reasonable. ACF reads a
  missing field group as empty rather than broken; the block stays registered
  through `block.json` either way.

## [0.7.4] - 2026-07-29

### Fixed

- **A legacy boolean `required` is now normalised on migration instead of
  being parked in `wp:`.** `Migration\AcfJsonReader` lifted `required` only
  for the canonical int `0`/`1`; a JSON boolean went into the raw `wp:`
  passthrough to keep `migrate` → `generate` byte-reproducing its source.

  That preserved a shape ACF itself never writes. `required` is edited as a
  `true_false` setting, so ACF stores `0`/`1` — a census of ACF-authored
  field groups found **16/16 as int `0`, never bool**. The consequence
  downstream was permanent: every `acf.json` generated from such a
  definition failed schema validation (`required` is `enum: [0, 1]`), with
  no way out short of hand-editing the `wp:` block — and the hand-edit was
  reverted by the next `fields-generate` run. One consuming project carried
  21 of these across 8 components.

  Reversibility is not loosened generally. Only the two enumerated pairs
  (`false`→`0`, `true`→`1`) are normalised; any other value on `required`
  still round-trips verbatim in `wp:`. The residual this creates — a
  definition generating the canonical int against a legacy committed
  boolean — is filtered by two new `Lint\DriftAllowlist` rules
  (`legacy-boolean-required-false` / `-true`), the established mechanism
  for already-understood export-era artifacts. A generated `0` against a
  legacy `true` (a genuine disagreement about whether the field is
  required) still fails, as does any non-boolean value.

  `Migration\MigrationCompletenessAuditor` accepts the same shapes. Its
  `required` branch has to agree with the reader's: a shape the reader
  consumes but the auditor skips falls through to the generic leftover
  check, which finds it neither in the type baseline (`required` is
  excluded there) nor in `wp:` — and reports losslessly-migrated data as
  "silent data loss".

### Added

- `Lint\DriftAllowlist` rules accept a `field_only` scope key — the
  inverse of `root_only`, for props that exist on ACF fields but mean
  nothing on the field-group object. The root `wp:` bag merges verbatim
  into that object (`Generator\RootFieldGroupBuilder`), so a field-scoped
  prop authored there does reach root level, where a field-scoped rule
  must not excuse it.

### Changed

- `schemas/drift-lint-allowlist.yaml`'s header pointed at a
  `static/tools/definition-kit/README.md` section as its source of truth —
  a path from when this tool lived inside a consuming project, which does
  not exist in this repository. The rules are now self-documenting, each
  carrying its rationale inline. Stale "five residuals" counts in that
  header and in `Support\StructuralDiff`'s docblock were dropped rather
  than re-pinned to a number that will drift again.

## [0.7.3] - 2026-07-29
### Fixed

- **An unrecognised CLI option is now refused instead of silently ignored.**
  Every binary parsed its arguments with a trailing catch-all — anything that
  matched no known flag became the positional component directory. A second
  positional then overwrote it, so an unknown option simply vanished and the
  command ran with its defaults.

  The dangerous case is `fields-generate`: it writes `acf.json` and
  `block.json` unless `--dry-run` is set. `fields-generate --check <dir>` reads
  like a read-only check and instead **rewrote the working tree**, while
  printing the same `OK <component> -> …/acf.json` line a real run prints.
  Found downstream after a batch of hand-edits kept reappearing across eight
  files with no apparent cause — the run that reverted them looked like a
  verification step.

  `fields-migrate` had the same shape with worse stakes: both `--dry-run` and
  `--force` are opt-in, so `--dryrun` migrated for real. `fields-lint` and
  `fields-roles` silently widened their scope the same way, and
  `fields-validate` reported an option as an unreadable file, which reads like
  a broken definition rather than a bad invocation.

  All five now print `unknown option: <arg>` plus usage and exit `2` for any
  argument starting with `-`. Known flags are untouched — a correct invocation
  behaves exactly as before.

## [0.7.2] - 2026-07-28
### Fixed

- **A declared shape is now checked whatever the role carries it.** `childrenOf()`
  stopped at any non-`field` role before looking for declared children, so a
  `role: parent` or `role: query` prop that enumerated its `fields:` had that
  enumeration ignored — the author wrote the shape and got no verification for
  it. **18 such declarations across mairateam and tailwind-base** were being
  skipped, including every menu shape in mairateam.

  The rule is now: declared children are checked; the role only decides what
  happens when nothing is declared. A `query` row with no `fields:` is still
  opaque, because there its shape genuinely is its source's business.

  Neither corpus gains a violation from this, so it costs nothing today and
  starts verifying what was already written.

- **`of:` pointing at its own component expresses recursion.** A menu whose rows
  contain menu rows had to be modelled to a fixed depth, and a level past that
  was silently undeclared. `below: {of: component:menu#items}` says "and so on"
  instead. The walk terminates because depth is bounded by the read path, not
  by the definition — no cycle detection needed, and a typo at any depth is
  still caught. Documented and pinned by a test rather than built: it already
  worked, and nothing said so.

## [0.7.1] - 2026-07-28
### Fixed

- **`fields-migrate` refuses to overwrite an existing definition** unless
  `--force`. Migration derives a definition from `acf.json`, so anything
  authored only in the YAML — `mcp:` guidance, `dev:` notes, a hand-corrected
  `name:` — exists in no other file and was silently deleted by a re-run.

  Found by running the 0.7.0 pipeline over an adopted project: a `--root` sweep
  cost **430 lines of authored annotation across 52 components**, and nothing in
  the tool's output said so — every component reported `OK`. It surfaced only
  from reading the diff.

  Re-deriving after the CMS changed is still legitimate, so `--force` keeps that
  path, and the skip message names it.

## [0.7.0] - 2026-07-28
### Added

- **`of: component:<slug>[#<field>]`** — a prop whose shape is another
  component's input, declared once where it belongs (issue #27).

  `header.twig` hands `menu` straight to `header-menu` and never looks inside
  it, but a `repeater` had to enumerate `fields:`, so `header.yaml` transcribed
  the child's item shape. The transcript drifted: it was missing
  `attributes.target` and the whole submenu group until review caught it.
  tailwind-base has four such pairs across 25 components — `header.menu` ≡
  `header-menu.items`, `header.language_switcher` ≡
  `header-language-switcher.items`, `article-list.pagination` ≡
  `pagination.items` (seven sub-fields), `page-header-default.breadcrumb` ≡
  `breadcrumb.items`.

  `of:` already answers "what does this point at" with a `<kind>:<name>`
  vocabulary (`post:article`, `term:category`, `geo`), so a component is a
  fourth kind of target rather than a new key. The contract check reads the
  target's fields, which is what the reference buys over a transcript: adding a
  field to the child immediately reaches every parent that forwards to it, and
  a parent cannot read a field the child does not have.

  A field carrying a component target declares no `fields:` of its own and must
  carry a non-`field` role: a borrowed shape with a local copy beside it is the
  transcript again, and a forward that projects would emit an ACF group with
  nothing in it (the schema denies both; `FieldProjectionFilter` denies the
  second again for callers that skip schema validation).

  An unresolved target **fails** the run rather than merely being noted, and is
  found by reading the definition rather than by tripping over it: every `of:`
  target is resolved up front, so a broken reference surfaces even when the twig
  never reads through it, reads the prop as a whole, does not parse, or does not
  exist. The check reads the target's fields, so an unreachable one leaves the
  prop with no declared shape at all — reporting that and exiting zero is how a
  broken reference survives CI. `fields-validate` names the two cases apart: no such
  component, or that component does not declare that field (the second is what
  a rename looks like).

  Cheap because a forwarded prop is always non-projecting: nobody edits a value
  the parent passes through, so `acf.json` generation never sees one and only
  the check had to learn the reference.

### Changed

- **`of:` is now a closed grammar** — `geo`, `term:<taxonomy>`,
  `post:<type>[,post:<type>…]`, `component:<slug>[#<field>]`. It was an open
  string, so `components:header-menu` or `post :article` passed validation and
  was then silently ignored by every linter: a target kind nobody checks. The
  generator already enforced the three reference kinds at generation time
  (`AbstractTypeReverseMapper`); the schema now says the same thing earlier, and
  covers the group/repeater fields the generator never sees.

## [0.6.1] - 2026-07-27
### Added

- **`fields-lint --contract-only`** runs the input-contract check without the
  CMS-projection drift check. Drift compares a definition against the
  `acf.json`/`block.json` generated from it; a CMS-agnostic skeleton has neither
  — tailwind-base generates its projections downstream — so every component
  reported "acf.json missing" and buried the contract half, which is perfectly
  meaningful there. The flag suppresses a check; it does not change one.

## [0.6.0] - 2026-07-27
### Added

- **A project can state its own framework props baseline.** `fields-lint` and
  `fields-roles` look for `framework-props-baseline.yaml` next to the components
  root, then one level up, and fall back to the shipped timber-kit table. What a
  framework injects is a fact about the framework in front of you: tailwind-base
  treats `container` as a layout slot every wrapper supplies, and asked for it in
  the baseline (its `docs/398-unresolved-roles.md`). Hardcoding one project's
  convention into a shipped file would turn this table into a negotiation.

  A project file **replaces** the shipped one rather than merging. A baseline is
  the list of props the check will never report, and a half-inherited list is
  one nobody can read off the page.

- **`open: true`** on a `group`/`repeater` declares a map whose keys are not
  knowable in advance — `picture.link_attributes`, an arbitrary attribute bag
  merged onto the `<a>`. Such a field previously had to enumerate `fields:`, so
  an author either invented representative leaves and documented them as
  approximations, or dropped a real input from the contract. The contract check
  stops descending at an open map: what is inside belongs to whoever fills it.
  It does not excuse the field from carrying a role.

- **`fields-roles`, a bootstrap that proposes rather than guesses** (issue #27).
  Roles for 200 components cannot be hand-written, and a tool that guesses at
  them is worse than none: a wrong `role:` is a false claim about where a value
  comes from, and the check would then be verifying fiction.

  It proposes `field` for a prop with an actual ACF field behind it (read from
  the sibling acf.json — being in the YAML is not evidence, which is the very
  distinction `role:` records), `query` or `global` from what the component's
  `.php` sidecar assigns, `derived` when the derived-props table explains it AND
  the sibling it would name is present, `parent` when a call site hands it in,
  and omits framework-injected props entirely. Everything else is **left blank
  and listed**, and the blank rate — roughly one prop in five on a real project
  — is printed at the end of every run, so it is expected rather than
  discovered halfway through a review.

  Nothing is written without `--write`. Sidecars are read with PHP's own lexer
  and call sites with Twig's own parser, same doctrine as the prop extractor.
  Only an explicit `with { … }` counts as a call site: a bare include hands over
  the whole context, and counting that would let every component claim every
  prop as `parent`.

- **`fields-lint` now checks a component's input contract** — a prop the twig
  reads that no role accounts for, and that the framework baseline does not
  supply, is reported by name (issue #27). #26 planned this as a linter with a
  maintained exception table; declaring the other provenances instead removed
  the need for one, which is why the check is the smallest piece of the work
  rather than the largest.

  **Gated per component, not per project.** A component is *typed* when it
  declares at least one field and every declared field carries a role
  (explicitly, or inherited from an ancestor that does) — an author saying they
  have been through the definition with the vocabulary in hand. Only a typed
  component is checked for real; everything else is reported as **untyped**,
  which is deliberately not the same as passing. A project-level gate would
  leave the check untrustworthy until every component was done, so nobody would
  enable it, so it would never get done.

  `fields: {}` counts as untyped rather than vacuously clean. The empty map is
  honest — `fields-migrate` writes it rather than guess — but it records that
  nobody has stated the contract, not that there is none.

  A read that goes past a declared leaf (`cta.url`, `image.src`) or into a
  `query`/`parent`/`global`/`derived` structure is not a violation: the first is
  ACF's own return shape, the second is a value whose shape belongs to its
  source. A read that names something unknown at a level the definition *does*
  enumerate is — including a typo in an enumerated repeater row.

- **A Twig prop extractor** (`src/Contract/TwigPropExtractor.php`) — what a
  component's template actually reads off `content`, using Twig's own lexer and
  parser (issue #27). `twig/twig` becomes a runtime dependency: every project
  consuming this package already runs Twig, so it was installed everywhere and
  merely undeclared here.

  Regex extraction was the cheap alternative and was rejected. This codebase
  took three separate silent bugs in one day from regex-parsing YAML — a far
  simpler grammar — and each shipped past review because the failing shape is
  one nobody writes by hand.

  It resolves direct reads, loop rebinding (`{% for item in content.items %}`
  makes `item.value` a read of `items.value`), `{% set %}` aliasing, and one
  level of `{% include %}` / `{% embed %}` / `include()`. Everything else comes
  back as a *note* rather than silence: a second level of include nesting,
  `attribute(content, key)`, a template naming its include by an expression, and
  a template this environment cannot parse. A missed read is worse than an
  unresolved one — it makes an incomplete definition look complete — so the
  limits are reported and the check downstream treats a noted component as
  unanalysed rather than clean.

  An include carrying `only` stops at the boundary and is *not* reported. What
  was handed over is a read of the calling component; what the child does with
  it belongs to the child's contract. That is the semantics, not an
  approximation.

- **`schemas/framework-props-baseline.yaml`** — the props the render pipeline
  injects into every component (`content.wrapper_id`, `content.wrapper_classes`,
  `content.is_preview`), implicitly declared everywhere and never written into a
  definition (issue #27). Same precedent as the ACF type-defaults baseline: a
  fact true across the whole corpus lives in one file derived from real data
  rather than being repeated 200 times, where it would rot the moment the
  pipeline changes.

  Scoped to `content.*` deliberately. `Timber::context()` globals — `homeUrl`,
  `header.*`, `footer.*` — live at the Twig context root and arrive by a
  different mechanism; they are equally ambient and not the same thing, and the
  file's header says so before somebody adds them.

  `is_preview` is in the list because `BlockRenderer` sets it one line above the
  other two with identical unconditional semantics. It was missing from the
  first draft only because nobody read the line above.

- **`fields-roles` proposes at any depth, not just the root.** It considered
  top-level reads only, so `article-video-grid.items.sources` — the framework
  enrichment `role: derived` was invented for — was flagged by the check instead
  of proposed by the bootstrap. The proposer now descends exactly as far as the
  definition itself does, stopping at the first segment missing from a level the
  definition enumerates, which is the same rule `ContractLinter` uses; the two
  agree on where a contract ends.

  Evidence does not descend with it. A sidecar assigns onto `$content` and a
  caller hands props to the component, so neither says anything about a row of
  one of its repeaters — reusing that evidence one level down would be a guess.
  Nested proposals therefore come from the derived-props table, which names the
  sibling it needs and can be checked against the row it lands in.

  On mairateam: `derived` proposals 2 → 4, components flagged by the check
  11 → 9, and one violation turned into a review item, which is the honest
  answer for a prop nothing on disk explains.

- **`fields-migrate` adopts a component that has only a twig.** `button`,
  `picture`, `header`, `breadcrumb` — rendered by a caller, never by the editor,
  so no ACF group and no `block.json` will ever exist for them. That is **17 of
  mairateam's 69 components**, including the two most-called in the whole theme
  (`picture` at 77 call sites, `button` at 31), and refusing them kept the
  contract check blind to exactly the components #14 called load-bearing.

  The template is the thing being described, so its presence is what makes a
  directory a component; only a directory with none of the three files is
  nothing to migrate. Nothing is guessed — the definition carries what the twig
  front-comment already states (name, category, usage, render, description) and
  an empty `fields:` map for `fields-roles` to fill from evidence.

  A `--root` sweep now covers every shape the single-component form accepts. It
  had migrated only acf.json components, silently passing over the block-only
  ones the CLI has accepted one at a time since 0.4.2.

- **`fields-roles --call-sites=<dir>`**, defaulting to the parent of the
  components root. `footer` and `header` are rendered by `page/_partials/*.twig`
  — one level up, outside the sweep — so scanning only the components directory
  left every one of their props with no evidence. On mairateam this took the
  blank rate from 40% to **21%**, which is the figure the plan predicted before
  any of it had been run.

- **`role: derived` covers any value built out of a declared sibling**, not only
  the framework's field-formatting layer (issue #27). The mairateam run found
  `reference-slider.php` doing `$content['title'] = wp_kses_post($content['heading']['title'])`
  — a component's own PHP lifting a nested field to the root. No database, no
  options, no caller: under the narrow definition it had no role at all, which
  is the gap the removal of `computed` was supposed not to leave.

  Who builds the value turned out not to be the interesting fact; where it comes
  from is, and `from:` records that either way. The framework case
  (`article-video-grid.sources` from `video`) and the lift case are the same
  claim, and both stay checkable.

  `fields-roles` proposes the lift straight from the sidecar — the assigning
  statement names the sibling — and refuses when that sibling is undeclared or
  when two siblings are combined, since `from:` names one origin and picking
  either would be a guess dressed as evidence.

### Changed

- **`type:` and `label:` are required only of a field that projects into
  acf.json.** They describe an ACF field — the widget and the caption above it —
  and a `role: parent` / `query` / `global` / `inherited` / `derived` prop has
  neither. Requiring them anyway would mean writing an editor label for a value
  no editor ever sees, which an author then has to delete before the definition
  reads true. Absent `role:` still means `field`, so every definition written
  before this is unaffected.

### Fixed

- **`styleguide.*.twig` fixtures no longer count as call-site evidence.** In a
  styleguide repository fixtures are the only data source, so *every* prop of
  *every* component is "passed by a template" there — including the ones an
  editor authors. On mairateam 191 props are fixture-only against 58 from
  production templates; that project has an `acf.json` per component and `field`
  outranks a call site, so nothing broke, but a CMS-agnostic skeleton has
  nothing to outrank anything and all 191 would have been mislabelled `parent`.

  The rule call sites serve is not "somebody passes it" but what the prop *is*:
  content an editor would author is `field` even when only a fixture supplies
  it, and a prop that exists so a parent can wire a child into its composition
  is `parent`. Call-site evidence decides ambiguous cases; it does not decide
  the rule.

  A fixture-passed prop is still reported — as a *hint* on the blank rather than
  as a proposal, naming the question a reviewer has to answer. 12 of mairateam's
  28 blanks now carry one.

- **`fields-roles` now sees the call sites that actually exist.** The first
  version recognised only `{% include %}`/`{% embed %}`; a run over a real
  69-component project found **304 `component_*()` calls and zero includes
  between components**, so `role: parent` was proposable exactly nowhere.
  `CallSiteIndex` now understands parisek/timber-kit's `component_*` Twig
  function (`StarterBase::twig_component_template()`), including its `_`-for-`-`
  slug normalisation and the `styleguide_data()|merge({…})` shape its fixtures
  use.

  `styleguide.*.twig` fixtures count as call sites: they pass exactly the props
  a real caller passes, and for a component with no ACF layer they are often
  the only call sites there are. An ACF field still wins over a call site, so
  demo data cannot relabel a genuine `field` prop as `parent`.

- **Sidecar evidence recognises the shapes sidecars are actually written in.**
  Every real sidecar in the corpus came back unclassified, because the parser
  understood only `$content['x'] = <marker>`. Now also handled: `$content['x'][]
  = …` and `$content['x']['y'] = …`; evidence carried across a `foreach` over a
  query result; two-variable chains (`$q = Timber::get_posts(); $p =
  $q->pagination(); $content['pagination'] = Helpers::pagination($p)`); and
  `Timber::get_posts` / `get_categories` as query markers.

  `formatFields` stopped being a `global` marker. It is timber-kit's field
  formatter, called on a post far more often than on options, and listing it
  made every query-built row report as `global` — a confidently wrong answer,
  which is worse than the blank it replaced. `'option'` alone still catches
  `get_option()`, `get_field(…, 'option')` and `formatFields('option')`.

  Measured on the same corpus: blanks fell from 17 to 5, and the proposals went
  from 202 `field` + 1 `derived` to 203 `field`, 6 `query`, 6 `parent`,
  1 `derived`.

## [0.5.1] - 2026-07-27
### Added

- **The role vocabulary is complete: `field` / `query` / `global` / `parent` /
  `inherited` / `derived`** (issue #27). A definition described a component's
  ACF fields; it is meant to describe the component's whole input contract, and
  it could not, because two thirds of a real component's inputs had no way to be
  declared. `parent` (passed by the calling template) and `inherited` (injected
  framework-wide) were designed in #14 and never built — without them, the most
  reused components in a project are the ones a contract check can say least
  about: `button` and `divider` have inputs that are *entirely* `parent`, and
  zero ACF fields between them.

- **`role: derived` with a required `from:`.** A seventh provenance turned up
  in the corpus that none of the six roles could express: `article-video-grid`
  reads `sources`, which is not authored, not queried, not passed in, and
  produced by no per-component code — the field-formatting layer builds it out
  of the sibling `video` field on the same row. `from:` names that sibling, and
  `fields-validate` checks it resolves at the same level. That check is the
  point of the key: an unverifiable role is a hiding place for props nobody
  managed to classify, and this one points at something a linter can confirm.

  Declaring the enrichment on the *type* instead (`media` returns `sources`)
  would have covered `image` srcset and `link` shapes in one move, and was
  rejected: the enrichment is conditional on the data, not the type — `sources`
  exists only for a row that carries a video — so a type-level rule would assert
  a key that is usually absent.

### Removed

- **`role: computed`.** It was defined as "derivation says nothing about a
  backing field", which is why it alone had no `acf:` default and demanded the
  key explicitly. A sweep of ~200 components across five projects found no
  instance that was not database-backed, so it was a permanent explanation
  burden covering nothing. Use `role: query`, or `role: derived` when the value
  is built from a sibling. `role: computed` is now rejected by name, with the
  replacement in the message — it shipped, so somebody's definition still
  carries it.

  `acf:` derivation is simpler as a result: `field` projects, every other role
  does not, and an explicit `acf:` still overrides — which is how a `role: query`
  repeater that needs projecting children declares itself.

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
