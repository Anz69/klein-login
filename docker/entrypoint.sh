#!/bin/bash
set -e

echo "[entrypoint] waiting for MySQL..."
for i in $(seq 1 60); do
  if php -r '
    try {
      $h = getenv("DB_HOST") ?: "db";
      $p = getenv("DB_PORT") ?: "3306";
      $root = getenv("DB_ROOT_PASSWORD");
      if ($root !== false && $root !== "") {
        new PDO("mysql:host=$h;port=$p", "root", $root);
        exit(0);
      }
      $u = getenv("DB_USER") ?: "klein";
      $w = getenv("DB_PASSWORD") ?: "";
      $n = getenv("DB_NAME") ?: "kleinanzeigen_login";
      new PDO("mysql:host=$h;port=$p;dbname=$n", $u, $w);
      exit(0);
    } catch (Throwable $e) { exit(1); }
  '; then
    echo "[entrypoint] MySQL is ready"
    php /var/www/html/scripts/ensure-db.php 2>/dev/null || true
    break
  fi
  sleep 1
  if [ "$i" -eq 60 ]; then
    echo "[entrypoint] MySQL wait timeout (starting Apache anyway)" >&2
  fi
done

# Setup НЕ блокирует Apache — выполняется deploy-cf.sh после старта
if [ "${SETUP_ON_START:-0}" = "1" ]; then
  MARKER=/var/www/html/.setup_done
  if [ ! -f "$MARKER" ]; then
    echo "[entrypoint] running setup.php in background..."
    (php /var/www/html/setup.php && touch "$MARKER") &
  fi
fi

echo "[entrypoint] starting Apache..."
exec "$@"
