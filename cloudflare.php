#!/usr/bin/env php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use tagadvance\roguedns\Cloudflare;
use GuzzleHttp\Exception\ClientException;

const CONFIG_FILE = __DIR__ . '/config.ini';

$options = getopt('', [
	'add-zone:',
	'list-zones',
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
	if (isset($options['list-zones'])) {
		$zones = $cloudflare->listZones();
		foreach ($zones as $zone) {
				print "$zone->name" . PHP_EOL;
				$records = $cloudflare->listRecords($zone->id);
				foreach ($records as $record) {
					print "\t$record->type $record->name $record->content" . PHP_EOL;
				}
		}
	} else if (isset($options['add-zone'])) {
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
			$cloudflare->updateIp($ip, $config['domains']['domain']);
		} else {
			if (!$config['ip']) {
				$message = sprintf('please set one or more ip urls in %s', CONFIG_FILE);
				throw new \RuntimeException($message);
			}

			$domain = $config['domains']['primary'];
			$r = dns_get_record($domain, DNS_A, $config['dns']);
			if (!isset($r[0]['ip']) || !filter_var($r[0]['ip'], FILTER_VALIDATE_IP)) {
				$message = sprintf('DNS lookup failed for %s', $domain);
				throw new \RuntimeException($message);
			}

			$currentIp = $r[0]['ip'];
			$newIp = get_public_ip_address();

			if ($newIp == $currentIp) {
				print '...' . PHP_EOL;
			} else {
				print "New IP address detected: $currentIp => $newIp" . PHP_EOL;
				$cloudflare->updateIp($newIp, $config['domains']['domain']);
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
# list zones and their records
./$script --list-zones
# add a new zone with reasonable defaults
./$script --add-zone foo.com
# automatically detect IP
./$script --update-ip
# manually set IP address
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
    $ip = trim($ip);
		if (filter_var($ip, FILTER_VALIDATE_IP)) {
			return trim($ip);
		}
	}

	throw new \RuntimeException('public ip address could not be found');
}


