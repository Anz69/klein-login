#!/usr/bin/env bash
# =====================================================================
#  klein-login — установка на Ubuntu одной командой
#
#  curl -fsSL https://YOUR_DOMAIN/.../install.sh | bash
#  или:  cd klein-login && bash install.sh
# =====================================================================
set -euo pipefail

RED=$'\033[0;31m'
GREEN=$'\033[0;32m'
YELLOW=$'\033[1;33m'
CYAN=$'\033[0;36m'
BOLD=$'\033[1m'
NC=$'\033[0m'

say()  { echo -e "${GREEN}✔${NC} $*"; }
warn() { echo -e "${YELLOW}!${NC} $*"; }
die()  { echo -e "${RED}✖${NC} $*" >&2; exit 1; }
ask()  {
  local prompt="$1" default="${2:-}" var
  if [[ -n "$default" ]]; then
    read -r -p "$(echo -e "${CYAN}?${NC} ${prompt} [${default}]: ")" var || true
    echo "${var:-$default}"
  else
    while true; do
      read -r -p "$(echo -e "${CYAN}?${NC} ${prompt}: ")" var || true
      [[ -n "${var:-}" ]] && { echo "$var"; return; }
      echo -e "  ${RED}обязательно${NC}"
    done
  fi
}

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

echo ""
echo -e "${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BOLD}  klein-login · Docker · Ubuntu${NC}"
echo -e "${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

# ---------- 1. Docker ----------
if ! command -v docker >/dev/null 2>&1; then
  say "Docker не найден — ставлю get.docker.com..."
  if [[ "$(id -u)" -ne 0 ]]; then
    curl -fsSL https://get.docker.com | sudo sh
    sudo usermod -aG docker "$USER" || true
  else
    curl -fsSL https://get.docker.com | sh
  fi
  say "Docker установлен"
else
  say "Docker уже есть: $(docker --version)"
fi

# docker / compose (если нет доступа к сокету — через sudo)
DOCKER=(docker)
if ! docker info >/dev/null 2>&1; then
  if sudo docker info >/dev/null 2>&1; then
    DOCKER=(sudo docker)
    warn "Docker через sudo (после перелогина группа docker подхватится)"
  else
    die "Docker установлен, но нет доступа. Попробуй: sudo usermod -aG docker \$USER && newgrp docker"
  fi
fi

if "${DOCKER[@]}" compose version >/dev/null 2>&1; then
  COMPOSE=("${DOCKER[@]}" compose)
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE=(docker-compose)
  if ! docker-compose version >/dev/null 2>&1; then
    COMPOSE=(sudo docker-compose)
  fi
else
  die "Нет docker compose. Перелогинься или: sudo apt install docker-compose-plugin"
fi
say "Compose: ${COMPOSE[*]}"

# ---------- 2. Вопросы ----------
echo ""
echo -e "${BOLD}Данные для запуска${NC}"
echo "  Токен бота: @BotFather → /newbot"
echo "  Свой chat_id: напиши боту /start, потом /myid (после деплоя) или @userinfobot"
echo ""

DEPLOY_MODE=$(ask "Режим (ssl = HTTPS + Let's Encrypt, http = порт без SSL)" "ssl")
DEPLOY_MODE=$(echo "$DEPLOY_MODE" | tr '[:upper:]' '[:lower:]')
[[ "$DEPLOY_MODE" == "ssl" || "$DEPLOY_MODE" == "http" ]] || die "Режим только ssl или http"

if [[ "$DEPLOY_MODE" == "ssl" ]]; then
  DOMAIN=$(ask "Домен (A-запись уже на этот сервер)")
  ACME_EMAIL=$(ask "Email для Let's Encrypt" "admin@${DOMAIN}")
  BASE_URL="https://${DOMAIN}"
  HTTP_PORT="8080"
else
  DOMAIN=$(ask "Hostname / IP (для BASE_URL)" "127.0.0.1")
  HTTP_PORT=$(ask "HTTP-порт на хосте" "8080")
  ACME_EMAIL="admin@localhost"
  if [[ "$DOMAIN" =~ ^[0-9.]+$ ]]; then
    BASE_URL="http://${DOMAIN}:${HTTP_PORT}"
  else
    BASE_URL="http://${DOMAIN}:${HTTP_PORT}"
  fi
fi

