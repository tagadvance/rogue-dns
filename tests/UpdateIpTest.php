<?php

declare(strict_types=1);

namespace tagadvance\roguedns\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use tagadvance\roguedns\Cloudflare;
use tagadvance\roguedns\Test\Support\FakeAdapter;
use tagadvance\roguedns\Test\Support\SilencesOutput;

#[CoversClass(Cloudflare::class)]
final class UpdateIpTest extends TestCase
{
    use SilencesOutput;

    public function testRecordsAlreadyPointingAtTheAddressAreNotRewritten(): void
    {
        $adapter = self::adapterWithRecords([
            ['id' => 'r1', 'name' => 'example.com', 'type' => 'A', 'content' => '203.0.113.9', 'ttl' => 60],
        ]);

        $this->silently(fn() => Cloudflare::fromAdapter($adapter)->updateIp('203.0.113.9', ['example.com']));

        self::assertSame([], self::putUris($adapter));
    }

    public function testStaleRecordsAreRewritten(): void
    {
        $adapter = self::adapterWithRecords([
            ['id' => 'r1', 'name' => 'example.com', 'type' => 'A', 'content' => '198.51.100.1', 'ttl' => 60],
        ]);
        $adapter->queue('put', 'zones/z1/dns_records/r1', ['success' => true, 'result' => []]);

        $this->silently(fn() => Cloudflare::fromAdapter($adapter)->updateIp('203.0.113.9', ['example.com']));

        self::assertSame(['zones/z1/dns_records/r1'], self::putUris($adapter));
    }

    public function testARecordWithTheRightTtlButWrongTtlIsRewritten(): void
    {
        $adapter = self::adapterWithRecords([
            ['id' => 'r1', 'name' => 'example.com', 'type' => 'A', 'content' => '203.0.113.9', 'ttl' => 3600],
        ]);
        $adapter->queue('put', 'zones/z1/dns_records/r1', ['success' => true, 'result' => []]);

        $this->silently(fn() => Cloudflare::fromAdapter($adapter)->updateIp('203.0.113.9', ['example.com']));

        self::assertSame(['zones/z1/dns_records/r1'], self::putUris($adapter));
    }

    public function testRecordsOutsideTheWhitelistAreNotTouched(): void
    {
        $adapter = self::adapterWithRecords([
            ['id' => 'r1', 'name' => 'other.example.com', 'type' => 'A', 'content' => '198.51.100.1', 'ttl' => 60],
        ]);

        $errors = $this->captureErrors();
        $this->silently(fn() => Cloudflare::fromAdapter($adapter, $errors)->updateIp('203.0.113.9', ['example.com']));

        self::assertSame([], self::putUris($adapter));
        self::assertSame('', $this->captured($errors), 'an ordinary pass says nothing on the error stream');
    }

    public function testDryRunReportsWithoutWriting(): void
    {
        $adapter = self::adapterWithRecords([
            ['id' => 'r1', 'name' => 'example.com', 'type' => 'A', 'content' => '198.51.100.1', 'ttl' => 60],
        ]);

        ob_start();
        Cloudflare::fromAdapter($adapter)->updateIp('203.0.113.9', ['example.com'], dryRun: true);
        $output = (string) ob_get_clean();

        self::assertSame([], self::putUris($adapter));
        self::assertStringContainsString('Would update example.com: 198.51.100.1 => 203.0.113.9', $output);
    }

    public function testANameWithSeveralARecordsIsSkippedRatherThanPartiallyRewritten(): void
    {
        $adapter = self::adapterWithRecords([
            ['id' => 'r1', 'name' => 'pages.example.com', 'type' => 'A', 'content' => '185.199.108.153', 'ttl' => 60],
            ['id' => 'r2', 'name' => 'pages.example.com', 'type' => 'A', 'content' => '185.199.109.153', 'ttl' => 60],
        ]);

        $errors = $this->captureErrors();
        $this->silently(fn() => Cloudflare::fromAdapter($adapter, $errors)->updateIp('203.0.113.9', ['pages.example.com']));

        self::assertSame([], self::putUris($adapter), 'a round-robin set must not be half rewritten');
        self::assertStringContainsString(
            'Skipping pages.example.com: 2 A records',
            $this->captured($errors),
            'and the operator is told which name and why',
        );
    }

