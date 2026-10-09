# Итоговый отчёт по ТЗ №5 — MVP CRM

**Дата:** 2026-10-10  
**Git commit:** `d8f7634` (HEAD)  
**Ветка:** `main`  
**Незакоммиченные изменения:** `Dockerfile`, `docker-compose.yml`, `docs/*.md`, `src/` (новый Laravel scaffold)

---

## 1. Реализованные функции

### Аутентификация и RBAC
- Вход/выход с регенерацией сессии, проверка `is_active`
- Роли: `admin`, `manager`
- Policies: `CustomerPolicy`, `InquiryPolicy`, `ActivityPolicy` (зарегистрированы в `AppServiceProvider`)
- `Gate::authorize()` во всех изменяющих контроллерах
- Команда `crm:create-admin` — безопасная генерация пароля (min 12, mixed case, numbers, symbols)
- Сброс пароля: request → send link → reset form

### Справочники
- Sites: `us812.ru`, `zoloto-spb.ru` (идемпотентный `SiteSeeder`)
- Sources: нормализованные коды (идемпотентный `SourceSeeder`)
- API credentials per site (HMAC секреты зашифрованы, `ApiCredentialSeeder`)

### Клиенты и идентичности
- `customers`: нормализованные `phone`/`email` + raw, `is_merged`, `first_touchpoint_id`, `last_touchpoint_id`
- `customer_identities`: внешние идентификаторы (provider + external_key)
- `FindOrCreateCustomerAction` — матчинг по phone И/ИЛИ email, конфликт phone→A / email→B не объединяет автоматически
- `MergeCustomersAction` — только `admin`, одна транзакция, перенос inquiries/touchpoints/activities/identities, `audit_logs` (`customer_merged`), пересчёт атрибуции
- Частичные уникальные индексы на активные (не объединённые) phone/email

### Обращения и статусы
- `inquiries`: nullable `site_id`, `source_kind` (call/email/other), UTM, `client_id`, `yclid`, `external_id`, `comment`, `landing_path`, `referrer`
- `InquiryStatus` backed enum: `new`, `qualified`, `converted`, `rejected`, `duplicate`, `archived`
- `InquiryStateMachine` — валидированные переходы, ошибка на недопустимых
- `inquiry_status_history` (append-only)
- Ручное создание: `CreateManualInquiryAction` + `create.blade.php`

### Маркетинговые события и тачпоинты
- `marketing_events` — сырые события сайта
- `touchpoints` — бизнес-история атрибуции (не перезаписывается)
- `IngestMarketingEventsAction` — HMAC, idempotency (event key), rate limit
- `AttributionProjectionService` — first/last touch projections, команда `crm:rebuild-attribution`

### Публичный Machine API
- `POST /api/v1/inquiries` — HMAC-SHA256 (method + path + timestamp + body hash), timestamp ±300с, constant-time verify, scope `inquiries`
- `POST /api/v1/events` — та же схема, scope `events`, дедуп по event key
- Rate limit: 60 req/min на credential (`throttle:api-credential`)
- Idempotency: обязательный `Idempotency-Key`, scope = `inquiry:{key_id}`, request hash (SHA256), 30 дней retention, конфликт hash → 422

### Атомарность, аудит, outbox
- Одна транзакция: Customer → Inquiry → Touchpoint → StatusHistory → AuditLog → BusinessEvent (`inquiry_received`) → Outbox → Idempotency
- `outbox` таблица + `crm:outbox:deliver --limit=50` (реальная доставка отключена без вебхука)
- Никакого внешнего HTTP внутри транзакции

### CRM UI (Blade)
- `/login`, `/dashboard` (счётчики обращений/клиентов)
- `/customers` — index (поиск, пагинация), show (контакты, обращения, merge-форма для админа, атрибуция)
- `/inquiries` — index, create (ручной звонок/email/other), show (история статусов, тачпоинты, активности), смена статуса
- XSS-safe вывод, серверная валидация, состояния ошибок/пустых списков

---

## 2. Изменённые/созданные файлы (основные группы)

### Инфраструктура
- `Dockerfile`, `docker-compose.yml`
- `src/.env.example`, `src/.env.testing`, `src/phpunit.xml`
- `docs/LOCAL_DEVELOPMENT.md`

### Миграции (21)
- `create_sites_table`, `create_sources_table`, `create_customers_table`, `create_customer_identities_table`
- `create_inquiries_table`, `create_inquiry_status_history_table`, `create_activities_table`
- `create_marketing_events_table`, `create_touchpoints_table`, `create_audit_logs_table`
- `create_business_events_table`, `create_outbox_table`, `create_idempotency_table`
- `create_api_credentials_table`, `add_role_and_is_active_to_users_table`
- `add_comment_to_inquiries_table`, `add_attribution_projections_to_customers_table`
- `replace_customers_unique_with_partial_indexes`, `remove_redundant_inquiries_index`
- `make_activities_subject_nullable`

### Модели (14)
`Site`, `Source`, `Customer`, `CustomerIdentity`, `Inquiry`, `InquiryStatusHistory`, `Activity`, `MarketingEvent`, `Touchpoint`, `AuditLog`, `BusinessEvent`, `Outbox`, `Idempotency`, `ApiCredential`, `User` (role, is_active, isAdmin/isManager)

