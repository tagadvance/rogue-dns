<?php

declare(strict_types=1);

namespace tagadvance\roguedns\Test\Support;

/**
 * A call counter shared between a task and its continuation check.
 *
 * An object rather than a `use (&$n)` variable: a closure that captures by value silently never
 * observes the change, which is a loop that never terminates.
 */
final class Counter
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }
}
