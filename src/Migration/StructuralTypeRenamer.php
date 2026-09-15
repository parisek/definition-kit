<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Migration;

use Parisek\DefinitionKit\Support\StructuralType;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Rewrites `type: group` to `type: object` and `type: repeater` to
 * `type: list` in a definition's YAML source (issue #79).
 *
 * The rewrite is textual, so comments, blank lines, quoting and key order
 * survive. Only the value of a `type:` line changes. A field named `group`, a
 * `wp.acf_type: repeater` marker or a description that mentions a repeater is
 * never touched.
 *
 * A textual match can still land in the wrong place: a `type: group` line
 * inside a block scalar is text, not a key. The parser is the judge. Each
 * candidate line is kept only when the parsed result still normalizes to the
 * same tree as the original, and the final result must parse to exactly the
 * normalized original. A definition that cannot reach that state (a
 * flow-style `{ type: group }`, for example) is refused, not half-rewritten.
 */
final class StructuralTypeRenamer
{
    private const LINE = '/^(\s*(?:-\s+)?type:[ \t]+)([\'"]?)(group|repeater)\2([ \t]*(?:#.*)?)$/';

    /**
     * @return array{source: string, renamed: int}
     *
     * @throws \RuntimeException when the source does not parse, or when an
     *     alias survives that a line rewrite cannot reach
     */
    public function rename(string $source): array
    {
        $original = $this->parse($source);
        $target = is_array($original) ? StructuralType::normalize($original) : $original;
        if ($target === $original) {
            return ['source' => $source, 'renamed' => 0];
        }

        $lines = preg_split('/(?<=\n)/', $source) ?: [];
        $renamed = 0;
        foreach ($lines as $i => $line) {
            $eol = '';
            $body = $line;
            if (preg_match('/(\r?\n)$/', $line, $m)) {
                $eol = $m[1];
                $body = substr($line, 0, -strlen($eol));
            }
            if (!preg_match(self::LINE, $body, $parts)) {
                continue;
            }
            $candidate = $lines;
            $candidate[$i] = $parts[1] . $parts[2] . StructuralType::canonical($parts[3]) . $parts[2] . $parts[4] . $eol;
            $parsed = $this->parse(implode('', $candidate));
            if (!is_array($parsed) || StructuralType::normalize($parsed) !== $target) {
                continue;
            }
            $lines = $candidate;
            $renamed++;
        }

        $result = implode('', $lines);
        if ($this->parse($result) !== $target) {
            throw new \RuntimeException(
                'a `type: group` or `type: repeater` could not be rewritten line by line '
                . '(flow-style mapping or multi-line value) — rename it by hand',
            );
        }

        return ['source' => $result, 'renamed' => $renamed];
    }

    private function parse(string $source): mixed
    {
        try {
            return Yaml::parse($source);
        } catch (ParseException $e) {
            throw new \RuntimeException('malformed YAML: ' . $e->getMessage(), 0, $e);
        }
    }
}
