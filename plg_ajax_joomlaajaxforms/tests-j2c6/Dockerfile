# Moving tag: newest Joomla 6.x release on the newest PHP of the official image.
ARG JOOMLA_BASE_IMAGE=joomla:6-php8.4-apache
FROM ${JOOMLA_BASE_IMAGE}


RUN apt-get update -qq && apt-get install -y --no-install-recommends \
    unzip curl default-mysql-client git \
    && rm -rf /var/lib/apt/lists/*

RUN echo "[client]\nskip-ssl" > /etc/mysql/conf.d/no-ssl.cnf

# Production-like lane (docker-compose.production.yml): log every PHP
# deprecation, warning and error of CLI and Apache to /tmp/php-errors.log.
# The shared-deprecations.php suite evaluates the log afterwards.
ARG PHP_STRICT_DEPRECATIONS=0
RUN if [ "$PHP_STRICT_DEPRECATIONS" = "1" ]; then \
        printf 'error_reporting = E_ALL\ndisplay_errors = Off\nlog_errors = On\nerror_log = /tmp/php-errors.log\n' \
            > "$PHP_INI_DIR/conf.d/zz-strict-deprecations.ini" \
        && touch /tmp/php-errors.log && chmod 0666 /tmp/php-errors.log; \
    fi
# Plugin ZIP copied in by CI
COPY plg_ajax_joomlaajaxforms/tests-j2c6/extension.zip /tmp/extension.zip

# J2Commerce 6 ZIP built from source by CI and placed at tests-j2c6/j2commerce6.zip
COPY plg_ajax_joomlaajaxforms/tests-j2c6/j2commerce6.zip /tmp/j2commerce6.zip

# Standard test scripts (01-12) from the base test suite
COPY plg_ajax_joomlaajaxforms/tests/scripts/ /scripts/
# J2C6-specific overrides (11-j2c6-cart.php replaces 11-j2store-cart.php)
COPY plg_ajax_joomlaajaxforms/tests-j2c6/scripts/11-j2c6-cart.php /scripts/11-j2store-cart.php

COPY plg_ajax_joomlaajaxforms/tests-j2c6/scripts/docker-entrypoint.sh /docker-entrypoint-custom.sh
RUN chmod +x /docker-entrypoint-custom.sh

ENTRYPOINT ["/docker-entrypoint-custom.sh"]
CMD ["apache2-foreground"]
