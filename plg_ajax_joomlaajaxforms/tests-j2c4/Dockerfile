FROM joomla:5.4-php8.2-apache

RUN apt-get update -qq && apt-get install -y --no-install-recommends \
    unzip curl default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

RUN echo "[client]\nskip-ssl" > /etc/mysql/conf.d/no-ssl.cnf

# Plugin ZIP is copied in by the CI build step
COPY plg_ajax_joomlaajaxforms/tests-j2c4/extension.zip /tmp/extension.zip

# J2Commerce 4 ZIP is downloaded at build time from the public GitHub release
ARG J2C4_URL=https://github.com/j2commerce/j2cart/releases/download/v4.1.3/com_j2store_v4-4.1.3-pro.zip
RUN curl -fsSL "$J2C4_URL" -o /tmp/j2commerce4.zip

# Standard test scripts (01-12) from the base test suite
COPY plg_ajax_joomlaajaxforms/tests/scripts/ /scripts/
# J2C4-specific overrides (11-j2c4-cart.php replaces 11-j2store-cart.php)
COPY plg_ajax_joomlaajaxforms/tests-j2c4/scripts/11-j2c4-cart.php /scripts/11-j2store-cart.php

COPY plg_ajax_joomlaajaxforms/tests-j2c4/scripts/docker-entrypoint.sh /docker-entrypoint-custom.sh
RUN chmod +x /docker-entrypoint-custom.sh

ENTRYPOINT ["/docker-entrypoint-custom.sh"]
CMD ["apache2-foreground"]
