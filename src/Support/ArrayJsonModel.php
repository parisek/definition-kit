<?php

declare(strict_types=1);

namespace Parisek\DefinitionKit\Support;

/**
 * Converts plain nested PHP arrays into the JSON-model shape
 * FieldsSchemaValidator::validateData() expects — stdClass for JSON
 * objects, arrays only for genuine JSON sequences. Driven purely by
 * array_is_list(); an empty array is treated as an object (empty `{}`,
 * matching Yaml::PARSE_OBJECT_FOR_MAP's own empty-map behaviour).
 */
final class ArrayJsonModel
{
    public static function toJsonModel(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if ([] !== $value && array_is_list($value)) {
            return array_map([self::class, 'toJsonModel'], $value);
        }

        $object = new \stdClass();
        foreach ($value as $key => $item) {
            $object->{(string) $key} = self::toJsonModel($item);
        }
        return $object;
    }
}
