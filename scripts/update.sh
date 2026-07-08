#!/usr/bin/env bash
# Обновить код на уже работающем сервере (без пересоздания .env и БД)
set -euo pipefail
cd "$(dirname "$0")/.."

git pull
docker compose --profile http up -d --build
echo "✔ Обновлено: $(grep '^BASE_URL=' .env | cut -d= -f2-)"
