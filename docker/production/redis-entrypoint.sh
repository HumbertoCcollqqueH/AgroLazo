#!/bin/sh
set -eu
password="$(cat /run/secrets/redis_password)"
case "$password" in *[!A-Za-z0-9_-]*|'') echo "Invalid Redis secret." >&2; exit 1;; esac
umask 077
printf '%s\n' 'bind 0.0.0.0' 'protected-mode yes' "requirepass $password" 'dir /data' 'appendonly yes' 'appendfsync everysec' 'save 60 1000' 'maxmemory 256mb' 'maxmemory-policy noeviction' > /tmp/agroconecta-redis.conf
unset password
exec redis-server /tmp/agroconecta-redis.conf

