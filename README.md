# Rogue DNS

[![CI](https://github.com/tagadvance/rogue-dns/actions/workflows/ci.yml/badge.svg)](https://github.com/tagadvance/rogue-dns/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-8.5-777bb4?logo=php&logoColor=white)](https://www.php.net/supported-versions.php)
[![License](https://img.shields.io/github/license/tagadvance/rogue-dns)](LICENSE)

Point DNS records in Cloudflare at a dynamically allocated IP address, so you can host from home
without a static IP.

Every five minutes it detects the host's public address and, when it differs from what Cloudflare
holds, rewrites the whitelisted A records to match.

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
container does. Consider a minimal `Zone:Read` + `DNS:Edit` token for the long-running container and
a separate one for interactive use.

`config.ini` sections:

- **`[api] token`** — the API token.
- **`[domains] primary`** — resolved through the system resolver to decide whether an update is
  worth attempting. Make it one of the whitelisted domains; if it is not, the check never agrees and
  every run does a needless pass over the API.
- **`[domains] domain[]`** — the whitelist. Only these *exact* record names are updated. Wildcards
  are not matched.
- **`[ip] url[]`** — public-address lookup services, tried in random order. **Any single one of these
  decides where your domains point**, so prefer `https://`: a plaintext source lets anyone on the
  network path choose for you.

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
```

Note the `=` in the last form. `--update-ip` takes an *optional* value, and PHP's `getopt` only binds
those when they are attached with `=`; `--update-ip 203.0.113.9` silently discards the address and
falls back to auto-detection.

## How it is scheduled

The container's `CMD` is `sleep infinity` and the work is done by the Docker `HEALTHCHECK`, which
runs `--update-ip` every five minutes. This has some sharp edges worth knowing:

- Output goes to the health log, not the container log. `docker logs` will be empty; use
  `docker inspect --format '{{json .State.Health}}' <container>`. That log keeps only the last five
  probes and truncates each to 4KB, so it holds roughly 25 minutes of history.
- Nothing acts on `unhealthy`. `restart: unless-stopped` reacts to PID 1 exiting, and PID 1 is
  `sleep infinity`, so a container whose updates have been failing for hours still looks "up".

## Development

```bash
composer install
composer run check    # format check, static analysis, tests
```

Static analysis runs at PHPStan level 10 with nothing suppressed — no baseline, no ignores. Keep it
that way.

---

If this is useful to you, you can [sponsor the author](https://github.com/sponsors/tagadvance).
