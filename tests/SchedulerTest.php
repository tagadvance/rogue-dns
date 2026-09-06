<?php

declare(strict_types=1);

namespace tagadvance\roguedns\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use tagadvance\roguedns\HealthState;
use tagadvance\roguedns\Scheduler;
use tagadvance\roguedns\Test\Support\Counter;

#[CoversClass(Scheduler::class)]
#[CoversClass(HealthState::class)]
final class SchedulerTest extends TestCase
{
    private string $statePath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rogue-dns-health');
        self::assertIsString($path);
        $this->statePath = $path;
        unlink($path);
    }

    protected function tearDown(): void
    {
        @unlink($this->statePath);
    }

    public function testTaskRunsOnceImmediatelyAndThenPerInterval(): void
    {
        $runs = new Counter();
        $slept = [];
        $scheduler = new Scheduler(new HealthState($this->statePath), 300, function (int $s) use (&$slept): void {
            $slept[] = $s;
        });

        $scheduler->run(
            $runs->increment(...),
            static fn(): bool => $runs->count < 3,
        );

        self::assertSame(3, $runs->count);
        self::assertSame([300, 300], $slept, 'sleeps between runs, not after the last');
    }

    public function testAFailingTaskDoesNotStopTheLoopAndIsReported(): void
    {
        $runs = new Counter();
        $errors = fopen('php://memory', 'r+');
        self::assertIsResource($errors);
        $scheduler = new Scheduler(new HealthState($this->statePath), 60, function (): void {}, $errors);

        $scheduler->run(
            static function () use ($runs): void {
                $runs->increment();

                throw new RuntimeException('boom');
            },
            static fn(): bool => $runs->count < 3,
        );

        rewind($errors);
        $logged = (string) stream_get_contents($errors);

        self::assertSame(3, $runs->count, 'a transient failure must not take the container down');
        self::assertSame(3, substr_count($logged, 'boom'), 'every failure is reported');
        self::assertFalse(new HealthState($this->statePath)->isHealthy(60));
    }

    public function testSchedulingIsMeasuredFromTheStartOfARunSoItDoesNotDrift(): void
    {
        $slept = [];
        $scheduler = new Scheduler(new HealthState($this->statePath), 300, function (int $s) use (&$slept): void {
            $slept[] = $s;
        });

        $runs = new Counter();
        $scheduler->run(
            static function () use ($runs): void {
                $runs->increment();
                if ($runs->count === 1) {
                    // A first run that takes real time, so the deduction has something to deduct.
                    usleep(1_100_000);
                }
            },
            static fn(): bool => $runs->count < 2,
        );

        self::assertCount(1, $slept);
        self::assertLessThan(300, $slept[0], 'elapsed time is deducted from the next wait');
    }

    public function testHealthIsFalseBeforeAnyRun(): void
    {
        self::assertFalse(new HealthState($this->statePath)->isHealthy(300));
    }

    public function testHealthIsTrueAfterASuccess(): void
    {
        $health = new HealthState($this->statePath);
        $health->recordSuccess();

        self::assertTrue($health->isHealthy(300));
    }

    public function testASingleFailureAfterASuccessIsStillHealthy(): void
    {
        $health = new HealthState($this->statePath);
        $health->recordSuccess();
        $health->recordFailure('transient');

        self::assertTrue($health->isHealthy(300), 'one bad cycle should not flap the container');
        self::assertStringContainsString('transient', $health->describe(300));
    }

    public function testSustainedFailureIsUnhealthy(): void
    {
        file_put_contents($this->statePath, json_encode([
            'ok' => false,
            'at' => time() - 3600,
            'reason' => 'still broken',
        ], JSON_THROW_ON_ERROR));

        $health = new HealthState($this->statePath);

        self::assertFalse($health->isHealthy(300));
        self::assertStringContainsString('still broken', $health->describe(300));
    }

    public function testCorruptStateIsTreatedAsUnhealthyRatherThanCrashing(): void
    {
        file_put_contents($this->statePath, 'not json');

        self::assertFalse(new HealthState($this->statePath)->isHealthy(300));
    }
}
