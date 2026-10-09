# IMPLEMENTATION_DECISIONS — первый вертикальный срез

**Задача:** Task05_MVP_IMPLEMENTATION  
**Дата:** 2026-10-10  
**Режим:** локальная разработка; SWeb/production не затрагивались

---

## 1. Среда и версии

| Компонент | Версия | Примечание |
|---|---|---|
| PHP | 8.4.35 | Docker `php:8.4-apache` |
| Laravel | 13.35.0 | scaffold через `composer create-project` |
| PostgreSQL | 16 | Docker `postgres:16` |
| PHPUnit | 12.5.38 | используется `#[DataProvider]` |
| Apache | 2.4.68 | DocumentRoot = `public` |

### Расхождение: PHP 8.3 → 8.4

Architecture и SWeb заявлены на PHP 8.3, но `laravel/framework` 13.35.0 объявляет `>= 8.4.1` в `platform_check.php`. Для локального этапа взят PHP 8.4. **Расхождение вынесено в open question** — для production нужно либо понизить версию Laravel, либо проверить совместимость на SWeb.

---

## 2. Принятые архитектурные решения

### D-1. Customer matching

Реализовано согласно §3.1 Task05:

- сначала ищется совпадение по нормализованному телефону **и** email;
- совпало одно поле → используется найденный клиент, недостающий контакт дополняется;
- оба поля → один клиент → используется он;
- телефон у A, email у B → **не объединяем**, **не переносим** контакт, в `audit_logs` пишется `customer_matching_conflict`;
- ClientID / yclid / `client_key` **не** участвуют в matching.

Уникальные индексы: `customers_phone_unique` и `customers_email_unique` (составные с `is_merged`).
Примечание: составной unique не делает их «partial» только для активных — SQL-различие active/merged реализовано на уровне запроса (`where('is_merged', false)`). Вынесено в open question Q-1.

### D-2. Idempotency и атомарность

`InquiryController` + `IngestInquiryAction`:

- `scope = inquiry:{key_id}`, ключ — `Idempotency-Key`;
- `request_hash = sha256(scope|key|rawBody)`;
- совпадение scope+key и hash → отдаётся сохранённый ответ;
- совпадение scope+key и разный hash → `422`;
- бизнес-записи (customer, inquiry, status history, touchpoint, audit, business_event, outbox) и запись idempotency пишутся в одной транзакции.

### D-8. Тестовая среда

Критическая находка: `docker-compose.yml` пробрасывал `DB_DATABASE=crm` в окружение контейнера.
`Illuminate\Support\Env` строит **иммутабельный** репозиторий и читает адаптеры в порядке
`$_SERVER → $_ENV → putenv`, поэтому `$_SERVER` (окружение контейнера) перебивал настройки из
`phpunit.xml`, и `RefreshDatabase` очищал **основную** БД `crm` при каждом запуске тестов.

Исправление:
- из `docker-compose.yml` убран блок `environment` с `DB_*` — приложение читает `src/.env`;
- в `src/phpunit.xml` добавлен `force="true"` ко всем `<env>`;
- создан `src/.env.testing` с БД `crm_test` и собственным `APP_KEY`.

**Тесты запускать только через `./vendor/bin/phpunit`.** `php artisan test` загружает `.env` до
того, как PHPUnit применит свои переменные, и снова уйдёт в `crm`.

### D-9. Деньги

На этапе не реализованы (`payments`, `expenses`, `contracts`, `orders`).
Зафиксировано для следующего этапа:

- `NUMERIC(12,2)` единый стандарт (ADR-004), `NUMERIC(14,2)` в более поздних схемах — к приведению;
- `net_paid_revenue` = подтверждённые входящие платежи − подтверждённые возвраты;
- `actual_profit` ≠ `net_paid_revenue`.

### D-4. HMAC

Реализовано по §6.7 Task05:
`UPPERCASE_METHOD\nPATH\nTIMESTAMP\nsha256(rawBody)`, HMAC-SHA256, `hash_equals`, окно ±300 с.
Секрет сайта шифруется `Crypt::encryptString()`.

Замечание: `Crypt::encrypt()` сериализует значение и несовместим с `decryptString()` — используется только пара `encryptString()` / `decryptString()`.

