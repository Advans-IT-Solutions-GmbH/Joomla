FROM joomla:5.4-php8.2-apache

ARG JOOMLA_VERSION=5.4.6
ARG JOOMLA_PACKAGE_URL=https://github.com/joomla/joomla-cms/releases/download/${JOOMLA_VERSION}/Joomla_${JOOMLA_VERSION}-Stable-Full_Package.zip

# Install required packages
RUN apt-get update && apt-get install -y \
    unzip \
    curl \
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

RUN rm -rf /usr/src/joomla/* /var/www/html/* \
    && curl -fsSL "$JOOMLA_PACKAGE_URL" -o /tmp/joomla.zip \
    && unzip -q /tmp/joomla.zip -d /usr/src/joomla \
    && rm /tmp/joomla.zip

# Configure MySQL client to skip SSL
RUN echo "[client]\nskip-ssl" > /etc/mysql/conf.d/no-ssl.cnf

# Copy extension package
COPY extension.zip /tmp/extension.zip

ARG J2C4_URL=https://github.com/j2commerce/j2cart/releases/download/v4.1.4/com_j2store_v4-4.1.4-pro.zip
RUN curl -fsSL "$J2C4_URL" -o /tmp/j2commerce4.zip

# Copy custom entrypoint
COPY scripts/docker-entrypoint.sh /usr/local/bin/custom-entrypoint.sh
RUN chmod +x /usr/local/bin/custom-entrypoint.sh

ENV EXTENSION_NAME="Cleanup Component"

ENTRYPOINT ["/usr/local/bin/custom-entrypoint.sh"]
CMD ["apache2-foreground"]
