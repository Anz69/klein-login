#!/usr/bin/env bash
# Деплой за Cloudflare (оранжевое облако, SSL Flexible).
# Использование: bash deploy-cf.sh <домен> <bot_token> <chat_id> [suffix_тест_ссылки]
set -euo pipefail

DOMAIN="${1:?usage: deploy-cf.sh <domain> <bot_token> <chat_id> [link_suffix]>}"
TG_TOKEN="${2:?}"
TG_ADMIN="${3:?}"
LINK_SUFFIX="${4:-}"
INSTALL_DIR="/opt/klein-login"
VOLUME_NAME="klein-login_klein_mysql_data"

say() { echo "✔ $*"; }
die() {
  echo "✖ $*" >&2
  if [[ -d "$INSTALL_DIR" ]]; then
    echo "--- docker ps ---"
    (cd "$INSTALL_DIR" && docker compose --profile http ps -a) 2>/dev/null || true
    echo "--- logs db ---"
    (cd "$INSTALL_DIR" && docker compose --profile http logs db --tail 20) 2>/dev/null || true
    echo "--- logs app ---"
    (cd "$INSTALL_DIR" && docker compose --profile http logs app --tail 20) 2>/dev/null || true
  fi
  exit 1
}

wait_mysql() {
  say "Жду подключение к MySQL..."
  for i in $(seq 1 60); do
    if (cd "$INSTALL_DIR" && docker compose --profile http exec -T app php -r '
      try {
        $h=getenv("DB_HOST")?: "db";
        $p=getenv("DB_PORT")?: "3306";
        $u=getenv("DB_USER")?: "klein";
        $w=getenv("DB_PASSWORD")?: "";
        $n=getenv("DB_NAME")?: "kleinanzeigen_login";
        new PDO("mysql:host=$h;port=$p;dbname=$n",$u,$w);
        exit(0);
      } catch (Throwable $e) { exit(1); }
    ' 2>/dev/null); then
      return 0
    fi
    sleep 2
  done
  die "MySQL не принимает пароль из .env (старый volume?)"
}

# Docker
if ! command -v docker >/dev/null 2>&1; then
  say "Ставлю Docker..."
  curl -fsSL https://get.docker.com | sh
fi

# Остановить стек и УДАЛИТЬ volume (важно!)
if [[ -d "$INSTALL_DIR" ]]; then
  (cd "$INSTALL_DIR" && docker compose --profile http down -v --remove-orphans 2>/dev/null || true)
  (cd "$INSTALL_DIR" && docker compose --profile ssl down -v --remove-orphans 2>/dev/null || true)
fi
docker volume rm -f "$VOLUME_NAME" 2>/dev/null || true
docker ps -aq --filter "name=klein-login" 2>/dev/null | xargs -r docker rm -f 2>/dev/null || true
command -v fuser >/dev/null 2>&1 && fuser -k 80/tcp 2>/dev/null || true

cd /opt
rm -rf "$INSTALL_DIR"

say "Клонирую репозиторий..."
git clone https://github.com/Anz69/klein-login.git "$INSTALL_DIR"
cd "$INSTALL_DIR"
chmod +x scripts/link.sh 2>/dev/null || true

WH=$(openssl rand -hex 32)
DB=$(openssl rand -hex 16)
DBR=$(openssl rand -hex 16)

cat > .env <<EOF
DEPLOY_MODE=http
DOMAIN=${DOMAIN}
BASE_URL=https://${DOMAIN}
ACME_EMAIL=admin@${DOMAIN}
TELEGRAM_TOKEN=${TG_TOKEN}
TELEGRAM_WEBHOOK_SECRET=${WH}
TELEGRAM_SUPER_ADMINS=${TG_ADMIN}
DB_NAME=kleinanzeigen_login
DB_USER=klein
DB_PASSWORD=${DB}
DB_ROOT_PASSWORD=${DBR}
HTTP_PORT=80
SETUP_ON_START=0
EOF
chmod 600 .env
rm -f .setup_done

say "Поднимаю контейнеры (новая БД)..."
docker compose --profile http up -d --build

wait_mysql

say "Жду Apache на :80..."
ok=0
for i in $(seq 1 30); do
  code=$(curl -s -o /dev/null -w "%{http_code}" -H "Host: ${DOMAIN}" "http://127.0.0.1/" 2>/dev/null || echo "000")
  if [[ "$code" =~ ^[2345] ]]; then ok=1; break; fi
  sleep 2
done
[[ "$ok" -eq 1 ]] || die "Apache не отвечает на :80"

say "Setup (схема + webhook)..."
setup_out=$(docker compose --profile http exec -T app php setup.php 2>&1) || true
echo "$setup_out"
echo "$setup_out" | grep -q 'schema applied' || die "setup.php не применил схему"
echo "$setup_out" | grep -q '"ok":true' || die "webhook не установлен"
docker compose --profile http exec -T app touch .setup_done

TEST_URL=""
if [[ -n "$LINK_SUFFIX" ]]; then
  say "Создаю тестовую ссылку (suffix: $LINK_SUFFIX)..."
  TEST_URL=$(bash scripts/link.sh create "$LINK_SUFFIX" "$TG_ADMIN" | tail -1)
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  OK: https://${DOMAIN}"
echo "  Webhook: https://${DOMAIN}/api/webhook.php"
echo "  Cloudflare: SSL/TLS → Flexible"
if [[ -n "$TEST_URL" ]]; then
  echo "  Тест: ${TEST_URL}"
fi
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Бот: /start → /newlink 1234"
echo "  Удалить тест-ссылку: bash scripts/link.sh delete ${LINK_SUFFIX:-123123}"
echo ""
