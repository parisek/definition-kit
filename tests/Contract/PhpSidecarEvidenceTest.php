<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Contract\PhpSidecarEvidence;

/**
 * Every case here is a shape taken from a real sidecar in the mairateam
 * corpus. The first version of this class classified none of them — it
 * recognised only `$content['x'] = <something with a marker in it>`, which is
 * the shape nobody actually writes.
 */
final class PhpSidecarEvidenceTest extends TestCase
{
    /** @return array<string,?string> */
    private function evidence(string $php): array
    {
        $path = sys_get_temp_dir() . '/sidecar-' . uniqid('', true) . '.php';
        file_put_contents($path, $php);

        try {
            return (new PhpSidecarEvidence())->evidence($path);
        } finally {
            unlink($path);
        }
    }

    public function testEvidenceCarriesAcrossAForeachOverAQueryResult(): void
    {
        $evidence = $this->evidence(<<<'PHP'
        <?php
        $post_query = Timber::get_posts($query_args);
        $content['items'] = array();
        foreach ($post_query as $post) {
            $item = Helpers::formatFields($post);
            $content['items'][] = $item;
        }
        PHP);

        self::assertSame('query', $evidence['items']);
    }

    public function testAnAppendIsAnAssignment(): void
    {
        // `$content['categories'][] = …` — the prop did not appear in the
        // evidence at all until the parser stopped insisting on `] =`.
        $evidence = $this->evidence(<<<'PHP'
        <?php
        $categories = get_categories(array('hide_empty' => true));
        foreach ($categories as $category) {
            $content['categories'][] = array('title' => $category->name);
        }
        PHP);

        self::assertSame('query', $evidence['categories']);
    }

    public function testEvidenceCarriesThroughTwoVariables(): void
    {
        $evidence = $this->evidence(<<<'PHP'
        <?php
        $post_query = Timber::get_posts($args);
        $pagination = $post_query->pagination(array('show_all' => true));
        $content['pagination'] = Helpers::pagination($pagination);
        PHP);

        self::assertSame('query', $evidence['pagination']);
    }

    public function testFormatFieldsOnAPostIsNotGlobal(): void
    {
        // `formatFields` is timber-kit's field formatter, called on a post far
        // more often than on options. Treating the name itself as a global
        // marker made every query-built row in a real sidecar come back
        // `global`.
        $evidence = $this->evidence(<<<'PHP'
        <?php
        $query = new WP_Query($args);
        foreach ($query as $post) {
            $content['items'][] = Helpers::formatFields($post);
        }
        PHP);

        self::assertSame('query', $evidence['items']);
    }

    public function testFormatFieldsOnOptionsIsGlobal(): void
    {
        $evidence = $this->evidence(<<<'PHP'
        <?php
        $content['contact_socials'] = Helpers::formatFields('option');
        PHP);

        self::assertSame('global', $evidence['contact_socials']);
    }

    public function testADynamicAssignmentKeyIsNotEvidenceOfAnything(): void
    {
        // contact.php, verbatim in shape. Which props this assigns depends on
        // runtime data, so nothing can be attributed — and nothing is.
        $evidence = $this->evidence(<<<'PHP'
        <?php
        if (isset($content['contact']) && is_array($content['contact'])) {
            foreach ($content['contact'] as $key => $value) {
                $content[$key] = $value;
            }
        }
        PHP);

        self::assertSame([], $evidence);
    }

    public function testAnAssignmentWithNoRecognisableSourceStaysUnclassified(): void
    {
        $evidence = $this->evidence("<?php\n\$content['total'] = 1 + 2;\n");

        self::assertArrayHasKey('total', $evidence);
        self::assertNull($evidence['total']);
    }
}
