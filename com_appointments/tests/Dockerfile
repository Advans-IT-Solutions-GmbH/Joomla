# Moving tag: newest Joomla 5.4.x release on the newest PHP of the official image.
FROM joomla:5.4-php8.3-apache

# Install required packages.
RUN apt-get update && apt-get install -y \
    unzip \
    curl \
    default-mysql-client \
    libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# Configure MySQL client to skip SSL.
RUN echo "[client]\nskip-ssl" > /etc/mysql/conf.d/no-ssl.cnf

# Copy extension package.
COPY extension.zip /tmp/extension.zip

# Copy custom entrypoint.
COPY scripts/docker-entrypoint.sh /usr/local/bin/custom-entrypoint.sh
COPY scripts/capture-mail.php /usr/local/bin/appointments-capture-mail
COPY scripts/configure-mail.php /usr/local/bin/appointments-configure-mail.php
RUN chmod +x /usr/local/bin/custom-entrypoint.sh /usr/local/bin/appointments-capture-mail \
    && mkdir /tmp/appointments-mail && chmod 1777 /tmp/appointments-mail

ENV EXTENSION_NAME="Appointments Component"

ENTRYPOINT ["/usr/local/bin/custom-entrypoint.sh"]
CMD ["apache2-foreground"]