### D-5. Sanctum / Livewire

Sanctum не используется как механизм UI-сессии. Для Blade-экранов — обычная Laravel session auth + CSRF. Sanctum не установлен; при необходимости — отдельным решением.

### D-6. Файлы

Модель `files` и загрузка документов на этот этап не реализованы.

---

## 3. Что реализовано

- 18 миграций + `add_role_and_is_active_to_users_table`;
- модели: Site, Source, Customer, CustomerIdentity, Inquiry, InquiryStatusHistory, Activity, MarketingEvent, Touchpoint, AuditLog, BusinessEvent, Outbox, Idempotency, ApiCredential;
- `App\Enums\InquiryStatus` + `App\Domain\Inquiries\InquiryStateMachine`;
- `App\Support\InquirySourceKind`, `App\Support\ContactNormalizer`, `App\Support\Hmac\PayloadSigner`, `App\Support\Hmac\HmacVerifier`;
- Actions: `FindOrCreateCustomerAction`, `IngestInquiryAction`, `CreateManualInquiryAction`, `RecordInquiryReceivedEventAction`, `IngestMarketingEventsAction`;
- `App\Attribution\AttributionProjectionService` + команда `crm:rebuild-attribution`;
- Policies: `CustomerPolicy`, `InquiryPolicy`;
- Контроллеры: `AuthController`, `DashboardController`, `CustomerController`, `InquiryController`, `Api\V1\InquiryController`, `Api\V1\MarketingEventController`;
- Команда `crm:create-admin` — создание первого администратора без фиксированного пароля в исходниках/сидере;
- Роуты web + api, rate limit `api-credential` 60/мин;
- Blade-экраны: login, dashboard, inquiries (index/show/create), customers (index/show);
- Seeders идемпотентные; **пользователи не сидируются** — только справочники.

## 3.1 Архитектурные несостыковки §3 Task05 — статус

| § | Проблема | Как закрыта |
|---|---|---|
| 3.1 | Противоречивый customer matching | Алгоритм проверяет оба контакта; конфликт → audit + activity, без мержа и перезаписи (D-1) |
| 3.2 | `inquiries.site_id` NOT NULL vs ручной звонок | `site_id` nullable; `source_kind` различает web/call/email/other; `site_id` из тела API не принимается |
| 3.3 | `NUMERIC(12,2)` vs `(14,2)` | Денежные таблицы на этом этапе не реализованы; стандарт `NUMERIC(12,2)` зафиксирован |
| 3.4 | Двойная связь Contract↔Order | Таблицы договоров на этапе не создавались; решение `orders.contract_id` зафиксировано |
| 3.5 | Две формулы прибыли | Финансы вне этапа; разделение `net_paid_revenue` / `actual_profit` зафиксировано |
| 3.6 | Неявные свойства activities / `client_key` | `activities` добавлены `direction`, `actor_id`, `happened_at`, `type`, `channel`; ClientID/yclid в matching не участвуют |
| 3.7 | Sanctum как механизм сессии | Sanctum не установлен; UI использует стандартную Laravel session + CSRF |
| 3.8 | Неописанные `files` | Модель файлов на этап не реализована |

---

## 4. Не реализовано (следующие этапы)

Leads, contracts, orders, payments, expenses, files, live delivery в рекламное приложение, Livewire-компоненты, слияние клиентов (кроме флага `is_merged`), email-уведомления, атрибуция анонимных визитов.

---

## 5. Open questions

| # | Вопрос | Рекомендуемый дефолт |
|---|---|---|
| Q-1 | Partial unique index для активных (не merged) карточек | перевести `customers_phone_unique` / `customers_email_unique` на `whereRaw('is_merged = false')` |
| Q-2 | Версия PHP для production | Laravel 13.35 требует 8.4.1+; на SWeb заявлен 8.3 — проверить до деплоя |
| Q-3 | Webhook рекламного приложения: URL и auth | без контракта доставка выключена; outbox пишется |
| Q-4 | Контракт `/api/v1/events` | отдельным этапом |
| Q-5 | SMTP для password reset | локальные тесты на fake mail |
| Q-6 | Слияние дублей клиентов | только вручную админом; автоматическое слияние запрещено |
