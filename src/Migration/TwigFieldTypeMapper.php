<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Migration;

use Parisek\DefinitionKit\Support\StructuralType;

/**
 * Translates ONE field from the twig `fields:` annotation (tailwind-base's
 * `update-fields` skill vocabulary — `title`/`type`/`required`/
 * `description`/`placeholder`/`options`/`choices`/`fields`) into the
 * abstract shape `component.fields.schema.json` expects. Used only for a
 * component with no acf.json: there, the twig annotation is the sole
 * field-level source, so losing it during migration (ADR 0007 retires the
 * front-comment once a component has a YAML) discards documentation with no
 * other home.
 *
 * Provenance (`role:`) is NOT inferred from missing acf.json alone (Codex
 * review round 5, finding 1, decided by @parisek as "option B"): the
 * absence of acf.json proves only that a value is not editor-authored — it
 * could equally be `query` (a PHP sidecar's own database read, e.g.
 * upstream tailwind-base's `pagination.yaml` `items`) or `global` (site-wide
 * options), not necessarily `parent` (passed in by the calling template).
 * A field's own twig `role:` annotation, when present, always wins (see
 * `map()`); absent that, every field falls back to the caller-supplied
 * `$assumeRole` — and if neither is given, `map()` throws rather than
 * guess, naming the field and pointing to `fields-migrate --assume-role`.
 * `$assumeRole` is restricted to `parent`/`query`/`global` (the roles valid
 * for a component with nothing ACF-backed); `field`/`inherited`/`derived`
 * are refused at construction. `role: parent` (and `query`/`global`) are
 * also the ones the schema does NOT require `type`/`label` for, but they
 * are emitted anyway wherever the twig annotation states them — dropping
 * accurate type information just because the schema does not demand it
 * would defeat the point of this migration.
 *
 * Every raw prop on a twig field is tracked as consumed or not (mirroring
 * AbstractTypeMapper's own `consumed` contract for ACF fields, per a
 * `codex-cli` review of PR #76): a prop with no semantic home in the
 * abstract schema (e.g. a typo, or a genuinely unmapped annotation key) is
 * never silently dropped — `map()` throws, naming the field's full dotted
 * path and the leftover prop names, so the author fixes the annotation (or
 * migrates the field by hand) instead of losing it with no diagnostic.
 *
 * Twig type -> abstract type table (see the accompanying pull request for the
 * full rationale of each row):
 *
 *   text        -> text
 *   textarea    -> text (multiline: true)
 *   wysiwyg     -> richtext
 *   html        -> richtext (wp.twig_type: html — same widget class as wysiwyg,
 *                  marker kept so a re-migration or an audit can tell them apart)
 *   url         -> link (shape: url)
 *   link        -> link (shape: link)
 *   email       -> text (wp.acf_type: email — mirrors AbstractTypeMapper's
 *                  own text/email collision handling)
 *   phone       -> text (wp.acf_type: phone — no abstract phone type exists;
 *                  ACF itself backs a phone with a plain text field)
 *   number      -> number
 *   boolean     -> boolean
 *   true_false  -> boolean (wp.acf_type: true_false — ACF's own type name;
 *                  `boolean` is the majority spelling in the observed corpus)
 *   select      -> select (options: comma-string -> {token: token} map, or an
 *                  already-keyed `choices:` map used verbatim; missing/empty
 *                  options, or a non-string label, throws — see `select()`)
 *   image       -> media (kind: image)
 *   file        -> media (kind: file)
 *   gallery     -> media (kind: gallery, multiple: true)
 *   video       -> media (kind: file, wp.twig_type: video — no video kind
 *                  exists in the abstract vocabulary; closest ACF-backable shape)
 *   date        -> date
 *   group       -> group (recurses into `fields:`)
 *   repeater    -> repeater (recurses into `fields:`)
 *   array       -> ALWAYS throws \DomainException, nested `fields:` or not.
 *                  Decided by @parisek on issue #75 / PR #76: `array` is
 *                  genuinely ambiguous between a single nested object
 *                  (`pagination.items.first`) and a list of them
 *                  (`article-teaser.categories`, `footer.menu_primary`), and
 *                  nothing in the annotation distinguishes the two. Mapping
 *                  it to `group` mis-describes every list-shaped field (a
 *                  categories or menu list is not a single object); guessing
 *                  from the field name's plurality is unreliable and silent.
 *                  The author must re-annotate the field as `group` (one
 *                  nested object) or `repeater` (a list) before migrating —
 *                  both remain fully supported, with nested `fields:`.
 *   post_object -> reference (no `of:` — the twig annotation never states a
 *                  target post type, so none is guessed)
 *
 * Any other twig `type:` throws \DomainException naming the field and the
 * type — never silently dropped, mirroring AbstractTypeMapper's own contract
 * for an unmapped ACF type.
 */
