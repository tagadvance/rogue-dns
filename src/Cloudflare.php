<?php

namespace tagadvance\roguedns;

use Cloudflare\API\Adapter\Adapter;
use Cloudflare\API\Adapter\Guzzle;
use Cloudflare\API\Adapter\ResponseException;
use Cloudflare\API\Auth\APIToken;
use Cloudflare\API\Endpoints\DNS;
use Cloudflare\API\Endpoints\SSL;
use Cloudflare\API\Endpoints\Zones;
use Iterator;
use stdClass;

class Cloudflare
{
    public const TTL = 60;
    public const PROXIED = false;

    private DNS $dns;
    private SSL $ssl;
    private Zones $zones;
    private ZoneSettings $zoneSettings;

    public static function fromToken(string $token): self
    {
        $key = new APIToken($token);
        $adapter = new Guzzle($key);

        return new self($adapter);
    }

    /**
     * Builds a client over an already-configured adapter. Prefer fromToken(); this exists so
     * tests can substitute a transport.
     */
    public static function fromAdapter(Adapter $adapter): self
    {
        return new self($adapter);
    }

    private function __construct(Adapter $adapter)
    {
        $this->dns = new DNS($adapter);
        $this->ssl = new SSL($adapter);
        $this->zones = new Zones($adapter);
        $this->zoneSettings = new ZoneSettings($adapter);
    }

    public function addZone(string $name, string $ip, bool $printNs = false): stdClass
    {
        $zone = $this->zones->addZone($name, $jumpStart = true);

        if ($printNs) {
            foreach ($zone->name_servers as $nameServer) {
                print "Name Server: $nameServer" . PHP_EOL;
            }
        }

        $records = $this->listRecords($zone->id);
        $recordsByName = array_column($records, null, 'name');

        if (!isset($recordsByName[$zone->name])) {
            print "Creating A $zone->name" . PHP_EOL;

            $this->dns->addRecord($zone->id, 'A', $zone->name, $ip, self::TTL, self::PROXIED);
        }

        $wildCname = "*.$zone->name";
        if (!isset($recordsByName[$wildCname])) {
            print "Creating CNAME $wildCname" . PHP_EOL;
            $this->dns->addRecord($zone->id, 'CNAME', $wildCname, $zone->name, self::TTL, self::PROXIED);
        }

        $www = ['www', "www.$zone->name"];
        foreach ($www as $subdomain) {
            if (isset($recordsByName[$subdomain])) {
                print "Deleting CNAME $subdomain" . PHP_EOL;
                $this->dns->deleteRecord($zone->id, $recordsByName[$subdomain]->id);
            }
        }

        return $zone;
    }

    public function listRecords(string $zoneId, string $type = '', $name = '', $content = ''): array
    {
        $listRecords = fn(int $page) => $this->dns->listRecords($zoneId, $type, $name, $content, $page);
        $recordGenerator = self::paginate($listRecords);

        return iterator_to_array($recordGenerator, preserve_keys: false);
    }

    public function deproxifyRecords(string $zoneId): void
    {
        $records = $this->listRecords($zoneId);
        $proxiedRecords = array_filter($records, fn($record) => $record->proxied);
        foreach ($proxiedRecords as $record) {
            $this->updateRecord($zoneId, $record, ['proxied' => false]);
        }
    }

    /**
     * The API overwrites the whole record rather than merging, so every field worth keeping has
     * to be sent back with the change. The zone id is a parameter because Cloudflare stopped
     * returning zone_id on records in November 2024.
     *
     * @param array<string, mixed> $details fields to change
     */
    private function updateRecord(string $zoneId, stdClass $record, array $details): stdClass
    {
        $existing = [
            'type' => $record->type,
            'name' => $record->name,
            'content' => $record->content,
            'ttl' => $record->ttl,
            'proxied' => $record->proxied ?? self::PROXIED,
        ];
        foreach (['comment', 'tags', 'priority'] as $optional) {
            if (isset($record->{$optional})) {
                $existing[$optional] = $record->{$optional};
            }
        }

        return $this->dns->updateRecordDetails($zoneId, $record->id, array_merge($existing, $details));
    }

    public function configure(string $zoneId): void
    {
        $this->ssl->updateHTTPSRewritesSetting($zoneId, 'on'); // Automatic HTTPS Rewrites
        $this->ssl->updateHTTPSRedirectSetting($zoneId, 'on'); // Always Use HTTPS
        $this->zoneSettings->updateMinifySetting($zoneId, 'off', 'off', 'off');
        $this->zoneSettings->updateBrotliSetting($zoneId, 'on');
    }

    public function updateIp(string $ip, array $domainWhitelist): void
    {
        $listZones = fn(int $page) => $this->zones->listZones($name = '', $status = '', $page);
        $zones = self::paginate($listZones);
        foreach ($zones as $zone) {
            print "Updating zone $zone->name..." . PHP_EOL;
            $records = $this->listRecords($zone->id, $type = 'A');
            $isWhitelisted = fn($record) => in_array($record->name, $domainWhitelist);
            $whitelistedRecords = array_filter($records, $isWhitelisted);
            foreach ($whitelistedRecords as $record) {
                print "Updating record $record->name..." . PHP_EOL;
                try {
                    $update = $this->updateRecord($zone->id, $record, [
                        'content' => $ip,
                        'ttl' => self::TTL,
                    ]);
                    if ($update->success) {
                        print "Updated record $record->name!" . PHP_EOL;
                    } else {
                        print_r($update->errors);
                        exit(1);
                    }
                } catch (ResponseException $e) {
                    if ($e->getMessage() == 'Record already exists.') {
                        continue;
                    }

                    throw $e;
                }
            }
        }
    }

    public function listZones(
        string $name = '',
        string $status = '',
        int    $page = 1,
        int    $perPage = 20,
        string $order = '',
        string $direction = '',
        string $match = 'all'
    ): array {
        $listZones = fn(int $page) => $this->zones->listZones($name, $status, $page, $perPage, $order, $direction, $match);
        $zoneGenerator = self::paginate($listZones);

        return iterator_to_array($zoneGenerator, preserve_keys: false);
    }

    public static function paginate(callable $getPage): Iterator
    {
        $pageNumber = 1;
        do {
            $page = $getPage($pageNumber);
            $meta = $page->result_info;
            yield from $page->result;
        } while (++$pageNumber <= $meta->total_pages);
    }

}
