# Деплой на Ubuntu (Docker) — полный тутор

Одна команда. Скрипт сам: поставит Docker → спросит токен/домен → поднимет сайт + MySQL + HTTPS → проставит Telegram webhook.

---

## Что понадобится заранее

1. **VPS Ubuntu** (20.04 / 22.04 / 24.04), root или sudo  
2. **Домен** — A-запись на IP сервера (для HTTPS)  
3. **Бот у @BotFather** → `/newbot` → скопировать токен  
4. **Свой Telegram chat_id** (для суперадмина):
   - Вариант A: написать [@userinfobot](https://t.me/userinfobot)  
   - Вариант B: после установки написать своему боту `/myid`

Открой порты **80** и **443** (для SSL) или нужный порт для HTTP-режима.

---

## Установка одной командой

Залей папку `klein-login` на сервер (scp/rsync/git), затем:

```bash
cd /path/to/klein-login
bash install.sh
```

Скрипт спросит:

| Вопрос | Пример |
|--------|--------|
| Режим | `ssl` (рекомендуется) или `http` |
| Домен | `login.example.com` |
| Email Let's Encrypt | `admin@example.com` |
| Bot token | `8906…:AAF…` |
| Super-admin chat_id | `5820902474` |

Пароли MySQL и `webhook_secret` генерируются сами и пишутся в `.env`.

### Что происходит внутри

1. `curl get.docker.com` — Docker + Compose (если ещё нет)  
2. Создаётся `.env`  
3. `docker compose --profile ssl up -d --build`  
   - MySQL 8  
   - PHP 8.3 + Apache  
   - Caddy (HTTPS + сертификат)  
4. `setup.php` — таблицы БД + `setWebhook`  
5. Готово

После установки:

```text
Сайт:     https://login.example.com
Webhook:  https://login.example.com/api/webhook.php
```

1. Открой бота → `/start`  
2. `/newlink 1234` — суффикс телефона на 2FA  
3. Открой ссылку из ответа бота

---

## Режимы

### ssl (продакшен)

- Порты 80/443  
- Авто-сертификат Let's Encrypt через Caddy  
- `BASE_URL=https://твой-домен`

### http (тест / без домена)

- Порт `8080` (или другой)  
- Без HTTPS — **Telegram webhook не примет** `http://` публичный URL  
  Для теста webhook нужен туннель (cloudflared / ngrok) или сразу `ssl`

---

## Полезные команды

```bash
# логи
docker compose --profile ssl logs -f

# переставить webhook (смена домена/токена)
bash webhook.sh
# или
docker compose --profile ssl exec app_ssl php setup.php

# стоп
docker compose --profile ssl down

# стоп + стереть БД
docker compose --profile ssl down -v

# пересобрать после правок кода
docker compose --profile ssl up -d --build
```

Конфиг лежит в **`.env`** (не в git, права 600):

```env
BASE_URL=https://login.example.com
TELEGRAM_TOKEN=...
TELEGRAM_WEBHOOK_SECRET=...
TELEGRAM_SUPER_ADMINS=123456789
DOMAIN=login.example.com
```

`config/config.php` читает эти переменные автоматически.

---

## Firewall (ufw)

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80,443/tcp
sudo ufw enable
```

---

## Если webhook не встаёт

1. Домен уже резолвится на сервер? `dig +short login.example.com`  
2. HTTPS открывается в браузере?  
3. Перезапусти setup:

```bash
bash webhook.sh
```

4. Проверь ответ Telegram:

```bash
docker compose --profile ssl exec app_ssl php -r '
require "lib/bootstrap.php";
print_r(tg_api("getWebhookInfo"));
'
```

---

## Текст заказчику (коротко)

```
1. Купи VPS Ubuntu, привяжи домен (A-запись на IP)
2. Создай бота у @BotFather, сохрани токен
3. Узнай свой chat_id (@userinfobot)
4. Залей папку klein-login на сервер
5. cd klein-login && bash install.sh
6. Ответь на вопросы (домен, токен, chat_id)
7. Напиши боту /start → /newlink 1234 → готово
```

---

## Структура Docker

```
docker-compose.yml
docker/
  php/Dockerfile      # PHP 8.3 + Apache + pdo_mysql
  entrypoint.sh       # ждёт MySQL → setup.php
  Caddyfile           # HTTPS
install.sh            # интерактивный деплой
webhook.sh            # переустановка webhook
.env.example
```