    public function testOtherNamesStillUpdateWhenOneIsSkipped(): void
    {
        $adapter = self::adapterWithRecords([
            ['id' => 'r1', 'name' => 'pages.example.com', 'type' => 'A', 'content' => '185.199.108.153', 'ttl' => 60],
            ['id' => 'r2', 'name' => 'pages.example.com', 'type' => 'A', 'content' => '185.199.109.153', 'ttl' => 60],
            ['id' => 'r3', 'name' => 'home.example.com', 'type' => 'A', 'content' => '198.51.100.1', 'ttl' => 60],
        ]);
        $adapter->queue('put', 'zones/z1/dns_records/r3', ['success' => true, 'result' => []]);

        $errors = $this->captureErrors();
        $this->silently(fn() => Cloudflare::fromAdapter($adapter, $errors)
            ->updateIp('203.0.113.9', ['pages.example.com', 'home.example.com']));

        self::assertSame(['zones/z1/dns_records/r3'], self::putUris($adapter));
        self::assertStringContainsString('Skipping pages.example.com', $this->captured($errors));
    }

    /**
     * A pass interrupted partway -- a router reboot during an address change -- leaves records
     * split across two addresses. The next pass must converge them, and must not be talked out
     * of running by any record already being correct.
     */
    public function testASplitFleetConvergesOnTheNextPass(): void
    {
        $adapter = self::adapterWithRecords([
            // already written before the interruption
            ['id' => 'r1', 'name' => 'a.example.com', 'type' => 'A', 'content' => '203.0.113.9', 'ttl' => 60],
            ['id' => 'r2', 'name' => 'b.example.com', 'type' => 'A', 'content' => '203.0.113.9', 'ttl' => 60],
            // never reached
            ['id' => 'r3', 'name' => 'c.example.com', 'type' => 'A', 'content' => '198.51.100.1', 'ttl' => 60],
            ['id' => 'r4', 'name' => 'd.example.com', 'type' => 'A', 'content' => '198.51.100.1', 'ttl' => 60],
        ]);
        $adapter->queue('put', 'zones/z1/dns_records/r3', ['success' => true, 'result' => []]);
        $adapter->queue('put', 'zones/z1/dns_records/r4', ['success' => true, 'result' => []]);

        $whitelist = ['a.example.com', 'b.example.com', 'c.example.com', 'd.example.com'];
        $changed = 0;
        $this->silently(function () use ($adapter, $whitelist, &$changed): void {
            $changed = Cloudflare::fromAdapter($adapter)->updateIp('203.0.113.9', $whitelist);
        });

        self::assertSame(
            ['zones/z1/dns_records/r3', 'zones/z1/dns_records/r4'],
            self::putUris($adapter),
            'only the stale half is rewritten, and it is rewritten',
        );
        self::assertSame(2, $changed);
    }

    public function testZonesHoldingNothingWhitelistedAreNotRead(): void
    {
        $adapter = new FakeAdapter();
        $adapter->queue('get', 'zones', [
            'success' => true,
            'result' => [
                ['id' => 'z1', 'name' => 'example.com'],
                ['id' => 'z2', 'name' => 'unrelated.com'],
            ],
            'result_info' => ['total_pages' => 1],
        ]);
        $adapter->queue('get', 'zones/z1/dns_records', [
            'success' => true,
            'result' => [['id' => 'r1', 'name' => 'example.com', 'type' => 'A', 'content' => '203.0.113.9', 'ttl' => 60]],
            'result_info' => ['total_pages' => 1],
        ]);

        $this->silently(fn() => Cloudflare::fromAdapter($adapter)->updateIp('203.0.113.9', ['example.com']));

        $read = array_column(array_filter($adapter->requests, fn(array $r): bool => $r['method'] === 'get'), 'uri');
        self::assertNotContains('zones/z2/dns_records', $read, 'unrelated zones cost no request');
    }

