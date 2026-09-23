<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Generator\Drupal;

use Parisek\DefinitionKit\Drupal\ConfigStore;
use Parisek\DefinitionKit\Drupal\DrupalBaseline;
use Parisek\DefinitionKit\Drupal\DrupalConfig;
use Parisek\DefinitionKit\Drupal\DrupalSettings;
use Parisek\DefinitionKit\Drupal\DrupalTypeMap;
use Parisek\DefinitionKit\Schema\FieldsSchemaValidator;
use Parisek\DefinitionKit\Support\EntryDefinition;
use Parisek\DefinitionKit\Support\StructuralType;

/**
 * `fields-generate --target=drupal`: component directories -> one plan for
 * the whole export (ADR 0002). One plan, because components share field
 * storage: a storage decision needs every bundle that uses it.
 *
 * Per component the result is OK (with its bundles), SKIP (no paragraph
 * type, or a `kind` without a CMS projection) or FAIL (an invalid
 * definition, or one the generator cannot map). A FAIL component adds
 * nothing to the plan, and the command then writes nothing.
 */
final class DrupalGenerator
{
    private readonly BundleSpecBuilder $builder;

    private readonly DrupalConfigPlanner $planner;

    private readonly ?DrupalConfig $config;

    public function __construct(
        private readonly ConfigStore $store,
        DrupalSettings $settings = new DrupalSettings(),
        ?DrupalBaseline $baseline = null,
        DrupalTypeMap $types = new DrupalTypeMap(),
    ) {
        $this->builder = new BundleSpecBuilder($settings, $types);
        $this->planner = new DrupalConfigPlanner($store, $settings, $baseline, $types);
        $this->config = [] === $store->names('paragraphs.paragraphs_type.') ? null : DrupalConfig::fromDirectory($store->directory());
    }

    /**
     * @param list<string> $componentDirs
     * @return array{components: list<array{status: 'OK'|'SKIP'|'FAIL', component: string, detail: string}>, plan: Plan}
     */
    public function run(array $componentDirs): array
    {
        $components = [];
        $specs = [];
        $validator = new FieldsSchemaValidator();
        foreach ($componentDirs as $dir) {
            $dir = rtrim($dir, '/');
            $name = basename($dir);
            if (null !== EntryDefinition::typeOfDirectory($dir)) {
                continue;
            }
            $yaml = "{$dir}/{$name}.yaml";
            if (!is_file($yaml)) {
                $components[] = ['status' => 'FAIL', 'component' => $name, 'detail' => "no {$name}.yaml at {$yaml}"];
                continue;
            }
            $validation = $validator->validateFile($yaml);
            if (!$validation->valid) {
                $first = $validation->errors[0] ?? ['pointer' => '/', 'message' => 'invalid'];
                $components[] = ['status' => 'FAIL', 'component' => $name, 'detail' => "invalid definition ({$first['pointer']}: {$first['message']})"];
                continue;
            }
            $definition = StructuralType::parseFile($yaml);
            $definition = is_array($definition) ? $definition : [];
            $kind = $definition['kind'] ?? null;
            if (is_string($kind) && 'block' !== $kind) {
                $components[] = ['status' => 'SKIP', 'component' => $name, 'detail' => "kind {$kind} has no CMS projection"];
                continue;
            }
            try {
                $built = $this->builder->build($definition, $name, $this->config);
            } catch (\DomainException $e) {
                $components[] = ['status' => 'FAIL', 'component' => $name, 'detail' => $e->getMessage()];
                continue;
            }
            if ([] === $built) {
                $components[] = ['status' => 'SKIP', 'component' => $name, 'detail' => 'no paragraph type: no drupal: link, no existing bundle by convention, no bundle_aliases entry'];
                continue;
            }
            $top = array_values(array_unique(array_map(static fn (BundleSpec $s): string => $s->bundle, array_filter($built, static fn (BundleSpec $s): bool => $s->topLevel))));
            $components[] = ['status' => 'OK', 'component' => $name, 'detail' => implode(', ', $top)];
            $specs = [...$specs, ...$built];
        }

        return ['components' => $components, 'plan' => $this->planner->plan($specs)];
    }
}
