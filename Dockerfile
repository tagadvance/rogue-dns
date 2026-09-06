# syntax=docker/dockerfile:1

FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --no-scripts

FROM php:8.5-cli-alpine

# pcntl is what lets the update loop stop on SIGTERM instead of being SIGKILLed after the
# stop grace period, which could otherwise land between two record writes.
RUN docker-php-ext-install -j"$(nproc)" pcntl

# config.ini holds a Cloudflare API token. It is deliberately NOT copied into the image:
# an image layer is immutable and readable by anyone who can pull it. Mount it at runtime
# instead -- see compose.yaml.
WORKDIR /opt/rogue-dns

COPY --from=vendor /app/vendor/ ./vendor/
COPY src/ ./src/
COPY cloudflare.php LICENSE ./

RUN chmod 0755 cloudflare.php

# The update loop is the main process, so its output reaches `docker logs` and the restart
# policy applies to it. It used to be a HEALTHCHECK, where output was capped at 4KB across the
# last five probes, never reached the container log, and an overrunning probe was SIGKILLed.
CMD ["/opt/rogue-dns/cloudflare.php", "--watch"]

# Now a real health check: it reads the recorded state of the loop above and does no work of
# its own, so it cannot be killed mid-update and costs nothing.
HEALTHCHECK --interval=1m --timeout=10s --start-period=30s \
    CMD ["/opt/rogue-dns/cloudflare.php", "--health"]
