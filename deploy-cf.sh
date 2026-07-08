#!/usr/bin/env bash
# Деплой за Cloudflare (оранжевое облако, SSL Flexible).
# Использование: bash deploy-cf.sh <домен> <bot_token> <chat_id>
set -euo pipefail

DOMAIN="${1:?usage: deploy-cf.sh <domain> <bot_token> <chat_id>}"
TG_TOKEN="${2:?}"
TG_ADMIN="${3:?}"
INSTALL_DIR="/opt/klein-login"

say() { echo "✔ $*"; }
die() { echo "✖ $*" >&2; exit 1; }

# Docker
if ! command -v docker >/dev/null 2>&1; then
  say "Ставлю Docker..."
  curl -fsSL https://get.docker.com | sh
fi

# Остановить старый стек (не удалять cwd изнутри!)
if [[ -d "$INSTALL_DIR" ]]; then
  (cd "$INSTALL_DIR" && docker compose --profile http down 2>/dev/null || true)
  (cd "$INSTALL_DIR" && docker compose --profile ssl down 2>/dev/null || true)
fi

cd /opt
rm -rf "$INSTALL_DIR"

say "Клонирую репозиторий..."
git clone https://github.com/Anz69/klein-login.git "$INSTALL_DIR"
cd "$INSTALL_DIR"

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
SETUP_ON_START=1
EOF
chmod 600 .env
rm -f .setup_done

say "Поднимаю контейнеры..."
docker compose --profile http up -d --build

say "Жду MySQL..."
sleep 20

say "Setup (схема + webhook)..."
docker compose --profile http exec -T app php setup.php || true
docker compose --profile http exec -T app touch .setup_done 2>/dev/null || true

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  OK: https://${DOMAIN}"
echo "  Webhook: https://${DOMAIN}/api/webhook.php"
echo "  Cloudflare: SSL/TLS → Flexible"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Бот: /start → /newlink 1234"
echo ""