final class TwigFieldTypeMapper
{
    /** Props every twig field annotation may carry, regardless of type. */
    private const BASE_PROPS = ['type', 'title', 'description', 'placeholder', 'required', 'role', 'from'];

    /** The full schema `role` enum — what a twig field's own `role:` annotation may state. */
    private const SCHEMA_ROLES = ['field', 'query', 'global', 'parent', 'inherited', 'derived'];

    /**
     * Roles `--assume-role` may set: valid for a field with nothing
     * ACF-backed to project. `field` (implies acf.json backing),
     * `inherited` (framework-injected, never authored in a definition) and
     * `derived` (needs a `from:` this flag cannot supply per-field) are
     * refused here — see the class doc header.
     */
    private const ASSUMABLE_ROLES = ['parent', 'query', 'global'];

    /**
     * @param string|null $assumeRole Fallback role for a field with no
     *                                explicit twig `role:` annotation. Must be
     *                                one of ASSUMABLE_ROLES, or null (in which
     *                                case an un-annotated field's role is
     *                                ambiguous and `map()` throws).
     */
    public function __construct(private readonly ?string $assumeRole = null)
    {
        if (null !== $this->assumeRole && !in_array($this->assumeRole, self::ASSUMABLE_ROLES, true)) {
            throw new \DomainException(sprintf(
                "Invalid --assume-role '%s' — must be one of: %s.",
                $this->assumeRole,
                implode(', ', self::ASSUMABLE_ROLES),
            ));
        }
    }

    /**
     * Every type's raw-prop footprint, independent of actually building its
     * shape — used to run the leftover-unmapped-prop check (and hence throw
     * on a malformed annotation) before role resolution touches anything,
     * without first recursing into a group/repeater's children. Recursion
     * needs THIS field's own resolved role (to pass down as the fallback
     * for un-annotated children — see Codex review round 6), so it cannot
     * happen before role resolution; the leftover check must still happen
     * before it, per round 5's ordering. Splitting "which props does this
     * type consume" from "build the actual shape" is what makes both true.
     *
     * @return list<string>
     */
    private function typeConsumedProps(string $twigType): array
    {
        return match ($twigType) {
            'select' => ['options', 'choices'],
            'object', 'list', 'group', 'repeater' => ['fields'],
            default => [],
        };
    }

