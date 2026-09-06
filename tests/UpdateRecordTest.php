<?php

declare(strict_types=1);

namespace tagadvance\roguedns\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use tagadvance\roguedns\Cloudflare;
use tagadvance\roguedns\Test\Support\FakeAdapter;

#[CoversClass(Cloudflare::class)]
final class UpdateRecordTest extends TestCase
{
    public function testDeproxifyUsesTheZoneIdItWasGiven(): void
    {
        $adapter = new FakeAdapter();
        $adapter->queue('get', 'zones/z1/dns_records', self::records([
            ['id' => 'r1', 'name' => 'example.com', 'type' => 'A', 'content' => '203.0.113.1', 'ttl' => 60, 'proxied' => true],
        ]));
        $adapter->queue('put', 'zones/z1/dns_records/r1', ['success' => true, 'result' => []]);

        ob_start();
        Cloudflare::fromAdapter($adapter)->deproxifyRecords('z1');
        ob_end_clean();

        $put = self::requestsFor($adapter, 'put');
        self::assertSame('zones/z1/dns_records/r1', $put[0]['uri']);
        self::assertFalse($put[0]['data']['proxied']);
    }

    public function testUnproxiedRecordsAreLeftAlone(): void
    {
        $adapter = new FakeAdapter();
        $adapter->queue('get', 'zones/z1/dns_records', self::records([
            ['id' => 'r1', 'name' => 'example.com', 'type' => 'A', 'content' => '203.0.113.1', 'ttl' => 60, 'proxied' => false],
        ]));

        ob_start();
        Cloudflare::fromAdapter($adapter)->deproxifyRecords('z1');
        ob_end_clean();

        self::assertSame([], self::requestsFor($adapter, 'put'));
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return array<string, mixed>
     */
    private static function records(array $records): array
    {
        return ['success' => true, 'result' => $records, 'result_info' => ['total_pages' => 1]];
    }

    /**
     * @return list<array{method: string, uri: string, data: array<string, mixed>}>
     */
    private static function requestsFor(FakeAdapter $adapter, string $method): array
    {
        return array_values(array_filter($adapter->requests, fn(array $r): bool => $r['method'] === $method));
    }
}
