#!/bin/sh
set -e

if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
    attempt=1
    while [ "$attempt" -le 10 ]; do
        if php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration; then
            break
        fi
        attempt=$((attempt + 1))
        sleep 2
    done

    if [ "$attempt" -gt 10 ]; then
        echo "Migrations failed after 10 attempts" >&2
        exit 1
    fi
fi

exec "$@"
