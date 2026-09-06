<?php

declare(strict_types=1);

namespace tagadvance\roguedns;

use stdClass;

/**
 * A DNS record as Cloudflare returned it.
 *
 * Note there is no zone id: Cloudflare stopped returning zone_id on records in November 2024,
 * so callers have to track which zone a record came from themselves.
 */
final class Record
{
    /**
     * @param list<string> $tags
     */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $type,
        public readonly string $content,
        public readonly int $ttl,
        public readonly bool $proxied,
        public readonly ?string $comment,
        public readonly array $tags,
        public readonly ?int $priority,
    ) {}

    public static function fromResponse(stdClass $response): self
    {
        return new self(
            id: Field::string($response, 'id'),
            name: Field::string($response, 'name'),
            type: Field::string($response, 'type'),
            content: Field::string($response, 'content'),
            ttl: Field::int($response, 'ttl'),
            proxied: Field::bool($response, 'proxied', false),
            comment: Field::nullableString($response, 'comment'),
            tags: Field::stringList($response, 'tags'),
            priority: Field::nullableInt($response, 'priority'),
        );
    }

    /**
     * The fields that must be echoed back on an update. The API overwrites the whole record
     * rather than merging, so anything omitted here is reset.
     *
     * @return array<string, mixed>
     */
    public function toUpdatePayload(): array
    {
        $payload = [
            'type' => $this->type,
            'name' => $this->name,
            'content' => $this->content,
            'ttl' => $this->ttl,
            'proxied' => $this->proxied,
        ];
        if ($this->comment !== null) {
            $payload['comment'] = $this->comment;
        }
        if ($this->tags !== []) {
            $payload['tags'] = $this->tags;
        }
        if ($this->priority !== null) {
            $payload['priority'] = $this->priority;
        }

        return $payload;
    }
}
