<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Parisek\DefinitionKit\Migration\TwigMetadataReader;

final class TwigMetadataReaderTest extends TestCase
{
    public function test_reads_root_metadata_keys(): void
    {
        $twig = <<<'TWIG'
            {#
            name: Service - feature
            usage: homepage-v2
            category: Gutenberg
            render: bleed
            asana: "https://app.asana.com/1/311854867024856/task/1215711477693484"
            fields:
              heading: {}
            #}
            <div></div>
            TWIG;

        $meta = (new TwigMetadataReader())->read($twig);

        self::assertSame([
            'name' => 'Service - feature',
            'usage' => 'homepage-v2',
            'category' => 'Gutenberg',
            'render' => 'bleed',
            'asana' => 'https://app.asana.com/1/311854867024856/task/1215711477693484',
        ], $meta);
    }

    public function test_stops_at_fields_line_and_never_reads_field_annotation_lines(): void
    {
        $twig = <<<'TWIG'
            {#
            name: Demo
            fields:
              title:
                type: text
                description: "Should never leak into root metadata"
            #}
            TWIG;

        $meta = (new TwigMetadataReader())->read($twig);

        self::assertSame(['name' => 'Demo'], $meta);
    }

    public function test_returns_empty_array_when_no_front_comment(): void
    {
        self::assertSame([], (new TwigMetadataReader())->read('<div>no comment</div>'));
    }

    public function test_ignores_lines_with_empty_values(): void
    {
        $twig = "{#\nname: Demo\nweb:\nfields:\n#}\n";
        self::assertSame(['name' => 'Demo'], (new TwigMetadataReader())->read($twig));
    }

    public function test_strips_surrounding_double_quotes(): void
    {
        $twig = "{#\nname: \"Quoted Name\"\nfields:\n#}\n";
        self::assertSame(['name' => 'Quoted Name'], (new TwigMetadataReader())->read($twig));
    }
}
