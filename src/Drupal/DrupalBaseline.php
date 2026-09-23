<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Drupal;

use Symfony\Component\Yaml\Yaml;

/**
 * What the Drupal generator writes into a config entity it creates, beyond
 * the keys a definition owns (ADR 0002): `schemas/drupal-defaults-baseline.yaml`,
 * with the project file that `drupal.baseline` names deep-merged over it.
 *
 * Deep merge: a map merges key by key, a list or a scalar replaces. So a
 * project adds `third_party_settings.paragraphs_ee` without restating
 * `paragraphs_library`, and replaces `media_bundles.image` as a whole.
 */
final class DrupalBaseline
{
    public const DEFAULT_PATH = __DIR__ . '/../../schemas/drupal-defaults-baseline.yaml';

    private const SECTIONS = [
        'paragraphs_type',
        'field_storage',
        'field_instance',
        'field_config_cardinality',
        'media_bundles',
        'form_display',
        'widget_third_party_settings',
        'view_display',
        'language_content_settings',
    ];

    /** @param array<string,mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    /**
     * @param string|null $projectPath a project baseline to merge over the shipped one
     */
    public static function load(?string $projectPath = null, ?string $shippedPath = null): self
    {
        $data = self::parse($shippedPath ?? self::DEFAULT_PATH);
        if (null !== $projectPath) {
            $project = self::parse($projectPath);
            $unknown = array_diff(array_map('strval', array_keys($project)), self::SECTIONS);
            if ([] !== $unknown) {
                throw new \RuntimeException(sprintf(
                    'Unknown section(s) in the Drupal baseline %s: %s — expected: %s.',
                    $projectPath,
                    implode(', ', $unknown),
                    implode(', ', self::SECTIONS),
                ));
            }
            $data = self::deepMerge($data, $project);
        }

        return new self($data);
    }

    /**
     * One section of the baseline (`paragraphs_type`, `field_storage`, ...).
     *
     * @return array<string,mixed>
     */
    public function section(string $name): array
    {
        if (!in_array($name, self::SECTIONS, true)) {
            throw new \InvalidArgumentException("No Drupal baseline section '{$name}'.");
        }
        $section = $this->data[$name] ?? [];

        return is_array($section) ? $section : [];
    }

    /**
     * Media types for a media kind (`image`, `gallery`, `file`), or an empty
     * list when the baseline does not name any.
     *
     * @return list<string>
     */
    public function mediaBundles(string $kind): array
    {
        $bundles = $this->section('media_bundles')[$kind] ?? [];

        return is_array($bundles) ? array_values(array_map('strval', $bundles)) : [];
    }

    /**
     * Third-party settings for a new form display entry with this widget.
     *
     * @return array<string,mixed>
     */
    public function widgetThirdPartySettings(string $widget): array
    {
        $settings = $this->section('widget_third_party_settings')[$widget] ?? [];

        return is_array($settings) ? $settings : [];
    }

    /**
     * @param array<mixed> $base
     * @param array<mixed> $over
     * @return array<mixed>
     */
    public static function deepMerge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (is_array($value) && !array_is_list($value) && is_array($base[$key] ?? null) && !array_is_list($base[$key])) {
                $base[$key] = self::deepMerge($base[$key], $value);
            } elseif (is_array($value) && [] === $value && is_array($base[$key] ?? null)) {
                // `{}` in YAML parses to [], which cannot tell a map from a
                // list: an empty value keeps the base.
                continue;
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /** @return array<string,mixed> */
    private static function parse(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Drupal baseline not found: {$path}");
        }
        $parsed = Yaml::parseFile($path);
        if (null === $parsed) {
            return [];
        }
        if (!is_array($parsed) || (array_is_list($parsed) && [] !== $parsed)) {
            throw new \RuntimeException("Drupal baseline must be a YAML map: {$path}");
        }

        return $parsed;
    }
}
