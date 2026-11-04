#!/bin/sh
# HTTPS for the test images (Dockerfile, Dockerfile.joomla6), run once at image
# build time. CalDAV answers over HTTPS only, so the internet calendar suites and
# the CalDAV client job need TLS; every other suite keeps using plain HTTP on
# port 80, which stays unchanged.
#
# A throw-away test CA signs one server certificate for the names the tests use:
# "localhost" inside the Joomla container and "joomla", the compose service name
# that the CalDAV client container connects to. Clients verify the chain against
# the CA certificate in $TLS_DIR/ca.pem instead of switching verification off.
# Nothing here is a secret: the keys are generated per image build, live only
# inside the test image and are never committed.
set -eu

TLS_DIR=/etc/ssl/appointments-test
mkdir -p "$TLS_DIR"
cd "$TLS_DIR"

# Test CA. Explicit CA extensions, because strict verifiers (Python 3.13 sets
# VERIFY_X509_STRICT by default) reject a CA without key usage.
openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 3650 \
    -subj "/CN=com_appointments test CA" \
    -addext "basicConstraints=critical,CA:TRUE" \
    -addext "keyUsage=critical,keyCertSign,cRLSign" \
    -keyout ca.key -out ca.pem

openssl req -newkey rsa:2048 -nodes -sha256 \
    -subj "/CN=localhost" \
    -keyout server.key -out server.csr

cat > server.ext <<'EOF'
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=DNS:localhost,DNS:joomla,IP:127.0.0.1
subjectKeyIdentifier=hash
authorityKeyIdentifier=keyid,issuer
EOF

openssl x509 -req -in server.csr -CA ca.pem -CAkey ca.key -CAcreateserial \
    -sha256 -days 3650 -extfile server.ext -out server.pem

# The CA key is not needed after signing.
rm -f ca.key ca.srl server.csr server.ext
chmod 0644 ca.pem server.pem
chmod 0600 server.key

# Trust the test CA inside the image too, so PHP and curl in the container verify
# https://localhost like any other client would.
cp ca.pem /usr/local/share/ca-certificates/appointments-test-ca.crt
update-ca-certificates >/dev/null

# Port 443 next to the unchanged port 80 site. AllowOverride for /var/www comes
# from the official image's apache configuration, so Joomla's .htaccess applies
# on both ports.
cat > /etc/apache2/sites-available/appointments-test-ssl.conf <<EOF
<IfModule mod_ssl.c>
    <VirtualHost _default_:443>
        DocumentRoot /var/www/html
        SSLEngine on
        SSLCertificateFile $TLS_DIR/server.pem
        SSLCertificateKeyFile $TLS_DIR/server.key
    </VirtualHost>
</IfModule>
EOF

a2enmod ssl >/dev/null
a2ensite appointments-test-ssl >/dev/null
