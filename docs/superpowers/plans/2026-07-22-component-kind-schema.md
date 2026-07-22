# Component `kind` — schema + validator (definition-kit)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Teach `parisek/definition-kit` the `kind` component metadata key — a closed enum — and validate it, so every downstream tool has one authoritative definition to read.

**Architecture:** `kind` joins the component-level metadata already in `component.fields.schema.json` (`name`, `usage`, `category`, `render`, `weight`, `responsive`). The schema declares the enum; `fields-validate` enforces presence and the one machine-checkable rule (`block` ↔ `block.json`). Everything else about `kind` is authorial intent and is presence-checked only.

**Tech Stack:** PHP 8.3, `opis/json-schema`, PHPUnit, PHPStan.

**Design doc:** `tailwind-base` `docs/adr/0012-component-kind-taxonomy.md` (PR #331).

## Global Constraints

- `kind` values, exactly: `block`, `section`, `element`, `part`, `utility`. Closed enum — an unknown value is an error, never a pass-through.
- `kind` is **not** added to the schema's `required` array in this release. Existing definitions must keep validating; presence is enforced by the validator at a reportable severity, not by schema failure. (Downstream backfill lands before it becomes required.)
- Only `block` is machine-checkable. `section`/`element`/`part`/`utility` are intent — never inferred, never auto-fixed.
- Package language is English throughout (schema `description`s, messages, tests).
- Semver: this is additive → minor bump, `0.1.x` → `0.2.0`.

---

## Scope note — what this plan deliberately does NOT cover

Three things surfaced while grounding the ADR that belong to other plans:

1. **`parisek/styleguide` parser passthrough.** `normaliseMetadata()` is an explicit whitelist, so `kind` is dropped before reaching `/api/components`. Separate plan, separate release.
2. **`render: overlay`.** Already valid in the package — `RENDER_MODES = ['inset','bleed','chrome','overlay']`. No package change; the work is a mislabelled `cookieconsent` and a visual-spec change, both in `tailwind-base`.
3. **Downstream backfill.** Needs 1 released first.

They form a strict chain: this plan and the styleguide plan are independent and can run in parallel; `tailwind-base` needs both released; downstream needs `tailwind-base` synced.

---

### Task 1: `kind` in the schema as a closed enum

**Files:**
- Modify: `schemas/component.fields.schema.json` (`properties` object)
- Test: `tests/Schema/ComponentDefinitionSchemaTest.php`

**Interfaces:**
- Produces: schema property `kind`, `enum: [block, section, element, part, utility]`. Consumed by Task 2's validator and, later, by `parisek/styleguide`'s parser.

- [ ] **Step 1: Write the failing tests**

```php
public function testKindAcceptsEveryDeclaredValue(): void
{
    foreach (['block', 'section', 'element', 'part', 'utility'] as $kind) {
        $result = $this->validateDefinition(['name' => 'X', 'kind' => $kind, 'fields' => []]);
        $this->assertTrue($result->isValid(), "kind: {$kind} must validate");
    }
}

public function testKindRejectsAnUnknownValue(): void
{
    $result = $this->validateDefinition(['name' => 'X', 'kind' => 'widget', 'fields' => []]);
    $this->assertFalse($result->isValid(), 'kind is a closed enum — "widget" must fail');
}

public function testDefinitionWithoutKindStillValidates(): void
{
    // Backfill has not run yet; the schema must not break existing definitions.
    $result = $this->validateDefinition(['name' => 'X', 'fields' => []]);
    $this->assertTrue($result->isValid());
}
```

- [ ] **Step 2: Run them and watch the enum tests fail**

Run: `vendor/bin/phpunit --filter Kind tests/Schema/ComponentDefinitionSchemaTest.php`
Expected: `testKindRejectsAnUnknownValue` FAILS (unknown keys currently pass); the other two pass vacuously.

- [ ] **Step 3: Add the property**

In `schemas/component.fields.schema.json`, inside `properties`, after `category`:

```json
"kind": {
    "type": "string",
    "enum": ["block", "section", "element", "part", "utility"],
    "description": "What the component IS, as opposed to `category`, which is the styleguide sidebar bucket. block = editor-insertable (has block.json); section = page-level chrome, not editor-insertable; element = self-contained and reusable across unrelated parents; part = fragment authored for one specific parent's structure; utility = rendering helper with no visual identity of its own. Drives visual-baseline inclusion, catalogue presentation and Gutenberg eligibility. See tailwind-base ADR 0012."
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter Kind tests/Schema/ComponentDefinitionSchemaTest.php`
Expected: 3 passing.

- [ ] **Step 5: Commit**

```bash
git add schemas/component.fields.schema.json tests/Schema/ComponentDefinitionSchemaTest.php
git commit -m "feat(schema): add component \`kind\` as a closed enum

Five values, each tied to a concrete tooling decision — see tailwind-base
ADR 0012. Deliberately NOT added to \`required\`: downstream definitions must
keep validating until the backfill has run."
```

---

### Task 2: Validator — presence, and the one machine-checkable rule

**Files:**
- Create: `src/Lint/KindLinter.php`
- Modify: `bin/fields-validate` (register the linter)
- Test: `tests/Lint/KindLinterTest.php`

**Interfaces:**
- Consumes: the `kind` schema property from Task 1.
- Produces: `KindLinter::lint(string $definitionPath, array $definition): array` returning a list of `['severity' => 'error'|'warning', 'message' => string]`.

- [ ] **Step 1: Write the failing tests**

```php
public function testMissingKindIsAWarningNotAnError(): void
{
    $findings = (new KindLinter())->lint('/x/button/button.yaml', ['name' => 'Button']);
    $this->assertCount(1, $findings);
    $this->assertSame('warning', $findings[0]['severity']);
    $this->assertStringContainsString('declares no `kind`', $findings[0]['message']);
}

public function testKindBlockWithoutBlockJsonIsAnError(): void
{
    $dir = $this->fixtureDir();               // contains button.yaml, no block.json
    $findings = (new KindLinter())->lint("{$dir}/button.yaml", ['kind' => 'block']);
    $this->assertSame('error', $findings[0]['severity']);
    $this->assertStringContainsString('block.json', $findings[0]['message']);
}

public function testBlockJsonWithoutKindBlockIsAnError(): void
{
    $dir = $this->fixtureDirWithBlockJson();
    $findings = (new KindLinter())->lint("{$dir}/hero.yaml", ['kind' => 'element']);
    $this->assertSame('error', $findings[0]['severity']);
}

public function testIntentKindsAreNeverSecondGuessed(): void
{
    // part/element/section/utility cannot be derived — the linter must not try.
    $dir = $this->fixtureDir();
    foreach (['section', 'element', 'part', 'utility'] as $kind) {
        $this->assertSame([], (new KindLinter())->lint("{$dir}/button.yaml", ['kind' => $kind]));
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Lint/KindLinterTest.php`
Expected: FAIL — `Class "Parisek\DefinitionKit\Lint\KindLinter" not found`.

- [ ] **Step 3: Implement the linter**

```php
<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Lint;

/**
 * Validates the component `kind` (tailwind-base ADR 0012).
 *
 * Only `block` is machine-checkable: it is the one value with a filesystem
 * counterpart (`block.json`), so a mismatch in either direction is a real
 * contradiction. `section`/`element`/`part`/`utility` are authorial intent —
 * ADR 0012 measured that no derivable rule separates them, which is the whole
 * reason the key exists. The linter therefore never infers them and never
 * auto-fixes.
 *
 * Missing `kind` is a WARNING, not an error: the downstream backfill has not
 * run yet, and failing every un-migrated definition would make the tool
 * unusable during the migration it is meant to support.
 */
final class KindLinter
{
    /** @return list<array{severity: string, message: string}> */
    public function lint(string $definitionPath, array $definition): array
    {
        $kind = $definition['kind'] ?? null;

        if ($kind === null) {
            return [[
                'severity' => 'warning',
                'message' => sprintf(
                    '%s declares no `kind`. Add one of block/section/element/part/utility — see ADR 0012.',
                    basename($definitionPath)
                ),
            ]];
        }

        $hasBlockJson = is_file(dirname($definitionPath) . '/block.json');

        if ($kind === 'block' && !$hasBlockJson) {
            return [[
                'severity' => 'error',
                'message' => sprintf('%s declares `kind: block` but has no block.json.', basename($definitionPath)),
            ]];
        }

        if ($kind !== 'block' && $hasBlockJson) {
            return [[
                'severity' => 'error',
                'message' => sprintf(
                    '%s has a block.json but declares `kind: %s` — an editor-insertable component is `block`.',
                    basename($definitionPath),
                    $kind
                ),
            ]];
        }

        return [];
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Lint/KindLinterTest.php`
Expected: 4 passing.

- [ ] **Step 5: Register it in the CLI**

In `bin/fields-validate`, alongside the existing linters, run `KindLinter` per definition and merge its findings into the report. Warnings must not change the exit code; errors must.

- [ ] **Step 6: Run the full suite + static analysis**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add src/Lint/KindLinter.php tests/Lint/KindLinterTest.php bin/fields-validate
git commit -m "feat(lint): validate component \`kind\`

Presence is a warning (backfill has not run); \`block\` <-> block.json
mismatch in either direction is an error. The four intent kinds are never
inferred — ADR 0012 measured that no derivable rule separates them."
```

---

### Task 3: Tighten `render` to the same enum the package already enforces

**Files:**
- Modify: `schemas/component.fields.schema.json` (`properties.render`)
- Test: `tests/Schema/ComponentDefinitionSchemaTest.php`

**Rationale:** the schema types `render` as `{"type": "string", "minLength": 1}`,
while `parisek/styleguide` has enforced `RENDER_MODES = ['inset','bleed','chrome','overlay']`
for some time — and `normaliseRender()` silently rewrites anything else to the
default. A typo therefore produces a wrong preview with no signal anywhere. The
enum makes that loud at validation time instead.

**This is the one behaviour change in the release:** a project with an invalid
`render` value validates today and stops validating after. That is the point;
call it out in the changelog.

- [ ] **Step 1: Write the failing tests**

```php
public function testRenderAcceptsEveryPackageMode(): void
{
    foreach (['inset', 'bleed', 'chrome', 'overlay'] as $mode) {
        $result = $this->validateDefinition(['name' => 'X', 'render' => $mode, 'fields' => []]);
        $this->assertTrue($result->isValid(), "render: {$mode} must validate");
    }
}

public function testRenderRejectsAValueThePackageWouldSilentlyDefault(): void
{
    $result = $this->validateDefinition(['name' => 'X', 'render' => 'inline', 'fields' => []]);
    $this->assertFalse($result->isValid(), 'an unknown render mode must fail loudly, not default silently');
}
```

- [ ] **Step 2: Run them and watch the second fail**

Run: `vendor/bin/phpunit --filter Render tests/Schema/ComponentDefinitionSchemaTest.php`
Expected: `testRenderRejectsAValueThePackageWouldSilentlyDefault` FAILS.

- [ ] **Step 3: Replace the property**

```json
"render": {
    "type": "string",
    "enum": ["inset", "bleed", "chrome", "overlay"],
    "description": "Iframe-wrapper mode for the styleguide preview — how the component is FRAMED, not what it is (that is `kind`). Mirrors ComponentParser::RENDER_MODES in parisek/styleguide, which silently defaults an unknown value; this enum makes the mistake loud instead."
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter Render tests/Schema/ComponentDefinitionSchemaTest.php`
Expected: 2 passing.

- [ ] **Step 5: Commit**

```bash
git add schemas/component.fields.schema.json tests/Schema/ComponentDefinitionSchemaTest.php
git commit -m "feat(schema)!: constrain \`render\` to the package's four modes

parisek/styleguide has enforced RENDER_MODES for some time and silently
rewrites anything else to the default, so a typo produces a wrong preview
with no signal. BREAKING: a definition with an invalid \`render\` stops
validating."
```

---

### Task 3: Release 0.2.0

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Write the changelog entry**

```markdown
## 0.2.0

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
```

- [ ] **Step 2: Verify the package installs clean from a fresh checkout**

Run: `composer validate --strict && vendor/bin/phpunit && vendor/bin/phpstan analyse`
Expected: all green.

- [ ] **Step 3: Commit and tag per RELEASING.md**

```bash
git add CHANGELOG.md
git commit -m "chore(release): 0.2.0 — component \`kind\`"
```

Then follow `RELEASING.md` for the tag and Packagist step.

---

## Self-review

**Spec coverage.** ADR 0012's definition-kit row asks for two things — the enum in the schema and a validator deciding where `kind` is mandatory and what is machine-checkable. Task 1 covers the first, Task 2 the second. The ADR's other three surfaces are explicitly out of scope above, with the dependency order stated.

**Placeholders.** None: every step carries its code or its exact command.

**Type consistency.** `KindLinter::lint()` has one signature, used identically in Task 2's tests and its CLI registration. The five enum values appear identically in the schema, the linter's message and the changelog.

**`render` is bundled deliberately.** It is the one behaviour change here: a definition with an invalid `render` validates today and stops after. Included rather than deferred because the current state is a silent failure — the package rewrites the value and nobody learns — which is the same shape as every other bug ADR 0012 documents.
