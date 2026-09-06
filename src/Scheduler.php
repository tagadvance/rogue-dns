<?php

declare(strict_types=1);

namespace tagadvance\roguedns;

use Throwable;

/**
 * Runs a task on a fixed interval as the container's main process.
 *
 * This replaces driving the update from a Docker HEALTHCHECK. A healthcheck is defined as an
 * observation: its output is truncated to 4KB, only the last five probes are kept, it never
 * reaches `docker logs`, and it is SIGKILLed when it overruns -- which for a side-effecting job
 * means being killed between two writes. Doing the work in PID 1 instead puts the output in the
 * container log, makes the restart policy apply, and leaves the healthcheck free to do what it
 * is for: report whether the work is still happening.
 */
final class Scheduler
{
    /** @var callable(int): void */
    private $sleeper;

    /** @var resource */
    private $errorStream;

    /**
     * @param callable(int): void|null $sleeper overridable so tests need not actually wait
     * @param resource|null $errorStream defaults to STDERR
     */
    public function __construct(
        private readonly HealthState $health,
        private readonly int $intervalSeconds,
        ?callable $sleeper = null,
        $errorStream = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->errorStream = $errorStream ?? STDERR;
    }

    /**
     * Runs $task immediately and then every interval, until $shouldContinue returns false.
     *
     * A failing task is recorded and logged but never fatal: a transient network failure should
     * not take the container down, and sustained failure surfaces through the health state
     * instead. The next run is scheduled interval seconds after this one *started*, so a slow
     * run does not push the schedule later and later.
     *
     * @param callable(): void $task
     * @param callable(): bool $shouldContinue
     */
    public function run(callable $task, callable $shouldContinue): void
    {
        while (true) {
            $startedAt = time();

            try {
                $task();
                $this->health->recordSuccess();
            } catch (Throwable $e) {
                $this->health->recordFailure($e::class . ': ' . $e->getMessage());
                fwrite($this->errorStream, sprintf('[%s] %s: %s%s', self::now(), $e::class, $e->getMessage(), PHP_EOL));
            }

            if (!$shouldContinue()) {
                return;
            }

            ($this->sleeper)(max(1, $this->intervalSeconds - (time() - $startedAt)));
        }
    }

    public static function now(): string
    {
        return date('c');
    }
}
