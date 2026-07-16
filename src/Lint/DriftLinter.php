<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Lint;

use Parisek\DefinitionKit\Generator\BlockJsonGenerator;
use Parisek\DefinitionKit\Generator\FieldsGenerator;
use Parisek\DefinitionKit\Schema\FieldsSchemaValidator;
use Parisek\DefinitionKit\Support\StructuralDiff;
use Symfony\Component\Yaml\Yaml;

/**
 * The CI gate (ADR 0005): proves a component's committed acf.json (and,
 * when present, block.json) still equals what Generator\FieldsGenerator
 * / Generator\BlockJsonGenerator would produce from the committed
 * <name>.yaml, modulo Lint\DriftAllowlist's five narrow, documented
 * residuals. The definition is always authoritative — a diff always
 * reads "expected <what the definition implies>, got <what's on
 * disk>," and the fix is always `fields-generate`, never editing the
 * definition to match acf.json.
 *
 * Two forms of "not real drift" are handled by construction, not by
 * allowlisting: `modified` (injected from the committed file's own
 * value, so it can never differ) and block.json's
 * `example.attributes.data` (BlockJsonGenerator already preserves an
 * existing block.json's `example` verbatim when handed one — see that
 * class's own docblock, "owned by the sync-gutenberg-block-examples
 * skill").
 */
final class DriftLinter
{
    public function __construct(
        private readonly FieldsSchemaValidator $schemaValidator = new FieldsSchemaValidator(),
        private readonly FieldsGenerator $fieldsGenerator = new FieldsGenerator(),
        private readonly BlockJsonGenerator $blockJsonGenerator = new BlockJsonGenerator(),
        private readonly DriftAllowlist $allowlist = new DriftAllowlist(),
    ) {
    }

    public function lint(string $componentDir): DriftResult
    {
        $componentDir = rtrim($componentDir, '/');
        $componentName = basename($componentDir);
        $yamlPath = "{$componentDir}/{$componentName}.yaml";
        $acfJsonPath = "{$componentDir}/acf.json";
        $blockJsonPath = "{$componentDir}/block.json";

        if (!is_file($yamlPath)) {
            return DriftResult::error($componentName, "no {$componentName}.yaml at {$yamlPath}");
        }

        $validation = $this->schemaValidator->validateFile($yamlPath);
        if (!$validation->valid) {
            $messages = array_map(
                static fn (array $e): string => "{$e['pointer']}: {$e['message']}",
                $validation->errors,
            );
            return DriftResult::error($componentName, 'invalid definition: ' . implode('; ', $messages));
        }

        if (!is_file($acfJsonPath)) {
            return DriftResult::error($componentName, 'acf.json missing — run fields-generate');
        }

        $tree = Yaml::parseFile($yamlPath);
        if (!is_array($tree)) {
            return DriftResult::error($componentName, 'malformed YAML');
        }

        $acfJsonRaw = file_get_contents($acfJsonPath);
        if (false === $acfJsonRaw) {
            return DriftResult::error($componentName, "unable to read {$acfJsonPath}");
        }
        $committedAcf = json_decode($acfJsonRaw, true);
        if (!is_array($committedAcf)) {
            return DriftResult::error($componentName, 'acf.json is not valid JSON');
        }
        // Injected, not allowlisted: `modified` legitimately changes every
        // regeneration, so comparing it at all would make every component
        // "drift" the instant fields-generate is ever run. Reusing the
        // committed value here means a genuinely stale acf.json (definition
        // changed, never regenerated) still surfaces drift on every OTHER
        // prop, which is the actual signal this lint exists to catch.
        $modifiedAt = (int) ($committedAcf['modified'] ?? 0);

        try {
            $generatedAcf = $this->fieldsGenerator->generate($tree, $componentName, $modifiedAt);
        } catch (\Throwable $e) {
            return DriftResult::error($componentName, $e->getMessage());
        }

        $acfDiffs = $this->allowlist->filter(
            $componentName,
            StructuralDiff::diff($generatedAcf, $committedAcf),
            $generatedAcf,
        );

        $blockDiffs = [];
        if (is_file($blockJsonPath)) {
            $blockJsonRaw = file_get_contents($blockJsonPath);
            if (false === $blockJsonRaw) {
                return DriftResult::error($componentName, "unable to read {$blockJsonPath}");
            }
            $committedBlock = json_decode($blockJsonRaw, true);
            if (!is_array($committedBlock)) {
                // A PRESENT but malformed/non-object block.json is real
                // drift, not silent clean — an absent block.json is the
                // only shape that legitimately means "no block for this
                // component" (see test_missing_block_json_is_not_a_failure).
                return DriftResult::error($componentName, 'block.json is not valid JSON / not an object');
            }
            // Passing the COMMITTED block.json as $existingBlockJson makes
            // BlockJsonGenerator echo its `example` back verbatim — see
            // this class's own docblock. Everything else is still generated
            // fresh from the definition and compared for real.
            $generatedBlock = $this->blockJsonGenerator->generate($tree, $componentName, $committedBlock);
            $blockDiffs = $this->allowlist->filter(
                $componentName,
                StructuralDiff::diff($generatedBlock, $committedBlock),
                $generatedBlock,
            );
        }

        if ([] === $acfDiffs && [] === $blockDiffs) {
            return DriftResult::clean($componentName);
        }

        return DriftResult::drift(
            $componentName,
            array_map(StructuralDiff::formatEntry(...), $acfDiffs),
            array_map(StructuralDiff::formatEntry(...), $blockDiffs),
        );
    }
}
