FROM wordpress:cli-php8.3@sha256:2b5e9d4d3e51909dca1aaa4732e9f5e5bf0377c2114dbd8ff39f060bff202586 AS wpcli

FROM wordpress:7.0.3-php8.3-apache@sha256:a09147f15a882b956f67a617e9e1e053adf9322c45c797c2ff7c0e66522bf204 AS wordpress

# The WordPress image declares /var/www/html as a volume. Inheriting that
# metadata would silently add a fourth, generation-unowned mount and make the
# runtime's exact mount proof fail. The final pinned PHP base has no volumes;
# WordPress enters only as immutable source bytes.
FROM php:8.3-apache@sha256:bcf7ac6941725b08123df2732065b8ede097ed0977ba2891b411654efb35cc23

ARG MARIADB_VERSION=1:11.8.6-0+deb13u1
ARG XFSPROGS_VERSION=6.13.0-2+b1

LABEL org.opencontainers.image.title="Duo reviewed WordPress preview runtime" \
      org.opencontainers.image.description="Single-container, outbound-contained Duo Cloud preview workload" \
      duo.cloud.runtime-contract-sha256="c3db3b744d42e43fc3f78544f36494ab9e51d2e16b954eb7d0fdd15dbb3865e8"

RUN set -eux; \
    printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d; \
    chmod 0755 /usr/sbin/policy-rc.d; \
    apt-get update; \
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        "mariadb-client=${MARIADB_VERSION}" \
        "mariadb-server=${MARIADB_VERSION}" \
        "xfsprogs=${XFSPROGS_VERSION}"; \
    docker-php-ext-install -j1 mysqli pdo_mysql; \
    rm -rf /var/lib/apt/lists/* /var/lib/mysql/*; \
    rm -f /usr/sbin/policy-rc.d; \
    groupadd --gid 10001 duo-preview; \
    useradd --uid 10001 --gid 10001 --no-create-home --home-dir /nonexistent \
        --shell /usr/sbin/nologin duo-preview; \
    a2enmod rewrite; \
    sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf; \
    sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf; \
    sed -ri 's#DocumentRoot /var/www/html#DocumentRoot /var/lib/duo/wordpress#' \
        /etc/apache2/sites-available/000-default.conf; \
    sed -ri 's/^User .*/User duo-preview/; s/^Group .*/Group duo-preview/' /etc/apache2/apache2.conf; \
    printf '\nServerName 127.0.0.1\nAlias "/__duo/health" "/opt/duo/health/ready.txt"\n<Directory /opt/duo/health>\n  AllowOverride None\n  Require all granted\n</Directory>\n<Directory /var/lib/duo/wordpress>\n  AllowOverride All\n  Require all granted\n</Directory>\n' \
        >> /etc/apache2/apache2.conf; \
    mkdir -p /opt/duo/bin /opt/duo/dropin /opt/duo/health /var/lib/duo/database /var/lib/duo/wordpress /srv; \
    printf 'duo-cloud-preview-runtime-health/v1\n' > /opt/duo/health/ready.txt; \
    chmod 0555 /opt/duo/health; \
    chmod 0444 /opt/duo/health/ready.txt; \
    chown -R 10001:10001 /var/lib/duo/database /var/lib/duo/wordpress; \
    chmod 0700 /var/lib/duo/database /var/lib/duo/wordpress; \
    ln -s /var/lib/duo/wordpress/duo /srv/duo

COPY --from=wpcli /usr/local/bin/wp /usr/local/bin/wp
COPY --from=wordpress /usr/src/wordpress /usr/src/wordpress
COPY cloud/runtime/image/PreviewRuntimeHelper.php /opt/duo/bin/PreviewRuntimeHelper.php
COPY cloud/runtime/image/duo-preview-helper /opt/duo/bin/duo-preview-helper
COPY cloud/runtime/image/containment-canary.c /opt/duo/bin/containment-canary.c
COPY agent /opt/duo/dropin/agent
COPY manifests /opt/duo/dropin/manifests
COPY recovery /opt/duo/dropin/recovery

RUN set -eux; \
    cc -std=c11 -O2 -Wall -Wextra -Werror \
        -o /opt/duo/bin/containment-canary /opt/duo/bin/containment-canary.c; \
    rm /opt/duo/bin/containment-canary.c; \
    chmod 0555 /opt/duo/bin/containment-canary; \
    chmod 0555 /opt/duo/bin/duo-preview-helper /usr/local/bin/wp; \
    chmod 0444 /opt/duo/bin/PreviewRuntimeHelper.php; \
    for helper in duo-preview-runtime duo-preview-command materialize-repository \
        restore-database restore-media runtime-status url-rebind; do \
        ln -s duo-preview-helper "/opt/duo/bin/${helper}"; \
    done; \
    find /opt/duo/dropin -type d -exec chmod 0555 {} +; \
    find /opt/duo/dropin -type f -exec chmod 0444 {} +

EXPOSE 8080
USER 10001:10001
ENTRYPOINT ["/opt/duo/bin/duo-preview-runtime"]
