<?php

declare(strict_types=1);

namespace tagadvance\roguedns;

use RuntimeException;

/**
 * Validated access to config.ini.
 *
 * Every accessor fails with a message naming the missing key rather than letting an undefined
 * index surface later as a TypeError from deep inside a call, which is what the raw
 * parse_ini_file array used to do.
 */
final class Configuration
{
    /**
     * @param array<string, array<string, string|list<string>>> $sections
     */
    private function __construct(private readonly array $sections, private readonly string $path) {}

    public static function fromFile(string $path): self
    {
        if (!is_readable($path)) {
            throw new RuntimeException("configuration is missing or unreadable: $path");
        }

        $sections = @parse_ini_file($path, true);
        if ($sections === false) {
            throw new RuntimeException("configuration could not be parsed: $path");
        }

        /** @var array<string, array<string, string|list<string>>> $sections */
        return new self($sections, $path);
    }

    /**
     * A required single value. Fails when the key is absent, empty, or written as a list.
     */
    public function string(string $section, string $key): string
    {
        $value = $this->sections[$section][$key] ?? null;
        if (is_array($value)) {
            throw new RuntimeException($this->describe($section, $key, 'is a list, expected a single value'));
        }
        if ($value === null || $value === '') {
            throw new RuntimeException($this->describe($section, $key, 'is not set'));
        }

        return $value;
    }

    /**
     * A required list. A single `key = value` is accepted and wrapped, so `key[]` is a
     * convention rather than a trap.
     *
     * @return non-empty-list<string>
     */
    public function list(string $section, string $key): array
    {
        $value = $this->sections[$section][$key] ?? null;
        if ($value === null || $value === '' || $value === []) {
            throw new RuntimeException($this->describe($section, $key, 'is not set'));
        }

        return is_array($value) ? array_values($value) : [$value];
    }

    private function describe(string $section, string $key, string $problem): string
    {
        return sprintf('[%s] %s %s in %s', $section, $key, $problem, $this->path);
    }
}
