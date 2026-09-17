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

# Copy extension package
COPY extension.zip /tmp/extension.zip

ARG J2C4_URL=https://github.com/j2commerce/j2cart/releases/download/v4.1.4/com_j2store_v4-4.1.4-pro.zip
RUN curl -fsSL "$J2C4_URL" -o /tmp/j2commerce4.zip

# Copy custom entrypoint
COPY scripts/docker-entrypoint.sh /usr/local/bin/custom-entrypoint.sh
RUN chmod +x /usr/local/bin/custom-entrypoint.sh

ENV EXTENSION_NAME="J2Commerce Import/Export Component"

ENTRYPOINT ["/usr/local/bin/custom-entrypoint.sh"]
CMD ["apache2-foreground"]
