# Base image for OSMap J2Commerce tests.
# Contains a fully installed Joomla 5 + OSMap Free + J2Commerce.
# Built once and cached in ghcr.io — test containers start in seconds.
FROM joomla:5-php8.2-apache

RUN apt-get update && apt-get install -y \
    unzip curl default-mysql-client wait-for-it \
    && rm -rf /var/lib/apt/lists/*

RUN echo "[client]\nskip-ssl" > /etc/mysql/conf.d/no-ssl.cnf

# Download dependencies at build time
RUN curl -sL "https://github.com/joomlashack/OSMap/releases/download/v5.1.3/com_osmap_free_5.1.3.zip" \
        -o /tmp/com_osmap.zip \
    && curl -sL "https://github.com/J2Store4/j2store4/releases/download/v4.0.5/com_j2store_v4-4.0.5-pro.zip" \
        -o /tmp/com_j2store.zip

COPY setup-base.sh /usr/local/bin/setup-base.sh
RUN chmod +x /usr/local/bin/setup-base.sh
