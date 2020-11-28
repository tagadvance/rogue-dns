#!/usr/bin/env php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Cloudflare\API\Adapter\Adapter;
use Cloudflare\API\Adapter\Guzzle;
use Cloudflare\API\Auth\APIToken;
use Cloudflare\API\Endpoints\DNS;
use Cloudflare\API\Endpoints\SSL;
use Cloudflare\API\Endpoints\Zones;
use GuzzleHttp\Exception\ClientException;
use tagadvance\roguedns\ZoneSettings;

define('CONFIG_FILE', 'config.ini');
define('DEFAULT_CACHE', '/tmp/.current-ip');

$options = getopt('', [
	'add-zone:',
	'update-ip::',
	'help'
]);
if (isset($options['help'])) {
	print_example_usage();
	exit;
}

if (!is_readable(CONFIG_FILE)) {
	throw new \RuntimeException('configuration is missing');
}
$config = parse_ini_file(CONFIG_FILE, true);
$token = $config['api']['token'];
if (!$token) {
	$message = sprintf('please set token value in %s', CONFIG_FILE);
	throw new \RuntimeException($message);
}

$cloudflare = Cloudflare::fromToken($config['api']['token']);

try {
	if (isset($options['add-zone'])) {
		$name = $options['add-zone'];
		if (!filter_var($name, FILTER_VALIDATE_DOMAIN)) {
			throw new \InvalidArgumentException('zone name must be a valid domain name');
		}

		$zone = $cloudflare->addZone($name, $printNs = true);
		$cloudflare->deproxifyRecords($zone->id);
		$cloudflare->configure($zone->id);
	} else if (isset($options['update-ip'])) {
		$ip = $options['update-ip'];
		if (filter_var($ip, FILTER_VALIDATE_IP)) {
			$cloudflare->updateIp($ip);
		} else {
			if (!$config['ip']) {
				$message = sprintf('please set one or more ip urls in %s', CONFIG_FILE);
				throw new \RuntimeException($message);
			}

			$newIp = get_public_ip_address();

			$cache = $config['cache'] ?? DEFAULT_CACHE;
			if (!is_readable($cache)) {
				print "Creating $cache with $newIp" . PHP_EOL;
				file_put_contents($cache, $newIp);
				exit(0);
			}

			$currentIp = file_get_contents($cache);
			print "Cached IP address: $currentIp" . PHP_EOL;

			if ($newIp == $currentIp) {
				print '...' . PHP_EOL;
			} else {
				print "New IP address detected ($currentIp,$newIp)" . PHP_EOL;
				$cloudflare->updateIp($newIp);
				file_put_contents($cache, $newIp);
			}
		}
	} else {
		print_example_usage();
		exit;
	}
} catch (ClientException $e) {
	print $e->getTraceAsString();
	exit(1);
}

function print_example_usage(): void
{
	$script = basename(__FILE__);
	print <<<EXAMPLE
	./$script --add-zone foo.com
	# WARNING: These will update *ALL* of your zones!
	./$script --update-ip
	./$script --update-ip 127.0.0.1
	
	EXAMPLE;
}

function get_public_ip_address(): string
{
    global $config;
    $urls = $config['ip']['url'];

	// be kind to free services by randomizing urls to spread to load
	shuffle($urls);
	while (!empty($urls)) {
		$url = array_shift($urls);
		$ip = file_get_contents($url);
		if (filter_var($ip, FILTER_VALIDATE_IP)) {
			return trim($ip);
		}
	}

	throw new \RuntimeException('public ip address could not be found');
}

class Cloudflare
{
    const TTL = 60;
	const PROXIED = false;

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

	private function __construct(Adapter $adapter)
	{
		$this->dns = new DNS($adapter);
		$this->ssl = new SSL($adapter);
		$this->zones = new Zones($adapter);
		$this->zoneSettings = new ZoneSettings($adapter);
	}

