<?php

declare(strict_types=1);

namespace tagadvance\roguedns;

use Cloudflare\API\Adapter\Adapter;
use Cloudflare\API\Adapter\Guzzle;
use Cloudflare\API\Adapter\ResponseException;
use Cloudflare\API\Auth\APIToken;
use Cloudflare\API\Endpoints\DNS;
use Cloudflare\API\Endpoints\SSL;
use Cloudflare\API\Endpoints\Zones;
use Iterator;
use RuntimeException;
use stdClass;

class Cloudflare
{
    /** Short enough that a stale answer is not cached for long after the address moves. */
    public const TTL = 60;

    /** Records are grey-clouded: this tool exists to publish the origin, not to proxy it. */
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

    /**
     * Creates a zone with an apex A record and a wildcard CNAME pointing at it, which is why the
     * whitelist only ever needs to name the apex.
     *
     * Jump start is on, so Cloudflare imports whatever records it can already see; anything it
     * imported that this method would otherwise create is left alone, and the www CNAME it likes
     * to add is removed. The caller supplies the address because this class does no lookups.
     */
    public function addZone(string $name, string $ip, bool $printNs = false): Zone
    {
        $zone = Zone::fromResponse($this->zones->addZone($name, jumpStart: true));

        if ($printNs) {
            foreach ($zone->nameServers as $nameServer) {
                print "Name Server: $nameServer" . PHP_EOL;
            }
        }

        $recordsByName = array_column($this->listRecords($zone->id), null, 'name');

        if (!isset($recordsByName[$zone->name])) {
            print "Creating A $zone->name" . PHP_EOL;
            $this->dns->addRecord($zone->id, 'A', $zone->name, $ip, self::TTL, self::PROXIED);
        }

        $wildCname = "*.$zone->name";
        if (!isset($recordsByName[$wildCname])) {
            print "Creating CNAME $wildCname" . PHP_EOL;
            $this->dns->addRecord($zone->id, 'CNAME', $wildCname, $zone->name, self::TTL, self::PROXIED);
        }

        foreach (['www', "www.$zone->name"] as $subdomain) {
            if (isset($recordsByName[$subdomain])) {
                print "Deleting CNAME $subdomain" . PHP_EOL;
                $this->dns->deleteRecord($zone->id, $recordsByName[$subdomain]->id);
            }
        }

        return $zone;
    }

    /**
     * Every record in the zone, across all pages.
     *
     * @return list<Record>
     * @throws RuntimeException if a record is missing a field this project relies on
     */
    public function listRecords(string $zoneId, string $type = '', string $name = '', string $content = ''): array
    {
        $listRecords = fn(int $page): stdClass => $this->dns->listRecords($zoneId, $type, $name, $content, $page);

        return array_map(
            Record::fromResponse(...),
            iterator_to_array(self::paginate($listRecords), preserve_keys: false),
        );
    }

    /**
     * Every zone on the account, across all pages.
     *
     * @return list<Zone>
     * @throws RuntimeException if a zone is missing a field this project relies on
     */
    public function listZones(
        string $name = '',
        string $status = '',
        int $perPage = 20,
        string $order = '',
        string $direction = '',
        string $match = 'all',
    ): array {
        $listZones = fn(int $page): stdClass => $this->zones->listZones($name, $status, $page, $perPage, $order, $direction, $match);

        return array_map(
            Zone::fromResponse(...),
            iterator_to_array(self::paginate($listZones), preserve_keys: false),
        );
    }

    /**
     * Turns off the orange cloud for every proxied record in the zone.
     *
     * Cloudflare's jump-start import proxies what it creates, which hides the origin behind an
     * edge address and makes the DNS answer useless as a check on what was published.
     */
    public function deproxifyRecords(string $zoneId): void
    {
        foreach ($this->listRecords($zoneId) as $record) {
            if ($record->proxied) {
                $this->updateRecord($zoneId, $record, ['proxied' => false]);
            }
        }
    }

    /**
     * Applies this project's zone defaults. Note that Cloudflare has since removed the Auto
     * Minify and Brotli toggles, so those two calls may no longer do anything.
     */
    public function configure(string $zoneId): void
    {
        $this->ssl->updateHTTPSRewritesSetting($zoneId, 'on'); // Automatic HTTPS Rewrites
        $this->ssl->updateHTTPSRedirectSetting($zoneId, 'on'); // Always Use HTTPS
        $this->zoneSettings->updateMinifySetting($zoneId, 'off', 'off', 'off');
        $this->zoneSettings->updateBrotliSetting($zoneId, 'on');
    }

    /**
     * Points every whitelisted A record at $ip, across every zone on the account.
     *
     * Matching is on the exact record name, so wildcards in the whitelist match nothing. Records
     * already holding this address at this TTL are skipped, which is what keeps a disagreement
     * between the caller's change check and Cloudflare's actual state from becoming a write
     * every run.
     *
     * @param list<string> $domainWhitelist exact record names to update
     * @throws RuntimeException when the API reports an update as unsuccessful
     */
    public function updateIp(string $ip, array $domainWhitelist): void
    {
        foreach ($this->listZones() as $zone) {
            print "Updating zone $zone->name..." . PHP_EOL;

            foreach ($this->listRecords($zone->id, type: 'A') as $record) {
                if (!in_array($record->name, $domainWhitelist, strict: true)) {
                    continue;
                }
                if ($record->content === $ip && $record->ttl === self::TTL) {
                    continue;
                }

                print "Updating record $record->name..." . PHP_EOL;
                try {
                    $update = $this->updateRecord($zone->id, $record, [
                        'content' => $ip,
                        'ttl' => self::TTL,
                    ]);
                    if (Field::bool($update, 'success', false) !== true) {
                        $errors = json_encode($update->errors ?? null, JSON_THROW_ON_ERROR);

                        throw new RuntimeException("could not update $record->name: $errors");
                    }
                    print "Updated record $record->name!" . PHP_EOL;
                } catch (ResponseException $e) {
                    // Cloudflare rejects a duplicate rather than treating the write as a no-op.
                    if ($e->getMessage() === 'Record already exists.') {
                        continue;
                    }

                    throw $e;
                }
            }
        }
    }

    /**
     * The zone id is a parameter because Cloudflare stopped returning zone_id on records in
     * November 2024.
     *
     * @param array<string, mixed> $details fields to change
     */
    private function updateRecord(string $zoneId, Record $record, array $details): stdClass
    {
        $payload = array_merge($record->toUpdatePayload(), $details);

        return $this->dns->updateRecordDetails($zoneId, $record->id, $payload);
    }

    /**
     * Walks a paginated endpoint, yielding each page's results in order.
     *
     * Consume it with iterator_to_array(..., preserve_keys: false) or a foreach: `yield from`
     * re-emits each page array's own keys, so preserving them collapses every page onto the last.
     *
     * @param callable(int): stdClass $getPage returns a response carrying `result` and `result_info`
     * @return Iterator<int, stdClass>
     */
    public static function paginate(callable $getPage): Iterator
    {
        $pageNumber = 1;
        do {
            $page = $getPage($pageNumber);
            $totalPages = Field::int(Field::object($page, 'result_info'), 'total_pages');
            yield from Field::objectList($page, 'result');
        } while (++$pageNumber <= $totalPages);
    }
}
