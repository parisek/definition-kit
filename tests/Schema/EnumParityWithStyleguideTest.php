<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Schema;

use Parisek\Styleguide\ComponentParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The component metadata enums exist in two places by necessity, and this test
 * is what keeps them one truth rather than two.
 *
 * `parisek/styleguide` owns the runtime meaning: `ComponentParser` normalises a
 * `render:` / `kind:` value read from a template and its CLI linter reports an
 * unrecognised one. This package owns the authored contract: the same values as
 * a JSON-Schema `enum` validating `<name>.yaml`. Neither can be dropped — one
 * guards the twig front-comment, the other the definition file, and ADR 0007
 * has both formats coexisting through the migration.
 *
 * What must not happen is the two drifting apart in silence, which is precisely
 * what a comment saying "mirrors ComponentParser::RENDER_MODES" buys you:
 * mirroring with no enforcement. Hence a real dependency (`require-dev`, so it
 * never reaches a consumer's production vendor/) and a failing test.
 *
 * If this fails, do not edit the schema to match. Decide which side is right,
 * change that one, release it, then bump the constraint here.
 */
final class EnumParityWithStyleguideTest extends TestCase
{
    /** @return array<string, mixed> */
    private function schema(): array
    {
        $path = dirname(__DIR__, 2) . '/schemas/component.fields.schema.json';
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    #[Test]
    public function kind_enum_matches_the_styleguide_runtime(): void
    {
        self::assertSame(
            ComponentParser::KIND_VALUES,
            $this->schema()['properties']['kind']['enum'],
            'The schema and parisek/styleguide disagree about the valid `kind` values. '
            . 'A definition would validate here and normalise to "" at render time, or vice versa.'
        );
    }

    #[Test]
    public function render_enum_matches_the_styleguide_runtime(): void
    {
        self::assertSame(
            ComponentParser::RENDER_MODES,
            $this->schema()['properties']['render']['enum'],
            'The schema and parisek/styleguide disagree about the valid `render` modes. '
            . 'A definition would validate here and silently fall back to "inset" at render time.'
        );
    }
}
