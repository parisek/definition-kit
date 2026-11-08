<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Generator\Sdc;

use Parisek\DefinitionKit\Generator\Sdc\SdcComponentGenerator;
use PHPUnit\Framework\TestCase;

final class SdcComponentGeneratorTest extends TestCase
{
    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private function generate(array $fields, SdcComponentGenerator $generator = new SdcComponentGenerator()): array
    {
        return $generator->generate(['name' => 'Demo', 'category' => 'Block', 'fields' => $fields], 'demo');
    }

    public function testHeaderAndScalarTypes(): void
    {
        $meta = $this->generate([
            'title' => ['type' => 'text', 'label' => 'Title', 'required' => true, 'maxlength' => 80],
            'count' => ['type' => 'number', 'min' => 1, 'max' => 9],
            'on' => ['type' => 'boolean'],
            'when' => ['type' => 'date'],
        ]);

        $this->assertSame('Demo', $meta['name']);
        $this->assertSame('Block', $meta['group']);
        $this->assertSame(SdcComponentGenerator::SCHEMA_URL, $meta['$schema']);
        $props = $meta['props']['properties'];
        $this->assertSame(['title' => 'Title', 'type' => 'string', 'maxLength' => 80], $props['title']);
        $this->assertSame(['type' => 'number', 'minimum' => 1, 'maximum' => 9], $props['count']);
        $this->assertSame('boolean', $props['on']['type']);
        $this->assertSame('date-time', $props['when']['format']);
        $this->assertSame(['title'], $meta['props']['required']);
    }

    public function testRichtextIsASlot(): void
    {
        $meta = $this->generate(['text' => ['type' => 'richtext', 'label' => 'Text']]);

        $this->assertArrayHasKey('text', $meta['slots']);
        $this->assertArrayNotHasKey('text', (array) $meta['props']['properties']);
    }

    public function testSelectBecomesAnEnum(): void
    {
        $meta = $this->generate(['side' => ['type' => 'select', 'options' => ['left' => 'Left', 'right' => 'Right']]]);

        $this->assertSame(['left', 'right'], $meta['props']['properties']['side']['enum']);
        $this->assertSame(['left' => 'Left', 'right' => 'Right'], $meta['props']['properties']['side']['meta:enum']);
    }

    public function testMediaIsAlwaysAListAndAGalleryIsAListOfLists(): void
    {
        $meta = $this->generate([
            'image' => ['type' => 'media', 'kind' => 'image'],
            'photos' => ['type' => 'media', 'kind' => 'gallery'],
        ]);

        $image = $meta['props']['properties']['image'];
        $this->assertSame('array', $image['type']);
        $this->assertSame('object', $image['items']['type']);
        $this->assertSame(['string', 'null'], $image['items']['properties']['alt']['type']);
        $photos = $meta['props']['properties']['photos'];
        $this->assertSame('array', $photos['items']['type']);
    }

    public function testObjectAndListNest(): void
    {
        $meta = $this->generate([
            'heading' => ['type' => 'object', 'fields' => ['title' => ['type' => 'text', 'required' => true]]],
            'items' => ['type' => 'list', 'fields' => ['label' => ['type' => 'text']]],
        ]);

        $heading = $meta['props']['properties']['heading'];
        $this->assertSame(['title'], $heading['required']);
        $items = $meta['props']['properties']['items'];
        $this->assertSame('array', $items['type']);
        $this->assertSame('string', $items['items']['properties']['label']['type']);
    }

    public function testLooseSchemasKeepATypeAndAreReported(): void
    {
        $generator = new SdcComponentGenerator();
        $meta = $this->generate([
            'form' => ['type' => 'reference'],
            'teasers' => ['type' => 'list', 'of' => 'component:teaser'],
        ], $generator);

        // Core SDC refuses a prop with no type.
        $this->assertIsArray($meta['props']['properties']['form']['type']);
        $this->assertCount(2, $generator->notes);
    }

    public function testInheritedFieldsAreSkipped(): void
    {
        $meta = $this->generate(['classes' => ['type' => 'text', 'role' => 'inherited']]);

        $this->assertEquals(new \stdClass(), $meta['props']['properties']);
    }

    public function testWrapperMapsPropsAndSlotsToContent(): void
    {
        $generator = new SdcComponentGenerator();
        $meta = $this->generate(['title' => ['type' => 'text'], 'text' => ['type' => 'richtext']], $generator);
        $twig = $generator->wrapperTwig($meta, 'demo');

        $this->assertStringContainsString("'title': title|default(null),", $twig);
        $this->assertStringContainsString("'text': text is empty ? null : text|render,", $twig);
        $this->assertStringContainsString("{% include '@component/demo/demo.twig' with { content: content } %}", $twig);
    }
}
