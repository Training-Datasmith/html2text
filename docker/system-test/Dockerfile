FROM php:7.3-cli@sha256:b65d1b38434e586d73200b2feba4415da57eb2d5fe415e1ec3143df274abdaf0

RUN set -eux; \
    export DEBIAN_FRONTEND=noninteractive; \
    printf '%s\n' \
        'deb https://archive.debian.org/debian buster main' \
        'deb https://archive.debian.org/debian-security buster/updates main' \
        > /etc/apt/sources.list; \
    echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99archive; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl git unzip libonig-dev; \
  docker-php-ext-install mbstring; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /workspace
