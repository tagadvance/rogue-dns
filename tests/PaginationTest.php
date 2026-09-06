<?php

declare(strict_types=1);

namespace tagadvance\roguedns\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use tagadvance\roguedns\Cloudflare;
use tagadvance\roguedns\Test\Support\FakeAdapter;

#[CoversClass(Cloudflare::class)]
final class PaginationTest extends TestCase
{
    public function testListRecordsReturnsEveryPage(): void
    {
        $adapter = new FakeAdapter();
        $adapter->queue('get', 'zones/z1/dns_records', self::page(['a.example.com', 'b.example.com'], 3));
        $adapter->queue('get', 'zones/z1/dns_records', self::page(['c.example.com', 'd.example.com'], 3));
        $adapter->queue('get', 'zones/z1/dns_records', self::page(['e.example.com', 'f.example.com'], 3));

        $records = Cloudflare::fromAdapter($adapter)->listRecords('z1');

        self::assertSame(
            ['a.example.com', 'b.example.com', 'c.example.com', 'd.example.com', 'e.example.com', 'f.example.com'],
            array_column($records, 'name'),
        );
    }

    public function testListZonesReturnsEveryPage(): void
    {
        $adapter = new FakeAdapter();
        $adapter->queue('get', 'zones', self::page(['one.example.com'], 2));
        $adapter->queue('get', 'zones', self::page(['two.example.com'], 2));

        $zones = Cloudflare::fromAdapter($adapter)->listZones();

        self::assertSame(['one.example.com', 'two.example.com'], array_column($zones, 'name'));
    }

    public function testSinglePageIsFetchedOnce(): void
    {
        $adapter = new FakeAdapter();
        $adapter->queue('get', 'zones/z1/dns_records', self::page(['only.example.com'], 1));

        $records = Cloudflare::fromAdapter($adapter)->listRecords('z1');

        self::assertCount(1, $records);
        self::assertCount(1, $adapter->requests);
    }

    /**
     * @param list<string> $names
     * @return array<string, mixed>
     */
    private static function page(array $names, int $totalPages): array
    {
        $result = [];
        foreach ($names as $i => $name) {
            $result[] = ['id' => "r$i", 'name' => $name, 'type' => 'A', 'content' => '203.0.113.1', 'ttl' => 60];
        }

        return [
            'success' => true,
            'result' => $result,
            'result_info' => ['total_pages' => $totalPages],
        ];
    }
}
