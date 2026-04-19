FROM joomla:5-php8.2-apache

RUN apt-get update && apt-get install -y \
    unzip \
    curl \
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# Skip SSL for mysql client
RUN echo "[client]\nskip-ssl" > /etc/mysql/conf.d/no-ssl.cnf

# Download OSMap Free and J2Commerce at build time so the image is self-contained
RUN curl -sL "https://github.com/joomlashack/OSMap/releases/download/v5.1.3/com_osmap_free_5.1.3.zip" \
        -o /tmp/com_osmap.zip \
    && curl -sL "https://github.com/J2Store4/j2store4/releases/download/v4.0.5/com_j2store_v4-4.0.5-pro.zip" \
        -o /tmp/com_j2store.zip

# Copy extension package (built by CI before docker build)
COPY extension.zip /tmp/plg_osmap_j2commerce.zip

COPY scripts/docker-entrypoint.sh /usr/local/bin/custom-entrypoint.sh
RUN chmod +x /usr/local/bin/custom-entrypoint.sh

ENV EXTENSION_NAME="OSMap J2Commerce Plugin"

ENTRYPOINT ["/usr/local/bin/custom-entrypoint.sh"]
CMD ["apache2-foreground"]
