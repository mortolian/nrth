#!/bin/sh
set -eu

SITE="${CADDY_SITE:-localhost}"
TLS="${CADDY_TLS:-internal}"

{
    printf '%s {\n' "$SITE"
    if [ -n "$TLS" ] && [ "$TLS" != "off" ]; then
        printf '    tls %s\n' "$TLS"
    fi
    printf '    reverse_proxy app:8000\n'
    printf '}\n'
} > /etc/caddy/Caddyfile

exec caddy run --config /etc/caddy/Caddyfile --adapter caddyfile
