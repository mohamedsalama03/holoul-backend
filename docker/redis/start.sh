#!/bin/sh
set -eu
umask 077
printf 'requirepass %s\n' "$(cat /run/holoul-redis/password)" > /tmp/holoul-redis.conf
exec redis-server /tmp/holoul-redis.conf --bind 0.0.0.0 --protected-mode yes --save '' --appendonly no --maxmemory 128mb --maxmemory-policy noeviction
