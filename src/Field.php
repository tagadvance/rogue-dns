<?php

declare(strict_types=1);

namespace tagadvance\roguedns;

use RuntimeException;
use stdClass;

/**
 * Narrows a field of a decoded API response to a known type.
 *
 * The SDK hands back stdClass straight from json_decode, so every property is mixed. Reading
 * fields through here turns a shape the API has changed into a named failure at the boundary
 * rather than a TypeError or a silent wrong value somewhere further in.
 */
final class Field
{
    public static function string(stdClass $object, string $name): string
    {
        $value = $object->{$name} ?? null;
        if (!is_string($value)) {
            throw self::unexpected($name, 'string', $value);
        }

        return $value;
    }

    public static function int(stdClass $object, string $name): int
    {
        $value = $object->{$name} ?? null;
        if (!is_int($value)) {
            throw self::unexpected($name, 'int', $value);
        }

        return $value;
    }

    public static function bool(stdClass $object, string $name, bool $default): bool
    {
        $value = $object->{$name} ?? null;

        return is_bool($value) ? $value : $default;
    }

    public static function nullableString(stdClass $object, string $name): ?string
    {
        $value = $object->{$name} ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function nullableInt(stdClass $object, string $name): ?int
    {
        $value = $object->{$name} ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @return list<string>
     */
    public static function stringList(stdClass $object, string $name): array
    {
        $value = $object->{$name} ?? null;
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /**
     * @return list<stdClass>
     */
    public static function objectList(stdClass $object, string $name): array
    {
        $value = $object->{$name} ?? null;
        if (!is_array($value)) {
            throw self::unexpected($name, 'array', $value);
        }

        return array_values(array_filter($value, fn($item): bool => $item instanceof stdClass));
    }

    public static function object(stdClass $object, string $name): stdClass
    {
        $value = $object->{$name} ?? null;
        if (!$value instanceof stdClass) {
            throw self::unexpected($name, 'object', $value);
        }

        return $value;
    }

    private static function unexpected(string $name, string $expected, mixed $actual): RuntimeException
    {
        return new RuntimeException(sprintf('unexpected API response: %s should be %s, got %s', $name, $expected, get_debug_type($actual)));
    }
}
