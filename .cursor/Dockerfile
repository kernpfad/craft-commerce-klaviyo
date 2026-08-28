# Cloud Agent base image for the Commerce Klaviyo plugin.
#
# Pins PHP 8.3 (the project requires ^8.2; CI covers 8.2/8.3/8.4) plus the
# exact extension set the CI workflow installs, and Composer v2. Repository
# dependencies are installed later by the `install` phase in environment.json,
# not baked in here, so this image stays branch-independent.
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

# System tooling + PHP 8.3 with the extensions required by .github/workflows/ci.yml
# (json, openssl, pcre and tokenizer are compiled into php8.3-cli itself).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        unzip \
        php8.3-cli \
        php8.3-bcmath \
        php8.3-ctype \
        php8.3-curl \
        php8.3-dom \
        php8.3-gd \
        php8.3-intl \
        php8.3-mbstring \
        php8.3-pdo \
        php8.3-sqlite3 \
        php8.3-xml \
        php8.3-zip \
    && rm -rf /var/lib/apt/lists/*

# Composer v2, pinned via the official installer.
RUN curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && php /tmp/composer-setup.php --quiet --2 --install-dir=/usr/local/bin --filename=composer \
    && rm -f /tmp/composer-setup.php

# The ubuntu:24.04 image already ships a non-root `ubuntu` user (uid 1000),
# which environment.json runs commands as.
USER ubuntu
