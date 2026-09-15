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

    public function test_read_fields_returns_empty_array_without_a_fields_line(): void
    {
        $twig = "{#\nname: Demo\n#}\n";
        self::assertSame([], (new TwigMetadataReader())->readFields($twig));
    }

    public function test_read_fields_returns_empty_array_without_a_front_comment(): void
    {
        self::assertSame([], (new TwigMetadataReader())->readFields('<div>no comment</div>'));
    }

    public function test_read_fields_parses_the_tab_indented_annotation(): void
    {
        // Tab-indented, exactly as tailwind-base's update-fields skill emits it.
        $twig = "{#\nname: Button\nfields:\n\turl:\n\t\ttitle: Url\n\t\ttype: url\n\t\trequired: 1\n\ttarget:\n\t\ttitle: Target\n\t\ttype: select\n\t\toptions: _blank, _self\n#}\n";

        $fields = (new TwigMetadataReader())->readFields($twig);

        self::assertSame([
            'url' => ['title' => 'Url', 'type' => 'url', 'required' => 1],
            'target' => ['title' => 'Target', 'type' => 'select', 'options' => '_blank, _self'],
        ], $fields);
    }

    public function test_read_fields_parses_a_nested_fields_block(): void
    {
        $twig = "{#\nname: Article Teaser\nfields:\n\tcategories:\n\t\ttitle: Categories\n\t\ttype: array\n\t\tfields:\n\t\t\turl:\n\t\t\t\ttitle: Url\n\t\t\t\ttype: url\n#}\n";

        $fields = (new TwigMetadataReader())->readFields($twig);

        self::assertSame([
            'categories' => [
                'title' => 'Categories',
                'type' => 'array',
                'fields' => [
                    'url' => ['title' => 'Url', 'type' => 'url'],
                ],
            ],
        ], $fields);
    }

    public function test_read_fields_throws_on_invalid_yaml(): void
    {
        $twig = "{#\nname: Demo\nfields:\n\tbroken: [unterminated\n#}\n";

        $this->expectException(\Parisek\DefinitionKit\Migration\MigrationValidationException::class);
        (new TwigMetadataReader())->readFields($twig);
    }
}
