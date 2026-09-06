<?php

declare(strict_types=1);

namespace tagadvance\roguedns;

use RuntimeException;

/**
 * Resolves this host's public address by asking third-party echo services.
 *
 * Whatever this returns is published to DNS, so a single source that lies -- compromised,
 * misconfigured, or spoofed by anyone on the network path -- can repoint every whitelisted
 * domain. Two independent sources must therefore agree before an address is accepted. Sources
 * are asked in random order to spread load, and only as many as agreement needs are contacted.
 *
 * Answers are held to a public IPv4: private, reserved and IPv6 values all pass
 * FILTER_VALIDATE_IP but are either unroutable or rejected outright as A record content.
 */
final class PublicIpLookup
{
    /**
     * Agreeing sources needed before an address is trusted. Two is the smallest number that
     * survives one bad source, and cross-checking more costs a request per extra source on
     * every run.
     */
    public const QUORUM = 2;

    private const TIMEOUT_SECONDS = 5;

    /** An address is at most 45 bytes; anything larger is not an answer. */
    private const MAX_RESPONSE_BYTES = 128;

    /** @var non-empty-list<string> */
    private readonly array $urls;

    private readonly int $quorum;

    /**
     * @param non-empty-list<string> $urls echo services returning a bare address as plain text
     */
    public function __construct(array $urls)
    {
        $this->urls = array_values(array_unique($urls));

        // A single configured source cannot be corroborated. Accept it rather than refusing to
        // work, and let config-sample.ini be the place that recommends more than one.
        $this->quorum = min(self::QUORUM, count($this->urls));
    }

    /**
     * @throws RuntimeException naming every source tried and why each was unusable or dissenting,
     *                          so a source that has quietly started lying is visible rather than
     *                          being silently outvoted forever
     */
    public function find(): string
    {
        $urls = $this->urls;
        shuffle($urls);

        /** @var array<string, int> $votes */
        $votes = [];
        $rejected = [];

        foreach ($urls as $url) {
            $body = $this->fetch($url);
            if ($body === null) {
                $rejected[] = "$url: unreachable";

                continue;
            }

            $ip = self::validate(trim($body));
            if ($ip === null) {
                $rejected[] = "$url: not a public IPv4 address";

                continue;
            }

            $votes[$ip] = ($votes[$ip] ?? 0) + 1;
            if ($votes[$ip] >= $this->quorum) {
                return $ip;
            }
        }

        throw new RuntimeException($this->explainFailure($votes, $rejected));
    }

    /**
     * Rejects IPv6, private and reserved ranges.
     */
    public static function validate(string $candidate): ?string
    {
        $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        $ip = filter_var($candidate, FILTER_VALIDATE_IP, $flags);

        return $ip === false ? null : $ip;
    }

    /**
     * @param array<string, int> $votes
     * @param list<string> $rejected
     */
    private function explainFailure(array $votes, array $rejected): string
    {
        $detail = [];
        foreach ($votes as $ip => $count) {
            $detail[] = sprintf('%s: %d of %d needed', $ip, $count, $this->quorum);
        }
        $detail = array_merge($detail, $rejected);

        $reason = $votes === []
            ? 'no source returned a public IPv4 address'
            : 'sources disagreed';

        return sprintf('public ip address could not be determined (%s): %s', $reason, implode('; ', $detail));
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
