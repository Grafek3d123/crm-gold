# Локальная разработка — CRM Gold

Локальный запуск на Docker + PostgreSQL. Production/SWeb в этом этапе не используется.

---

## 1. Требования

- Docker Desktop (daemon запущен)
- Git

PHP, Composer, Node локально **не нужны** — всё внутри контейнеров.

---

## 2. Первый запуск

```bash
docker compose up -d --build
```

Поднимутся три контейнера:

| Сервис | Образ | Порт |
|---|---|---|
| `crm-app` | `php:8.4-apache` (собирается из `Dockerfile`) | `8000` → 80 |
| `crm-postgres` | `postgres:16` | только внутри сети |
| `crm-node` | `node:20-alpine` | — |

Приложение: **http://localhost:8000**

---

## 3. Миграции и справочники

```bash
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
```

Сидер создаёт `sites` (us812.ru, zoloto-spb.ru), `sources` и тестовые `api_credentials`.
**Пользователи не сидируются** — см. следующий шаг.

---

## 4. Создание администратора

Фиксированного пароля в сидере нет. Первый администратор создаётся командой:

```bash
# со своим паролем (мин. 12 символов, разный регистр, цифры и символы)
docker compose exec app php artisan crm:create-admin \
  --email=admin@example.com --name="Админ" --password='...'

# или со сгенерированным паролем — он показывается один раз
docker compose exec app php artisan crm:create-admin --email=admin@example.com --name="Админ"
```

Открой http://localhost:8000 и войди.

---

## 5. Тесты

**Важно — запускать только через `vendor/bin/phpunit`:**
```bash
docker compose exec app ./vendor/bin/phpunit
```

Через `php artisan test` — иначе.

```bash
# НЕ ТАК: artisan test загружает .env раньше, чем PHPUnit применит
# свои env-переменные, и тесты уйдут в основную БД crm,
# после чего RefreshDatabase её очистит.
docker compose exec app php artisan test   # ⛔ не используй
```

Причина: `Illuminate\Support\Env` строит иммутабельный репозиторий, читая адаптеры в порядке
`$_SERVER → $_ENV → putenv`, поэтому окружение контейнера перебивает `phpunit.xml`.

Фильтр по набору/тесту:
```bash
docker compose exec app ./vendor/bin/phpunit --testsuite=Feature
docker compose exec app ./vendor/bin/phpunit --filter=IngestInquiryApiTest
```

---

## 6. Файлы окружения

| Файл | Назначение | В Git |
|---|---|---|
| `src/.env` | разработка (БД `crm`) | нет |
| `src/.env.testing` | тесты (БД `crm_test`) | нет |
| `src/.env.example` | шаблон | да |

Ключ для тестов генерируется отдельно:
```bash
docker compose exec app php artisan key:generate --env=testing --force
```

---

## 7. Полезные команды

```bash
# полностью пересоздать БД разработки
docker compose exec app php artisan migrate:fresh --seed --force

# очистить кэши
docker compose exec app php artisan optimize:clear

# логи контейнера
docker compose logs -f app

# shell в контейнере
docker compose exec app sh

# остановить всё
docker compose down
```

---

## 8. Проверка API вручную

Подпись считается так:

```text
canonical = "POST\n" + path + "\n" + timestamp + "\n" + sha256(rawBody)
signature = hex(hmac_sha256(secret, canonical))
```

Тестовые `key_id` — `test-us812` / `test-zoloto` (пароли в `database/seeders/ApiCredentialSeeder.php`,
это плейсхолдеры для локальной разработки, не production-секреты).

Подробный контракт: [`docs/API_CONTRACT.md`](./API_CONTRACT.md).

---

## 9. Известные ограничения этапа

- **PHP 8.4 вместо 8.3.** `laravel/framework` 13.35.0 объявляет `>= 8.4.1`. На SWeb заявлен 8.3.31 — расхождение зафиксировано как open question Q-2.
- **Frontend не собирался.** `npm run build` не выполнялся, UI работает на системном CSS.
- **Livewire не установлен**, экраны на обычном Blade.
- Live delivery в рекламное приложение выключена: outbox пишется, но job не отправляет без подтверждённого webhook-контракта.
