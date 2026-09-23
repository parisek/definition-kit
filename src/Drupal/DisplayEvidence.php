<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Drupal;

/**
 * Optional evidence for the template prop names a paragraph's fields reach,
 * read from the PHP class that builds the component's `content` array.
 *
 * Drupal field names and template prop names differ in practice
 * (`$content['button'] = $this->getLinkField($entity, 'link')`), and only that
 * PHP code records the pairing. The file is tokenized with token_get_all()
 * and never loaded or executed. Recognised shapes, inside an `if`/`elseif`
 * whose condition compares `$bundle` with string literals (`$bundle === 'x'`,
 * `in_array($bundle, ['a', 'b'])`):
 *
 *   $content['a']['b'] = $this->getXxx($entity, 'field');
 *   $var = $this->getXxx($entity, 'field');  ...  $content['a'] = $var;
 *   $items = $this->getXxx($entity, 'paragraphs');
 *   foreach ($items as $item) {
 *     $content['items'][] = ['name' => $this->getXxx($item, 'title'), ...];
 *   }
 *
 * The same shapes outside any bundle branch apply to every bundle ("shared
 * fields"); a bundle's own branch wins over them. The field argument is the
 * name without its `field_` prefix, as the project's getters take it. Anything
 * else (queries, Views, conditionals on the value) yields no evidence, and the
 * migration falls back to the naming convention for that field.
 *
 * @phpstan-type Entry array{path: list<string>, field: string, children: list<array{path: list<string>, field: string}>}
 */
final class DisplayEvidence
{
    private const SHARED = '*';

    /** @var array<string,list<Entry>> bundle (or SHARED) => entries, first mapping per path wins */
    private array $entries = [];

    /** @var list<array{int,string}> */
    private array $tokens = [];

    /** @var array<string,array{field: string, entity: string}> variable => the getter call it holds */
    private array $origins = [];

    /** @var array<string,string> loop variable => iterated variable */
    private array $loops = [];

    private function __construct()
    {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Display class not found: {$path}");
        }

