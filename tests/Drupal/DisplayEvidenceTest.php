<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Tests\Drupal;

use Parisek\DefinitionKit\Drupal\DisplayEvidence;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DisplayEvidenceTest extends TestCase
{
    private function evidence(): DisplayEvidence
    {
        return DisplayEvidence::fromFile(__DIR__ . '/../fixtures/drupal/ParagraphDisplay.php');
    }

    /**
     * @param list<array{path: list<string>, field: string, children: list<array{path: list<string>, field: string}>}> $entries
     * @return array<string,string> prop path => field
     */
    private function flat(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $out[implode('.', $entry['path'])] = $entry['field'];
            foreach ($entry['children'] as $child) {
                $out[implode('.', $entry['path']) . '[].' . implode('.', $child['path'])] = $child['field'];
            }
        }

        return $out;
    }

    #[Test]
    public function a_list_built_in_a_foreach_maps_each_prop_to_its_item_field(): void
    {
        self::assertSame([
            'items' => 'paragraphs',
            'items[].name' => 'title',
            'items[].image' => 'image',
            'items[].company' => 'perex',
            'items[].phone' => 'phone',
            'items[].email' => 'email',
            'heading.title' => 'title',
            'wrapper_id' => 'wrapper_id',
        ], $this->flat($this->evidence()->forBundle('card_list')));
    }

    #[Test]
    public function nested_keys_and_renamed_props_are_kept(): void
    {
        $flat = $this->flat($this->evidence()->forBundle('quote_image'));

        self::assertSame('author_name', $flat['author.name']);
        self::assertSame('link', $flat['button']);
        self::assertSame('media', $flat['image']);
    }

    #[Test]
    public function an_in_array_condition_applies_the_branch_to_each_bundle(): void
    {
        self::assertSame('content', $this->flat($this->evidence()->forBundle('html'))['html']);
        self::assertSame('content', $this->flat($this->evidence()->forBundle('content'))['html']);
    }

    #[Test]
    public function a_bundle_without_a_branch_gets_the_shared_fields_only(): void
    {
        self::assertSame(
            ['heading.title' => 'title', 'wrapper_id' => 'wrapper_id'],
            $this->flat($this->evidence()->forBundle('teaser')),
        );
    }

    #[Test]
    public function a_loop_over_a_query_result_is_not_evidence(): void
    {
        // teaser_feed iterates loaded nodes: `$item` is not a paragraph field.
        self::assertArrayNotHasKey('items', $this->flat($this->evidence()->forBundle('teaser_feed')));
    }

    #[Test]
    public function the_branches_are_listed(): void
    {
        $bundles = $this->evidence()->bundles();
        sort($bundles);

        self::assertSame(['card_list', 'content', 'html', 'quote_image', 'stats'], $bundles);
    }

    #[Test]
    public function the_source_is_tokenized_not_run(): void
    {
        // Top-level code with a side effect: running it would define the constant.
        $evidence = DisplayEvidence::fromSource(
            "<?php\ndefine('DK_EVIDENCE_RAN', true);\nif (\$bundle === 'x') { \$content['a'] = \$this->getTextField(\$entity, 'b'); }\n"
        );

        self::assertFalse(defined('DK_EVIDENCE_RAN'));
        self::assertSame(['a' => 'b'], $this->flat($evidence->forBundle('x')));
    }

    #[Test]
    public function a_missing_file_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        DisplayEvidence::fromFile(__DIR__ . '/nope.php');
    }
}
