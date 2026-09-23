# 0004. A field can be scoped to some of a component's aliased Drupal bundles

## Context

`drupal.bundle_aliases` lets several paragraph bundles map onto one
component (`html`, `text_image` -> `content`), on the premise that they are
editorially the same content with different Drupal wiring. In practice
they are not always field-for-field identical: the first production Drupal
adoption (arkero) aliased Drupal paragraph bundle `html` to component
`content`, where `html` alone carries a `field_title` no other aliased
bundle has.

Before this decision, a definition field is expected on **every** aliased
bundle (`DrupalDriftLinter` lints each one against the whole field tree,
`BundleSpecBuilder` plans the whole field tree onto each one). A field real
on only one of them was therefore unresolvable: declaring it produced DRIFT
on every other aliased bundle ("Drupal field has no definition field" in
reverse — "no Drupal field X" on the bundles missing it), and dropping it
lost the field the `html` editor actually sees. There was no third option.

## Decision

A field's `drupal:` overlay gains `bundles: [<bundle>, ...]` — the subset of
the component's aliased bundles it applies to. Absent, behaviour is
unchanged: the field is expected on every aliased bundle. Present, the three
tools that walk a field tree against bundles treat a bundle outside the list
as if the field were not declared at all, for that bundle only:

- `DrupalDriftLinter` (`fields-lint-drupal`) skips the field when linting a
  bundle outside its scope — neither "no Drupal field" nor "Drupal field has
  no definition field" fires for it there.
- `BundleSpecBuilder` (`fields-generate --target=drupal`) never reserves the
  field's machine name or plans a `CREATE`/`UPDATE` for it on a bundle
  outside its scope.
- `DrupalParagraphReader::readMerged()` (`fields-migrate --drupal-config`)
  reads every aliased bundle the export has and merges them: a field found
  on all of them is written once, unscoped, same as before; a field found on
  only some gets `drupal.bundles` naming exactly that subset, automatically.
  A single-bundle migration (`read()`) is unaffected.

Only top-level-of-comparison presence is merged — `readMerged()` does not
reconcile two aliased bundles that disagree on a same-named field's *shape*
(a different type, say); the first bundle read wins and the disagreement is
left for a human, same as any other cross-bundle authoring conflict this
package does not try to resolve automatically.

## Consequences

- A component whose aliased bundles are not field-identical is expressible
  without either lying about coverage or losing a field.
- `schemas/component.fields.schema.json`'s `drupalOverlay` gains a fourth
  array-of-machine-names property, alongside `target_bundles`; both take the
  same `^[a-z][a-z0-9_]*$` shape but mean different things (reference
  targets vs. the field's own home bundles) and are not interchangeable.
- The round-trip invariant (`lint(migrate(export))` is clean by construction,
  ADR 0001) now covers the multi-bundle-alias case too:
  `DrupalDriftLinterTest::a_field_scoped_to_one_aliased_bundle_is_not_drift_on_the_others`
  and `DrupalParagraphReaderTest::merging_aliased_bundles_scopes_a_field_present_on_only_some_of_them`
  guard it.
- `bundles` says nothing about *why* the bundles differ (a genuine content
  difference vs. a Drupal-side inconsistency worth fixing at the config
  level); it only lets the definition describe what is actually deployed.
