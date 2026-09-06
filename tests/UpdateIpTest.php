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

        $this->silently(fn() => Cloudflare::fromAdapter($adapter)->updateIp('203.0.113.9', ['example.com']));

        self::assertSame([], self::putUris($adapter));
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

        $this->silently(fn() => Cloudflare::fromAdapter($adapter)->updateIp('203.0.113.9', ['pages.example.com']));

        self::assertSame([], self::putUris($adapter), 'a round-robin set must not be half rewritten');
    }

    public function testOtherNamesStillUpdateWhenOneIsSkipped(): void
    {
        $adapter = self::adapterWithRecords([
            ['id' => 'r1', 'name' => 'pages.example.com', 'type' => 'A', 'content' => '185.199.108.153', 'ttl' => 60],
            ['id' => 'r2', 'name' => 'pages.example.com', 'type' => 'A', 'content' => '185.199.109.153', 'ttl' => 60],
            ['id' => 'r3', 'name' => 'home.example.com', 'type' => 'A', 'content' => '198.51.100.1', 'ttl' => 60],
        ]);
        $adapter->queue('put', 'zones/z1/dns_records/r3', ['success' => true, 'result' => []]);

        $this->silently(fn() => Cloudflare::fromAdapter($adapter)
            ->updateIp('203.0.113.9', ['pages.example.com', 'home.example.com']));

        self::assertSame(['zones/z1/dns_records/r3'], self::putUris($adapter));
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