TELEGRAM_TOKEN=$(ask "Telegram bot token")
ADMINS=$(ask "Super-admin chat_id (несколько через запятую)")
TELEGRAM_WEBHOOK_SECRET=$(openssl rand -hex 32 2>/dev/null || head -c 32 /dev/urandom | xxd -p -c 64)
DB_PASSWORD=$(openssl rand -hex 16 2>/dev/null || head -c 16 /dev/urandom | xxd -p -c 32)
DB_ROOT_PASSWORD=$(openssl rand -hex 16 2>/dev/null || head -c 16 /dev/urandom | xxd -p -c 32)

echo ""
echo -e "${BOLD}Сводка:${NC}"
echo "  Mode:     $DEPLOY_MODE"
echo "  BASE_URL: $BASE_URL"
echo "  Domain:   $DOMAIN"
echo "  Admins:   $ADMINS"
echo "  Webhook secret: ${TELEGRAM_WEBHOOK_SECRET:0:8}…"
echo ""
CONFIRM=$(ask "Продолжить? (y/n)" "y")
[[ "$CONFIRM" =~ ^[Yy]$ ]] || die "Отменено"

# ---------- 3. .env ----------
cat > .env <<EOF
DEPLOY_MODE=${DEPLOY_MODE}
DOMAIN=${DOMAIN}
BASE_URL=${BASE_URL}
ACME_EMAIL=${ACME_EMAIL}
TELEGRAM_TOKEN=${TELEGRAM_TOKEN}
TELEGRAM_WEBHOOK_SECRET=${TELEGRAM_WEBHOOK_SECRET}
TELEGRAM_SUPER_ADMINS=${ADMINS}
DB_NAME=kleinanzeigen_login
DB_USER=klein
DB_PASSWORD=${DB_PASSWORD}
DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
HTTP_PORT=${HTTP_PORT}
SETUP_ON_START=1
EOF
chmod 600 .env
say "Создан .env (права 600)"

# Сброс маркера setup, чтобы при первом старте всё прогналось
rm -f .setup_done

# ---------- 4. Firewall hint ----------
if command -v ufw >/dev/null 2>&1; then
  if [[ "$DEPLOY_MODE" == "ssl" ]]; then
    warn "Если ufw включён: sudo ufw allow 80,443/tcp && sudo ufw reload"
  else
    warn "Если ufw включён: sudo ufw allow ${HTTP_PORT}/tcp && sudo ufw reload"
  fi
fi

# ---------- 5. Up ----------
say "Собираю и поднимаю контейнеры..."
if [[ "$DEPLOY_MODE" == "ssl" ]]; then
  "${COMPOSE[@]}" --profile ssl up -d --build
else
  "${COMPOSE[@]}" --profile http up -d --build
fi

say "Жду готовности приложения..."
sleep 5

APP_SERVICE="app"
[[ "$DEPLOY_MODE" == "ssl" ]] && APP_SERVICE="app_ssl"

# Повторный setup если webhook ещё не встал (DNS/сертификат)
for i in 1 2 3 4 5; do
  if "${COMPOSE[@]}" exec -T "$APP_SERVICE" php setup.php 2>/dev/null | tee /tmp/klein-setup.out | grep -q '"ok":true'; then
    say "Webhook установлен"
    break
  fi
  if [[ "$i" -eq 5 ]]; then
    warn "Webhook мог не встать с первого раза (DNS/HTTPS)."
    warn "Потом:  docker compose --profile ${DEPLOY_MODE} exec ${APP_SERVICE} php setup.php"
  else
    warn "Повтор setup через 10с ($i/5)..."
    sleep 10
  fi
done

"${COMPOSE[@]}" exec -T "$APP_SERVICE" touch /var/www/html/.setup_done 2>/dev/null || true

echo ""
echo -e "${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}${BOLD}  Готово${NC}"
echo -e "${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "  Сайт:     ${BASE_URL}"
echo "  Webhook:  ${BASE_URL}/api/webhook.php"
echo ""
echo "  1. Открой бота в Telegram → /start"
echo "  2. /newlink 1234   (суффикс телефона на 2FA)"
echo "  3. Открой ссылку из ответа бота"
echo ""
echo "  Логи:     ${COMPOSE[*]} --profile ${DEPLOY_MODE} logs -f"
echo "  Стоп:     ${COMPOSE[*]} --profile ${DEPLOY_MODE} down"
echo "  Setup:    ${COMPOSE[*]} --profile ${DEPLOY_MODE} exec ${APP_SERVICE} php setup.php"
echo ""
echo "  .env лежит в: ${ROOT}/.env  — не свети токены"
echo ""
