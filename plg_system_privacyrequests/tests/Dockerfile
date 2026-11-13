ARG JOOMLA_TEST_IMAGE=joomla:5.4-php8.3-apache
FROM ${JOOMLA_TEST_IMAGE}

RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev curl \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

RUN printf '%s\n' 'error_reporting=E_ALL' 'log_errors=On' \
    'display_errors=Off' 'error_log=/tmp/php-errors.log' \
    'opcache.revalidate_freq=0' \
    > /usr/local/etc/php/conf.d/zz-privacy-tests.ini \
    && touch /tmp/php-errors.log && chmod 666 /tmp/php-errors.log

COPY extension.zip /tmp/extension.zip
COPY scripts/docker-entrypoint.sh /usr/local/bin/privacy-test-entrypoint.sh
RUN chmod +x /usr/local/bin/privacy-test-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/privacy-test-entrypoint.sh"]
CMD ["apache2-foreground"]
