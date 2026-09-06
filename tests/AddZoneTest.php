<?php

declare(strict_types=1);

namespace tagadvance\roguedns\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use tagadvance\roguedns\Cloudflare;
use tagadvance\roguedns\Test\Support\FakeAdapter;

#[CoversClass(Cloudflare::class)]
final class AddZoneTest extends TestCase
{
    public function testExistingRecordsAreNotRecreated(): void
    {
        $adapter = self::adapterWithZoneRecords([
            ['id' => 'r1', 'name' => 'example.com', 'type' => 'A'],
            ['id' => 'r2', 'name' => '*.example.com', 'type' => 'CNAME'],
        ]);

        ob_start();
        Cloudflare::fromAdapter($adapter)->addZone('example.com', '203.0.113.1');
        ob_end_clean();

        self::assertSame([], self::urisFor($adapter, 'post', 'zones/z1/dns_records'));
    }

    public function testMissingRecordsAreCreated(): void
    {
        $adapter = self::adapterWithZoneRecords([]);
        $adapter->queue('post', 'zones/z1/dns_records', ['success' => true, 'result' => ['id' => 'new1']]);
        $adapter->queue('post', 'zones/z1/dns_records', ['success' => true, 'result' => ['id' => 'new2']]);

        ob_start();
        Cloudflare::fromAdapter($adapter)->addZone('example.com', '203.0.113.1');
        ob_end_clean();

        self::assertCount(2, self::urisFor($adapter, 'post', 'zones/z1/dns_records'));
    }

    public function testJumpStartWwwCnameIsDeleted(): void
    {
        $adapter = self::adapterWithZoneRecords([
            ['id' => 'r1', 'name' => 'example.com', 'type' => 'A'],
            ['id' => 'r2', 'name' => '*.example.com', 'type' => 'CNAME'],
            ['id' => 'rwww', 'name' => 'www.example.com', 'type' => 'CNAME'],
        ]);
        $adapter->queue('delete', 'zones/z1/dns_records/rwww', ['success' => true]);

        ob_start();
        Cloudflare::fromAdapter($adapter)->addZone('example.com', '203.0.113.1');
        ob_end_clean();

        self::assertSame(
            ['zones/z1/dns_records/rwww'],
            self::urisFor($adapter, 'delete', 'zones/z1/dns_records/'),
        );
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private static function adapterWithZoneRecords(array $records): FakeAdapter
    {
        $adapter = new FakeAdapter();
        $adapter->queue('post', 'zones', [
            'success' => true,
            'result' => ['id' => 'z1', 'name' => 'example.com', 'name_servers' => []],
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
    private static function urisFor(FakeAdapter $adapter, string $method, string $uriPrefix): array
    {
        $matching = array_filter(
            $adapter->requests,
            fn(array $r): bool => $r['method'] === $method && str_starts_with($r['uri'], $uriPrefix),
        );

        return array_values(array_column($matching, 'uri'));
    }
}
