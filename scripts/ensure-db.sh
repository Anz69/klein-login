#!/usr/bin/env bash
# Создаёт БД и пользователя через mysql внутри контейнера db (root@localhost).
set -euo pipefail
cd "$(dirname "$0")/.."

[[ -f .env ]] || { echo "✖ .env not found"; exit 1; }

read_env() {
  local key=$1
  grep -m1 "^${key}=" .env | cut -d= -f2- || true
}

DB_NAME=$(read_env DB_NAME)
DB_USER=$(read_env DB_USER)
DB_PASSWORD=$(read_env DB_PASSWORD)
DB_ROOT_PASSWORD=$(read_env DB_ROOT_PASSWORD)

DB_NAME=${DB_NAME:-kleinanzeigen_login}
DB_USER=${DB_USER:-klein}

[[ -n "$DB_PASSWORD" && -n "$DB_ROOT_PASSWORD" ]] || {
  echo "✖ DB_PASSWORD и DB_ROOT_PASSWORD должны быть в .env"
  exit 1
}

docker compose --profile http exec -T db mysql -uroot -p"${DB_ROOT_PASSWORD}" <<EOSQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
EOSQL

docker compose --profile http exec -T app php -r '
try {
  $h = getenv("DB_HOST") ?: "db";
  $p = getenv("DB_PORT") ?: "3306";
  $n = getenv("DB_NAME") ?: "kleinanzeigen_login";
  $u = getenv("DB_USER") ?: "klein";
  $w = getenv("DB_PASSWORD") ?: "";
  new PDO("mysql:host=$h;port=$p;dbname=$n;charset=utf8mb4", $u, $w);
  echo "[ok] database `$n` and user `$u` ready\n";
} catch (Throwable $e) {
  fwrite(STDERR, "[ensure-db] " . $e->getMessage() . "\n");
  exit(1);
}
'
