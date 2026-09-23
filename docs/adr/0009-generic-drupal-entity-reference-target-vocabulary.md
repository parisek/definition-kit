# 0009. `of: entity:<target_type>[:<bundle>,…]` names a Drupal entity reference outside the post/taxonomy/media vocabulary

## Context

`of:` on a `reference` field already has a closed vocabulary for the three
entity references WordPress and Drupal share a shape for: `geo`,
`term:<taxonomy>`, `post:<type>[,post:<type>…]`. A Drupal `entity_reference`
can point at any entity type, including config entities with no WordPress
counterpart at all — `webform`, or content entities outside the post
vocabulary, e.g. `block_content`, `media`, `taxonomy_term`. Before this
decision such a field had no `of:` vocabulary to reach for.

The first production case: arkero's `contact` paragraph carries
`field_webform`, an `entity_reference` targeting the `webform` config entity
with `handler_settings.target_bundles: null` (webform has no bundles; Drupal
stores "no restriction" as `null`, not `[]` — see `DrupalField::targetBundles()`).
`DrupalParagraphReader::reference()`'s fallback branch (no kit target
matched) wrote `drupal: {target_type: webform}` and no `of:` at all. Every
consumer of a `reference` field's `of:` treats a missing one as `''`
(`(string) ($field['of'] ?? '')`), which is itself a value with no case in
the match: `AbstractTypeReverseMapper::reference()` threw an uncaught
`DomainException` for it. That reverse mapper runs unconditionally inside
`fields-validate` and `fields-generate` (`FieldsGenerator::generate()`
assembles the WP reconstruction tree as a key-resolution check regardless of
target — see `bin/fields-validate`'s own comment), so a Drupal-only project
crashed validating its own migrated output. arkero's workaround,
`of: 'post:any'`, is semantically wrong — a webform is not a post — and it
still left DRIFT/UPDATE: `DrupalTypeMap::expectedTargetBundles()` derived
`['any']` from `post:any`, which does not equal the real, empty bundle list
`DrupalField::targetBundles()` reads from `target_bundles: null`, and
`drupal.target_bundles`'s own JSON Schema (`minItems: 1`) cannot pin an
empty list either, so there was no way to *declare* "any" through the
overlay.

## Decision

`of:` gains a fourth reference kind: `entity:<target_type>[:<bundle>[,<bundle>…]]`.

- `entity:webform` — an entity reference to `webform`, no bundle
  restriction.
- `entity:node:article,page` — an entity reference to `node`, restricted to
  those two bundles (equivalent to `post:article,post:page`, but this form
  exists for a target type post:/term:/media do not already name).

The target type is the segment right after `entity:`; a second `:` starts
an optional comma list of bundles. Omitting the bundle segment means "no
restriction" — Drupal's own `target_bundles: null` sentinel — and is a
**declared** value, not "the definition does not say": `DrupalTypeMap::expectedTargetBundles()`
returns `[]` for it (compared against the real, equally-empty
`DrupalField::targetBundles()`), never `null` (which means "skip the
check" — the pre-existing behaviour for a `reference` field with no `of:`
and no `drupal.target_bundles` pin at all). `drupal.target_type` and
`drupal.target_bundles` still override `of:` when present, unchanged.

`DrupalConfigPlanner` writes the real Drupal sentinel back: wherever it
builds `handler_settings.target_bundles` from a wanted bundle list — a new
field instance (`createInstance()`) or an existing one drifting towards
"any" (`ownTargets()`) — an empty wanted list now writes `null`, never `[]`.
Drupal has no other way to say "unrestricted"; writing a literal `[]` would
create config that round-trips to DRIFT against itself.

`DrupalParagraphReader::reference()`'s no-kit-target fallback now writes
`of: 'entity:<target_type>[:<bundle>…]'` instead of leaving `of:` unset.

`AbstractTypeReverseMapper::reference()` (the WP-side reverse mapper) treats
an `entity:` `of:` as an unrestricted `post_object` (`post_type: []`,
`multiple: 1` when the field is `multiple: true`) rather than throwing. ACF
has no concept of an arbitrary Drupal entity type, and no Drupal-only field
of this kind is ever generated for a WordPress target — the fallback exists
purely so a Drupal-only definition still reconstructs cleanly through the
CMS-neutral checks `fields-validate`/`fields-generate` run unconditionally.

Independently of the vocabulary, `bin/fields-validate` now also catches
`\DomainException` around its `FieldsGenerator::generate()` call, alongside
the `GenerationValidationException` it already caught, and reports a clean
`FAIL` instead of letting the process crash. A field shape the reverse
mapper genuinely cannot handle is a validation failure of that definition,
not an uncaught exception — the same principle `GenerationValidationException`
already established, just extended to the one path that bypassed it.

## Consequences

- A Drupal `entity_reference` to any entity type is nameable through `of:`,
  including "no bundle restriction" — the one shape the overlay's own
  `minItems: 1` on `target_bundles` could never express.
- `fields-lint-drupal` and `fields-generate --target=drupal` agree with each
  other and with the real export on arkero's `field_webform`: 0 DRIFT, 0
  UPDATE. `tests/fixtures/drupal/config/*.contact.field_webform.yml`
  (anonymised from the real field) and
  `DrupalGenerateRoundTripTest`'s `contact` bundle guard the round trip;
  `DrupalTypeMapTest` and `AbstractTypeReverseMapperTest` guard the
  vocabulary parsing and the WP-side fallback directly.
- `component.fields.schema.json`'s `of:` pattern grows a fifth alternative;
  a value that does not match the (still closed) grammar still fails schema
  validation rather than being silently ignored.
- The `of: 'post:any'` workaround is no longer the only lever for "any" —
  and was never the right one for a non-post entity type. Existing
  definitions that used it for something other than a genuine "any post"
  reference should migrate to `entity:<target_type>`.
- A malformed or unsupported `of:` on a `reference` field is now always a
  clean `FAIL` from `fields-validate`, never an uncaught crash, regardless
  of whether the specific cause is this vocabulary or something else
  entirely.
