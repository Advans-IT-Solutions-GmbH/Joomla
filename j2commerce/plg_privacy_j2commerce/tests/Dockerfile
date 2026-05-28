FROM joomla:5-php8.2-apache

# Install required packages
RUN apt-get update && apt-get install -y \
    unzip \
    curl \
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# Configure MySQL client to skip SSL
RUN echo "[client]\nskip-ssl" > /etc/mysql/conf.d/no-ssl.cnf

# Download J2Commerce from GitHub release (real installation, no mocks)
ARG J2COMMERCE_VERSION=v4.0.20
RUN curl -sL "https://github.com/j2commerce/j2cart/releases/download/${J2COMMERCE_VERSION}/com_j2store_v4-$(echo ${J2COMMERCE_VERSION} | sed 's/^v//')-pro.zip" \
    -o /tmp/j2commerce.zip

# Copy extension package
COPY extension.zip /tmp/extension.zip

# Copy custom entrypoint
COPY scripts/docker-entrypoint.sh /usr/local/bin/custom-entrypoint.sh
RUN chmod +x /usr/local/bin/custom-entrypoint.sh

ENV EXTENSION_NAME="J2Commerce Privacy Plugin"

ENTRYPOINT ["/usr/local/bin/custom-entrypoint.sh"]
CMD ["apache2-foreground"]
