# Moving tag: newest Joomla 5.4.x release on the newest PHP of the official image.
FROM joomla:5.4-php8.3-apache


# Install required packages
RUN apt-get update && apt-get install -y \
    unzip \
    curl \
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# Configure MySQL client to skip SSL
RUN echo "[client]\nskip-ssl" > /etc/mysql/conf.d/no-ssl.cnf

# Download OSMap (joomlashack/OSMap, free edition)
ARG OSMAP_VERSION=5.1.6
RUN curl -fsSL "https://github.com/joomlashack/OSMap/releases/download/v${OSMAP_VERSION}/com_osmap_free_${OSMAP_VERSION}.zip" \
    -o /tmp/osmap.zip

# Download J2Store/J2Commerce 4
ARG J2C4_URL=https://github.com/j2commerce/j2cart/releases/download/v4.1.4/com_j2store_v4-4.1.4-pro.zip
RUN curl -fsSL "$J2C4_URL" -o /tmp/j2commerce4.zip

# Copy extension package (built by CI)
COPY extension.zip /tmp/extension.zip

# Copy post-install hook (runs after Joomla entrypoint finishes)
COPY scripts/docker-entrypoint.sh /usr/local/bin/custom-entrypoint.sh
COPY scripts/install-extension.php /usr/local/bin/install-extension.php
COPY scripts/install-extension-http.php /usr/local/bin/install-extension-http.php
RUN chmod +x /usr/local/bin/custom-entrypoint.sh

ENV EXTENSION_NAME="OSMap J2Commerce Plugin"

ENTRYPOINT ["/usr/local/bin/custom-entrypoint.sh"]
CMD ["apache2-foreground"]
