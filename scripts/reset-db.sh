#!/usr/bin/env bash
# Полный сброс MySQL volume + пересоздание пользователя из .env
set -euo pipefail
cd "$(dirname "$0")/.."

say() { echo "✔ $*"; }

say "Останавливаю стек и удаляю volume..."
docker compose --profile http down -v --remove-orphans 2>/dev/null || true
docker compose --profile ssl down -v --remove-orphans 2>/dev/null || true
docker volume ls -q | grep -i klein | xargs -r docker volume rm -f 2>/dev/null || true

say "Поднимаю контейнеры..."
docker compose --profile http up -d --build

say "Жду MySQL (healthy)..."
for i in $(seq 1 60); do
  if docker compose --profile http ps db 2>/dev/null | grep -q '(healthy)'; then
    break
  fi
  sleep 2
  [[ "$i" -eq 60 ]] && { echo "✖ MySQL не стал healthy"; exit 1; }
done

sleep 3
bash scripts/ensure-db.sh
docker compose --profile http exec -T app php setup.php
docker compose --profile http exec -T app touch .setup_done
say "Готово"
