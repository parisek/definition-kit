<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Migration\WpmlTranslatableMapper;

final class WpmlTranslatableMapperTest extends TestCase
{
    private WpmlTranslatableMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new WpmlTranslatableMapper();
    }

    public function test_leaf_translatable_true_reverses_to_two(): void
    {
        self::assertSame(2, $this->mapper->toWpmlPreference('text', true));
    }

    public function test_leaf_translatable_false_reverses_to_one(): void
    {
        self::assertSame(1, $this->mapper->toWpmlPreference('text', false));
    }

    public function test_container_always_reverses_to_three(): void
    {
        self::assertSame(3, $this->mapper->toWpmlPreference('group', true));
        self::assertSame(3, $this->mapper->toWpmlPreference('repeater', false));
    }

    public function test_forward_then_reverse_round_trips_for_every_canonical_shape(): void
    {
        foreach (['text' => [1, false], 'number' => [2, true]] as $type => [$wpml, $translatable]) {
            self::assertTrue($this->mapper->isCanonical($type, $wpml));
            self::assertSame($translatable, $this->mapper->translatable($type, $wpml));
            self::assertSame($wpml, $this->mapper->toWpmlPreference($type, $translatable));
        }
    }
}
