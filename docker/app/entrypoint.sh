#!/bin/sh
set -eu
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
exec "$@"
