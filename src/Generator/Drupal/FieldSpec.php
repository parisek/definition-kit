<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Generator\Drupal;

/**
 * What a definition says about one Drupal field instance: the owned keys of
 * ADR 0002. A null value means "the definition does not say", and the
 * generator then keeps what the export has (or the baseline, on create).
 */
final class FieldSpec
{
    /**
     * @param list<string> $accepted storage types the definition accepts, the one to create first
     * @param list<string>|null $targetBundles null: not said
     * @param array<string,string>|null $allowedValues select options, value => label, in order
     * @param list<string> $groups field_group names from the outermost in, for a new form display
     */
    public function __construct(
        public readonly string $bundle,
        public readonly string $machine,
        public readonly string $path,
        public readonly array $accepted,
        public readonly bool $storagePinned,
        public readonly string $label,
        public readonly string $description,
        public readonly bool $required,
        public readonly ?bool $translatable,
        public readonly int $cardinality,
        public readonly ?string $targetType,
        public readonly ?array $targetBundles,
        public readonly ?array $allowedValues,
        public readonly ?bool $linkUrlOnly,
        public readonly ?string $mediaKind,
        public readonly ?string $widget,
        public readonly ?string $formatter,
        public readonly array $groups,
    ) {
    }

    /** The storage type to create when no storage exists yet. */
    public function storageToCreate(): string
    {
        return $this->accepted[0];
    }

    /**
     * What must agree when two bundles describe the same bundle twice.
     *
     * @return array<string,mixed>
     */
    public function signature(): array
    {
        return [
            'accepted' => $this->accepted,
            'label' => $this->label,
            'description' => $this->description,
            'required' => $this->required,
            'translatable' => $this->translatable,
            'cardinality' => $this->cardinality,
            'targetType' => $this->targetType,
            'targetBundles' => $this->targetBundles,
            'allowedValues' => $this->allowedValues,
            'linkUrlOnly' => $this->linkUrlOnly,
        ];
    }
}
