<?php

declare(strict_types=1);

namespace tagadvance\roguedns;

use JsonException;

/**
 * The last outcome of a scheduled run, written where a separate health-check process can read it.
 *
 * This exists so the Docker healthcheck can be a cheap file read rather than a second copy of the
 * work. A probe that did the work itself would be killed on timeout mid-update, and its output
 * would land in the health log instead of the container log.
 */
final class HealthState
{
    /**
     * How many missed cycles to tolerate before reporting unhealthy. One allows a single
     * transient failure to recover on the next tick without flapping the container's status.
     */
    private const TOLERATED_CYCLES = 2;

    private const SLACK_SECONDS = 60;

    public function __construct(private readonly string $path) {}

    public static function default(): self
    {
        $path = getenv('ROGUE_DNS_STATE');

        return new self($path === false || $path === '' ? sys_get_temp_dir() . '/rogue-dns-health.json' : $path);
    }

    public function recordSuccess(): void
    {
        $this->write(['ok' => true, 'at' => time(), 'reason' => null]);
    }

    public function recordFailure(string $reason): void
    {
        $previous = $this->read();
        $this->write([
            'ok' => false,
            'at' => $previous['at'] ?? null,
            'reason' => $reason,
        ]);
    }

    /**
     * Healthy while a run has succeeded recently enough. Deliberately keyed on the last
     * *success*, not the last attempt: an update that has been failing for an hour is not
     * healthy just because it failed again a moment ago.
     */
    public function isHealthy(int $intervalSeconds): bool
    {
        $state = $this->read();
        $lastSuccess = $state['at'] ?? null;
        if (!is_int($lastSuccess)) {
            return false;
        }

        return (time() - $lastSuccess) <= ($intervalSeconds * self::TOLERATED_CYCLES) + self::SLACK_SECONDS;
    }

    public function describe(int $intervalSeconds): string
    {
        $state = $this->read();
        $lastSuccess = $state['at'] ?? null;
        if (!is_int($lastSuccess)) {
            return 'no successful run recorded';
        }

        $age = time() - $lastSuccess;
        $reason = $state['reason'] ?? null;
        $suffix = is_string($reason) ? "; last attempt failed: $reason" : '';

        return sprintf(
            'last success %ds ago (tolerating %ds)%s',
            $age,
            ($intervalSeconds * self::TOLERATED_CYCLES) + self::SLACK_SECONDS,
            $suffix,
        );
    }

    /**
     * @return array{ok?: bool, at?: int|null, reason?: string|null}
     */
    private function read(): array
    {
        if (!is_readable($this->path)) {
            return [];
        }

        $contents = @file_get_contents($this->path);
        if ($contents === false) {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        /** @var array{ok?: bool, at?: int|null, reason?: string|null} */
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array{ok: bool, at: int|null, reason: string|null} $state
     */
    private function write(array $state): void
    {
        @file_put_contents($this->path, json_encode($state, JSON_THROW_ON_ERROR));
    }
}
