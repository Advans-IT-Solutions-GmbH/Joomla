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
# The CI passes the latest J2Store 4 release of j2commerce/j2cart and its sha256 (job
# "Resolve Shop Releases", .github/actions/resolve-shops); a local build without these
# arguments takes the fallback release below, unverified.
ARG J2C4_URL=
ARG J2C4_SHA256=
RUN URL="${J2C4_URL:-https://github.com/j2commerce/j2cart/releases/download/v4.1.5/com_j2store_v4-4.1.5-pro.zip}" \
    && curl -fsSL --retry 3 --retry-all-errors "$URL" -o /tmp/j2commerce4.zip \
    && if [ -n "$J2C4_SHA256" ]; then echo "$J2C4_SHA256  /tmp/j2commerce4.zip" | sha256sum -c -; fi \
    && echo "J2Store 4 package: $URL"

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
