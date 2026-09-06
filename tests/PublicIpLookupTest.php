<?php

declare(strict_types=1);

namespace tagadvance\roguedns\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use tagadvance\roguedns\PublicIpLookup;

#[CoversClass(PublicIpLookup::class)]
final class PublicIpLookupTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

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

    public function testTwoAgreeingSourcesAreAccepted(): void
    {
        $lookup = new PublicIpLookup([
            $this->source('203.0.113.9'),
            $this->source("203.0.113.9\n"),
        ]);

        self::assertSame('203.0.113.9', $lookup->find());
    }

    public function testOneLyingSourceCannotDecideTheAddress(): void
    {
        $lookup = new PublicIpLookup([
            $this->source('198.51.100.7'),
            $this->source('203.0.113.9'),
            $this->source('203.0.113.9'),
        ]);

        self::assertSame('203.0.113.9', $lookup->find());
    }

    public function testTotalDisagreementIsAFailureRatherThanAGuess(): void
    {
        $lookup = new PublicIpLookup([
            $this->source('198.51.100.7'),
            $this->source('203.0.113.9'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sources disagreed/');

        $lookup->find();
    }

    public function testASingleConfiguredSourceIsAcceptedUncorroborated(): void
    {
        $lookup = new PublicIpLookup([$this->source('203.0.113.9')]);

        self::assertSame('203.0.113.9', $lookup->find());
    }

    public function testDuplicateUrlsCannotManufactureAQuorum(): void
    {
        $url = $this->source('203.0.113.9');

        $lookup = new PublicIpLookup([$url, $url]);

        self::assertSame('203.0.113.9', $lookup->find(), 'collapses to a single source');
    }

    public function testUnreachableSourcesDoNotCountTowardQuorum(): void
    {
        $lookup = new PublicIpLookup([
            $this->source('203.0.113.9'),
            '/nonexistent/rogue-dns-test-a',
            '/nonexistent/rogue-dns-test-b',
        ]);

        $this->expectExceptionMessageMatches('/1 of 2 needed/');

        $lookup->find();
    }

    public function testFailureNamesEverySourceItTried(): void
    {
        $lookup = new PublicIpLookup(['/nonexistent/aaa', '/nonexistent/bbb']);

        $this->expectExceptionMessageMatches('/(aaa.+bbb|bbb.+aaa)/s');

        $lookup->find();
    }

    public function testAPrivateAnswerIsNotCountedAsAVote(): void
    {
        $lookup = new PublicIpLookup([
            $this->source('192.168.1.1'),
            $this->source('192.168.1.1'),
        ]);

        $this->expectExceptionMessageMatches('/no source returned a public IPv4 address/');

        $lookup->find();
    }

    private function source(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rogue-dns-ip');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }
}