    public function testSubdomainsResolveToTheirZone(): void
    {
        $adapter = new FakeAdapter();
        $adapter->queue('get', 'zones', [
            'success' => true,
            'result' => [['id' => 'z1', 'name' => 'example.com']],
            'result_info' => ['total_pages' => 1],
        ]);
        $adapter->queue('get', 'zones/z1/dns_records', [
            'success' => true,
            'result' => [['id' => 'r1', 'name' => 'home.example.com', 'type' => 'A', 'content' => '198.51.100.1', 'ttl' => 60]],
            'result_info' => ['total_pages' => 1],
        ]);
        $adapter->queue('put', 'zones/z1/dns_records/r1', ['success' => true, 'result' => []]);

        $this->silently(fn() => Cloudflare::fromAdapter($adapter)->updateIp('203.0.113.9', ['home.example.com']));

        self::assertSame(['zones/z1/dns_records/r1'], self::putUris($adapter));
    }

    public function testAZoneWhoseNameMerelyEndsTheSameIsNotMatched(): void
    {
        $adapter = new FakeAdapter();
        $adapter->queue('get', 'zones', [
            'success' => true,
            'result' => [['id' => 'z1', 'name' => 'notexample.com']],
            'result_info' => ['total_pages' => 1],
        ]);

        $this->silently(fn() => Cloudflare::fromAdapter($adapter)->updateIp('203.0.113.9', ['example.com']));

        $read = array_column(array_filter($adapter->requests, fn(array $r): bool => $r['method'] === 'get'), 'uri');
        self::assertNotContains('zones/z1/dns_records', $read);
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    /**
     * The orange cloud is the operator's choice, per record. An IP update must carry whatever
     * the record already had, never this tool's own default.
     */
    public function testTheProxiedFlagIsCarriedThroughAnUpdate(): void
    {
        $adapter = self::adapterWithRecords([
            ['id' => 'r1', 'name' => 'proxied.example.com', 'type' => 'A', 'content' => '198.51.100.1', 'ttl' => 60, 'proxied' => true],
            ['id' => 'r2', 'name' => 'direct.example.com', 'type' => 'A', 'content' => '198.51.100.1', 'ttl' => 60, 'proxied' => false],
        ]);
        $adapter->queue('put', 'zones/z1/dns_records/r1', ['success' => true, 'result' => []]);
        $adapter->queue('put', 'zones/z1/dns_records/r2', ['success' => true, 'result' => []]);

        $this->silently(fn() => Cloudflare::fromAdapter($adapter)
            ->updateIp('203.0.113.9', ['proxied.example.com', 'direct.example.com']));

        $puts = array_values(array_filter($adapter->requests, fn(array $r): bool => $r['method'] === 'put'));
        self::assertTrue($puts[0]['data']['proxied'], 'a proxied record stays proxied');
        self::assertFalse($puts[1]['data']['proxied'], 'an unproxied record stays unproxied');
        self::assertSame('203.0.113.9', $puts[0]['data']['content'], 'and the address is still updated');
        self::assertSame('203.0.113.9', $puts[1]['data']['content']);
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private static function adapterWithRecords(array $records): FakeAdapter
    {
        $adapter = new FakeAdapter();
        $adapter->queue('get', 'zones', [
            'success' => true,
            'result' => [['id' => 'z1', 'name' => 'example.com']],
            'result_info' => ['total_pages' => 1],
        ]);
        $adapter->queue('get', 'zones/z1/dns_records', [
            'success' => true,
            'result' => $records,
            'result_info' => ['total_pages' => 1],
        ]);

        return $adapter;
    }

    /**
     * @return list<string>
     */
    private static function putUris(FakeAdapter $adapter): array
    {
        $puts = array_filter($adapter->requests, fn(array $r): bool => $r['method'] === 'put');

        return array_values(array_column($puts, 'uri'));
    }
}
