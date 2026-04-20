FROM joomla:5-php8.2-apache

# Install required packages
RUN apt-get update && apt-get install -y \
    unzip \
    curl \
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# Configure MySQL client to skip SSL
RUN echo "[client]\nskip-ssl" > /etc/mysql/conf.d/no-ssl.cnf

# Download OSMap (joomlashack/OSMap, free edition)
ARG OSMAP_VERSION=5.1.3
RUN curl -fsSL "https://github.com/joomlashack/OSMap/releases/download/v${OSMAP_VERSION}/com_osmap_free_${OSMAP_VERSION}.zip" \
    -o /tmp/osmap.zip

# Download J2Commerce
ARG J2COMMERCE_VERSION=4.0.20
RUN curl -fsSL "https://github.com/j2commerce/j2cart/releases/download/v${J2COMMERCE_VERSION}/com_j2store_v4-${J2COMMERCE_VERSION}-pro.zip" \
    -o /tmp/j2commerce.zip

# Copy extension package (built by CI)
COPY extension.zip /tmp/extension.zip

# Copy post-install hook (runs after Joomla entrypoint finishes)
COPY scripts/docker-entrypoint.sh /usr/local/bin/custom-entrypoint.sh
RUN chmod +x /usr/local/bin/custom-entrypoint.sh

ENV EXTENSION_NAME="OSMap J2Commerce Plugin"

ENTRYPOINT ["/usr/local/bin/custom-entrypoint.sh"]
CMD ["apache2-foreground"]
