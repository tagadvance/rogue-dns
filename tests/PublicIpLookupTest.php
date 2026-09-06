<?php

declare(strict_types=1);

namespace tagadvance\roguedns\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tagadvance\roguedns\PublicIpLookup;

#[CoversClass(PublicIpLookup::class)]
final class PublicIpLookupTest extends TestCase
{
    #[DataProvider('unusableAddresses')]
    public function testUnusableAddressesAreRejected(string $candidate): void
    {
        self::assertNull(PublicIpLookup::validate($candidate));
    }

    /**
     * @return list<array{string}>
     */
    public static function unusableAddresses(): array
    {
        return [
            ['127.0.0.1'],
            ['10.0.0.1'],
            ['192.168.1.1'],
            ['172.16.0.1'],
            ['169.254.1.1'],
            ['0.0.0.0'],
            ['::1'],
            ['2001:db8::1'],
            ['not an address'],
            [''],
        ];
    }

    public function testPublicIpv4IsAccepted(): void
    {
        self::assertSame('203.0.113.9', PublicIpLookup::validate('203.0.113.9'));
    }

    public function testFailureNamesEverySourceItTried(): void
    {
        $lookup = new PublicIpLookup(['http://127.0.0.1:9/a', 'http://127.0.0.1:9/b']);

        $this->expectExceptionMessageMatches('/(a: unreachable.+b: unreachable|b: unreachable.+a: unreachable)/s');

        $lookup->find();
    }
}
