#!/bin/sh
set -eu
umask 077
# Provision a >=32-byte hex credential; rejecting other syntax prevents config injection.
credential="$(cat /run/holoul-redis/password)"
case "$credential" in ''|*[!0-9a-fA-F]*) exit 1;; esac
[ "${#credential}" -ge 64 ] || exit 1
printf 'requirepass %s\n' "$credential" > /tmp/redis.conf
unset credential
exec redis-server /tmp/redis.conf --bind 0.0.0.0 --protected-mode yes \
  --port 0 --tls-port 6379 --tls-auth-clients no \
  --tls-cert-file /run/holoul-redis-tls/server.crt \
  --tls-key-file /run/holoul-redis-tls/server.key \
  --tls-ca-cert-file /run/holoul-redis-tls/ca.crt \
  --tls-protocols 'TLSv1.2 TLSv1.3' \
  --save '' --appendonly no --maxmemory 128mb --maxmemory-policy noeviction
