<?php

declare(strict_types=1);

namespace tagadvance\roguedns;

use stdClass;

final class Zone
{
    /**
     * @param list<string> $nameServers assigned by Cloudflare; empty until the zone is activated
     */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $nameServers,
    ) {}

    public static function fromResponse(stdClass $response): self
    {
        return new self(
            id: Field::string($response, 'id'),
            name: Field::string($response, 'name'),
            nameServers: Field::stringList($response, 'name_servers'),
        );
    }
}