    /**
     * @param array<string,mixed> $twigField
     * @param string|null $inheritedRole The resolved `role:` of the field
     *                                   that recursed into this one via
     *                                   `fields:` (a group/repeater's own
     *                                   `container()` call), used as the
     *                                   fallback for a child with no
     *                                   explicit `role:` of its own — see
     *                                   the class doc header ("nested ones
     *                                   inherit"). `null` at the root
     *                                   (falls back to `$assumeRole`
     *                                   instead — see role resolution
     *                                   below).
     * @return array<string,mixed>
     */
    public function map(array $twigField, string $fieldName, ?string $inheritedRole = null): array
    {
        $twigType = (string) ($twigField['type'] ?? '');

        // Type validity (including the 'array' refusal and the unsupported-
        // type refusal) is checked here, up front, throwing exactly the
        // messages the type-building match below used to throw — but
        // before the leftover-prop check, since an unsupported `type:`
        // makes every other prop on the field moot.
        if (!in_array($twigType, ['text', 'textarea', 'wysiwyg', 'html', 'url', 'link', 'email', 'phone',
            'number', 'boolean', 'true_false', 'select', 'image', 'file', 'gallery', 'video', 'date',
            'post_object', 'object', 'list', 'group', 'repeater'], true)) {
            if ('array' === $twigType) {
                throw new \DomainException(sprintf(
                    "Field '%s' has twig type 'array', which is ambiguous between a single nested object and a "
                    . "list — re-annotate it as 'object' (one nested object) or 'list' (a list of objects) before "
                    . 'migrating.',
                    $fieldName,
                ));
            }
            throw new \DomainException(sprintf(
                "Unsupported twig field type '%s' for field '%s' — add a case to TwigFieldTypeMapper::map(), "
                . 'or migrate the field type by hand and add a `wp:` marker to preserve provenance.',
                $twigType,
                $fieldName,
            ));
        }

        // A raw twig annotation prop with no semantic home is never
        // silently dropped — see the class doc header. Checked before role
        // resolution (a malformed annotation is a more basic problem than
        // an ambiguous-but-otherwise-valid one) and before recursing into
        // any nested `fields:` (this field's own props, not its children's).
        // `required` is consumed regardless of its value (an unrecognised
        // value, e.g. `required: yes`, still occupied the slot; it simply
        // doesn't become `true`).
        $consumed = [...self::BASE_PROPS, ...$this->typeConsumedProps($twigType)];
        $leftover = array_diff(array_keys($twigField), $consumed);
        if ([] !== $leftover) {
            throw new \DomainException(sprintf(
                "Field '%s' has twig annotation prop(s) with no mapping to the abstract schema: %s. "
                . 'Add a mapping to TwigFieldTypeMapper::map(), or remove the prop from the annotation.',
                $fieldName,
                implode(', ', array_map(static fn (int|string $p): string => "'{$p}'", $leftover)),
            ));
        }

        // Codex review round 5, finding 1 (option B) + round 6: provenance
        // is not inferred from missing acf.json alone, and resolved BEFORE
        // building this field's shape — a group/repeater needs its own
        // resolved role in hand to pass down to `container()` as the
        // fallback for un-annotated children (round 6's fix: children used
        // to fall back straight to `$assumeRole`, skipping an explicit
        // parent `role:` entirely). A field's own `role:` annotation always
        // wins; absent that, `$inheritedRole` (the parent's resolved role,
        // root fields have none) applies; absent THAT, `$assumeRole`;
        // absent all three, provenance is genuinely ambiguous and this
        // reader refuses to guess.
        $role = null;
        $fromValue = null;
        if (array_key_exists('role', $twigField)) {
            $role = $twigField['role'];
            if (!is_string($role) || !in_array($role, self::SCHEMA_ROLES, true)) {
                throw new \DomainException(sprintf(
                    "Field '%s' has an invalid `role:` (%s) — must be one of: %s.",
                    $fieldName,
                    is_string($role) ? "'{$role}'" : get_debug_type($role),
                    implode(', ', self::SCHEMA_ROLES),
                ));
            }
            if ('derived' === $role) {
                $from = $twigField['from'] ?? null;
                if (!is_string($from) || '' === $from) {
                    throw new \DomainException(sprintf(
                        "Field '%s' has `role: derived` but no `from:` naming the sibling field it derives from.",
                        $fieldName,
                    ));
                }
                $fromValue = $from;
            } elseif (array_key_exists('from', $twigField)) {
                throw new \DomainException(sprintf(
                    "Field '%s' has `from:` but `role:` is not 'derived' — `from:` is only valid with role: derived.",
                    $fieldName,
                ));
            }
        } else {
            if (array_key_exists('from', $twigField)) {
                throw new \DomainException(sprintf(
                    "Field '%s' has `from:` but no `role: derived` — `from:` is only valid with role: derived.",
                    $fieldName,
                ));
            }
            // Codex review round 8: `derived` is NOT inheritable, unlike
            // every other role. It always carries its own `from:` naming a
            // SPECIFIC sibling field — that pairing is meaningless to copy
            // onto a child, which has different siblings of its own (or
            // none). Blanket-inheriting it produced a `role: derived` with
            // no `from:`, which the schema then rejects, rejecting the
            // whole component for an annotation that looked completely
            // valid. A child under a `derived` container falls back past
            // it, to `$assumeRole` (or ambiguous-provenance, same as if the
            // container had no role at all).
            $effectiveInherited = 'derived' !== $inheritedRole ? $inheritedRole : null;
            $role = $effectiveInherited ?? $this->assumeRole;
            if (null === $role) {
                throw new \DomainException(sprintf(
                    "Field '%s' has ambiguous provenance — no acf.json means it is not editor-authored, but that "
                    . "does not say whether it comes from the calling template (parent), a PHP sidecar's own "
                    . "query (query), or site-wide options (global). Annotate this field with an explicit "
                    . '`role:`, or pass `--assume-role=parent|query|global` to fields-migrate for the whole '
                    . 'component.',
                    $fieldName,
                ));
            }
        }

        $shape = match ($twigType) {
            'text' => ['type' => 'text'],
            'textarea' => ['type' => 'text', 'multiline' => true],
            'wysiwyg' => ['type' => 'richtext'],
            'html' => ['type' => 'richtext', 'wp' => ['twig_type' => 'html']],
            'url' => ['type' => 'link', 'shape' => 'url'],
            'link' => ['type' => 'link', 'shape' => 'link'],
            'email' => ['type' => 'text', 'wp' => ['acf_type' => 'email']],
            'phone' => ['type' => 'text', 'wp' => ['acf_type' => 'phone']],
            'number' => ['type' => 'number'],
            'boolean' => ['type' => 'boolean'],
            'true_false' => ['type' => 'boolean', 'wp' => ['acf_type' => 'true_false']],
            'select' => $this->select($twigField, $fieldName),
            'image' => ['type' => 'media', 'kind' => 'image'],
            'file' => ['type' => 'media', 'kind' => 'file'],
            'gallery' => ['type' => 'media', 'kind' => 'gallery', 'multiple' => true],
            'video' => ['type' => 'media', 'kind' => 'file', 'wp' => ['twig_type' => 'video']],
            'date' => ['type' => 'date'],
            'post_object' => ['type' => 'reference'],
            // The parent's OWN resolved $role (never $inheritedRole or
            // $assumeRole directly) is what a child with no explicit
            // `role:` of its own falls back to — this is the round-6 fix.
            // `group`/`repeater` are the older twig names; both write the
            // canonical `object`/`list` (#79).
            'object', 'group' => $this->container(StructuralType::OBJECT, $twigType, $twigField, $fieldName, $role),
            'list', 'repeater' => $this->container(StructuralType::LIST, $twigType, $twigField, $fieldName, $role),
        };

        $out = $shape;
        if (null !== $fromValue) {
            $out['from'] = $fromValue;
        }

        $label = $this->stringProp($twigField, 'title', $fieldName);
        if (null !== $label) {
            $out['label'] = $label;
        }
        $description = $this->stringProp($twigField, 'description', $fieldName);
        if (null !== $description) {
            $out['description'] = $description;
        }
        $placeholder = $this->stringProp($twigField, 'placeholder', $fieldName);
        if (null !== $placeholder) {
            $out['placeholder'] = $placeholder;
        }
        if (array_key_exists('required', $twigField)) {
            $required = $twigField['required'];
            if (true === $required || 1 === $required || '1' === $required) {
                $out['required'] = true;
            } elseif (false !== $required && 0 !== $required && '0' !== $required && null !== $required) {
                // Codex review round 2, finding 3: a non-canonical value
                // (`required: yes`, `required: 2`, a typo) used to be
                // silently treated as "not required" — the field then
                // migrates with the constraint dropped and no diagnostic.
                throw new \DomainException(sprintf(
                    "Field '%s' has an unrecognised `required:` value (%s) — use `1`/`true` or `0`/`false`.",
                    $fieldName,
                    var_export($required, true),
                ));
            }
        }

        $out['role'] = $role;

        return $out;
    }

