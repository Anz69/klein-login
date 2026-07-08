#!/bin/bash
set -e

echo "[entrypoint] waiting for MySQL..."
for i in $(seq 1 60); do
  if php -r '
    $h = getenv("DB_HOST") ?: "db";
    $p = (int)(getenv("DB_PORT") ?: "3306");
    $errno = 0; $errstr = "";
    $fp = @fsockopen($h, $p, $errno, $errstr, 2);
    if ($fp) { fclose($fp); exit(0); }
    exit(1);
  '; then
    echo "[entrypoint] MySQL port is open"
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
