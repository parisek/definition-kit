<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Migration;

/**
 * Translates ONE field from the twig `fields:` annotation (tailwind-base's
 * `update-fields` skill vocabulary — `title`/`type`/`required`/
 * `description`/`options`/`choices`/`fields`) into the abstract shape
 * `component.fields.schema.json` expects. Used only for a component with no
 * acf.json: there, the twig annotation is the sole field-level source, so
 * losing it during migration (ADR 0007 retires the front-comment once a
 * component has a YAML) discards documentation with no other home.
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
 *   select      -> select (options: comma-string -> {token: token} map,
 *                  or an already-keyed `choices:` map used verbatim)
 *   image       -> media (kind: image)
 *   file        -> media (kind: file)
 *   gallery     -> media (kind: gallery, multiple: true)
 *   video       -> media (kind: file, wp.twig_type: video — no video kind
 *                  exists in the abstract vocabulary; closest ACF-backable shape)
 *   date        -> date
 *   group       -> group (recurses into `fields:`)
 *   repeater    -> repeater (recurses into `fields:`)
 *   array       -> group when `fields:` is present (see the pull request's
 *                  "rejected alternatives" for why NOT repeater by default);
 *                  throws when `fields:` is absent — an `array` with no
 *                  declared shape has nothing this reader can express
 *   post_object -> reference (no `of:` — the twig annotation never states a
 *                  target post type, so none is guessed)
 *
 * Any other twig `type:` throws \DomainException naming the field and the
 * type — never silently dropped, mirroring AbstractTypeMapper's own contract
 * for an unmapped ACF type.
 */
final class TwigFieldTypeMapper
{
    /**
     * @param array<string,mixed> $twigField
     * @return array<string,mixed>
     */
    public function map(array $twigField, string $fieldName): array
    {
        $twigType = (string) ($twigField['type'] ?? '');

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
            'select' => $this->select($twigField),
            'image' => ['type' => 'media', 'kind' => 'image'],
            'file' => ['type' => 'media', 'kind' => 'file'],
            'gallery' => ['type' => 'media', 'kind' => 'gallery', 'multiple' => true],
            'video' => ['type' => 'media', 'kind' => 'file', 'wp' => ['twig_type' => 'video']],
            'date' => ['type' => 'date'],
            'post_object' => ['type' => 'reference'],
            'group' => $this->container('group', $twigField, $fieldName),
            'repeater' => $this->container('repeater', $twigField, $fieldName),
            'array' => $this->array($twigField, $fieldName),
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
        $required = $twigField['required'] ?? null;
        if (true === $required || 1 === $required || '1' === $required) {
            $out['required'] = true;
        }

        // Every field this reader touches is, by construction, passed in by
        // the calling template — see the class doc header.
        $out['role'] = 'parent';

        return $out;
    }

    /**
     * @param array<string,mixed> $twigField
     * @return array<string,mixed>
     */
    private function select(array $twigField): array
    {
        $out = ['type' => 'select'];

        if (isset($twigField['choices']) && is_array($twigField['choices'])) {
            // Already an ACF-shaped key => label map — used verbatim, no
            // guessing needed.
            $out['options'] = $twigField['choices'];
        } elseif (isset($twigField['options']) && '' !== (string) $twigField['options']) {
            // The shorthand comma-string form (`options: a, b, c`) carries no
            // human label, only the raw values a template compares against
            // (`content.type == 'status'`) — so key and label are the same
            // token. An author refining the migrated YAML can split them.
            $tokens = array_values(array_filter(
                array_map('trim', explode(',', (string) $twigField['options'])),
                static fn (string $t): bool => '' !== $t,
            ));
            $out['options'] = array_combine($tokens, $tokens);
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

    /**
     * The twig annotation's `array` type is genuinely ambiguous: it is used
     * both for a single nested object (`pagination.items.first`) and for a
     * list of them (`article-teaser.categories`), with nothing in the
     * annotation itself distinguishing the two — see the pull request's
     * design notes. This reader resolves the ambiguity towards `group`
     * (a single nested object), the structurally conservative choice: every
     * `group` round-trips through the schema whether the real data is
     * singular or repeated, while a wrongly-guessed `repeater` changes the
     * shape a consuming template would see. An author who confirms the real
     * component takes a list flips `type: group` to `type: repeater` by hand
     * — one line, checkable against the template's own `{% for %}` loop.
     *
     * `array` with no nested `fields:` (e.g. `header-menu.items`, `[]`
     * elsewhere) carries no shape at all to translate — this reader cannot
     * invent one, so it throws rather than emit a field with no meaning.
     *
     * @param array<string,mixed> $twigField
     * @return array<string,mixed>
     */
    private function array(array $twigField, string $fieldName): array
    {
        if ([] === (array) ($twigField['fields'] ?? [])) {
            throw new \RuntimeException(sprintf(
                "Field '%s' has twig type 'array' with no nested `fields:` — cannot translate an untyped array "
                . 'into an abstract shape. Add `fields:` to the annotation, or migrate this field by hand.',
                $fieldName,
            ));
        }

        $out = $this->container('group', $twigField, $fieldName);
        $out['wp'] = [...($out['wp'] ?? []), 'twig_type' => 'array'];

        return $out;
    }
}
