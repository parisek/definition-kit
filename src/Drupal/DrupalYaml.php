<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Drupal;

use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Yaml;

/**
 * YAML the way `drush config:export` writes it: Drupal core's
 * `YamlSymfony::encode()` (indent 2, never inline, multi-line literal
 * blocks). A file the generator writes then reads the same as one Drupal
 * exported, and a later export does not reformat it.
 */
final class DrupalYaml
{
    /** @param array<mixed> $data */
    public static function dump(array $data): string
    {
        return (new Dumper(2))->dump(
            $data,
            PHP_INT_MAX,
            0,
            Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK,
        );
    }
}