### Enums / Domain
- `InquiryStatus` (enum + transitions/labels)
- `InquirySourceKind`
- `InquiryStateMachine`
- `ContactNormalizer`

### Actions (9)
- `FindOrCreateCustomerAction`, `MergeCustomersAction`, `AttachCustomerIdentityAction`
- `IngestInquiryAction`, `CreateManualInquiryAction`
- `RecordTouchpointAction`, `IngestMarketingEventsAction`, `RecordAuditLogAction`, `RecordInquiryReceivedEventAction`

### Консольные команды
- `CreateAdminCommand` (`crm:create-admin`)
- `RebuildAttributionCommand` (`crm:rebuild-attribution`)
- `DeliverOutboxCommand` (`crm:outbox:deliver`)

### HTTP Controllers
- Web: `AuthController`, `DashboardController`, `CustomerController`, `CustomerMergeController`, `InquiryController`, `PasswordResetController`
- API: `Api\V1\InquiryController`, `Api\V1\MarketingEventController`

### Policies (3)
- `CustomerPolicy`, `InquiryPolicy`, `ActivityPolicy`

### Integrations
- `AdvertisingAppClient` (HMAC-signed outbox delivery)
- `HmacVerifier` (canonical string, constant-time compare)

### Views (Blade)
- `auth/login.blade.php`, `auth/passwords/request.blade.php`, `auth/passwords/reset.blade.php`
- `customers/index.blade.php`, `customers/show.blade.php`
- `inquiries/index.blade.php`, `inquiries/create.blade.php`, `inquiries/show.blade.php`
- `dashboard.blade.php`, `layouts/app.blade.php`

### Тесты (56 тестов, 175 assertions)
- Unit: `ContactNormalizerTest`, `InquiryStateMachineTest`, `AttributionProjectionServiceTest`
- Feature: `AuthFlowTest`, `CreateAdminCommandTest`, `CustomerMergeTest`, `IngestInquiryApiTest`, `IngestMarketingEventsApiTest`, `InquiryIngestionEdgeCaseTest`

### Документация
- `docs/ARCHITECTURE.md`, `docs/ARCHITECTURE_FINAL.md`
- `docs/API_CONTRACT.md`, `docs/IMPLEMENTATION_BASELINE.md`, `docs/IMPLEMENTATION_DECISIONS.md`
- `docs/LOCAL_DEVELOPMENT.md`, `docs/CRM_PRODUCT_BRIEF.md`

---

## 3. Команды тестирования и результаты

```bash
# Запуск тестов (Docker, PostgreSQL)
docker compose exec app ./vendor/bin/phpunit

# Результат:
# Tests: 56 passed
# Assertions: 175
# Time: ~5.3s
# Memory: 56.5 MB
```

Все тесты проходят на PostgreSQL 16 (test DB `crm_test`). SQLite не используется.

---

## 4. Версии окружения

| Компонент | Версия |
|-----------|--------|
| PHP | 8.4.26 (Docker `php:8.4-apache`) |
| Laravel | 13.35.0 |
| PostgreSQL | 16 (Docker `postgres:16`) |
| Composer | 2.8 (в образе) |
| Docker | 29.8.2 |

---

## 5. Оставшиеся блокеры / отклонения

| Пункт | Статус | Рекомендация |
|-------|--------|--------------|
| Livewire 3 + Alpine + Tailwind + Vite | ❌ Не реализовано | UI на Blade функционально покрывает MVP. Livewire можно добавить в следующем срезе при необходимости. |
| Frontend build (`npm run build`) | ⚠️ Не проверено | Node/npm недоступны локально. Можно запустить в CI/CD или отдельно. |
| SMTP / реальная почта | ⚠️ Не настроено | Тесты используют `MAIL_MAILER=log` / fake transport. Production SMTP настроить при деплое. |
| Доставка в рекламное приложение | ⚠️ Отключено | Outbox пишется, команда `crm:outbox:deliver` существует. Включить после предоставления URL, схемы и credentials вебхука. |

---

## 6. Подтверждение изоляции Production

- ❌ Не подключались к SWeb / SSH / панели хостинга
- ❌ Не менялись `.env` или файлы на production
- ❌ Не выполнялись production migrations / deploy / upload
- ❌ Не использовались реальные API secrets / клиентские данные
- ✅ Вся работа — локально в Git-репозитории
- ✅ `.gitignore` исключает `server.md`, `.env`, `vendor/`, `node_modules/`, `public/build/`

---

## 7. Git статус

```bash
# Текущий commit
d8f7634 docs: финальная архитектура CRM и product brief

# Незакоммиченные (untracked) — новые файлы этапа:
?? Dockerfile
?? docker-compose.yml
?? docs/API_CONTRACT.md
?? docs/IMPLEMENTATION_BASELINE.md
?? docs/IMPLEMENTATION_DECISIONS.md
?? docs/IMPLEMENTATION_REPORT.md
?? docs/LOCAL_DEVELOPMENT.md
?? src/

# Изменённые:
 M .gitignore
```

Все секреты в `server.md` (gitignored), в коммитах отсутствуют.