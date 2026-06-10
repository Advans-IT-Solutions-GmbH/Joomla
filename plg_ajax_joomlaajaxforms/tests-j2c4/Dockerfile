FROM joomla:5.4-php8.2-apache

ARG JOOMLA_VERSION=5.4.6
ARG JOOMLA_PACKAGE_URL=https://github.com/joomla/joomla-cms/releases/download/${JOOMLA_VERSION}/Joomla_${JOOMLA_VERSION}-Stable-Full_Package.zip

RUN apt-get update -qq && apt-get install -y --no-install-recommends \
    unzip curl default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

RUN rm -rf /usr/src/joomla/* /var/www/html/* \
    && curl -fsSL "$JOOMLA_PACKAGE_URL" -o /tmp/joomla.zip \
    && unzip -q /tmp/joomla.zip -d /usr/src/joomla \
    && rm /tmp/joomla.zip

RUN echo "[client]\nskip-ssl" > /etc/mysql/conf.d/no-ssl.cnf

# Plugin ZIP is copied in by the CI build step
COPY plg_ajax_joomlaajaxforms/tests-j2c4/extension.zip /tmp/extension.zip

# J2Commerce 4 ZIP is downloaded at build time from the public GitHub release
ARG J2C4_URL=https://github.com/j2commerce/j2cart/releases/download/v4.1.4/com_j2store_v4-4.1.4-pro.zip
RUN curl -fsSL "$J2C4_URL" -o /tmp/j2commerce4.zip

# Standard test scripts (01-12) from the base test suite
COPY plg_ajax_joomlaajaxforms/tests/scripts/ /scripts/
# J2C4-specific overrides (11-j2c4-cart.php replaces 11-j2store-cart.php)
COPY plg_ajax_joomlaajaxforms/tests-j2c4/scripts/11-j2c4-cart.php /scripts/11-j2store-cart.php

COPY plg_ajax_joomlaajaxforms/tests-j2c4/scripts/docker-entrypoint.sh /docker-entrypoint-custom.sh
RUN chmod +x /docker-entrypoint-custom.sh

ENTRYPOINT ["/docker-entrypoint-custom.sh"]
CMD ["apache2-foreground"]
