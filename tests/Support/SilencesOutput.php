<?php

declare(strict_types=1);

namespace tagadvance\roguedns\Test\Support;

/**
 * The client prints progress to stdout. Swallow it so test output stays readable, and swallow it
 * on the exception path too, or a failing assertion leaves an unbalanced buffer behind.
 */
trait SilencesOutput
{
    private function silently(callable $callable): void
    {
        ob_start();
        try {
            $callable();
        } finally {
            ob_end_clean();
        }
    }
}
