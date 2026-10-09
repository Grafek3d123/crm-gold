# Операции (Queue, Logging, Backup, Deployment, Testing, MVP, V2, ADR, Risks)

## Queue

### Использовать для
- Отправка Yandex conversion
- Email-уведомления
- Тяжёлые интеграции (Yandex API, внешние HTTP)
- Retry failed conversions
- Рассылки

### НЕ использовать для
- API ответ пользователю (ожидание мгновенное)
- Создание лида (критично, синхронно)
- Изменение статуса (синхронно)

### Retry-стратегия
```php
// Job
public $tries = 5;
public $backoff = [60, 300, 900, 3600, 14400]; // 1м, 5м, 15м, 1ч, 4ч
```
После 5 попыток → `failed_jobs` запись, ручная обработка.

## Logging

```text
storage/logs/laravel.log      — приложение
storage/logs/api.log          — API запросы (без тела с секретами)
storage/logs/integration.log  — Yandex API, email
storage/logs/failed_jobs.log  — неудачные задачи
storage/logs/conversion.log   — ошибки конверсий
```

**Не логировать:** пароли, API secrets, DB passwords, полные токены (только первые 4 знака).

## Backup

### PostgreSQL
- Ежедневные дампы (`pg_dump`).
- Ретеншн: 7 дней.
- Хранить: локально + облако (S3-совместимый).
- Проверка восстановления: раз в неделю.

### Laravel storage
- `storage/app` — загруженные файлы.
- `storage/logs` — логи.
- Резервировать ежедневно, ретеншн 7 дней.

### Что где хранится

| Данные | Где |
|---|---|
| Код | Git (GitHub) |
| .env | Только на сервере |
| vendor/ | composer install при деплое |
| storage/app | файлы |
| БД | PostgreSQL (pg4.sweb.ru:5433) |

## Deployment

```
MacBook → git push → GitHub → Tests → Production SWeb (SSH)
    → git pull + composer install + npm run build + migrate + cache
```

### Что хранится в Git
- Код, composer.json/lock, package.json, vite.config.js
- resources/, routes/, app/, config/, database/
- public/ (index.php, .htaccess, robots.txt)

### Что НЕ хранится
- `.env` (в .gitignore)
- `vendor/`, `node_modules/`
- `storage/` (только .gitkeep)
- `bootstrap/cache/` (генерируется)
- `.env.production` — секреты

## Development workflow

```text
main          — стабильная
feature/<name>— новая фича
fix/<name>    — баг
hotfix/<name> — срочный фикс
```

Процесс: feature → PR → review → tests → merge → deploy.

## Testing (план)

### Unit tests
- Attribution logic
- Duplicate detection
- Money calculations
- Status transitions

### Feature tests
- API создание лида + idempotency
- Auth, permissions
- Orders, payments
- Conversion events

### Integration tests
- PostgreSQL (миграции, uniqueness)
- Yandex API (mock)
- Email (mock)

**Не писать сейчас — только проектировать.**

## MVP (v1)

```text
✓ Authentication (login, logout, password reset)
✓ Users + RBAC (admin, manager)
✓ Customers (с дедупликацией)
✓ Leads (API, UTM, attribution)
✓ Lead statuses + history
✓ Deals (статусы + история)
✓ Orders + order_items
✓ Payments (частичные, возвраты, external_id)
✓ Attribution (touchpoints, first/last)
✓ UTM
✓ Yandex ClientID + yclid
✓ API (leads, events, orders, payments, conversions)
✓ API auth (HMAC + API key)
✓ Idempotency
✓ Rate limiting + duplicate detection
✓ Dashboard
✓ Audit log
✓ Notes
✓ Sites / sources / campaigns
✓ Health check /health
```

## V2

```text
✗ Tasks, calendar
✗ Advanced analytics (multi-touch)
✗ Automations
✗ Telegram
✗ WhatsApp
✗ Phone integration
✗ Email integration
✗ Advanced reports
✗ 2FA
✗ Livewire (reactive UI)
✗ Horizon (queue monitoring)
```

## ADR (архитектурные решения)

| ID | Решение |
|---|---|
| ADR-001 | Laravel 13, Blade + Livewire + Alpine + Tailwind + Vite |
| ADR-002 | PostgreSQL, NUMERIC(12,2), soft deletes, UTC |
| ADR-003 | Laravel Sanctum для auth менеджеров |
| ADR-004 | HMAC + API key для внешних сайтов |
| ADR-005 | Touchpoints + customer first/last touch cache |
| ADR-006 | Phone → email → ClientID приоритеты дедупликации |
| ADR-007 | Yandex Direct API + AppMetrica, queue + retry |
| ADR-008 | Queue (database) для Yandex, email, интеграций |
| ADR-009 | Git + manual deploy (GitHub → SWeb) |
| ADR-010 | Events + Listeners: sync для audit/history, queued для Yandex/email |
| ADR-011 | Статусы в справочниках, не enum'ы |
| ADR-012 | Domain logic в Services, контроллеры тонкие |

## Risks

### Технические

| Риск | Вероятность | Влияние | Мера |
|---|---|---|---|
| Дубли лидов при сбоях сети | Высокая | Средняя | idempotency_key |
| Ошибки Yandex API | Средняя | Низкая | retry + логи |
| Проблемы с дедупликацией | Средняя | Высокая | покрыть тестами |
| Рост БД (touchpoints) | Средняя | Средняя | архивация >2 лет |
| Rate limit Yandex API | Низкая | Средняя | очередь + throttle |

### Архитектурные

| Риск | Вероятность | Влияние | Мера |
|---|---|---|---| 
| MODX и CRM могут конфликтовать | Низкая | Высокая | отдельный домен, отдельный PHP (уже сделано) |
| Сайты перестанут отправлять | Низкая | Высокая | документировать API, поддержка |
| Расширение до 3-го сайта | Высокая | Низкая | справочник `sites` |
| Сложность attribution | Средняя | Средняя | модуль выделен отдельно |
