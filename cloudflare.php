#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use tagadvance\roguedns\Cloudflare;
use tagadvance\roguedns\Configuration;
use tagadvance\roguedns\HealthState;
use tagadvance\roguedns\PublicIpLookup;
use tagadvance\roguedns\Scheduler;

const CONFIG_FILE = __DIR__ . '/config.ini';

/** Overridable with [schedule] interval. Five minutes is what the old healthcheck used. */
const DEFAULT_INTERVAL_SECONDS = 300;

exit(main());

function main(): int
{
    $options = getopt('', [
        'add-zone:',
        'list-zones',
        'update-ip::',
        'watch',
        'health',
        'dry-run',
        'help',
    ]);

    if (isset($options['help'])) {
        print usage();

        return 0;
    }

    if ($options === false || $options === []) {
        fwrite(STDERR, usage());

        return 1;
    }

    if (isset($options['health'])) {
        return health();
    }

    try {
        $config = Configuration::fromFile(CONFIG_FILE);
        $cloudflare = Cloudflare::fromToken($config->string('api', 'token'));

        if (isset($options['list-zones'])) {
            return listZones($cloudflare);
        }

        if (isset($options['add-zone'])) {
            return addZone($cloudflare, $config, $options['add-zone']);
        }

        if (isset($options['watch'])) {
            return watch($cloudflare, $config, isset($options['dry-run']));
        }

        return updateIp($cloudflare, $config, $options['update-ip'], isset($options['dry-run']));
    } catch (Throwable $e) {
        fwrite(STDERR, $e::class . ': ' . $e->getMessage() . PHP_EOL);

        return 1;
    }
}

function listZones(Cloudflare $cloudflare): int
{
    foreach ($cloudflare->listZones() as $zone) {
        print $zone->name . PHP_EOL;
        foreach ($cloudflare->listRecords($zone->id) as $record) {
            print "\t$record->type $record->name $record->content" . PHP_EOL;
        }
    }

    return 0;
}

/**
 * @param mixed $name raw --add-zone value from getopt
 */
function addZone(Cloudflare $cloudflare, Configuration $config, mixed $name): int
{
    if (!is_string($name) || !filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        fwrite(STDERR, 'zone name must be a valid host name' . PHP_EOL);

        return 1;
    }

    $ip = new PublicIpLookup($config->list('ip', 'url'))->find();

    $zone = $cloudflare->addZone($name, $ip, printNs: true, accountId: $cloudflare->accountId());
    $cloudflare->deproxifyRecords($zone->id);
    $cloudflare->configure($zone->id);

    return 0;
}

/**
 * Detects the public address and rewrites the whitelisted records only when it has changed.
 *
 * The change check resolves `[domains] primary` through the system resolver, which is a cheap
 * pre-filter rather than the authority: updateIp compares against the record content Cloudflare
 * returns before writing anything, so a resolver that disagrees costs one list call, not a write.
 *
 * @param mixed $manualIp raw --update-ip value from getopt; false when the flag was passed bare
 * @param bool $dryRun report what would change without writing anything
 */
function updateIp(Cloudflare $cloudflare, Configuration $config, mixed $manualIp, bool $dryRun = false): int
{
    $whitelist = $config->list('domains', 'domain');

    if (is_string($manualIp) && $manualIp !== '') {
        $ip = filter_var($manualIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        if ($ip === false) {
            fwrite(STDERR, "not an IPv4 address: $manualIp" . PHP_EOL);

            return 1;
        }

        report($cloudflare->updateIp($ip, $whitelist, $dryRun), $ip, $dryRun);

        return 0;
    }

    $newIp = new PublicIpLookup($config->list('ip', 'url'))->find();

    report($cloudflare->updateIp($newIp, $whitelist, $dryRun), $newIp, $dryRun);

    return 0;
}

/**
 * One line per pass, so an idle loop is quiet and a real change is conspicuous in the log.
 */
function report(int $changed, string $ip, bool $dryRun): void
{
    if ($changed === 0) {
        print "... all records already point at $ip" . PHP_EOL;

        return;
    }

    printf('%s %d record%s to %s%s', $dryRun ? 'Would update' : 'Updated', $changed, $changed === 1 ? '' : 's', $ip, PHP_EOL);
}

/**
 * Runs the update on an interval as a long-lived foreground process, logging to stdout so
 * `docker logs` shows it. Stops cleanly on SIGTERM/SIGINT where pcntl is available.
 */
function watch(Cloudflare $cloudflare, Configuration $config, bool $dryRun): int
{
    $interval = $config->positiveIntOrDefault('schedule', 'interval', DEFAULT_INTERVAL_SECONDS);
    $health = HealthState::default();
    $running = true;

    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        $stop = static function (int $signal) use (&$running): void {
            $running = false;
            printf('[%s] signal %d received, stopping after this run%s', Scheduler::now(), $signal, PHP_EOL);
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }

    printf('[%s] watching every %ds%s', Scheduler::now(), $interval, PHP_EOL);

    $task = static function () use ($cloudflare, $config, $dryRun): void {
        printf('[%s] checking%s', Scheduler::now(), PHP_EOL);
        $status = updateIp($cloudflare, $config, false, $dryRun);
        if ($status !== 0) {
            throw new RuntimeException('update failed');
        }
    };

    // by-reference: an arrow function captures $running by value and would never see the signal
    $shouldContinue = static function () use (&$running): bool {
        return $running;
    };

    new Scheduler($health, $interval)->run($task, $shouldContinue);

    printf('[%s] stopped%s', Scheduler::now(), PHP_EOL);

    return 0;
}

/**
 * Reports whether the watch loop is still succeeding. Reads the recorded state only -- it must
 * not repeat the work, or an overrunning probe gets SIGKILLed mid-update.
 */
function health(): int
{
    $interval = DEFAULT_INTERVAL_SECONDS;
    try {
        $interval = Configuration::fromFile(CONFIG_FILE)->positiveIntOrDefault('schedule', 'interval', $interval);
    } catch (Throwable) {
        // Fall back to the default interval; the state check below is the real signal.
    }

    $health = HealthState::default();
    print $health->describe($interval) . PHP_EOL;

    return $health->isHealthy($interval) ? 0 : 1;
}

function usage(): string
{
    $script = basename(__FILE__);

    return <<<USAGE
        # list zones and their records
        ./$script --list-zones
        # add a new zone with reasonable defaults
        ./$script --add-zone foo.com
        # automatically detect IP
        ./$script --update-ip
        # manually set IP address (note the '=': an optional option value cannot be space-separated)
        ./$script --update-ip=203.0.113.9
        # report what would change without writing anything
        ./$script --update-ip --dry-run
        # run continuously on an interval (this is what the container does)
        ./$script --watch
        # report whether the watch loop is still succeeding
        ./$script --health

        USAGE;
}
