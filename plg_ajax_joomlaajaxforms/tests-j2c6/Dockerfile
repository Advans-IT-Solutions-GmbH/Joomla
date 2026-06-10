FROM joomla:6.1-php8.3-apache

ARG JOOMLA_VERSION=6.1.1
ARG JOOMLA_PACKAGE_URL=https://github.com/joomla/joomla-cms/releases/download/${JOOMLA_VERSION}/Joomla_${JOOMLA_VERSION}-Stable-Full_Package.zip

RUN apt-get update -qq && apt-get install -y --no-install-recommends \
    unzip curl default-mysql-client git \
    && rm -rf /var/lib/apt/lists/*

RUN rm -rf /usr/src/joomla/* /var/www/html/* \
    && curl -fsSL "$JOOMLA_PACKAGE_URL" -o /tmp/joomla.zip \
    && unzip -q /tmp/joomla.zip -d /usr/src/joomla \
    && rm /tmp/joomla.zip

RUN echo "[client]\nskip-ssl" > /etc/mysql/conf.d/no-ssl.cnf

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
