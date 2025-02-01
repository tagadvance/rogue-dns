FROM ubuntu:latest

RUN ln -fs /usr/share/zoneinfo/America/Denver /etc/localtime \
    && apt-get update \
    && apt-get -y install cron \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y php8.3-cli php8.3-curl composer \
    && rm -rf /var/cache/apt/archives /var/lib/apt/lists/* \
    && mkdir -p /opt/rogue-dns/src

COPY src/ /opt/rogue-dns/src/
COPY cloudflare.php /opt/rogue-dns/
COPY composer.* /opt/rogue-dns/
COPY config.ini /opt/rogue-dns/
COPY LICENSE /opt/rogue-dns/

RUN composer install --working-dir /opt/rogue-dns \
    && chmod 0744 /opt/rogue-dns/cloudflare.php

COPY crontab /etc/cron.d/crontab
RUN chmod 0644 /etc/cron.d/crontab
RUN crontab /etc/cron.d/crontab

CMD ["cron", "-f"]
