#!/usr/bin/env bash
# Переставить Telegram webhook (после смены домена / токена).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

if [[ -f .env ]]; then
  # shellcheck disable=SC1091
  set -a; source .env; set +a
fi

MODE="${DEPLOY_MODE:-ssl}"
SERVICE="app"
[[ "$MODE" == "ssl" ]] && SERVICE="app_ssl"

if docker compose version >/dev/null 2>&1; then
  COMPOSE=(docker compose)
else
  COMPOSE=(docker-compose)
fi

echo "Re-running setup.php in ${SERVICE}..."
rm -f .setup_done
"${COMPOSE[@]}" --profile "$MODE" exec -T "$SERVICE" php setup.php
"${COMPOSE[@]}" --profile "$MODE" exec -T "$SERVICE" touch /var/www/html/.setup_done
echo "Done."
