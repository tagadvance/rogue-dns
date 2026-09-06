<?php

declare(strict_types=1);

namespace tagadvance\roguedns\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use tagadvance\roguedns\Field;
use tagadvance\roguedns\Record;

#[CoversClass(Record::class)]
#[CoversClass(Field::class)]
final class RecordTest extends TestCase
{
    public function testUpdatePayloadCarriesFieldsTheApiWouldOtherwiseReset(): void
    {
        $record = Record::fromResponse((object) [
            'id' => 'r1',
            'name' => 'example.com',
            'type' => 'A',
            'content' => '203.0.113.1',
            'ttl' => 60,
            'proxied' => true,
            'comment' => 'home NAS',
            'tags' => ['prod', 'nas'],
        ]);

        self::assertSame([
            'type' => 'A',
            'name' => 'example.com',
            'content' => '203.0.113.1',
            'ttl' => 60,
            'proxied' => true,
            'comment' => 'home NAS',
            'tags' => ['prod', 'nas'],
        ], $record->toUpdatePayload());
    }

    public function testAbsentOptionalFieldsAreOmittedRatherThanSentAsNull(): void
    {
        $record = Record::fromResponse((object) [
            'id' => 'r1',
            'name' => 'example.com',
            'type' => 'A',
            'content' => '203.0.113.1',
            'ttl' => 60,
        ]);

        self::assertSame(
            ['type', 'name', 'content', 'ttl', 'proxied'],
            array_keys($record->toUpdatePayload()),
        );
        self::assertFalse($record->proxied);
    }

    public function testAChangedResponseShapeFailsByName(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('content should be string, got null');

        Record::fromResponse((object) ['id' => 'r1', 'name' => 'a', 'type' => 'A', 'ttl' => 60]);
    }

    public function testNonStringTagsAreDiscardedRatherThanPropagated(): void
    {
        $record = Record::fromResponse((object) [
            'id' => 'r1',
            'name' => 'example.com',
            'type' => 'A',
            'content' => '203.0.113.1',
            'ttl' => 60,
            'tags' => ['ok', 42, null],
        ]);

        self::assertSame(['ok'], $record->tags);
    }
}
