# syntax=docker/dockerfile:1
# Quokka: the family webmail, a fork of SnappyMail (AGPL-3.0, https://github.com/the-djmaze/snappymail).
# Built like upstream's .docker/release/Dockerfile, with only the PHP extensions a family install uses
# (no LDAP, MySQL, Postgres, Redis, ImageMagick): smaller and quicker to build.

# package.json without its version: raising the version (on every build) must not reinstall all the
# JavaScript dependencies. This stage reruns, but its result is the same, so the install below stays cached.
FROM alpine:3.24.3 AS manifest
COPY package.json yarn.lock /manifest/
RUN sed -i -E 's/^(\s*"version":\s*)"[^"]*"/\1"0.0.0"/' /manifest/package.json

FROM alpine:3.24.3 AS builder
RUN apk add --no-cache php85 php85-json php-phar php-zip npm \
 && npm install -g gulp yarn
WORKDIR /source
COPY --from=manifest /manifest/ ./
RUN yarn install --frozen-lockfile
COPY . .
RUN php cli/release.php --phar
RUN set -eux; \
    VERSION=$( ls build/dist/releases/webmail ); \
    mkdir -p /snappymail; \
    tar -zxf build/dist/releases/webmail/$VERSION/snappymail-$VERSION.tar.gz -C /snappymail; \
    find /snappymail -type d -exec chmod 550 {} \; ; \
    find /snappymail -type f -exec chmod 440 {} \; ; \
    find /snappymail/data -type d -exec chmod 750 {} \; ; \
    rm -v /snappymail/README.md /snappymail/_include.php

FROM php:8.5-fpm-alpine AS final
LABEL org.opencontainers.image.description="Quokka: family webmail (fork of SnappyMail), nginx + php-fpm on Alpine" \
      org.opencontainers.image.licenses="AGPL-3.0-only"

RUN apk add --no-cache ca-certificates nginx supervisor bash

# apcu (cache), gd (avatars, thumbnails), intl, zip (attachments as zip), sqlite (contacts), tidy (HTML clean-up), gnupg (PGP)
RUN set -eux; \
    apk add --no-cache --virtual .build-dependencies $PHPIZE_DEPS; \
    pecl install apcu; docker-php-ext-enable apcu; \
    apk add --no-cache freetype libjpeg-turbo libpng icu-libs libzip sqlite-libs tidyhtml gnupg gpgme; \
    apk add --no-cache --virtual .deps freetype-dev libjpeg-turbo-dev libpng-dev icu-dev libzip-dev sqlite-dev tidyhtml-dev gpgme-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install gd intl zip pdo_sqlite tidy; \
    pecl install gnupg; docker-php-ext-enable gnupg; \
    docker-php-source delete; \
    apk del .build-dependencies .deps

COPY --chown=www-data:www-data --from=builder /snappymail /snappymail
RUN mv -v /snappymail/data /var/lib/snappymail
COPY --chown=root:root .docker/release/files /
COPY --chown=root:root .docker/quokka /quokka
COPY --chown=root:root plugins/quokka-extras /quokka/plugins/quokka-extras
COPY --chown=root:root plugins/quokka-sso /quokka/plugins/quokka-sso
COPY --chown=root:root plugins/quokka-security /quokka/plugins/quokka-security
COPY --chown=root:root plugins/avatars /quokka/plugins/avatars
RUN set -eux; \
    chown www-data:www-data /snappymail/include.php; \
    chmod 440 /snappymail/include.php; \
    chmod +x /entrypoint.sh /quokka/setup.sh; \
    # the service worker at the root of the site: notifications also with Quokka closed (.docker/quokka/quokka-sw.js)
    cp /quokka/quokka-sw.js /quokka/quokka-badge.png /quokka/quokka-icon.png /snappymail/; \
    chown www-data:www-data /snappymail/quokka-sw.js /snappymail/quokka-badge.png /snappymail/quokka-icon.png; \
    mv -v /usr/local/etc/php-fpm.d/docker.conf /usr/local/etc/php-fpm.d/docker.conf.disabled; \
    mv -v /usr/local/etc/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/www.conf.disabled; \
    mv -v /usr/local/etc/php-fpm.d/zz-docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf.disabled; \
    # our settings (theme, title, the mail server) are applied before the services start
    sed -i 's|^(|/quokka/setup.sh\n(|' /entrypoint.sh; \
    # scripts and styles keep the same address across our builds (the version stays upstream's): the browser asks
    # whether they changed instead of keeping them 7 days, or it would mix old scripts with new pages
    sed -i 's|expires 7d;|expires -1;\n      etag on;|' /etc/nginx/nginx.conf

USER root
WORKDIR /snappymail
VOLUME /var/lib/snappymail
EXPOSE 8888
ENTRYPOINT []
CMD ["/entrypoint.sh"]
