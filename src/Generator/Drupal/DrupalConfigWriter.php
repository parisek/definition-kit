<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Generator\Drupal;

use Parisek\DefinitionKit\Drupal\ConfigStore;
use Parisek\DefinitionKit\Drupal\DrupalYaml;

/**
 * Writes the CREATE and UPDATE entries of a {@see Plan} into the export
 * directory (ADR 0002). It writes nothing when the plan has a REFUSE entry,
 * and it never deletes a file.
 */
final class DrupalConfigWriter
{
    /**
     * @return list<string> the paths written
     */
    public function write(Plan $plan, ConfigStore $store): array
    {
        if ($plan->refused()) {
            throw new \LogicException('A plan with a REFUSE entry is never written.');
        }
        $written = [];
        foreach ($plan->entries as $entry) {
            if (!$entry->writes() || null === $entry->data) {
                continue;
            }
            $path = $store->path($entry->name);
            if (false === file_put_contents($path, DrupalYaml::dump($entry->data))) {
                throw new \RuntimeException("Cannot write {$path}");
            }
            $written[] = $path;
        }

        return $written;
    }

    /**
     * One config name per line: what a deploy step must import.
     */
    public function writeNames(Plan $plan, string $path): void
    {
        $names = $plan->changedNames();
        if (false === file_put_contents($path, [] === $names ? '' : implode("\n", $names) . "\n")) {
            throw new \RuntimeException("Cannot write {$path}");
        }
    }
}
