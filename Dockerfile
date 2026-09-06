# syntax=docker/dockerfile:1

FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --no-scripts

FROM php:8.5-cli-alpine

# config.ini holds a Cloudflare API token. It is deliberately NOT copied into the image:
# an image layer is immutable and readable by anyone who can pull it. Mount it at runtime
# instead -- see compose.yaml.
WORKDIR /opt/rogue-dns

COPY --from=vendor /app/vendor/ ./vendor/
COPY src/ ./src/
COPY cloudflare.php LICENSE ./

RUN chmod 0755 cloudflare.php

CMD ["sleep", "infinity"]

# The healthcheck is also the scheduler: this is what replaced cron. The timeout has to cover
# a DNS lookup, an address lookup against a third-party service, and a paginated walk of the
# Cloudflare API -- the original 3s could not, and Docker SIGKILLs an overrunning probe.
HEALTHCHECK --interval=5m --timeout=60s --start-period=30s --start-interval=15s \
    CMD ["/opt/rogue-dns/cloudflare.php", "--update-ip"]
