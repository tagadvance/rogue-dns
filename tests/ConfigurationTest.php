<?php

declare(strict_types=1);

namespace tagadvance\roguedns\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use tagadvance\roguedns\Configuration;

#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rogue-dns-test');
        self::assertIsString($path);
        $this->path = $path;
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testMissingFileNamesThePath(): void
    {
        $this->expectExceptionMessageMatches('/configuration is missing/');

        Configuration::fromFile($this->path . '-does-not-exist');
    }

    public function testUnparseableFileIsNotReportedAsAMissingToken(): void
    {
        $this->write("[api]\ntoken = has = two equals\n");

        $this->expectExceptionMessageMatches('/could not be parsed/');

        Configuration::fromFile($this->path);
    }

    public function testEmptyValueNamesTheKeyAndFile(): void
    {
        $this->write("[api]\ntoken =\n");

        $this->expectExceptionMessageMatches('/\[api\] token is not set.+' . preg_quote(basename($this->path), '/') . '/');

        Configuration::fromFile($this->path)->string('api', 'token');
    }

    public function testMissingSectionNamesTheKey(): void
    {
        $this->write("[api]\ntoken = abc\n");

        $this->expectExceptionMessageMatches('/\[domains\] primary is not set/');

        Configuration::fromFile($this->path)->string('domains', 'primary');
    }

    public function testSingleValueIsAcceptedWhereAListIsWanted(): void
    {
        $this->write("[domains]\ndomain = only.example.com\n");

        self::assertSame(['only.example.com'], Configuration::fromFile($this->path)->list('domains', 'domain'));
    }

    public function testListIsReturnedAsAList(): void
    {
        $this->write("[domains]\ndomain[] = a.example.com\ndomain[] = b.example.com\n");

        self::assertSame(
            ['a.example.com', 'b.example.com'],
            Configuration::fromFile($this->path)->list('domains', 'domain'),
        );
    }

    public function testListWhereASingleValueIsWantedIsRejected(): void
    {
        $this->write("[api]\ntoken[] = a\ntoken[] = b\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/is a list/');

        Configuration::fromFile($this->path)->string('api', 'token');
    }

    private function write(string $ini): void
    {
        file_put_contents($this->path, $ini);
    }
}
