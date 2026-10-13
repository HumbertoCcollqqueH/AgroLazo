#!/bin/sh
set -eu
case "$PROD_SERVER_NAME" in *[!a-zA-Z0-9.-]*|'') echo "Invalid server name." >&2; exit 1;; esac
case "$PROD_APP_URL" in https://*) ;; *) echo "HTTPS origin required." >&2; exit 1;; esac
case "$PROD_APP_URL" in *[!a-zA-Z0-9:/.-]*) echo "Invalid HTTPS origin." >&2; exit 1;; esac
envsubst '${PROD_SERVER_NAME} ${PROD_APP_URL}' < /etc/nginx/templates/site.conf.template > /etc/nginx/conf.d/default.conf
exec "$@"

