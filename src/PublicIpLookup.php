<?php

declare(strict_types=1);

namespace tagadvance\roguedns;

use RuntimeException;

/**
 * Resolves this host's public address by asking third-party echo services.
 *
 * Sources are shuffled to spread load across free services, and the first usable answer wins.
 * That makes any single source authoritative over what gets published to DNS, so answers are
 * held to a public IPv4: a private, reserved or IPv6 value is rejected rather than written into
 * an A record. Prefer https:// sources — a plaintext one lets anyone on the network path choose
 * where your domains point.
 */
final class PublicIpLookup
{
    private const TIMEOUT_SECONDS = 5;

    /** An address is at most 45 bytes; anything larger is not an answer. */
    private const MAX_RESPONSE_BYTES = 128;

    /**
     * @param non-empty-list<string> $urls echo services returning a bare address as plain text
     */
    public function __construct(private readonly array $urls) {}

    /**
     * @throws RuntimeException listing every source tried and why each was unusable, so a
     *                          silent fallback to one working source is visible when it is not
     */
    public function find(): string
    {
        $urls = $this->urls;
        shuffle($urls);

        $failures = [];
        foreach ($urls as $url) {
            $body = $this->fetch($url);
            if ($body === null) {
                $failures[] = "$url: unreachable";

                continue;
            }

            $ip = self::validate(trim($body));
            if ($ip === null) {
                $failures[] = "$url: not a public IPv4 address";

                continue;
            }

            return $ip;
        }

        throw new RuntimeException('public ip address could not be found: ' . implode('; ', $failures));
    }

    /**
     * Rejects IPv6, private and reserved ranges. These validate as addresses but would publish
     * an unroutable or nonsensical destination into public DNS, and an IPv6 value in an A record
     * is rejected by the API anyway.
     */
    public static function validate(string $candidate): ?string
    {
        $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        $ip = filter_var($candidate, FILTER_VALIDATE_IP, $flags);

        return $ip === false ? null : $ip;
    }

    private function fetch(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => self::TIMEOUT_SECONDS,
                'follow_location' => 0,
                'ignore_errors' => false,
                'user_agent' => 'rogue-dns (+https://github.com/tagadvance/rogue-dns)',
            ],
        ]);

        $body = @file_get_contents($url, false, $context, 0, self::MAX_RESPONSE_BYTES);

        return $body === false ? null : $body;
    }
}
