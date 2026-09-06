#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use tagadvance\roguedns\Cloudflare;
use tagadvance\roguedns\Configuration;
use tagadvance\roguedns\PublicIpLookup;

const CONFIG_FILE = __DIR__ . '/config.ini';

exit(main());

function main(): int
{
    $options = getopt('', [
        'add-zone:',
        'list-zones',
        'update-ip::',
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

    try {
        $config = Configuration::fromFile(CONFIG_FILE);
        $cloudflare = Cloudflare::fromToken($config->string('api', 'token'));

        if (isset($options['list-zones'])) {
            return listZones($cloudflare);
        }

        if (isset($options['add-zone'])) {
            return addZone($cloudflare, $config, $options['add-zone']);
        }

        return updateIp($cloudflare, $config, $options['update-ip']);
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
 * @param string|list<string>|false $name
 */
function addZone(Cloudflare $cloudflare, Configuration $config, string|array|false $name): int
{
    if (!is_string($name) || !filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        fwrite(STDERR, 'zone name must be a valid host name' . PHP_EOL);

        return 1;
    }

    $ip = new PublicIpLookup($config->list('ip', 'url'))->find();

    $zone = $cloudflare->addZone($name, $ip, printNs: true);
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
 * @param string|list<string>|false $manualIp value of --update-ip, or false when passed bare
 */
function updateIp(Cloudflare $cloudflare, Configuration $config, string|array|false $manualIp): int
{
    $whitelist = $config->list('domains', 'domain');

    if (is_string($manualIp) && $manualIp !== '') {
        $ip = filter_var($manualIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        if ($ip === false) {
            fwrite(STDERR, "not an IPv4 address: $manualIp" . PHP_EOL);

            return 1;
        }

        $cloudflare->updateIp($ip, $whitelist);

        return 0;
    }

    $newIp = new PublicIpLookup($config->list('ip', 'url'))->find();

    $domain = $config->string('domains', 'primary');
    $records = dns_get_record($domain, DNS_A);
    $currentIp = $records === false ? null : ($records[0]['ip'] ?? null);

    if ($currentIp === $newIp) {
        print '...' . PHP_EOL;

        return 0;
    }

    print sprintf('New IP address detected: %s => %s', $currentIp ?? 'unresolved', $newIp) . PHP_EOL;
    $cloudflare->updateIp($newIp, $whitelist);

    return 0;
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

        USAGE;
}
