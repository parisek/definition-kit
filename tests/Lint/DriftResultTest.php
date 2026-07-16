<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Lint;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Lint\DriftResult;

final class DriftResultTest extends TestCase
{
    public function test_clean_result_has_no_drift_and_no_error(): void
    {
        $result = DriftResult::clean('service-feature');
        self::assertTrue($result->clean);
        self::assertSame([], $result->acfDrift);
        self::assertSame([], $result->blockDrift);
        self::assertNull($result->error);
    }

    public function test_drift_result_is_not_clean_and_carries_both_diff_lists(): void
    {
        $result = DriftResult::drift('service-feature', ['.label: expected "A", got "B"'], ['.title: expected "A", got "B"']);
        self::assertFalse($result->clean);
        self::assertSame(['.label: expected "A", got "B"'], $result->acfDrift);
        self::assertSame(['.title: expected "A", got "B"'], $result->blockDrift);
        self::assertNull($result->error);
    }

    public function test_error_result_is_not_clean_and_carries_a_message(): void
    {
        $result = DriftResult::error('service-feature', 'invalid definition: ...');
        self::assertFalse($result->clean);
        self::assertSame('invalid definition: ...', $result->error);
    }
}
