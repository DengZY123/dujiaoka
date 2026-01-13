FROM webdevops/php-nginx:7.4
COPY . /app
WORKDIR /app
RUN [ "sh", "-c", "composer install --ignore-platform-reqs" ]
RUN cat <<'EOF' > /app/start.sh
#!/bin/sh
set -e

if [ "${AUTO_INSTALL_LOCK:-true}" = "true" ] && [ ! -e /app/install.lock ]; then
  echo "install ok" > /app/install.lock
fi

exec supervisord
EOF
RUN mkdir -p /app/public/uploads /app/storage/logs /app/bootstrap/cache \
    && chown -R application:application /app \
    && chmod -R 755 /app \
    && chmod -R 775 /app/storage /app/bootstrap/cache /app/public/uploads
CMD [ "sh", "-c","/app/start.sh" ]
