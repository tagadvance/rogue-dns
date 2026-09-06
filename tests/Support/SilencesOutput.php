<?php

declare(strict_types=1);

namespace tagadvance\roguedns\Test\Support;

/**
 * The client prints progress to stdout and reports skipped names on an error stream. Swallow the
 * former so test output stays readable -- on the exception path too, or a failing assertion leaves
 * an unbalanced buffer behind -- and capture the latter so it can be asserted on instead of
 * leaking into the runner's output.
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

    /**
     * @return resource an in-memory stream to pass as Cloudflare's error stream
     */
    private function captureErrors()
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function captured($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