    /**
     * Reads a free-text prop (`title`/`description`/`placeholder`) as a
     * string, or `null` when absent/empty. Codex review round 4, finding 2:
     * the previous `(string) ($twigField[$prop] ?? '')` cast a YAML list or
     * map straight to the literal string `"Array"` (PHP's array-to-string
     * coercion, with a warning nobody sees in a CLI run) — schema-valid,
     * silently corrupted data with no diagnostic. A non-string, non-null
     * value is now a hard error instead.
     *
     * @param array<string,mixed> $twigField
     */
    private function stringProp(array $twigField, string $prop, string $fieldName): ?string
    {
        if (!array_key_exists($prop, $twigField) || null === $twigField[$prop]) {
            return null;
        }
        $value = $twigField[$prop];
        if (!is_string($value)) {
            throw new \DomainException(sprintf(
                "Field '%s' has a `%s:` that is not a string (got %s).",
                $fieldName,
                $prop,
                get_debug_type($value),
            ));
        }
        return '' !== $value ? $value : null;
    }

    /**
     * @param array<string,mixed> $twigField
     * @return array<string,mixed>
     */
    private function select(array $twigField, string $fieldName): array
    {
        $out = ['type' => 'select'];

        $hasChoices = array_key_exists('choices', $twigField) && [] !== $twigField['choices'] && null !== $twigField['choices'];
        $hasOptions = array_key_exists('options', $twigField) && [] !== $twigField['options'] && null !== $twigField['options'] && '' !== $twigField['options'];

        // Codex review round 2, finding 2: both were previously "consumed"
        // unconditionally, so a bad or conflicting source vanished with no
        // diagnostic — `choices` silently won over a present `options`, and
        // a malformed `choices` (wrong type) silently fell through to
        // `options`. Neither is allowed to disappear quietly now: exactly
        // one representation is required, and each is validated for its
        // own shape before use.
        if ($hasChoices && $hasOptions) {
            throw new \DomainException(sprintf(
                "Field '%s' has both `options:` and `choices:` — only one select option source is allowed.",
                $fieldName,
            ));
        }

        if ($hasChoices) {
            if (!is_array($twigField['choices'])) {
                throw new \DomainException(sprintf(
                    "Field '%s' has a `choices:` that is not a map (got %s) — expected a key => label map.",
                    $fieldName,
                    get_debug_type($twigField['choices']),
                ));
            }
            // Already an ACF-shaped key => label map — used verbatim, no
            // guessing needed.
            $out['options'] = $twigField['choices'];
        } elseif ($hasOptions) {
            $rawOptions = $twigField['options'];
            if (is_array($rawOptions) && !array_is_list($rawOptions)) {
                // Codex review round 4, finding 1: an associative
                // `options:` map (`draft: Draft`) used to have its keys
                // silently discarded — the loop below only ever reads
                // values, so it re-keyed every label by itself
                // (`Draft: Draft`), losing the authored `draft` key with no
                // diagnostic. An author who wants key != label belongs on
                // `choices:`, which already carries that shape verbatim.
                throw new \DomainException(sprintf(
                    "Field '%s' has an `options:` map with its own keys (%s) — "
                    . 'a keyed option map is `choices:`, not `options:`. Rename it, or drop the keys for the '
                    . 'comma-string/list shorthand.',
                    $fieldName,
                    implode(', ', array_map(static fn (int|string $k): string => "'{$k}'", array_keys($rawOptions))),
                ));
            }
            if (is_array($rawOptions)) {
                // Codex review round 2, finding 1: a YAML sequence form
                // (`options: [a, b, c]`) used to be cast straight to string
                // — PHP's array-to-string coercion silently produced the
                // single bogus option `Array => Array`, which still passed
                // schema validation. A plain list of scalars is now read
                // the same way as the comma-string shorthand: each element
                // becomes both the option's key and its label.
                $tokens = [];
                foreach ($rawOptions as $token) {
                    // Codex review round 3, finding 2: `is_scalar()` let a
                    // YAML boolean/int/float through and cast it to string
                    // (`true` -> `'1'`, `false` -> `''` -> dropped, `2` and
                    // `2.5` -> `'2'`/`'2.5'`) — silent data loss the caller
                    // could not tell apart from a real string option. Only
                    // an actual string is accepted; anything else is a type
                    // mismatch in the annotation, not a value to coerce.
                    if (!is_string($token)) {
                        throw new \DomainException(sprintf(
                            "Field '%s' has an `options:` list entry that is not a string (got %s) — "
                            . 'every entry must be a plain string value.',
                            $fieldName,
                            get_debug_type($token),
                        ));
                    }
                    $token = trim($token);
                    if ('' !== $token) {
                        $tokens[] = $token;
                    }
                }
                $out['options'] = $this->tokensToOptions($tokens, $fieldName);
            } elseif (is_string($rawOptions)) {
                // The shorthand comma-string form (`options: a, b, c`) carries
                // no human label, only the raw values a template compares
                // against (`content.type == 'status'`) — so key and label are
                // the same token. An author refining the migrated YAML can
                // split them.
                $tokens = array_values(array_filter(
                    array_map('trim', explode(',', $rawOptions)),
                    static fn (string $t): bool => '' !== $t,
                ));
                $out['options'] = $this->tokensToOptions($tokens, $fieldName);
            } else {
                throw new \DomainException(sprintf(
                    "Field '%s' has an `options:` value that is neither a comma-separated string nor a list "
                    . '(got %s).',
                    $fieldName,
                    get_debug_type($rawOptions),
                ));
            }
        }

        // The schema requires a non-empty `options` map with string labels
        // for every `select` — an annotation missing `options`/`choices`
        // entirely, or carrying an empty one, would otherwise migrate to
        // schema-invalid YAML with no diagnostic until `fields-validate`
        // runs (or later, `fields-generate`). Fail here, where the field's
        // own name is still in scope.
        if (empty($out['options'])) {
            throw new \DomainException(sprintf(
                "Field '%s' is a twig 'select' with no (or empty) `options:`/`choices:` — "
                . 'the schema requires at least one option.',
                $fieldName,
            ));
        }

        // Codex review round 7: PHP always re-casts a numeric string array
        // key back to an int (there is no way to force `'0'` to survive as
        // a string key — `array_combine(['0'], [...])` still ends up
        // int-keyed), so a `choices:`/`options:` map whose keys are the
        // sequential integers 0, 1, 2, … from zero (e.g.
        // `choices: {0: None, 1: One}`) is indistinguishable, at the PHP
        // array level, from a genuine JSON *list* — exactly what
        // `Support\ArrayJsonModel::toJsonModel()` (shared by every
        // migration path, ACF included, not something this class can fix
        // in isolation) uses to decide list vs. object. Left unchecked,
        // this reaches FieldsSchemaValidator only at write time, failing
        // with `/fields/<x>/options: must match type: object` — a message
        // that names neither this field nor why. Caught here instead, by
        // field name, while the cause is still obvious.
        if (array_is_list($out['options'])) {
            throw new \DomainException(sprintf(
                "Field '%s' has select options keyed 0, 1, 2, … from zero (e.g. `choices: {0: ..., 1: ...}`) — "
                . 'this is indistinguishable from a plain list once written, and the schema requires an object. '
                . "Give at least one key a non-numeric form (e.g. prefix it: 'opt_0') to keep it a map.",
                $fieldName,
            ));
        }

        foreach ($out['options'] as $key => $label) {
            if (!is_string($label)) {
                throw new \DomainException(sprintf(
                    "Field '%s' has a non-string option label for choice '%s' (%s) — "
                    . 'the schema requires every option value to be a string.',
                    $fieldName,
                    (string) $key,
                    get_debug_type($label),
                ));
            }
        }

        return $out;
    }

