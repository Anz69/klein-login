#!/usr/bin/env bash
# Создать тестовую ссылку: bash scripts/link.sh create 123123 [chat_id]
# Удалить ссылку:       bash scripts/link.sh delete 123123 [chat_id]
set -euo pipefail
cd "$(dirname "$0")/.."

ACTION="${1:?create|delete}"
SUFFIX="${2:?suffix required}"
CHAT_ID="${3:-${TELEGRAM_SUPER_ADMINS:-}}"
CHAT_ID="${CHAT_ID%%,*}"

if [[ -z "$CHAT_ID" ]]; then
  CHAT_ID=$(grep '^TELEGRAM_SUPER_ADMINS=' .env 2>/dev/null | cut -d= -f2 | cut -d, -f1 || true)
fi
[[ -n "$CHAT_ID" ]] || { echo "✖ chat_id не задан"; exit 1; }

if [[ "$ACTION" == "create" ]]; then
  docker compose --profile http exec -T app php -r "
    require 'lib/bootstrap.php';
    \$id = gen_link_id();
    db_run('INSERT INTO links (id, number_suffix, chat_id) VALUES (?,?,?)', [\$id, '$SUFFIX', $CHAT_ID]);
    echo link_public_url(['id' => \$id]) . PHP_EOL;
  "
elif [[ "$ACTION" == "delete" ]]; then
  docker compose --profile http exec -T app php -r "
    require 'lib/bootstrap.php';
    \$n = db_run('UPDATE links SET is_active = 0 WHERE number_suffix = ? AND chat_id = ?', ['$SUFFIX', $CHAT_ID]);
    echo (\$n ? '✔ Ссылка $SUFFIX деактивирована' : '✖ Не найдена') . PHP_EOL;
  "
else
  echo "usage: link.sh create|delete <suffix> [chat_id]"
  exit 1
fi
