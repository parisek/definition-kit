<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Baseline;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Baseline\FrameworkProps;

final class FrameworkPropsTest extends TestCase
{
    public function testShippedBaselineCarriesTheThreeInjectedProps(): void
    {
        // Pinned by name. The check treats these as declared for every
        // component, so growing or shrinking the list changes what a contract
        // violation IS — that should never happen as a side effect of an edit.
        self::assertSame(
            ['wrapper_id', 'wrapper_classes', 'is_preview'],
            (new FrameworkProps())->contentProps(),
        );
    }

    public function testIsFrameworkPropAnswersOnBarePropNames(): void
    {
        $props = new FrameworkProps();

        self::assertTrue($props->isFrameworkProp('wrapper_classes'));
        self::assertFalse($props->isFrameworkProp('title'));
        // Not a dotted path — the check compares against a `fields:` map,
        // whose keys are bare names.
        self::assertFalse($props->isFrameworkProp('content.wrapper_classes'));
    }

    public function testTimberContextGlobalsAreOutOfScope(): void
    {
        // `homeUrl` / `header.*` / `footer.*` reach a template through
        // Timber::context() at the Twig root, not through the block render
        // pipeline under `content`. Equally ambient, different mechanism —
        // the baseline says so in its header, and this pins it.
        $props = new FrameworkProps();

        foreach (['homeUrl', 'header', 'footer'] as $global) {
            self::assertFalse($props->isFrameworkProp($global), $global);
        }
    }

    public function testMalformedBaselineIsRejectedRatherThanSilentlyEmpty(): void
    {
        $path = sys_get_temp_dir() . '/framework-props-' . uniqid('', true) . '.yaml';
        file_put_contents($path, "globals:\n  - homeUrl\n");

        try {
            $this->expectException(\RuntimeException::class);
            new FrameworkProps($path);
        } finally {
            unlink($path);
        }
    }
}