    /**
     * Turns a flat list of already-trimmed, non-empty string tokens into a
     * key => label options map (key and label are the same token — see
     * `select()`'s own comment on why). Throws when two tokens collide,
     * rather than letting the second silently overwrite the first through
     * `array_combine()` — a real risk once list entries can carry values
     * that only look distinct before normalisation.
     *
     * @param list<string> $tokens
     * @return array<string,string>
     */
    private function tokensToOptions(array $tokens, string $fieldName): array
    {
        $options = [];
        foreach ($tokens as $token) {
            if (array_key_exists($token, $options)) {
                throw new \DomainException(sprintf(
                    "Field '%s' has a duplicate select option '%s' after trimming.",
                    $fieldName,
                    $token,
                ));
            }
            $options[$token] = $token;
        }
        return $options;
    }

    /**
     * @param array<string,mixed> $twigField
     * @return array<string,mixed>
     */
    private function container(string $type, string $twigType, array $twigField, string $fieldName, string $role): array
    {
        $children = (array) ($twigField['fields'] ?? []);
        if ([] === $children) {
            throw new \RuntimeException(sprintf(
                "Field '%s' is a twig '%s' with no nested `fields:` — the schema forbids an empty fields map.",
                $fieldName,
                $twigType,
            ));
        }

        $out = ['type' => $type];
        $mappedChildren = [];
        foreach ($children as $childName => $childField) {
            // Codex review round 6: a child with no explicit `role:` of its
            // own inherits THIS field's resolved role — not `$assumeRole`
            // directly, which would skip right past an explicit `role:`
            // this container itself carries (see the class doc header,
            // "nested ones inherit").
            $mappedChildren[(string) $childName] = $this->map((array) $childField, $fieldName . '.' . $childName, $role);
        }
        $out['fields'] = $mappedChildren;

        return $out;
    }
}