	public function addZone(string $name, bool $printNs = false)
	{
		$zone = $this->zones->addZone($name, $jumpStart = true);

		if ($printNs) {
			foreach ($zone->name_servers as $nameServer) {
				print "Name Server: $nameServer" . PHP_EOL;
			}
		}

		$records = $this->listRecords($zone->id);
		$recordsByName = array_column($records, 'name');
		if (!isset($recordsByName[$zone->name])) {
			print "Creating A $zone->name" . PHP_EOL;

			$ip = get_public_ip_address();
			$this->dns->addRecord($zone->id, 'A', $zone->name, $ip, self::TTL, self::PROXIED);
		}

		$wildCname = "*.$zone->name";
		if (!isset($records[$wildCname])) {
			print "Creating CNAME $wildCname" . PHP_EOL;
			$this->dns->addRecord($zone->id, 'CNAME', $wildCname, $zone->name, self::TTL, self::PROXIED);
		}

		$www = ['www', "www.$zone->name"];
		foreach ($www as $subdomain) {
			if (isset($records[$subdomain])) {
				print "Deleting CNAME $subdomain" . PHP_EOL;
				$this->dns->deleteRecord($zone->id, $records[$subdomain]->id);
			}
        }

		return $zone;
	}

	private function listRecords(string $zoneId, string $type = '', $name = '', $content = ''): array
	{
		$listRecords = fn(int $page) => $this->dns->listRecords($zoneId, $type, $name, $content, $page);
		$recordGenerator = self::paginate($listRecords);

		return iterator_to_array($recordGenerator);
	}

	public function deproxifyRecords(string $zoneId): void
	{
		$records = $this->listRecords($zoneId);
		$proxiedRecords = array_filter($records, fn($record) => $record->proxied);
		foreach ($proxiedRecords as $record) {
			$update = $this->patchRecordDetails($record, [
				'proxied' => false,
			]);
			print_r($update);
		}
	}

	private function patchRecordDetails(\stdClass $record, array $details)
	{
		$required = [
			'type' => $record->type,
			'name' => $record->name,
			'content' => $record->content,
			'ttl' => $record->ttl,
		];
		$details = array_merge($required, $details);

		return $this->dns->updateRecordDetails($record->zone_id, $record->id, $details);
	}

	public function configure(string $zoneId): void
	{
		$this->ssl->updateHTTPSRewritesSetting($zoneId, 'on'); // Automatic HTTPS Rewrites
		$this->ssl->updateHTTPSRedirectSetting($zoneId, 'on'); // Always Use HTTPS
		$this->zoneSettings->updateMinifySetting($zoneId, 'off', 'off', 'off');
		$this->zoneSettings->updateBrotliSetting($zoneId, 'on');
	}

	public function updateIp(string $ip): void
	{
		$listZones = fn(int $page) => $this->zones->listZones($name = '', $status = '', $page);
		$zones = self::paginate($listZones);
		foreach ($zones as $zone) {
			print "Updating zone $zone->name..." . PHP_EOL;
			$records = $this->listRecords($zone->id, $type = 'A');
			$isRoot = fn($record) => in_array($record->name, ['@', $record->zone_name]);
			$rootRecords = array_filter($records, $isRoot);
			$rootRecord = current($rootRecords);
			print "Updating record $rootRecord->name..." . PHP_EOL;
			$update = $this->patchRecordDetails($rootRecord, [
				'content' => $ip,
				'ttl' => self::TTL,
			]);
			if ($update->success) {
				print "Updated record $rootRecord->name!" . PHP_EOL;
			} else {
				print_r($update->errors);
				exit(1);
			}
		}
	}

	public static function paginate(callable $getPage): Generator
	{
		$pageNumber = 1;
		do {
			$page = $getPage($pageNumber);
			$meta = $page->result_info;
			yield from $page->result;
		} while (++$pageNumber <= $meta->total_pages);
	}
}