        return self::fromSource((string) file_get_contents($path));
    }

    public static function fromSource(string $source): self
    {
        $self = new self();
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true)) {
                    continue;
                }
                $self->tokens[] = [$token[0], $token[1]];
            } else {
                $self->tokens[] = [0, $token];
            }
        }
        $self->scan($self->branches());

        return $self;
    }

    /** @return list<string> bundles with a branch of their own */
    public function bundles(): array
    {
        return array_values(array_filter(array_keys($this->entries), static fn (string $b): bool => self::SHARED !== $b));
    }

    /**
     * Evidence for one bundle: its own branch first, then the shared entries
     * whose path and field the branch does not already map.
     *
     * @return list<Entry>
     */
    public function forBundle(string $bundle): array
    {
        $own = $this->entries[$bundle] ?? [];
        $paths = array_map(static fn (array $e): string => implode('.', $e['path']), $own);
        $fields = array_column($own, 'field');
        foreach ($this->entries[self::SHARED] ?? [] as $entry) {
            if (in_array(implode('.', $entry['path']), $paths, true) || in_array($entry['field'], $fields, true)) {
                continue;
            }
            $own[] = $entry;
        }

        return $own;
    }

    /**
     * Bundle branches: token range of the block body => bundle names.
     *
     * @return list<array{start: int, end: int, bundles: list<string>}>
     */
    private function branches(): array
    {
        $branches = [];
        $count = count($this->tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!in_array($this->tokens[$i][0], [T_IF, T_ELSEIF], true) || '(' !== ($this->tokens[$i + 1][1] ?? null)) {
                continue;
            }
            $close = $this->matching($i + 1);
            $mentionsBundle = false;
            $names = [];
            for ($j = $i + 2; $j < $close; $j++) {
                if (T_VARIABLE === $this->tokens[$j][0] && '$bundle' === $this->tokens[$j][1]) {
                    $mentionsBundle = true;
                }
                if (T_CONSTANT_ENCAPSED_STRING === $this->tokens[$j][0]) {
                    $names[] = $this->unquote($this->tokens[$j][1]);
                }
            }
            if (!$mentionsBundle || [] === $names || '{' !== ($this->tokens[$close + 1][1] ?? null)) {
                continue;
            }
            $branches[] = ['start' => $close + 1, 'end' => $this->matching($close + 1), 'bundles' => $names];
        }

        return $branches;
    }

    /** @param list<array{start: int, end: int, bundles: list<string>}> $branches */
    private function scan(array $branches): void
    {
        $count = count($this->tokens);
        for ($i = 0; $i < $count; $i++) {
            [$id, $text] = $this->tokens[$i];
            if (T_FOREACH === $id) {
                $this->readForeach($i);
                continue;
            }
            if (T_VARIABLE !== $id || '$this' === $text) {
                continue;
            }
            $bundles = [self::SHARED];
            foreach ($branches as $branch) {
                if ($i > $branch['start'] && $i < $branch['end']) {
                    $bundles = $branch['bundles'];
                }
            }
            if ('$content' === $text) {
                $this->readContentAssignment($i, $bundles);
            } else {
                $this->readVariableAssignment($i);
            }
        }
    }

    private function readForeach(int $i): void
    {
        if ('(' !== ($this->tokens[$i + 1][1] ?? null)) {
            return;
        }
        $close = $this->matching($i + 1);
        $vars = [];
        for ($j = $i + 2; $j < $close; $j++) {
            if (T_VARIABLE === $this->tokens[$j][0]) {
                $vars[] = $this->tokens[$j][1];
            }
        }
        if (count($vars) >= 2) {
            $this->loops[$vars[count($vars) - 1]] = $vars[0];
        }
    }

    /** @param list<string> $bundles */
    private function readContentAssignment(int $i, array $bundles): void
    {
        $keys = [];
        $append = false;
        $j = $i + 1;
        while ('[' === ($this->tokens[$j][1] ?? null)) {
            $next = $this->tokens[$j + 1] ?? [0, ''];
            if (T_CONSTANT_ENCAPSED_STRING === $next[0] && ']' === ($this->tokens[$j + 2][1] ?? null)) {
                $keys[] = $this->unquote($next[1]);
                $j += 3;
                continue;
            }
            if (']' === $next[1]) {
                $append = true;
                $j += 2;
                break;
            }

            return;
        }
        if ([] === $keys || '=' !== ($this->tokens[$j][1] ?? null)) {
            return;
        }
        $rhs = $j + 1;

        $call = $this->getterCall($rhs);
        if (null !== $call) {
            if ('$entity' === $call['entity'] && !$append) {
                $this->record($bundles, ['path' => $keys, 'field' => $call['field'], 'children' => []]);
            }

            return;
        }

        if ('[' === ($this->tokens[$rhs][1] ?? null)) {
            $this->readArrayLiteral($rhs, $keys, $append, $bundles);

            return;
        }

        if (T_VARIABLE === $this->tokens[$rhs][0]) {
            $origin = $this->origins[$this->tokens[$rhs][1]] ?? null;
            if (null !== $origin && '$entity' === $origin['entity'] && !$append) {
                $this->record($bundles, ['path' => $keys, 'field' => $origin['field'], 'children' => []]);
            }
        }
    }

    /**
     * @param list<string> $keys
     * @param list<string> $bundles
     */
    private function readArrayLiteral(int $open, array $keys, bool $append, array $bundles): void
    {
        $close = $this->matching($open);
        $children = [];
        $listField = null;
        for ($j = $open + 1; $j < $close; $j++) {
            $token = $this->tokens[$j];
            if ('[' === $token[1] || '(' === $token[1]) {
                // Call arguments and nested arrays: an entry of this level was
                // already read at its key, and a nested array yields no evidence.
                $j = $this->matching($j);
                continue;
            }
            if (T_CONSTANT_ENCAPSED_STRING !== $token[0] || T_DOUBLE_ARROW !== ($this->tokens[$j + 1][0] ?? null)) {
                continue;
            }
            $key = $this->unquote($token[1]);
            $call = $this->getterCall($j + 2);
            if (null === $call) {
                continue;
            }
            if ('$entity' === $call['entity'] && !$append) {
                $this->record($bundles, ['path' => [...$keys, $key], 'field' => $call['field'], 'children' => []]);
                continue;
            }
            $iterated = $this->loops[$call['entity']] ?? null;
            $origin = null !== $iterated ? ($this->origins[$iterated] ?? null) : null;
            if ($append && null !== $origin && '$entity' === $origin['entity']) {
                $listField = $origin['field'];
                $children[] = ['path' => [$key], 'field' => $call['field']];
            }
        }
        if (null !== $listField) {
            $this->record($bundles, ['path' => $keys, 'field' => $listField, 'children' => $children]);
        }
    }

    private function readVariableAssignment(int $i): void
    {
        $variable = $this->tokens[$i][1];
        if ('=' !== ($this->tokens[$i + 1][1] ?? null)) {
            return;
        }
        $call = $this->getterCall($i + 2);
        if (null !== $call) {
            $this->origins[$variable] = ['field' => $call['field'], 'entity' => $call['entity']];
        } else {
            // Reassigned to something else: the old pairing no longer holds.
            unset($this->origins[$variable]);
        }
    }

    /**
     * `$this->getXxx($var, 'name'` starting at $i.
     *
     * @return array{entity: string, field: string}|null
     */
    private function getterCall(int $i): ?array
    {
        $t = fn (int $k): array => $this->tokens[$i + $k] ?? [0, ''];
        if (T_VARIABLE !== $t(0)[0] || '$this' !== $t(0)[1]
            || T_OBJECT_OPERATOR !== $t(1)[0]
            || T_STRING !== $t(2)[0] || !str_starts_with($t(2)[1], 'get')
            || '(' !== $t(3)[1]
            || T_VARIABLE !== $t(4)[0]
            || ',' !== $t(5)[1]
            || T_CONSTANT_ENCAPSED_STRING !== $t(6)[0]) {
            return null;
        }

        return ['entity' => $t(4)[1], 'field' => $this->unquote($t(6)[1])];
    }

    /**
     * @param list<string> $bundles
     * @param Entry $entry
     */
    private function record(array $bundles, array $entry): void
    {
        foreach ($bundles as $bundle) {
            foreach ($this->entries[$bundle] ?? [] as $existing) {
                if ($existing['path'] === $entry['path']) {
                    continue 2;
                }
            }
            $this->entries[$bundle][] = $entry;
        }
    }

    private function matching(int $open): int
    {
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];
        $openText = $this->tokens[$open][1];
        $closeText = $pairs[$openText] ?? null;
        if (null === $closeText) {
            return $open;
        }
        $depth = 0;
        $count = count($this->tokens);
        for ($j = $open; $j < $count; $j++) {
            $text = $this->tokens[$j][1];
            // `{$var}` in strings and `${` open with their own token ids.
            if ($text === $openText || ('{' === $openText && in_array($this->tokens[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($text === $closeText) {
                $depth--;
                if (0 === $depth) {
                    return $j;
                }
            }
        }

        return $count - 1;
    }

    private function unquote(string $literal): string
    {
        return stripcslashes(substr($literal, 1, -1));
    }
}
