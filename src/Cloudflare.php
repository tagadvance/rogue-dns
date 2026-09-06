<?php

declare(strict_types=1);

namespace tagadvance\roguedns;

use Cloudflare\API\Adapter\Adapter;
use Cloudflare\API\Adapter\Guzzle;
use Cloudflare\API\Adapter\ResponseException;
use Cloudflare\API\Auth\APIToken;
use Cloudflare\API\Endpoints\Accounts;
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

    private Adapter $adapter;
    private DNS $dns;
    private SSL $ssl;
    private Zones $zones;
    private ZoneSettings $zoneSettings;

    /** @var resource */
    private $errorStream;

    public static function fromToken(string $token): self
    {
        $key = new APIToken($token);
        $adapter = new Guzzle($key);

        return new self($adapter);
    }

    /**
     * Builds a client over an already-configured adapter. Prefer fromToken(); this exists so
     * tests can substitute a transport and capture what would go to stderr.
     *
     * @param resource|null $errorStream defaults to STDERR
     */
    public static function fromAdapter(Adapter $adapter, $errorStream = null): self
    {
        return new self($adapter, $errorStream);
    }

    /**
     * @param resource|null $errorStream defaults to STDERR
     */
    private function __construct(Adapter $adapter, $errorStream = null)
    {
        $this->adapter = $adapter;
        $this->errorStream = $errorStream ?? STDERR;
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
     *
     * @param string $accountId required by POST /zones under a scoped token; discover it with
     *                          accountId() rather than hardcoding it
     */
    public function addZone(string $name, string $ip, bool $printNs = false, string $accountId = ''): Zone
    {
        $zone = Zone::fromResponse($this->zones->addZone($name, jumpStart: true, accountId: $accountId));

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
     * The first account this token can see, which is the one a new zone belongs to.
     *
     * POST /zones rejects a request with no account when the token is scoped, which is every
     * token created through the dashboard's token UI.
     *
     * @throws RuntimeException when the token can see no accounts
     */
    public function accountId(): string
    {
        $accounts = new Accounts($this->adapter);
        $listAccounts = fn(int $page): stdClass => $accounts->listAccounts($page);
        $first = iterator_to_array(self::paginate($listAccounts), preserve_keys: false)[0] ?? null;

        if ($first === null) {
            throw new RuntimeException('the API token can see no accounts; it needs account-level scope to add a zone');
        }

        return Field::string($first, 'id');
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
     * Every whitelisted record is reconciled against what the API reports on every call. There is
     * deliberately no cheaper "has anything changed" pre-check: any such check answers a question
     * about one name, or about a cached copy, and cannot establish that the other records are
     * correct. A pass interrupted partway -- which is what a router reboot during an address
     * change produces -- leaves records split across two addresses, and only re-reading all of
     * them converges.
     *
     * Progress goes to stdout; a name that had to be skipped is reported on the error stream,
     * so the two can be told apart by whatever is reading the container log.
     *
     * @param list<string> $domainWhitelist exact record names to update
     * @param bool $dryRun report what would change without writing anything
     * @return int records changed, or that would change in a dry run
     * @throws RuntimeException when the API reports an update as unsuccessful
     */
    public function updateIp(string $ip, array $domainWhitelist, bool $dryRun = false): int
    {
        $changed = 0;

        foreach ($this->listZones() as $zone) {
            // Only zones that could hold a whitelisted name. Without this the pass costs one
            // request per zone on the account, most of them for zones with nothing to do.
            if (!self::mayContain($zone, $domainWhitelist)) {
                continue;
            }

            $whitelisted = array_filter(
                $this->listRecords($zone->id, type: 'A'),
                fn(Record $record): bool => in_array($record->name, $domainWhitelist, strict: true),
            );

            foreach (self::groupByName($whitelisted) as $name => $records) {
                // A name served by several A records is round-robin, not dynamic DNS. Writing
                // this address into the first would break whatever the set points at, and
                // Cloudflare rejects the rest as duplicates -- an error this method deliberately
                // ignores, so the result would be a silent partial rewrite.
                if (count($records) > 1) {
                    fwrite($this->errorStream, sprintf(
                        'Skipping %s: %d A records (%s). Remove it from the whitelist, or reduce it to one record.%s',
                        $name,
                        count($records),
                        implode(', ', array_map(fn(Record $r): string => $r->content, $records)),
                        PHP_EOL,
                    ));

                    continue;
                }

                $record = $records[0];
                // Cloudflare forces ttl=1 ("auto") on a proxied record and ignores any value
                // sent for it, so comparing TTL there would make every proxied record look
                // permanently stale and rewrite it on every pass, forever.
                $ttlSettled = $record->proxied || $record->ttl === self::TTL;
                if ($record->content === $ip && $ttlSettled) {
                    continue;
                }

                if ($dryRun) {
                    print sprintf('Would update %s: %s => %s', $record->name, $record->content, $ip) . PHP_EOL;
                    $changed++;

                    continue;
                }

                try {
                    // Leave a proxied record's TTL alone for the same reason; toUpdatePayload
                    // echoes back whatever Cloudflare currently holds.
                    $details = ['content' => $ip];
                    if (!$record->proxied) {
                        $details['ttl'] = self::TTL;
                    }

                    $update = $this->updateRecord($zone->id, $record, $details);
                    if (Field::bool($update, 'success', false) !== true) {
                        $errors = json_encode($update->errors ?? null, JSON_THROW_ON_ERROR);

                        throw new RuntimeException("could not update $record->name: $errors");
                    }
                    print sprintf('Updated %s: %s => %s', $record->name, $record->content, $ip) . PHP_EOL;
                    $changed++;
                } catch (ResponseException $e) {
                    // Cloudflare rejects a duplicate rather than treating the write as a no-op.
                    if (self::isDuplicateRecord($e)) {
                        continue;
                    }

                    throw $e;
                }
            }
        }

        return $changed;
    }

    /**
     * Every A record from the zones that could hold a whitelisted name. Read-only, and the same
     * set updateIp reconciles, so a report built from it describes what updateIp would actually
     * see.
     *
     * @param list<string> $domainWhitelist
     * @return list<Record>
     */
    public function listWhitelistedRecords(array $domainWhitelist): array
    {
        $records = [];
        foreach ($this->listZones() as $zone) {
            if (!self::mayContain($zone, $domainWhitelist)) {
                continue;
            }

            foreach ($this->listRecords($zone->id, type: 'A') as $record) {
                if (in_array($record->name, $domainWhitelist, strict: true)) {
                    $records[] = $record;
                }
            }
        }

        return $records;
    }

    /**
     * Whether any whitelisted name falls inside this zone.
     *
     * @param list<string> $domainWhitelist
     */
    private static function mayContain(Zone $zone, array $domainWhitelist): bool
    {
        foreach ($domainWhitelist as $name) {
            if ($name === $zone->name || str_ends_with($name, ".$zone->name")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cloudflare's error codes for "a record with this name and content already exists". The
     * code is the stable identifier; the message is human-readable prose that can be reworded,
     * and matching on it was one Cloudflare copy edit away from turning a skip into a crash.
     */
    private static function isDuplicateRecord(ResponseException $e): bool
    {
        return in_array($e->getCode(), [81053, 81057, 81058], strict: true)
            || $e->getMessage() === 'Record already exists.';
    }

    /**
     * @param array<int, Record> $records
     * @return array<string, non-empty-list<Record>>
     */
    private static function groupByName(array $records): array
    {
        $grouped = [];
        foreach ($records as $record) {
            $grouped[$record->name][] = $record;
        }

        return $grouped;
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
