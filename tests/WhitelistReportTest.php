<?php

declare(strict_types=1);

namespace tagadvance\roguedns\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use tagadvance\roguedns\Record;
use tagadvance\roguedns\WhitelistReport;

#[CoversClass(WhitelistReport::class)]
final class WhitelistReportTest extends TestCase
{
    private const IP = '203.0.113.9';

    public function testACleanWhitelistHasNoProblems(): void
    {
        $report = WhitelistReport::build(
            ['a.example.com', 'b.example.com'],
            [self::record('a.example.com', self::IP), self::record('b.example.com', self::IP)],
            self::IP,
        );

        self::assertFalse($report->hasProblems());
        self::assertSame(2, $report->matching);
    }

    public function testAWhitelistEntryWithNoRecordIsReported(): void
    {
        $report = WhitelistReport::build(['gone.example.com'], [], self::IP);

        self::assertSame(['gone.example.com'], $report->missing);
        self::assertTrue($report->hasProblems());
        self::assertStringContainsString('no A record', $report->render(self::IP));
    }

    public function testARoundRobinSetIsReportedAsAmbiguous(): void
    {
        $report = WhitelistReport::build(
            ['pages.example.com'],
            [
                self::record('pages.example.com', '185.199.108.153'),
                self::record('pages.example.com', '185.199.109.153'),
            ],
            self::IP,
        );

        self::assertSame(['pages.example.com' => ['185.199.108.153', '185.199.109.153']], $report->ambiguous);
        self::assertSame([], $report->elsewhere, 'an ambiguous name is not also counted as pointing elsewhere');
        self::assertTrue($report->hasProblems());
    }

    public function testARecordPointingElsewhereIsReportedButIsNotItselfAProblem(): void
    {
        $report = WhitelistReport::build(
            ['moved.example.com'],
            [self::record('moved.example.com', '198.51.100.50')],
            self::IP,
        );

        self::assertSame(['moved.example.com' => '198.51.100.50'], $report->elsewhere);
        self::assertFalse(
            $report->hasProblems(),
            'indistinguishable from a pending update after an address change, so not an error on its own',
        );
        self::assertStringContainsString('remove them from the whitelist', $report->render(self::IP));
    }

    public function testAProxiedRecordIsReported(): void
    {
        $report = WhitelistReport::build(
            ['orange.example.com'],
            [self::record('orange.example.com', self::IP, proxied: true)],
            self::IP,
        );

        self::assertSame(['orange.example.com' => self::IP], $report->proxied);
        self::assertTrue($report->hasProblems());
    }

    public function testRecordsOutsideTheWhitelistAreIgnored(): void
    {
        $report = WhitelistReport::build(
            ['a.example.com'],
            [self::record('a.example.com', self::IP), self::record('other.example.com', '198.51.100.1')],
            self::IP,
        );

        self::assertSame(1, $report->matching);
        self::assertSame([], $report->elsewhere);
    }

    private static function record(string $name, string $content, bool $proxied = false): Record
    {
        return Record::fromResponse((object) [
            'id' => 'r-' . $name,
            'name' => $name,
            'type' => 'A',
            'content' => $content,
            'ttl' => 60,
            'proxied' => $proxied,
        ]);
    }
}
