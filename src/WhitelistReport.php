<?php

declare(strict_types=1);

namespace tagadvance\roguedns;

/**
 * What the whitelist actually corresponds to in Cloudflare right now.
 *
 * A whitelist drifts: a domain gets repointed somewhere else, or renamed, or retired, and the
 * config is the last thing anyone remembers to update. Nothing in the update path can tell the
 * difference between "stale, fix it" and "deliberately moved, leave it alone" -- both are just a
 * record holding an address. So the answer is to make the drift visible before it matters rather
 * than to guess in the write path.
 */
final class WhitelistReport
{
    /**
     * @param list<string> $missing whitelisted names with no A record at all
     * @param array<string, non-empty-list<string>> $ambiguous name => the several addresses it holds
     * @param array<string, string> $elsewhere name => an address that is not this host's
     * @param array<string, string> $proxied name => address, orange-clouded so DNS hides the origin
     * @param int $matching names that resolve to exactly one record pointing at this host
     */
    public function __construct(
        public readonly array $missing,
        public readonly array $ambiguous,
        public readonly array $elsewhere,
        public readonly array $proxied,
        public readonly int $matching,
    ) {}

    /**
     * @param list<string> $whitelist
     * @param list<Record> $records every A record from the zones covering the whitelist
     */
    public static function build(array $whitelist, array $records, string $expectedIp): self
    {
        $byName = [];
        foreach ($records as $record) {
            if (in_array($record->name, $whitelist, strict: true)) {
                $byName[$record->name][] = $record;
            }
        }

        $missing = array_values(array_diff($whitelist, array_keys($byName)));
        $ambiguous = [];
        $elsewhere = [];
        $proxied = [];
        $matching = 0;

        foreach ($byName as $name => $found) {
            if (count($found) > 1) {
                $ambiguous[$name] = array_map(fn(Record $r): string => $r->content, $found);

                continue;
            }

            $record = $found[0];
            if ($record->proxied) {
                $proxied[$name] = $record->content;
            }
            if ($record->content !== $expectedIp) {
                $elsewhere[$name] = $record->content;

                continue;
            }

            $matching++;
        }

        return new self($missing, $ambiguous, $elsewhere, $proxied, $matching);
    }

    public function hasProblems(): bool
    {
        return $this->missing !== [] || $this->ambiguous !== [] || $this->proxied !== [];
    }

    public function render(string $expectedIp): string
    {
        $lines = [sprintf('%d whitelisted name(s) point at %s as expected.', $this->matching, $expectedIp)];

        if ($this->missing !== []) {
            $lines[] = '';
            $lines[] = sprintf('%d whitelisted name(s) have no A record, so nothing is ever updated for them.', count($this->missing));
            $lines[] = 'They are probably typos, or domains that have since been retired:';
            foreach ($this->missing as $name) {
                $lines[] = "  $name";
            }
        }

        if ($this->ambiguous !== []) {
            $lines[] = '';
            $lines[] = sprintf('%d whitelisted name(s) are served by several A records and are skipped.', count($this->ambiguous));
            $lines[] = 'A round-robin set is not dynamic DNS; these are almost certainly hosted elsewhere now:';
            foreach ($this->ambiguous as $name => $addresses) {
                $lines[] = sprintf('  %s -> %s', $name, implode(', ', $addresses));
            }
        }

        if ($this->proxied !== []) {
            $lines[] = '';
            $lines[] = sprintf('%d whitelisted name(s) are proxied, so public DNS shows a Cloudflare address', count($this->proxied));
            $lines[] = 'rather than this host:';
            foreach ($this->proxied as $name => $address) {
                $lines[] = "  $name -> $address";
            }
        }

        if ($this->elsewhere !== []) {
            $lines[] = '';
            $lines[] = sprintf('%d whitelisted name(s) point somewhere other than %s.', count($this->elsewhere), $expectedIp);
            $lines[] = 'If this host has just moved, that is simply pending work and the next pass fixes it.';
            $lines[] = 'If any of these were deliberately repointed elsewhere, remove them from the whitelist';
            $lines[] = 'BEFORE the next address change, or they will be rewritten to this host:';
            foreach ($this->elsewhere as $name => $address) {
                $lines[] = "  $name -> $address";
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
