# Base image for OSMap J2Commerce tests.
# Extends joomla:5 with OSMap and J2Commerce pre-downloaded.
# Joomla installation itself happens at runtime via the official entrypoint.
FROM joomla:5-php8.2-apache

RUN apt-get update && apt-get install -y \
    unzip curl default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

RUN echo "[client]\nskip-ssl" > /etc/mysql/conf.d/no-ssl.cnf

# Download dependencies at build time so they are available without network at test time
RUN curl -sL "https://github.com/joomlashack/OSMap/releases/download/v5.1.3/com_osmap_free_5.1.3.zip" \
        -o /tmp/com_osmap.zip \
    && curl -sL "https://github.com/J2Store4/j2store4/releases/download/v4.0.5/com_j2store_v4-4.0.5-pro.zip" \
        -o /tmp/com_j2store.zip

COPY setup-base.sh /usr/local/bin/setup-base.sh
RUN chmod +x /usr/local/bin/setup-base.sh

# Keep the official joomla:5 entrypoint — do NOT override it here
