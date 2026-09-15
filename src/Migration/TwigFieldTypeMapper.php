<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Migration;

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
 * Every field this reader can attribute to the calling template rather than
 * to the CMS gets `role: parent` (issue #14's "source: parent" axis) — a
 * component with no acf.json has, by definition, nothing an editor fills in;
 * every one of these values is passed in by whoever calls `component_*()`.
 * `role: parent` also happens to be the one role the schema does NOT require
 * `type`/`label` for, but they are emitted anyway wherever the twig
 * annotation states them — dropping accurate type information just because
 * the schema does not demand it would defeat the point of this migration.
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
    private const BASE_PROPS = ['type', 'title', 'description', 'placeholder', 'required'];

    /**
     * @param array<string,mixed> $twigField
     * @return array<string,mixed>
     */
    public function map(array $twigField, string $fieldName): array
    {
        $twigType = (string) ($twigField['type'] ?? '');

        [$shape, $typeConsumed] = match ($twigType) {
            'text' => [['type' => 'text'], []],
            'textarea' => [['type' => 'text', 'multiline' => true], []],
            'wysiwyg' => [['type' => 'richtext'], []],
            'html' => [['type' => 'richtext', 'wp' => ['twig_type' => 'html']], []],
            'url' => [['type' => 'link', 'shape' => 'url'], []],
            'link' => [['type' => 'link', 'shape' => 'link'], []],
            'email' => [['type' => 'text', 'wp' => ['acf_type' => 'email']], []],
            'phone' => [['type' => 'text', 'wp' => ['acf_type' => 'phone']], []],
            'number' => [['type' => 'number'], []],
            'boolean' => [['type' => 'boolean'], []],
            'true_false' => [['type' => 'boolean', 'wp' => ['acf_type' => 'true_false']], []],
            'select' => [$this->select($twigField, $fieldName), ['options', 'choices']],
            'image' => [['type' => 'media', 'kind' => 'image'], []],
            'file' => [['type' => 'media', 'kind' => 'file'], []],
            'gallery' => [['type' => 'media', 'kind' => 'gallery', 'multiple' => true], []],
            'video' => [['type' => 'media', 'kind' => 'file', 'wp' => ['twig_type' => 'video']], []],
            'date' => [['type' => 'date'], []],
            'post_object' => [['type' => 'reference'], []],
            'group' => [$this->container('group', $twigField, $fieldName), ['fields']],
            'repeater' => [$this->container('repeater', $twigField, $fieldName), ['fields']],
            'array' => throw new \DomainException(sprintf(
                "Field '%s' has twig type 'array', which is ambiguous between a single nested object and a list — "
                . "re-annotate it as 'group' (one nested object) or 'repeater' (a list) before migrating.",
                $fieldName,
            )),
            default => throw new \DomainException(sprintf(
                "Unsupported twig field type '%s' for field '%s' — add a case to TwigFieldTypeMapper::map(), "
                . 'or migrate the field type by hand and add a `wp:` marker to preserve provenance.',
                $twigType,
                $fieldName,
            )),
        };

        $out = $shape;

        if ('' !== (string) ($twigField['title'] ?? '')) {
            $out['label'] = (string) $twigField['title'];
        }
        if ('' !== (string) ($twigField['description'] ?? '')) {
            $out['description'] = (string) $twigField['description'];
        }
        if ('' !== (string) ($twigField['placeholder'] ?? '')) {
            $out['placeholder'] = (string) $twigField['placeholder'];
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

        // Every field this reader touches is, by construction, passed in by
        // the calling template — see the class doc header.
        $out['role'] = 'parent';

        // A raw twig annotation prop with no semantic home above is never
        // silently dropped — see the class doc header. `required` is
        // consumed regardless of its value (an unrecognised value, e.g.
        // `required: yes`, still occupied the slot; it simply doesn't
        // become `true`).
        $consumed = [...self::BASE_PROPS, ...$typeConsumed];
        $leftover = array_diff(array_keys($twigField), $consumed);
        if ([] !== $leftover) {
            throw new \DomainException(sprintf(
                "Field '%s' has twig annotation prop(s) with no mapping to the abstract schema: %s. "
                . 'Add a mapping to TwigFieldTypeMapper::map(), or remove the prop from the annotation.',
                $fieldName,
                implode(', ', array_map(static fn (int|string $p): string => "'{$p}'", $leftover)),
            ));
        }

        return $out;
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
                    if (!is_scalar($token)) {
                        throw new \DomainException(sprintf(
                            "Field '%s' has an `options:` list entry that is not a scalar (got %s) — "
                            . 'every entry must be a plain string value.',
                            $fieldName,
                            get_debug_type($token),
                        ));
                    }
                    $token = trim((string) $token);
                    if ('' !== $token) {
                        $tokens[] = $token;
                    }
                }
                $out['options'] = array_combine($tokens, $tokens);
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
                $out['options'] = array_combine($tokens, $tokens);
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
     * @param array<string,mixed> $twigField
     * @return array<string,mixed>
     */
    private function container(string $type, array $twigField, string $fieldName): array
    {
        $children = (array) ($twigField['fields'] ?? []);
        if ([] === $children) {
            throw new \RuntimeException(sprintf(
                "Field '%s' is a twig '%s' with no nested `fields:` — the schema forbids an empty fields map.",
                $fieldName,
                $type,
            ));
        }

        $out = ['type' => $type];
        $mappedChildren = [];
        foreach ($children as $childName => $childField) {
            $mappedChildren[(string) $childName] = $this->map((array) $childField, $fieldName . '.' . $childName);
        }
        $out['fields'] = $mappedChildren;

        return $out;
    }
}
