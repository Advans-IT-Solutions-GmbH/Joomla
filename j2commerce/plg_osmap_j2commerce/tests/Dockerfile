# Uses the pre-built base image (Joomla + OSMap + J2Commerce already installed).
# Only adds the plugin under test and the custom entrypoint.
FROM ghcr.io/advansit/joomla-osmap-j2commerce-base:latest

# Copy extension package (built by CI before docker build)
COPY extension.zip /tmp/plg_osmap_j2commerce.zip

COPY scripts/docker-entrypoint.sh /usr/local/bin/custom-entrypoint.sh
RUN chmod +x /usr/local/bin/custom-entrypoint.sh

ENV EXTENSION_NAME="OSMap J2Commerce Plugin"

ENTRYPOINT ["/usr/local/bin/custom-entrypoint.sh"]
CMD ["apache2-foreground"]
