FROM debian:trixie

ARG DEBIAN_FRONTEND=noninteractive

COPY src/ /opt/rogue-dns/src/
COPY cloudflare.php /opt/rogue-dns/
COPY composer.* /opt/rogue-dns/
COPY config.ini /opt/rogue-dns/
COPY LICENSE /opt/rogue-dns/

RUN apt-get update \
    && apt-get install -y php8.4-cli php8.4-curl composer \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /opt/rogue-dns/src \
    && composer install --working-dir /opt/rogue-dns \
    && chmod 0744 /opt/rogue-dns/cloudflare.php

CMD ["sleep", "infinity"]

HEALTHCHECK --interval=5m --timeout=3s \
  CMD /opt/rogue-dns/cloudflare.php --update-ip || exit 1
