# Rogue DNS

[![CI](https://github.com/tagadvance/rogue-dns/actions/workflows/ci.yml/badge.svg)](https://github.com/tagadvance/rogue-dns/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-8.5-777bb4?logo=php&logoColor=white)](https://www.php.net/supported-versions.php)
[![License](https://img.shields.io/github/license/tagadvance/rogue-dns)](LICENSE)

Point DNS records in Cloudflare at a dynamically allocated IP address, so you can host from home
without a static IP.

Every five minutes it detects the host's public address and reconciles the whitelisted A records
against it, rewriting only those that disagree. Two independent address sources must agree before
anything is published.

## Requirements

PHP 8.5, or Docker.

## Installation

### Docker (preferred)

```bash
git clone git@github.com:tagadvance/rogue-dns.git
cd rogue-dns
cp config-sample.ini config.ini
chmod 600 config.ini
$EDITOR config.ini          # add your API token and domains
docker compose up -d --build
```

`config.ini` is mounted into the container at runtime, not copied into the image — an image layer
is immutable and readable by anyone who can pull it, so a token baked into one stays there.

### Without Docker

```bash
git clone git@github.com:tagadvance/rogue-dns.git
cd rogue-dns
composer install --no-dev
cp config-sample.ini config.ini
chmod 600 config.ini
$EDITOR config.ini
```

## Configuration

[Create a Cloudflare API token](https://dash.cloudflare.com/profile/api-tokens). The container only
ever needs to read zones and edit DNS, so scope it as narrowly as you can:

| Permission | Level | Needed for |
| --- | --- | --- |
| Zone → Zone | Read | `--update-ip`, `--list-zones` |
| Zone → DNS | Edit | `--update-ip` |
| Zone → Zone | Edit | `--add-zone` only |
| Zone → Zone Settings | Edit | `--add-zone` only |

`--add-zone` creates zones and rewrites SSL settings, so it wants a far more powerful token than the
container does. It also needs the token's **Account Resources** to include the account — `POST /zones`
is rejected without one. Consider a minimal `Zone:Read` + `DNS:Edit` token for the long-running
container and a separate one for interactive use.

`config.ini` sections:

- **`[api] token`** — the API token.
- **`[domains] domain[]`** — the whitelist. Only these *exact* record names are updated. Wildcards
  are not matched.
- **`[ip] url[]`** — public-address lookup services, asked in random order until two agree. **List at
  least three**, so one being down does not stop updates, and prefer `https://`: a plaintext source
  lets anyone on the network path cast a vote. With only one configured there is nothing to
  corroborate it, and that single source decides where your domains point.
- **`[schedule] interval`** — seconds between checks in `--watch` mode. Optional; defaults to 300.

A whitelisted name served by **several** A records is skipped with a warning. Writing one address
into a round-robin set breaks whatever it points at, and Cloudflare rejects the rest as duplicates,
so the result would be a silent partial rewrite. Reduce it to one record, or drop it from the
whitelist.

## Usage

```bash
# list zones and their records
./cloudflare.php --list-zones
# add a new zone with reasonable defaults
./cloudflare.php --add-zone foo.com
# automatically detect IP
./cloudflare.php --update-ip
# manually set IP address
./cloudflare.php --update-ip=203.0.113.9
# show what would change, without writing anything
./cloudflare.php --update-ip --dry-run
# run continuously on an interval (this is what the container does)
./cloudflare.php --watch
# report whether the watch loop is still succeeding
./cloudflare.php --health
# check the whitelist against what Cloudflare actually holds
./cloudflare.php --doctor
```

### Keeping the whitelist honest

A whitelist drifts. A domain gets moved to GitHub Pages or a VPS, and `config.ini` is the last thing
anyone remembers to update — at which point the next address change rewrites a record that is no
longer yours to rewrite.

`--doctor` is read-only and reports exactly that: whitelisted names with no A record, names served by
several records, names that are proxied, and names pointing somewhere other than this host. It exits
non-zero when something needs a human, so it can be run on a schedule.

Note the limit of what it can do: a *single* A record pointing elsewhere is indistinguishable from
one that simply has not been updated yet, so the tool reports it and still rewrites it on the next
address change. Only you know which it is. Run `--doctor` after moving a domain.

Note the `=` in the last form. `--update-ip` takes an *optional* value, and PHP's `getopt` only binds
those when they are attached with `=`; `--update-ip 203.0.113.9` silently discards the address and
falls back to auto-detection.

## How it is scheduled

The container runs `--watch` as its main process: it checks every `[schedule] interval` seconds and
logs each run to stdout, so `docker logs -f rogue-dns` shows what it is doing. `SIGTERM` stops it
after the current run, so `docker compose down` will not interrupt a write.

The `HEALTHCHECK` runs `--health`, which only reads the outcome the loop recorded — it does no work
of its own, so it cannot be killed mid-update. It reports unhealthy when no run has *succeeded*
within two intervals, which means a single transient failure recovers on the next tick rather than
flapping the container's status.

Each pass re-reads every whitelisted record from the API rather than asking a cheaper question
first. That is deliberate: an interrupted pass — a router reboot partway through an address change —
leaves records split across two addresses, and no shortcut based on one name or on a cached copy can
detect that. On a 17-zone account a pass costs 18 requests, about 1.5% of Cloudflare's rate limit.

A transient failure is logged and retried on the next tick rather than exiting; sustained failure
surfaces as `unhealthy` in `docker ps`. Note that nothing restarts an unhealthy container in plain
Docker — that is a healthcheck's nature, not a defect here — so this is a signal to watch, not a
self-healing mechanism.

## Development

```bash
composer install
composer run check    # format check, static analysis, tests
```

Static analysis runs at PHPStan level 10 with nothing suppressed — no baseline, no ignores. Keep it
that way.

---

If this is useful to you, you can [sponsor the author](https://github.com/sponsors/tagadvance).
