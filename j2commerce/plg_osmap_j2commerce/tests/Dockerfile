FROM joomla:5-php8.2-apache

# Install required packages
RUN apt-get update && apt-get install -y \
    unzip \
    curl \
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# Configure MySQL client to skip SSL
RUN echo "[client]\nskip-ssl" > /etc/mysql/conf.d/no-ssl.cnf

# Download OSMap from GitHub release
ARG OSMAP_VERSION=v4.5.2
RUN curl -sL "https://github.com/joomlatools/joomlatools-framework-osmap/releases/download/${OSMAP_VERSION}/pkg_osmap-${OSMAP_VERSION}.zip" \
    -o /tmp/osmap.zip || \
    curl -sL "https://github.com/joomlatools/joomlatools-framework-osmap/releases/latest/download/pkg_osmap.zip" \
    -o /tmp/osmap.zip || \
    echo "WARNING: OSMap download failed"

# Download J2Commerce from GitHub release
ARG J2COMMERCE_VERSION=v4.0.20
RUN curl -sL "https://github.com/j2commerce/j2cart/releases/download/${J2COMMERCE_VERSION}/com_j2store_v4-$(echo ${J2COMMERCE_VERSION} | sed 's/^v//')-pro.zip" \
    -o /tmp/j2commerce.zip

# Copy extension package (built by CI)
COPY extension.zip /tmp/extension.zip

# Copy custom entrypoint
COPY scripts/docker-entrypoint.sh /usr/local/bin/custom-entrypoint.sh
RUN chmod +x /usr/local/bin/custom-entrypoint.sh

ENV EXTENSION_NAME="OSMap J2Commerce Plugin"

ENTRYPOINT ["/usr/local/bin/custom-entrypoint.sh"]
CMD ["apache2-foreground"]
