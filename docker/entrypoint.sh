#!/bin/bash
set -e

echo "[entrypoint] waiting for MySQL..."
for i in $(seq 1 60); do
  if php -r '
    try {
      $h = getenv("DB_HOST") ?: "db";
      $p = getenv("DB_PORT") ?: "3306";
      $u = getenv("DB_USER") ?: "klein";
      $w = getenv("DB_PASSWORD") ?: "";
      $n = getenv("DB_NAME") ?: "kleinanzeigen_login";
      new PDO("mysql:host=$h;port=$p;dbname=$n", $u, $w);
      exit(0);
    } catch (Throwable $e) { exit(1); }
  '; then
    echo "[entrypoint] MySQL is ready"
    break
  fi
  sleep 2
  if [ "$i" -eq 60 ]; then
    echo "[entrypoint] MySQL wait timeout" >&2
    exit 1
  fi
done

if [ "${SETUP_ON_START:-1}" = "1" ]; then
  MARKER=/var/www/html/.setup_done
  if [ ! -f "$MARKER" ]; then
    echo "[entrypoint] running setup.php (schema + webhook)..."
    if php /var/www/html/setup.php; then
      touch "$MARKER"
      echo "[entrypoint] setup complete"
    else
      echo "[entrypoint] setup failed (will retry next start)" >&2
    fi
  else
    echo "[entrypoint] setup already done (remove .setup_done to re-run)"
  fi
fi

exec "$@"
