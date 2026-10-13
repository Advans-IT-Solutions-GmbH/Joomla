# Moving tag: newest Joomla 5.4.x release on the newest PHP of the official image.
FROM joomla:5.4-php8.3-apache


RUN apt-get update -qq && apt-get install -y --no-install-recommends \
    unzip curl default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

RUN echo "[client]\nskip-ssl" > /etc/mysql/conf.d/no-ssl.cnf

# Plugin ZIP is copied in by the CI build step
COPY plg_ajax_joomlaajaxforms/tests-j2c4/extension.zip /tmp/extension.zip

# J2Commerce 4 ZIP is downloaded at build time from the public GitHub release
# The CI passes the latest J2Store 4 release of j2commerce/j2cart and its sha256 (job
# "Resolve Shop Releases", .github/actions/resolve-shops); a local build without these
# arguments takes the fallback release below, unverified.
ARG J2C4_URL=
ARG J2C4_SHA256=
RUN URL="${J2C4_URL:-https://github.com/j2commerce/j2cart/releases/download/v4.1.5/com_j2store_v4-4.1.5-pro.zip}" \
    && curl -fsSL --retry 3 --retry-all-errors "$URL" -o /tmp/j2commerce4.zip \
    && if [ -n "$J2C4_SHA256" ]; then echo "$J2C4_SHA256  /tmp/j2commerce4.zip" | sha256sum -c -; fi \
    && echo "J2Store 4 package: $URL"

# Standard test scripts (01-12) from the base test suite
COPY plg_ajax_joomlaajaxforms/tests/scripts/ /scripts/
# J2C4-specific overrides (11-j2c4-cart.php replaces 11-j2store-cart.php)
COPY plg_ajax_joomlaajaxforms/tests-j2c4/scripts/11-j2c4-cart.php /scripts/11-j2store-cart.php

COPY plg_ajax_joomlaajaxforms/tests-j2c4/scripts/docker-entrypoint.sh /docker-entrypoint-custom.sh
RUN chmod +x /docker-entrypoint-custom.sh

ENTRYPOINT ["/docker-entrypoint-custom.sh"]
CMD ["apache2-foreground"]
