# Архитектурный документ CRM-системы

> Статус: ПРОЕКТ (ожидает утверждения владельцем)
> Этап: ТЗ №2 — проектирование до написания кода
> Реализация ЗАПРЕЩЕНА до утверждения этого документа.
> После утверждения будет выдано отдельное ТЗ на реализацию MVP.

---

## Содержание

1. [Architecture Overview](#1-architecture-overview)
2. [Принципы](#2-принципы)
3. [Модули и структура кода](#3-модули-и-структура-кода)
4. [Доменная модель и ERD](#4-доменная-модель-и-erd)
5. [Структура БД (потаблично)](#5-структура-бд)
6. [Customers: идентификация и дедупликация](#6-customers-идентификация-и-дедупликация)
7. [Attribution: touchpoints, UTM, yclid, ClientID](#7-attribution)
8. [Воронка: Leads → Deals → Orders → Payments](#8-воронка)
9. [Статусы и история статусов](#9-статусы-и-история-статусов)
10. [Конверсии и Yandex](#10-конверсии-и-yandex)
11. [REST API](#11-rest-api)
12. [API authentication](#12-api-authentication)
13. [Idempotency](#13-idempotency)
14. [Защита от спама](#14-защита-от-спама)
15. [Авторизация CRM и RBAC](#15-авторизация-crm-и-rbac)
16. [Audit log, Notes, Activities, Tasks](#16-audit-log-notes-activities-tasks)
17. [UI и Dashboard](#17-ui-и-dashboard)
18. [Events и Queue](#18-events-и-queue)
19. [Нефункциональные решения](#19-нефункциональные-решения)
20. [Deployment и workflow](#20-deployment-и-workflow)
21. [Тестирование](#21-тестирование)
22. [MVP (v1) и V2](#22-mvp-v1-и-v2)
23. [Risks](#23-risks)
24. [ADR](#24-adr)
25. [Итоговые таблицы](#25-итоговые-таблицы)

---

## 1. Architecture Overview

### 1.1. Уточнённая схема

Предложенная в ТЗ схема в целом верна. Улучшения: (а) интеграция с Yandex разделена на два независимых канала (Metrica goals + Direct offline conversions), (б) outbound-интеграции идут **только через очередь и outbox-таблицу** `conversion_events`, (в) оба сайта используют **единый контракт API**, чтобы добавление третьего сайта было конфигурацией, а не разработкой.

```text
        ┌─────────────────┐          ┌─────────────────────┐
        │    us812.ru     │          │   zoloto-spb.ru     │
        │  (сайт)         │          │   (MODX 2.3.1)      │
        └────────┬────────┘          └──────────┬──────────┘
                 │  JS-пиксель: сбор UTM/yclid/  │
                 │  ClientID/landing/referrer     │
                 │  HTTPS JSON (+HMAC)            │
                 ▼                                ▼
        ┌────────────────────────────────────────────────┐
        │                Laravel CRM                     │
        │            crm.zoloto-spb.ru                   │
        │                                                │
        │  routes/api.php ──► Middleware:                │
        │    • SiteAuth (HMAC, rate limit, idempotency)  │
        │    • ValidateRequest                           │
        │         │                                      │
        │         ▼                                      │
        │  Actions / Services (бизнес-логика)            │
        │    • IngestLead  • IdentifyCustomer            │
        │    • RecordTouchpoint • Attribution            │
        │    • ConvertLead→Deal • Orders/Payments        │
        │         │                                      │
        │         ▼                                      │
        │  Domain Events ──► Outbox: conversion_events   │
        │         │                    │                 │
        │         ▼                    ▼                 │
        │  Livewire UI (менеджеры)   Queue (database)    │
        │                            │                   │
        └────────────────────────────┼───────────────────┘
                                     ▼
                     ┌───────────────────────────────┐
                     │ PostgreSQL        Yandex API  │──► Metrica Goals API
                     │  (crm data)       Email       │──► Direct Offline
                     │                             │     Conversions API
                     └─────────────────────────────┘──► SMTP (уведомления)
```

### 1.2. Ключевые архитектурные решения (сводка)

| # | Область | Решение |
|---|---------|---------|
| 1 | Приложение | Монолит Laravel 11+ (не микросервисы). Один деплой, одна БД. |
| 2 | Вход данных | REST API `/api/v1/*`, единый для всех сайтов. Сайты — записи в `sites`. |
| 3 | API-аутентификация | HMAC-подпись запроса + API key на сайт + timestamp/nonce (replay-protection). |
| 4 | Идемпотентность | Заголовок `Idempotency-Key` + таблица `api_idempotency_keys`. |
| 5 | Attribution | Гибрид: `touchpoints` (источник истины) + денормализованные first/last-колонки на `customers` + снимок attribution на `deals`. |
| 6 | Идентификация | Нормализованный телефон (E.164) → email → пара. Таблица `customer_identities`. |
| 7 | Деньги | `numeric(14,2)` + `currency char(3)`. Никаких float. |
| 8 | Время | Хранение `timestamptz` в UTC, отображение Europe/Moscow. |
| 9 | Конверсии | Outbox-паттерн: `conversion_events` + queued job → Yandex API, retry с backoff. |
| 10 | Очередь | Database driver (хостинг SWeb, без гарантий Redis/supervisor) + cron `queue:work --stop-when-empty`. |
| 11 | Авторизация UI | Laravel Fortify + spatie/laravel-permission (RBAC). |
| 12 | UI | Blade + Livewire 3 + Alpine + Tailwind + Vite. |
| 13 | Статусы | PHP enum + колонка с проверкой допустимых значений, без строковых литералов в коде. |
| 14 | Аудит | `audit_logs` (кто/что/когда/до/после) + `*_status_history`. |
| 15 | Удаление | Soft delete для customers/leads/deals; payments и orders НЕ удаляются. |

---

## 2. Принципы

CRM:

- **независима от MODX** — никаких обращений к БД/файлам MODX; только HTTP-контракт;
- **единая для обоих сайтов** — сайт определяется как запись в `sites`, не как код;
- **API-first** — весь inbound идёт через REST API; UI и API используют одни и те же Actions;
- **безопасна** — HMAC, rate limiting, RBAC, аудит, секреты только в `.env`;
- **расширяема** — третий сайт = INSERT в `sites` + выдача ключей; новый канал конверсий = новый Listener;
- **пригодна для 2–10 менеджеров** — конкурентный доступ, статус-история, назначения;
- **готова к рекламным интеграциям** — outbox конверсий, touchpoints, идентификаторы (yclid, ClientID).

Система сознательно спроектирована **не под две формы**, а под поток «визит → лид → сделка → заказ → оплата» с произвольным числом источников.

---

## 3. Модули и структура кода

### 3.1. Модули предметной области

| Модуль | Ответственность |
|--------|-----------------|
| Identity | Customers, identities, дедупликация, merge |
| Ingest | Приём лидов/событий с сайтов (API), валидация, идемпотентность |
| Attribution | Touchpoints, UTM, yclid, ClientID, first/last touch |
| Sales | Leads, Deals, статусы, конвертация |
| Commerce | Orders, order_items, Payments, revenue |
| Marketing | Sites, sources, conversion_events, Yandex pipeline |
| Notifications | Email-уведомления менеджерам (queued) |
| Access | Users, roles, permissions, policies |
| Audit | audit_logs, status histories |
| Collaboration | Notes, Activities, Tasks (Tasks — v2) |
| Platform | Queue, health, backup, logging |

### 3.2. Структура Laravel-проекта

Для проекта такого размера (монолит, ~10 модулей, 1–3 разработчика) рекомендуется **модульная вертикальная нарезка внутри стандартной структуры Laravel**, без папок «на 5 уровней» и без Repository pattern (Eloquent + query scopes покрывают потребности; Repository добавит слой без выгоды при одном источнике данных).

```text
app/
├── Domain/                       # чистая бизнес-логика, без HTTP
│   ├── Attribution/              #   TouchpointResolver, FirstLastTouch, UtmParser
│   ├── Identity/                  #   PhoneNormalizer, EmailNormalizer, CustomerMatcher
│   ├── Sales/                     #   LeadStatusMachine, DealStatusMachine (переходы)
│   └── Money/                     #   Money value object (сумма+валюта, арифметика)
├── Actions/                      # сценарии уровня приложения (по одному классу на действие)
│   ├── IngestLeadAction.php
│   ├── RecordTouchpointAction.php
│   ├── ConvertLeadToDealAction.php
│   ├── ChangeDealStatusAction.php
│   ├── RegisterPaymentAction.php
│   └── MergeCustomersAction.php
├── Models/                       # Eloquent-модели (только данные + scopes)
├── Http/
│   ├── Controllers/
│   │   ├── Api/V1/               # тонкие контроллеры: Request → Action → Resource
│   │   └── Web/                  # Livewire-контроллеры страниц
│   ├── Middleware/               # VerifySiteSignature, IdempotencyGuard, ThrottleByIp
│   ├── Requests/                 # Form Request-валидация API
│   └── Resources/                # JSON-ресурсы ответов
├── Livewire/                     # компоненты UI (тонкие, вызывают Actions)
├── Policies/                     # RBAC-политики на модели
├── Events/                       # LeadCreated, PaymentReceived, ...
├── Listeners/                    # SendYandexConversion, SendEmailNotification, LogAudit
├── Jobs/                         # SendConversionJob, SendEmailJob
├── Integrations/
│   └── Yandex/                   # MetricaGoalsClient, DirectOfflineConversionsClient,
│                                 # DTO, аутентификация, маппинг ошибок
├── Support/                      # общие утилиты
├── Enums/                        # LeadStatus, DealStatus, PaymentStatus, ConversionType...
└── Observers/                    # аудит-наблюдатели (пишут audit_logs)
```

### 3.3. Где что живёт (прямые ответы на п.33–34)

- **Бизнес-логика** — `app/Domain` (правила) + `app/Actions` (сценарии). Контроллеры, Livewire и Blade бизнес-логику не содержат.
- **API** — `Http/Controllers/Api` + Requests + Resources. Контроллер делает: валидация → Action → Resource. Никаких запросов к БД и ветвлений бизнес-правил в контроллере.
- **Интеграции** — `app/Integrations/<Вендор>`. Только там знают форматы внешних API.
- **Yandex** — `app/Integrations/Yandex` (клиенты) + `Jobs/SendConversionJob` (доставка) + outbox-модель `ConversionEvent`.
- **Attribution** — `app/Domain/Attribution` + `Actions/RecordTouchpointAction`.
- **Работа с лидами** — `Actions/IngestLeadAction` (приём), `Actions/ConvertLeadToDealAction`, `Domain/Sales/LeadStatusMachine`.

Слои:

```text
Controller / Livewire
   ↓ (только ввод/вывод)
Action (транзакция, сценарий, события)
   ↓
Domain (правила: матчинг клиента, переходы статусов, attribution)
   ↓
Model / DB (Eloquent)
```

Repository pattern — **не нужен** (один БД, ORM покрывает). Service-классы допустимы только как группировка Actions одного модуля, без «Service-слоя ради слоя».

---

## 4. Доменная модель и ERD

### 4.1. Полная ERD (текстовая)

```text
sites ──────────────┐
                    │ (site_id)
                    ▼
              ┌─── leads ────(customer_id)──► customers ◄────(customer_id)─── touchpoints
              │        │          (deal_id)      │  ▲                            ▲
              │        │  (source_id)            │  │ (customer_identity)        │
              │        ▼                         │  │                            │
              │     sources                  identities                     (customer_id)
              │                              (site_id)
              │                                │
              │                                ▼
              └────────────► deals ◄───────────┘
                             │  │
                 (deal_id)   │  │  (deal_id: snapshot attribution)
                             ▼  │
                           orders
                             │  │
                  (order_id) │  │  (order_item)
                             ▼  ▼
                          payments   order_items ──(product_id)──► products
                             │
                             │ (payment_id)
                             ▼
                      conversion_events ◄──── (lead_id / deal_id / order_id / customer_id)
                             ▲
                             │ outbox → queue → Yandex

users ──(assigned)──► leads / deals / orders        users ──(author)──► notes / audit_logs
users ──(role)──────► roles ──► permissions                       (spatie)

lead_status_history ◄── lead_id        deal_status_history ◄── deal_id
audit_logs ◄── (auditable_type/id)     notes ◄── (noteable_type/id)
activities ◄── (subject_type/id)       attachments ◄── (attachable_type/id)   [v2]
tags / customer_tags / lead_tags                                                 [v2]
webhook_events (входящие сырые события, опционально v2)
```

### 4.2. Обоснование состава сущностей

| Сущность | Нужна? | Зачем |
|----------|--------|-------|
| `users` | Да | Менеджеры/админы CRM, назначения, аудит. |
| `sites` | Да | Справочник источников трафика; убирает захардкоженные домены из логики; масштабируется на N сайтов. |
| `sources` | Да | Нормализованные каналы (yandex-direct, google-organic, ...). UTM-поле — сырьё; `sources` — справочник для отчётов. |
| `campaigns` | Нет (v1) | В v1 кампания хранится строкой на touchpoint/lead. Отдельная таблица оправдана при росте числа кампаний — **v2**. |
| `products` | Да (v1, базово) | Позиции заказа; в перспективе — себестоимость, привязка к услугам. В v1 справочник минимален. |
| `order_items` | Да | Заказ с несколькими позициями; нужен для детальной аналитики и будущих прайсов. |
| `notes` | Да (v1) | Текстовые заметки к клиенту/лиду/сделке — базовая потребность менеджеров. |
| `activities` | Да (v1) | Лента событий (смена статуса, звонок, письмо) — лёгкая реализация через polymorphic. |
| `tasks` | **v2** | «Позвонить завтра» — требует календаря/напоминаний, не входит в MVP. |
| `attachments` | **v2** | Файлы к сделкам; на v1 нет бизнес-процесса загрузки. |
| `tags`, `customer_tags` | **v2** | Сегментация; в v1 хватает статусов/источников. |
| `lead_statuses`, `deal_statuses`, `payment_statuses` | Нет как таблицы | Статусы — **PHP enum** (единый источник истины в коде) + колонка в БД с проверкой допустимых значений. Табличный справочник оправдан только если статусы настраивает пользователь — это не требуется. «Не зашивать в десятках файлов»: enum решает это. |
| `audit_logs` | Да | «Кто/что/когда» — обязательное требование. |
| `webhook_events` | Опционально v2 | Сырые входящие payload'ы для отладки/повтора. В v1 достаточно `marketing_events` и логов. |
| `conversion_events` | Да | Outbox исходящих конверсий в Yandex + статусы отправки. |
| `marketing_events` | Да | Сырые события с сайтов (визит, клик формы) ДО превращения в лид — питают attribution, позволяют реконструировать touchpoints. |
| `api_idempotency_keys` | Да | Идемпотентность POST-эндпоинтов. |
| `customer_identities` | Да | Гибкая идентификация (phone/email/yclid/client_id) и merge дублей. |
| `lead_status_history`, `deal_status_history` | Да | История переходов с автором и комментарием. |

---

## 5. Структура БД

Общие правила для всех таблиц:

- PK `bigserial id` (кроме pivot — составной PK);
- `created_at`/`updated_at` — `timestamptz`, значения пишет Laravel (Carbon) в UTC;
- все FK — `on delete` указан явно (см. таблицы);
- индексы на все FK и на поля фильтра/поиска;
- `citext` (расширение PostgreSQL) для email — регистронезависимая уникальность;
- телефон хранится нормализованным в E.164 (`+7...`), отдельная колонка `phone_e164` для уникальности/поиска.

> Примечание для этапа реализации: расширение `citext` требует `CREATE EXTENSION` в первой миграции — здесь фиксируется как проектное решение.

### 5.1. sites

Справочник сайтов-источников. Убирает домены из бизнес-логики (п.14 ТЗ).

| Колонка | Тип | Null | Default | Комментарий |
|---------|-----|------|---------|-------------|
| id | bigserial | no | | PK |
| code | varchar(50) | no | | `us812`, `zoloto` — машинный код для API/логики |
| name | varchar(150) | no | | Отображаемое имя |
| domain | varchar(255) | no | unique | Основной домен |
| active | boolean | no | true | Выключение = приём с сайта запрещён |
| api_key_id | varchar(64) | no | unique | Идентификатор ключа (публичная часть HMAC) |
| api_secret_encrypted | text | no | | Секрет в шифровании Laravel Crypt |
| created_at/updated_at | timestamptz | no | | |

Индексы: unique(code), unique(domain), index(active).
Связи: → leads, marketing_events, touchpoints.

### 5.2. sources

Нормализованные маркетинговые каналы.

| Колонка | Тип | Null | Default |
|---------|-----|------|---------|
| id | bigserial | no | |
| code | varchar(80) | no | unique — `yandex-direct`, `google-organic`, `site-us812`, `referral` |
| name | varchar(150) | no | |
| medium_pattern | varchar(255) | yes | шаблон соответствия utm_medium (для авто-маппинга) |
| is_paid | boolean | no | false |

Индексы: unique(code). Связи: → touchpoints, leads, deals (first/last source).
Пояснение: UTM-поля на событиях — сырые; `source_id` — результат нормализации для отчётов. Правила маппинга utm→source живут в `Domain/Attribution/SourceResolver`.

### 5.3. customers

Клиент — единая карточка человека. Не создаётся автоматически при каждом лиде (см. п.6).

| Колонка | Тип | Null | Default | Комментарий |
|---------|-----|------|---------|-------------|
| id | bigserial | no | | |
| name | varchar(200) | yes | | nullable: лид может оставить только телефон |
| phone | varchar(20) | yes | | нормализованный E.164 |
| phone_e164 | varchar(20) | yes | | канонический ключ поиска (частичный уникальный индекс) |
| email | citext | yes | | unique где not null |
| address | text | yes | | |
| note | text | yes | | свободный комментарий |
| first_source_id | bigint FK→sources | yes | | денормализованный first touch |
| first_medium | varchar(150) | yes | | |
| first_campaign | varchar(255) | yes | | |
| first_content | varchar(255) | yes | | |
| first_term | varchar(255) | yes | | |
| first_yclid | varchar(100) | yes | | |
| first_client_id | varchar(100) | yes | | Yandex Metrica clientID |
| first_landing_page | text | yes | | |
| first_referrer | text | yes | | |
| first_touch_at | timestamptz | yes | | |
| last_source_id | bigint FK→sources | yes | | денормализованный last touch |
| last_medium | varchar(150) | yes | | |
| last_campaign | varchar(255) | yes | | |
| last_content | varchar(255) | yes | | |
| last_term | varchar(255) | yes | | |
| last_yclid | varchar(100) | yes | | |
| last_client_id | varchar(100) | yes | | |
| last_landing_page | text | yes | | |
| last_referrer | text | yes | | |
| last_touch_at | timestamptz | yes | | |
| merged_into_id | bigint FK→customers | yes | null | если дубль объединён — ссылка на основного |
| deleted_at | timestamptz | yes | | soft delete |
| created_at/updated_at | timestamptz | no | | |

Индексы: partial unique (phone_e164) where phone_e164 not null and deleted_at is null; partial unique (email) where email not null and deleted_at is null; index(last_touch_at); index(created_at); index(merged_into_id); GIN trigram по `name` (pg_trgm) — для поиска.
Soft delete: да.
Связи: 1→N leads, deals, orders, touchpoints, notes, identities.

Пояснение first/last колонок: это **кэш** поверх `touchpoints` (см. п.7 — почему гибрид, а не «или/или»).

### 5.4. customer_identities

Гибкие идентификаторы клиента (расширяемый матчинг без изменения схемы customers).

| Колонка | Тип | Null | Default |
|---------|-----|------|---------|
| customer_id | FK→customers | no | on delete cascade |
| type | varchar(30) | no | `phone`,`email`,`yclid`,`yandex_client_id` |
| value | varchar(255) | no | нормализованное значение |
| created_at/updated_at | timestamptz | no | |

PK: составной (customer_id, type, value). Индексы: index(type, value) — ключевой для матчинга; FK-индекс на customer_id.
Связи: N→1 customers.

### 5.5. marketing_events

Сырые события с сайтов (визит, просмотр формы, клик) — «топливо» attribution.

| Колонка | Тип | Null | Default | Комментарий |
|---------|-----|------|---------|-------------|
| id | bigserial | no | | |
| site_id | FK→sites | no | on delete restrict | |
| event_type | varchar(50) | no | `visit`,`form_view`,`form_submit` | |
| client_key | varchar(100) | yes | | cookie-идентификатор браузера (UUID из JS-пикселя) |
| yandex_client_id | varchar(100) | yes | | |
| yclid | varchar(100) | yes | | |
| utm_source | varchar(255) | yes | | |
| utm_medium | varchar(255) | yes | | |
| utm_campaign | varchar(255) | yes | | |
| utm_content | varchar(255) | yes | | |
| utm_term | varchar(255) | yes | | |
| referrer | text | yes | | |
| landing_page | text | yes | | |
| ip_hash | varchar(64) | yes | | SHA-256(ip + суточные соль), не чистый IP — privacy |
| user_agent | varchar(500) | yes | | |
| occurred_at | timestamptz | no | | время на сайте |
| received_at | timestamptz | no | now() | время приёма CRM |
| payload | jsonb | yes | | полный JSON (отладка/реконструкция) |

Индексы: index(site_id, occurred_at); index(client_key); index(yandex_client_id); partial index(yclid) where yclid not null.
Очистка: ретеншен сырых событий — 180 дней (cron-джоб, этап реализации).
Связи: N→1 sites; при конвертации в лид/касание → порождает touchpoint.

### 5.6. leads

Лид = входящая заявка (сырая, до квалификации).

| Колонка | Тип | Null | Default | Комментарий |
|---------|-----|------|---------|-------------|
| id | bigserial | no | | |
| site_id | FK→sites | no | on delete restrict | откуда заявка (п.14) |
| customer_id | FK→customers | yes | on delete set null | может быть ещё не идентифицирован |
| deal_id | FK→deals | yes | on delete set null | результат конвертации (1 lead → 0..1 deal) |
| source_id | FK→sources | yes | | нормализованный канал заявки |
| request | varchar(255) | yes | | услуга/запрос («изготовление кольца») |
| message | text | yes | | текст обращения |
| contact_phone | varchar(20) | yes | | как оставил клиент (сырое) |
| contact_email | citext | yes | | |
| status | varchar(30) | no | 'new' | enum LeadStatus |
| assigned_to | FK→users null | yes | on delete set null | менеджер |
| utm_source/medium/campaign/content/term | varchar(255) each | yes | | снимок UTM заявки |
| yclid | varchar(100) | yes | | снимок |
| yandex_client_id | varchar(100) | yes | | снимок |
| referrer | text | yes | | |
| landing_page | text | yes | | |
| deleted_at | timestamptz | yes | | soft delete |
| created_at/updated_at | timestamptz | no | | |

Индексы: index(status); index(site_id, created_at); index(customer_id); index(assigned_to, status); index(created_at).
Soft delete: да.
Связи: N→1 sites; N→1 customers (повторные обращения); 1→0..1 deals; 1→N lead_status_history.

Почему снимок UTM на лиде отдельный от customer: заявка должна сохранять **свой** источник, даже если у клиента later обновится last touch.

### 5.7. deals

Сделка = квалифицированная возможность продажи. Создаётся из лида менеджером (или правилом).

| Колонка | Тип | Null | Default | Комментарий |
|---------|-----|------|---------|-------------|
| id | bigserial | no | | |
| customer_id | FK→customers | no | on delete restrict | |
| lead_id | FK→leads | yes | on delete set null | источник сделки |
| site_id | FK→sites | no | on delete restrict | |
| title | varchar(255) | no | | название сделки |
| status | varchar(30) | no | 'new' | enum DealStatus |
| assigned_to | FK→users null | yes | on delete set null | |
| amount_expected | numeric(14,2) | yes | | плановая сумма |
| currency | char(3) | no | 'RUB' | |
| closed_at | timestamptz | yes | | когда переведена в won/lost |
| closed_reason | text | yes | | |
| attribution_snapshot | jsonb | yes | | снимок first/last touch на момент сделки |
| deleted_at | timestamptz | yes | | |
| created_at/updated_at | timestamptz | no | | |

Индексы: index(status); index(customer_id); index(assigned_to, status); partial index(closed_at) where closed_at not null; index(created_at).
Soft delete: да.
Связи: N→1 customers/leads/sites; 1→N orders; 1→N deal_status_history.

`attribution_snapshot` — фиксация на момент сделки: отчёты «какая кампания продала» не должны меняться задним числом при новых касаниях клиента.

### 5.8. orders

Заказ — коммерческий документ внутри сделки.

| Колонка | Тип | Null | Default | Комментарий |
|---------|-----|------|---------|-------------|
| id | bigserial | no | | |
| number | varchar(50) | no | unique | человекочитаемый номер (US812-2026-000123) |
| deal_id | FK→deals | no | on delete restrict | |
| customer_id | FK→customers | no | on delete restrict | |
| site_id | FK→sites | no | on delete restrict | |
| status | varchar(30) | no | 'draft' | enum OrderStatus: draft/confirmed/completed/cancelled |
| amount | numeric(14,2) | no | 0 | сумма = Σ позиций (пересчитывается при изменении позиций) |
| discount_amount | numeric(14,2) | no | 0 | |
| currency | char(3) | no | 'RUB' | |
| comment | text | yes | | |
| created_by | FK→users | no | | |
| created_at/updated_at | timestamptz | no | | |

Индексы: unique(number); index(deal_id); index(customer_id); index(status); index(created_at).
Soft delete: **нет** — отмена только статусом `cancelled` (финансовый документ).
Связи: N→1 deals (один deal → 0..N orders: допродажи/рассрочки), customers, sites; 1→N order_items; 1→N payments.

### 5.9. order_items

| Колонка | Тип | Null | Default |
|---------|-----|------|---------|
| id | bigserial | no | |
| order_id | FK→orders | no | on delete cascade |
| product_id | FK→products null | yes | on delete set null |
| name | varchar(255) | no | наименование позиции (денормализация — защищает историю при правке справочника) |
| quantity | numeric(10,3) | no | 1 |
| unit_price | numeric(14,2) | no | |
| discount_amount | numeric(14,2) | no | 0 |
| line_amount | numeric(14,2) | no | = qty*price − discount (расчёт в Action) |

Индексы: index(order_id); index(product_id).
Связи: N→1 orders; N→1 products.

### 5.10. products

Справочник товаров/услуг (в v1 — минимальный).

| Колонка | Тип | Null | Default |
|---------|-----|------|---------|
| id | bigserial | no | |
| code | varchar(50) | yes | unique |
| name | varchar(255) | no | |
| kind | varchar(20) | no | 'product'/'service' |
| default_price | numeric(14,2) | yes | |
| active | boolean | no | true |

### 5.11. payments

Факт оплаты. **Никогда не удаляется** (ни soft, ни hard).

| Колонка | Тип | Null | Default | Комментарий |
|---------|-----|------|---------|-------------|
| id | bigserial | no | | |
| order_id | FK→orders | no | on delete restrict | |
| type | varchar(20) | no | 'payment' | payment/refund |
| status | varchar(30) | no | 'paid' | enum: pending/paid/failed/refunded/cancelled |
| amount | numeric(14,2) | no | | положительная сумма; для refund — сумма возврата |
| currency | char(3) | no | 'RUB' | |
| method | varchar(30) | yes | | cash/card/transfer |
| paid_at | timestamptz | yes | | дата фактической оплаты |
| external_id | varchar(100) | yes | unique | ID внешнего эквайринга (если появится) |
| comment | text | yes | | |
| created_by | FK→users | no | | |
| created_at/updated_at | timestamptz | no | | |

Индексы: index(order_id); index(paid_at); index(status); partial unique(external_id) where external_id not null.
Частичные оплаты: несколько строк payment на заказ; оплаченная сумма заказа — производная (Σ paid), не хранится отдельным полем (в v1 на лету; при росте — материализация).
Связи: N→1 orders; → conversion_events.

### 5.12. touchpoints

История касаний клиента (п.10 ТЗ). Источник истины attribution.

| Колонка | Тип | Null | Default | Комментарий |
|---------|-----|------|---------|-------------|
| id | bigserial | no | | |
| customer_id | FK→customers | no | on delete cascade | |
| site_id | FK→sites | yes | on delete set null | |
| source_id | FK→sources | yes | | нормализованный канал |
| lead_id | FK→leads null | yes | on delete set null | если касание — заявка |
| marketing_event_id | bigint null | yes | | исходное событие (для дедупа) |
| kind | varchar(30) | no | `visit`,`lead`,`call`,`email` | |
| utm_source/medium/campaign/content/term | varchar(255) | yes | | снимок |
| yclid | varchar(100) | yes | | |
| yandex_client_id | varchar(100) | yes | | |
| landing_page | text | yes | | |
| referrer | text | yes | | |
| occurred_at | timestamptz | no | | когда произошло |
| created_at/updated_at | timestamptz | no | | |

Индексы: index(customer_id, occurred_at); index(source_id); index(lead_id); partial unique(marketing_event_id) where marketing_event_id not null.
**Защита от мусора** (п.10): touchpoint создаётся только когда (а) есть идентификатор клиента (phone/email/yclid/client_id из события), И (б) событие прошло SourceResolver, И (в) нет дубля: то же marketing_event_id / та же lead_id уже не порождают запись. Визиты без единого идентификатора, связывающего с человеком, остаются только в `marketing_events` и **не** становятся touchpoints.
Связи: N→1 customers/leads/sites/sources.

### 5.13. conversion_events (outbox)

Исходящие конверсии в Yandex (п.22 ТЗ).

| Колонка | Тип | Null | Default | Комментарий |
|---------|-----|------|---------|-------------|
| id | bigserial | no | | |
| type | varchar(50) | no | `lead_created`,`lead_qualified`,`deal_created`,`order_created`,`order_paid` | enum ConversionType |
| channel | varchar(30) | no | `metrica_goal` / `direct_offline` | куда отправляем |
| customer_id | FK→customers null | yes | on delete set null | |
| lead_id | FK→leads null | yes | on delete set null | |
| deal_id | FK→deals null | yes | on delete set null | |
| order_id | FK→orders null | yes | | |
| payment_id | FK→payments null | yes | | |
| value | numeric(14,2) | yes | | реальная сумма (для order_paid) |
| currency | char(3) | yes | 'RUB' | |
| external_event_id | varchar(100) | no | unique | идемпотентный ключ доставки (uuid, генерирует CRM) |
| payload | jsonb | no | | тело запроса (что отправили) |
| status | varchar(30) | no | 'pending' | pending/sent/failed/skipped |
| attempts | int | no | 0 | |
| last_error | text | yes | | |
| sent_at | timestamptz | yes | | |
| created_at/updated_at | timestamptz | no | | |

Индексы: unique(external_event_id); index(status, created_at); index(type); index(payment_id).
Связи: N→1 customers/leads/deals/orders/payments.

### 5.14. lead_status_history / deal_status_history

| Колонка | Тип | Null | Default |
|---------|-----|------|---------|
| id | bigserial | no | |
| lead_id (deal_id) | FK | no | on delete cascade |
| from_status | varchar(30) | yes | NULL для первой записи |
| to_status | varchar(30) | no | |
| changed_by | FK→users null | yes | on delete set null |
| comment | text | yes | |
| created_at | timestamptz | no | |

Индексы: index(lead_id, created_at); index(deal_id, created_at); index(changed_by).

### 5.15. audit_logs

| Колонка | Тип | Null | Default | Комментарий |
|---------|-----|------|---------|-------------|
| id | bigserial | no | | |
| auditable_type | varchar(100) | no | | Customer, Lead, Deal, Order, Payment, User... |
| auditable_id | bigint | no | | |
| event | varchar(30) | no | created/updated/deleted/restored | |
| old_values | jsonb | yes | | только изменённые поля |
| new_values | jsonb | yes | | |
| user_id | FK→users null | yes | on delete set null | автор (NULL — системное действие) |
| ip_address | varchar(45) | yes | | для действий UI |
| user_agent | varchar(500) | yes | | |
| created_at | timestamptz | no | | |

Индексы: index(auditable_type, auditable_id); index(user_id, created_at); index(created_at).
Секреты (пароли, токены, api_secret) в old/new не пишутся (Observer — белый список полей).

### 5.16. users / roles / permissions (spatie)

`users`: id, name, email citext unique, password (hash), timezone varchar default 'Europe/Moscow', is_active boolean, last_login_at, remember_token, timestamps.
Таблицы `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permission` — по стандартной схеме spatie/laravel-permission (роль = набор permissions, user получает через роль).

### 5.17. notes / activities

`notes`: id, noteable_type, noteable_id (Lead/Deal/Customer/Order), user_id FK, body text, pinned boolean default false, timestamps. Индекс (noteable_type, noteable_id).

`activities`: id, subject_type, subject_id (лид/сделка/заказ), user_id FK null, type varchar (status_changed, note_added, call, email_sent, conversion_sent), description varchar, properties jsonb, created_at. Индексы: (subject_type, subject_id, created_at), (user_id, created_at).

### 5.18. api_idempotency_keys

| Колонка | Тип | Null | Default |
|---------|-----|------|---------|
| site_id | FK→sites | no | |
| key | varchar(100) | no | |
| endpoint | varchar(100) | no | |
| request_hash | varchar(64) | no | SHA-256 тела запроса |
| response_status | int | no | |
| response_body | jsonb | yes | |
| expires_at | timestamptz | no | |

PK: составной (site_id, key). Индекс: index(expires_at). Очистка: cron каждые сутки (удаляет просроченные).
См. п.13.

### 5.19. Сводная таблица связей

| Сущность | Родитель(и) | Cardinality |
|----------|-------------|-------------|
| leads | sites (1), customers (0..1), deals (0..1), users (0..1) | N:1 |
| customers | sources first/last (0..1) | 1:N к leads/deals/orders/touchpoints |
| customer_identities | customers | N:1 |
| marketing_events | sites | N:1 |
| touchpoints | customers, sites, sources, leads | N:1 |
| deals | customers, leads, sites, users | N:1 |
| orders | deals, customers, sites | N:1 |
| order_items | orders, products | N:1 |
| payments | orders, users | N:1 |
| conversion_events | customers/leads/deals/orders/payments | N:1 |
| *_status_history | leads/deals, users | N:1 |
| audit_logs | users + polymorphic | N:1 |
| notes/activities | users + polymorphic | N:1 |

---

## 6. Customers: идентификация и дедупликация

### 6.1. Нормализация

- **Телефон**: приводится к E.164 (`+79991234567`) библиотекой `giggsey/libphonenumber-for-php` (кандидат на этап реализации; альтернатива — собственный нормализатор под RU/KZ номера: удаление не-цифр, обработка `8`→`+7`, отбрасывание добавочных). Хранится в `phone`, канонический ключ — `phone_e164`.
- **Email**: trim + lowercase (citext обеспечивает регистронезависимость на уровне БД). Валидация формата; фильтры доменов-одноразовок — не на первом этапе.

### 6.2. Стратегия идентификации (приоритеты)

При приёме лида `CustomerMatcher` выполняет поиск в порядке:

1. **phone_e164** совпадает → это существующий клиент;
2. **email** совпадает → существующий клиент;
3. **phone + email** оба совпадают, но с разными клиентами → конфликт: создаётся задача на merge (см. 6.4), лид временно прикрепляется к клиенту по телефону (телефон — более сильный сигнал для услуг с обзвоном);
4. ни один не найден → создаётся новый customer.

Дополнительные слабые сигналы (не создают совпадение автоматически, но используются как подсказка merge): совпадение `yandex_client_id`, совпадение пары (name + регион).

### 6.3. Повторные обращения

- Один человек при повторной заявке **не** создаёт нового клиента: матч по телефону/email → существующая карточка, к ней цепляется новый lead.
- У клиента может быть N лидов, N сделок, N заказов, N touchpoints — всё через FK `customer_id`.
- История касаний и first/last touch живут на клиенте, отдельные заявки хранят собственный UTM-снимок.

### 6.4. Merge дублей

`Actions/MergeCustomersAction` (только permission `customers.merge`):

- вход: primary + duplicate;
- переносит: leads, deals, orders (и payments через orders), touchpoints, notes, identities, first/last (если у primary пусто);
- помечает duplicate: `merged_into_id = primary.id`, soft delete;
- пишет `audit_logs` + `activities`;
- полная транзакция, откат при любой ошибке;
- merge необратим из UI (может потребоваться ручная правка — фиксируется в audit).

### 6.5. Поиск

- Точный: по `phone_e164`, `email`.
- Нечёткий: trigram `ILIKE` по name/phone (GIN pg_trgm).
- Глобальный поиск в UI — Livewire-компонент, вызывает scopes `searchTerm`, `byPhone`, `byEmail`.

---

## 7. Attribution

### 7.1. Ключевой вопрос: поля в customers vs touchpoints (п.9)

| Подход | Плюсы | Минусы |
|--------|-------|--------|
| Только first/last поля в customers | Быстрые отчёты, просто | Нет истории; «повторный визит с другой кампанией» затирает след; нельзя ответить «какие каналы привели к продаже»; нет реконструкции пути |
| Только touchpoints | Полная история, любая модель атрибуции | Каждый отчёт = агрегат по touchpoints; дороже; first/last вычисляются каждый раз |
| **Гибрид (рекомендуется)** | История + скорость; first/last — денормализованный кэш, обновляемый при записи touchpoint | Нужно поддерживать согласованность кэша (решается тем, что и то, и другое пишет один Action в одной транзакции) |

**Решение (ADR-005):** `touchpoints` — источник истины; first/last-колонки на `customers` — кэш; `deals.attribution_snapshot` — снимок на момент сделки.

### 7.2. Что такое touchpoint и когда создаётся

Touchpoint — значимое взаимодействие клиента, привязанное к человеку.

Создаёт: CRM (серверная логика `RecordTouchpointAction`), на основе входящих `marketing_events` и `leads`.

Правила создания:

- событие `visit` с `client_key`/`yclid`/`client_id`/UTM → после матчинга к клиенту → touchpoint(kind=visit);
- заявка (lead) → touchpoint(kind=lead, lead_id);
- ручные действия менеджера (звонок, письмо) → touchpoint(kind=call/email) — v2;
- событие без идентификатора человека → НЕ touchpoint (остаётся в marketing_events);
- дедуп: один marketing_event_id → максимум один touchpoint (partial unique).

Связи: customer_id обязателен, lead_id — для заявок; deal не хранит touchpoint напрямую (снимок в `deals.attribution_snapshot`).

### 7.3. First touch / Last touch

`RecordTouchpointAction` в одной транзакции:

1. вставляет touchpoint;
2. если `customer.first_touch_at IS NULL` или `event.occurred_at < first_touch_at` → обновляет first_*;
3. если `event.occurred_at >= last_touch_at` → обновляет last_*.

Первый визит с Google organic, затем Yandex Direct, затем заявка → first = organic, last = yandex-direct, история видна в touchpoints.

### 7.4. UTM (п.11)

- Хранятся: на `marketing_events` (сырьё), `touchpoints` (касание), `leads` (снимок заявки), first/last на customers (агрегат).
- Передача с сайта: JS-пиксель читает query-параметры URL при визите, сохраняет в first-party cookie (session/localStorage не подходит — теряется между сессиями), шлёт в `POST /api/v1/events`; при отправке формы cookie подмешивается в payload формы.
- Сохранение: Form Request + `UtmParser` (санитизация, лимиты длины, без HTML).
- Нет UTM: event с пустыми utm; SourceResolver назначает `source` по referrer (google→organic, yandex→organic, прямой заход→direct) — данные не теряются.
- Повторный визит с другой кампанией: новый touchpoint, last_* обновляются, first_* не трогаются.

### 7.5. Yandex ClientID (п.12)

- Источник: `ym(counterID, 'getClientID', cb)` на сайте (JS-пиксель) — идентификатор браузера в Метрике.
- Передача: в payload события `visit` и формы `lead` (`yandex_client_id`).
- Хранение: `marketing_events.yandex_client_id`, `touchpoints.yandex_client_id`, `leads.yandex_client_id`, first/last на customers, `customer_identities(type=yandex_client_id)`.
- Связка с клиентом: client_id сам по себе не создаёт клиента (это cookie-уровень), но используется как подсказка matcher'а и как ключ для Metrica offline goals.
- Отсутствует: ничего не ломается — конверсия уходит без client_id (для Metrica goals идентификатор визита подставляется на стороне сайта при JS-цели; server-side канал использует то, что есть).

### 7.6. yclid (п.13)

- yclid — метка клика Яндекс.Директ (параметр `?yclid=...`).
- Хранение: те же уровни, что и client_id (`marketing_events`, `touchpoints`, `leads`, first/last, identities).
- First/last: подчиняются общим правилам touchpoint.
- Повторные визиты: yclid сохраняется на новом touchpoint; last_yclid обновляется, first_yclid — нет.
- Назначение: ключ для **Direct Offline Conversions API** (загрузка реальных сумм по кликам) — см. п.10.

---

## 8. Воронка

### 8.1. Нужны ли Lead и Deal отдельно (п.15)

Анализ:

- Лид = «сырой входящий контакт» (может быть спам, нецелевой, без бюджета). Сделка = «квалифицированная возможность с перспективой денег».
- Разделение даёт: чистую воронку продаж (лид-конверсия, скорость квалификации), разную ответственность (оператор/менеджер), разные конверсии в Yandex (lead_qualified ≠ deal_created).
- Аргумент «схлопнуть» оправдан только при одном менеджере и нулевой квалификации. У ТЗ прямой план масштабирования → **разделяем**.

Решения:

- один Lead → **0..1** Deal (конвертация создаёт сделку; повторная конвертация того же лида запрещена);
- один Customer → N Deals;
- один Deal → **0..N** Orders (основной заказ + допродажи); на практике в v1 чаще 1:1, но схема допускает N;
- Deal без Orders возможен (проиграл на переговорах);
- Order без Payment возможен (ожидает оплаты).

### 8.2. Процесс

```text
Новая заявка (API) → Lead(new)
   → квалификация (менеджер) → Lead(in_progress → qualified | unqualified | lost)
   → qualified → ConvertLeadToDealAction → Deal(new) [+ lead.status=converted]
   → Deal: qualification → proposal → negotiation → won | lost
   → won → Order(draft→confirmed) → Payment(s)
   → Payment received → conversion order_paid → реальная выручка
```

Deal создаётся **только** из квалифицированного лида (статус qualified). Прямое ручное создание сделки без лида допускается (permission `deals.create`) — для входящих звонков «мимо сайта».

### 8.3. Revenue (п.20)

Цепочка: `lead → deal → order → payment → revenue`.

Правила:

- лид никогда не является оплаченной сделкой: у лида нет суммы;
- `order_created` — намерение (не выручка), `payment_received` — выручка;
- выручка = Σ payments.amount (type=payment, status=paid) за период; возвраты (type=refund) вычитаются;
- все метрики выручки считаются от payments, не от orders и не от deals.

---

## 9. Статусы и история статусов

### 9.1. Lead statuses (enum `LeadStatus`)

```text
new            — новый
in_progress    — в работе (берёт менеджер)
qualified      — квалифицирован (перспективный)
unqualified    — нецелевой (спам/не наш профиль)
converted      — конвертирован в сделку
lost           — потерян (был перспективен, но отказ)
```

Разделение `unqualified` (мусор) и `lost` (был клиент, отказался) — важно для честной аналитики.

### 9.2. Deal statuses (enum `DealStatus`)

```text
new            — новая
qualification  — квалификация потребностей
proposal       — отправлено КП
negotiation    — переговоры
won            — выиграна
lost           — проиграна
```

### 9.3. Order statuses (enum `OrderStatus`)

```text
draft — черновик
confirmed — подтверждён
completed — выполнен
cancelled — отменён
```

### 9.4. Payment statuses (enum `PaymentStatus`)

```text
pending — ожидает (зарезервирован, не подтверждён)
paid — оплачен
failed — не прошёл
refunded — возвращён
cancelled — отменён
```

### 9.5. Переходы

- Все переходы — через `Domain/Sales/LeadStatusMachine` / `DealStatusMachine`: таблица разрешённых переходов в одном месте (PHP enum + метод `canTransition`).
- Запрещено менять статус напрямую `$model->status = ...` вне Action: Action вызывает StateMachine → пишет `*_status_history` → публикует event.
- Финальные статусы: lead: unqualified/lost; deal: won/lost — выход из финала только через админскую permission с комментарием в историю.

### 9.6. История (п.17)

Каждая смена → строка в `lead_status_history` / `deal_status_history`: кто (`changed_by`), когда (`created_at`), from → to, комментарий. UI показывает ленту на странице лида/сделки.

---

## 10. Конверсии и Yandex

### 10.1. Два канала (почему не один API)

| Событие | Куда | Зачем |
|---------|------|-------|
| lead_created, lead_qualified, deal_created, order_created | **Яндекс.Метрика**: цели счётчика (counterId + goal) | Метрика видит клиентский clientID; server-side API Метрики (management API, counter goal reach) дополняет JS-пиксель. |
| order_paid (с реальной суммой) | **Яндекс.Директ**: Offline Conversions API (`conversionUploads/addUpdate`), ключ — yclid | Только Директ принимает денежные суммы по кликам для автостратегий на оплату. |

> На этапе реализации: точные имена методов/версий API (Метрика management API, Direct API v5) проверить по актуальной документации Яндекса; архитектура от этого не меняется — клиенты изолированы в `Integrations/Yandex`.

### 10.2. События

| Тип | Триггер | Value |
|-----|---------|-------|
| lead_created | создание лида из API | — |
| lead_qualified | lead → qualified | — |
| deal_created | создана сделка | amount_expected |
| order_created | создан заказ | order.amount |
| order_paid | payment → paid | сумма этого платежа (реальные деньги) |

### 10.3. Pipeline (outbox)

```text
Action (в транзакции) → создаёт conversion_events(status=pending)
   → после коммита: queued SendConversionJob(external_event_id)
      → Job читает record → Yandex client → успех: status=sent, sent_at
      → ошибка: attempts++, status=failed, last_error → retry backoff
```

- **Где хранится статус отправки**: `conversion_events.status/attempts/last_error/sent_at`.
- **Не отправить дважды**: `external_event_id` unique; Job идемпотентен — перед отправкой проверяет `status != sent`; повторный запуск того же job = no-op; Yandex-запрос содержит стабильный `id` записи (для Direct offline — наш external_event_id как `id` конверсии).
- **Retry**: экспоненциальная задержка (1m, 5m, 30m, 2h, 6h), максимум 5 попыток, затем `failed` + уведомление админу (email, раз в сутки дайджест).
- **Ошибка API**: различать 4xx (не ретраить — конфигурационная ошибка: лог + статус failed + алерт) и 5xx/timeout (ретраить).
- **Ретраи вне очереди**: cron-джоб `conversions:retry` каждые 15 минут подбирает `status=failed AND attempts<5` (защита от потери job'ов database-очереди).

### 10.4. order_paid с суммой

- Берётся `payments.amount` конкретного платежа (частичная оплата = частичная сумма);
- валюта RUB;
- yclid берётся: `payment→order→deal→attribution_snapshot.last_yclid` → fallback `customer.last_yclid` → fallback `customer.last touchpoint yclid`;
- нет yclid → event уходит в канал Метрики (цель) без суммы по клику; в Direct offline — пропускается (status=skipped, причина в last_error).

---

## 11. REST API

### 11.1. Общие правила

- Префикс `/api/v1`, только HTTPS, JSON, кодировка UTF-8.
- Версионирование через URL (`/v1`), обратно совместимые изменения без bump.
- Даты в ISO-8601 UTC (`2026-10-09T12:00:00Z`).
- Пагинация списков: `?page=&per_page=` (max 100), meta в `links`/`meta`.
- Ошибки — единый формат (11.4).

### 11.2. Таблица эндпоинтов

| Method | Endpoint | Назначение | Auth | Idempotency |
|--------|----------|-----------|------|-------------|
| POST | /api/v1/leads | Создание лида с сайта | Site HMAC | обязательна |
| POST | /api/v1/events | Приём маркетингового события (visit/form_view) | Site HMAC | опциональна |
| POST | /api/v1/orders | Создание заказа (резерв из сайта, если появится) | Site HMAC | обязательна |
| POST | /api/v1/payments | Приём платежа (внешний эквайринг/сайт) | Site HMAC | обязательна |
| GET | /api/v1/leads/{id} | Карточка лида | Sanctum token (staff) | — |
| GET | /api/v1/customers | Список клиентов | Sanctum token | — |
| GET | /api/v1/reports/conversions | Выгрузка конверсий для BI | Sanctum token | — |

В v1 обязательны первые четыре; GET-эндпоинты — по мере необходимости (UI ходит через Livewire/Actions напрямую).

### 11.3. Формат POST /api/v1/leads (пример контракта)

Request:

```json
{
  "site_code": "us812",
  "name": "Иван",
  "phone": "8 999 123-45-67",
  "email": "ivan@example.com",
  "request": "Ремонт кольца",
  "message": "Срочно",
  "utm": {"source":"yandex","medium":"cpc","campaign":"a123","content":"g1","term":"ремонт колец"},
  "yclid": "1234567890.1234",
  "yandex_client_id": "987654321.1234567890",
  "referrer": "https://yandex.ru/...",
  "landing_page": "https://us812.ru/services",
  "client_key": "c1f2...",
  "occurred_at": "2026-10-09T12:00:00Z"
}
```

Validation (Form Request): `site_code` required + существует + active; `phone` required unless email; нормализация; длины; `occurred_at` ±24ч от now (иначе 422).

Response `201`:

```json
{"data":{"lead_id":42,"customer_id":7,"status":"new","created_at":"..."}}
```

`200` при повторном `Idempotency-Key` — законсервированный первый ответ.

### 11.4. Формат ошибок

```json
{"error":{"code":"validation_failed","message":"Телефон некорректен","details":{"phone":["invalid"]}},"request_id":"..."}
```

| HTTP | Случай |
|------|--------|
| 200 | успех / повтор идемпотентного запроса |
| 201 | создано |
| 400 | кривой JSON/параметры |
| 401 | неверная подпись/ключ |
| 403 | сайт неактивен, нет прав |
| 409 | конфликт (например, сделка уже создана) |
| 422 | валидация |
| 429 | rate limit |
| 500 | внутренняя (request_id для поиска в логах) |

### 11.5. Идемпотентность — см. п.13. Спам — см. п.14.

---

## 12. API authentication

### 12.1. Сравнение вариантов (п.24)

| Вариант | Плюсы | Минусы | Безопасность | Сложность |
|---------|-------|--------|--------------|-----------|
| **API key** (статичный заголовок `X-Api-Key`) | максимально просто | ключ ездит в каждом запросе в открытом виде (в TLS-теле); утечка = компрометация; нет защиты от replay | средняя | низкая |
| **Bearer token** (OAuth2/JWT, срок жизни) | ротация, scope'ы | для machine-to-machine двух сайтов — оверхед; нужен токен-эндпоинт, refresh, хранение секрета на сайте | высокая | средняя/высокая |
| **HMAC-подпись** (api_key_id + secret, подпись запроса, timestamp+nonce) | секрет не ездит по сети; подпись уникальна на запрос; replay защищён; идемпотентно к утечке заголовков | чуть больше кода на сайте (подписать тело) | высокая | средняя |

### 12.2. Рекомендация

**HMAC-подпись** (`VerifySiteSignature` middleware):

```text
X-Site-Key:   <api_key_id>
X-Timestamp:  unixtime (окно ±300 с)
X-Nonce:      uuid (одноразовый)
X-Signature:  base64( HMAC-SHA256(secret, timestamp + "\n" + nonce + "\n" + md5(body)) )
```

- секрет хранится в `sites.api_secret_encrypted` (Laravel Crypt) и на сайте (в конфиге MODX / PHP-константе вне docroot);
- проверка: сайт активен → timestamp в окне → nonce не использован (кэш на 10 мин) → подпись совпала;
- третий сайт = INSERT + генерация пары ключей (команда `site:create-keys`, этап реализации);
- бонус: `api_key_id` однозначно определяет `site_id` → бизнес-логика не зависит от `site_code` в теле.

---

## 13. Idempotency

### 13.1. Механизм

- Клиент шлёт заголовок `Idempotency-Key: <uuid>` (обязателен для POST /leads, /orders, /payments).
- Middleware `IdempotencyGuard`: ищет запись `(site_id, key)` в `api_idempotency_keys`.
  - нет → пропускает запрос, после ответа сохраняет `request_hash`, `response_status`, `response_body`, `expires_at = now + 24ч`;
  - есть, запрос в полёте (нет response) → `409` «обработка идёт»;
  - есть, ответ сохранён → отдаёт сохранённый ответ (`200/201`) без повторного выполнения.
- Если тот же ключ пришёл с другим телом (`request_hash` не совпал) → `422` `idempotency_key_reuse`.

### 13.2. Параметры

- Кто создаёт ключ: сайт-источник (UUID формы при первой отправке; при повторной отправке той же формы — тот же ключ).
- Где хранится: `api_idempotency_keys` (PK site_id+key).
- Сколько: 24 часа (закрывает дубли от double-click, таймаутов сети, ретраев curl).
- Повторный запрос: возвращает исходный результат, БД не меняется.

### 13.3. Второй слой защиты от дублей

Даже с разными ключами одинаковая заявка не должна плодиться — `IngestLeadAction` выполняет **duplicate detection**: за последние 10 минут от того же site + тот же нормализованный phone + та же request → считается дублем: лид не создаётся, ответ `200` с пометкой `"duplicate": true` и существующим `lead_id`. Это защита от повторной отправки формы с новым ключом.

---

## 14. Защита от спама

Разумный первый вариант (без оверхеда):

| Мера | Реализация | Комментарий |
|------|-----------|-------------|
| Rate limiting | middleware throttle: по `site` (60 req/мин) + по IP (10 req/мин на /leads) | Laravel RateLimiter |
| Honeypot | скрытое поле формы на сайте; непустое → `201`-имитация, лид не создаётся (тихий сброс) | дешёво, не видно боту |
| Validation | строгая Form Request: телефон формат, длины, запрет HTML/ссылок в message | основной фильтр |
| Duplicate detection | 13.3 | |
| IP throttling | 429 после превышения | |
| CAPTCHA | **не в v1** на стороне CRM. Если включится на сайте (Turnstile) — CRM принимает необязательный токен и проверяет его сервер-секретом (отдельная опция конфига) | не усложняем API |
| Блокировки | таблица банов по IP/IP-подсети при всплеске (ручное управление админом) — v2 | |

Принцип: CRM не превращается в WAF. Основные фильтры — валидация + дедуп + лимиты; капча остаётся на сайте при необходимости.

---

## 15. Авторизация CRM и RBAC

### 15.1. Подход (п.27)

- **Laravel Fortify** (headless аутентификация: login, logout, password reset, email verification) + Blade/Livewire UI. Breeze не брать (он тянет лишний UI-слой, который будем переписывать под Livewire).
- Сессии (web guard) — для менеджеров; **Sanctum** (SPA/API tokens) — для staff GET-эндпоинтов и будущих интеграций.
- **spatie/laravel-permission** — roles/permissions.
- 2FA — перспектива (Fortify поддерживает two-factor из коробки, включается позже без переделки).

### 15.2. Роли (v1)

| Роль | Назначение |
|------|-----------|
| admin | всё, включая users.manage, settings.manage, merge, retry конверсий |
| manager | работа с лидами/сделками/заказами/платежами, заметки |

Закладка на v2: `sales_manager`, `analyst` (только read + reports), `operator` (лиды + клиенты без выручки).

### 15.3. Permissions (v1, полный набор)

```text
customers.view  customers.create  customers.update  customers.delete  customers.merge
leads.view  leads.create  leads.update  leads.assign  leads.delete
deals.view  deals.create  deals.update  deals.delete
orders.view  orders.create  orders.update  orders.cancel
payments.view  payments.create  payments.refund
conversion_events.view  conversion_events.retry
notes.create
reports.view
users.manage
settings.manage
```

- Проверка — Policies на модели + middleware `can:` / `permission:` на маршрутах + `@can` в Blade.
- Границы видимости в v1: все менеджеры видят все записи (общая CRM); персональная «моя воронка» — фильтр UI, не ограничение прав. При необходимости — отдельная permission `leads.view.own` в v2.

### 15.4. Сессии и пароли

- Password reset по email (Fortify);
- хэш bcrypt (argon2id — опция, если доступен);
- `is_active=false` мгновенно блокирует вход и текущие сессии (middleware);
- логирование входов (`last_login_at`, audit `user.login`).

---

## 16. Audit log, Notes, Activities, Tasks

### 16.1. Audit log (п.29)

- Пишут Observers на Customer/Lead/Deal/Order/Payment/User (created/updated/deleted/restored) — автоматически, без ручных вызовов.
- Поля: см. 5.15 (кто `user_id`, что `auditable_type/id + event + old/new_values`, когда `created_at`, откуда `ip/user_agent`).
- Только белый список полей (без password/secret/токенов).
- Хранение: без soft delete; ретеншен 24 месяца, затем архив выгрузкой (cron, этап реализации).
- UI: лента «История изменений» на карточке + админ-страница общего журнала с фильтрами (permission `users.manage`).

### 16.2. Notes (v1)

Заметки к клиенту/лиду/сделке/заказу (polymorphic). Создает менеджер, edit/delete — автор или admin. Аудит не обязателен (но автор + время есть).

### 16.3. Activities (v1)

Лента действий по объекту: смена статуса, добавлена заметка, отправлена конверсия, создан заказ. Пишется из Listeners. Отличие от audit: activities — человекочитаемая лента для менеджеров, audit — юридический журнал изменений полей.

### 16.4. Tasks (v2)

«Позвонить завтра», напоминания, календарь — **не в v1**. Схема заложена (tasks polymorphic + due_at + assigned_to), реализация в v2 вместе с cron напоминаниями.

---

## 17. UI и Dashboard

### 17.1. Стек

Blade + Livewire 3 + Alpine.js + Tailwind CSS + Vite. SPA-фреймворки не используются.

### 17.2. Страницы (структура, без реализации)

```text
/login                      Fortify
/dashboard                  метрики (17.3)

/leads                      таблица: статус, сайт, источник, менеджер, дата; фильтры, поиск
/leads/{id}                 карточка: данные, UTM-снимок, история статусов, заметки,
                            действия (взять/квалифицировать/конвертировать/потерять)

/customers                  таблица: имя, телефон, email, источников first/last, счётчики
/customers/{id}             карточка: контакты, история касаний (timeline touchpoints),
                            лиды, сделки, заказы, платежи, заметки, merge-панель

/deals                      kanban по статусам + таблица
/deals/{id}                 карточка: клиент, сумма, attribution_snapshot, статус-история,
                            заказы, заметки

/orders                   таблица: номер, клиент, сумма, статус, оплачено
/orders/{id}              карточка: позиции, суммы, платежи, статусы

/payments                 таблица платежей: дата, заказ, клиент, сумма, метод, статус

/reports/conversions      конверсии: статусы отправки, retry (admin)
/analytics                маркетинговые отчёты (см. 17.3)

/settings                 общие настройки (admin)
/settings/sites           справочник сайтов + выдача ключей (admin)
/settings/sources         справочник источников (admin)
/users                    пользователи, роли (admin)
```

### 17.3. Dashboard — CRM vs Marketing метрики (п.32)

**CRM metrics** (для менеджеров, считаются из CRM-таблиц):

- новые лиды за сегодня/неделю/период;
- лиды по статусам (воронка), просроченные без движения;
- конверсия lead→deal, deal→order;
- сделки в работе, выручка (Σ paid payments) за период;
- средний чек, количество оплат.

**Marketing metrics** (для владельца, из touchpoints/leads/conversion_events):

- лиды по источникам/кампаниям (first vs last);
- CPL по источникам (бюджет вне CRM, но стоимость лида = выручка/лиды по каналу);
- статус отправки конверсий (failed/pending);
- touchpoint-пути (топ каналов → продажа).

Разделение принципиальное: CRM-метрики не считают «кампании», маркетинговые не считают «задачи менеджера». На v1 — один дашборд с двумя секциями.

---

## 18. Events и Queue

### 18.1. Laravel Events (п.35)

| Событие | Триггер | Синхронные listeners | Queued listeners |
|---------|---------|----------------------|------------------|
| LeadCreated | IngestLeadAction | — | SendYandexConversion(lead_created), SendEmailNotification(менеджерам), CreateActivity |
| LeadQualified | смена статуса | — | SendYandexConversion(lead_qualified), CreateActivity |
| DealCreated | ConvertLeadToDealAction | — | SendYandexConversion(deal_created), CreateActivity |
| OrderCreated | создание заказа | — | SendYandexConversion(order_created), CreateActivity |
| PaymentReceived | payment→paid | — | SendYandexConversion(order_paid), CreateActivity |
| CustomerMerged | merge | — | CreateActivity |

Правило: **синхронно** — только то, без чего нельзя ответить клиенту (валидация, матчинг, запись). Всё, что обращается наружу (Yandex, email) или не критично к таймингу (activity) — **queued**. Это сохраняет быстрый ответ API (п.36: «не использовать queue там, где пользователь ждёт немедленного ответа»).

### 18.2. Отправка конверсий через outbox (уточнение)

Событие → queued listener `SendYandexConversion` **не** вызывает Yandex напрямую: listener создаёт `conversion_events(pending)` (если ещё не создан в транзакции Action) и диспатчит `SendConversionJob`. Итог: запись о конверсии существует всегда (даже если worker упал), доставка гарантирована retry-джобом по cron.

### 18.3. Queue (п.36)

- Driver: **database** (`jobs` таблица Laravel) — из-за ограничений shared-хостинга SWeb (Redis/supervisor не гарантированы). Проверка при реализации; если Redis доступен — переключение конфигом.
- Worker: cron `php artisan queue:work database --stop-when-empty --max-time=50` раз в минуту (plus `queue:monitor` для алертов).
- Connections: `default` (email/activity), `conversions` (отдельная очередь, свой timeout и retry).
- Failed jobs: `failed_jobs` + алерт админу; команда `queue:retry` — вручную.
- Retry strategy job'ов: `tries=5`, backoff `[60, 300, 1800, 7200, 21600]`.
- Тяжёлые интеграции (будущие телефония/CRM-выгрузки) — отдельная очередь `integrations`.

---

## 19. Нефункциональные решения

### 19.1. Money (п.38)

- Тип: `numeric(14,2)` + `currency char(3)` (ISO-4217) в каждой денежной колонке.
- Почему не float: ошибки двоичной дроби недопустимы в деньгах.
- Почему numeric, а не integer в копейках: в проекте одна основная валюта (RUB), суммы вводятся менеджерами, PostgreSQL numeric точен до 2 знаков; integer-в-минимальных-единицах оправдан при мульти-валютности с эквайринговыми API (там суммы приходят в копейках) — зафиксировано как альтернатива в ADR-010, при подключении эквайринга сумм-в-копейках можно добавить отдельный слой конвертации, не ломая схему.
- Все вычисления — через value object `Domain/Money/Money` (bcmath), запрет арифметики на «сырых» decimal в контроллерах.
- Округление: half-up, до копеек, в момент расчёта позиции.

### 19.2. Timezone (п.39)

- PostgreSQL: колонки `timestamptz`, сервер работает в UTC;
- Laravel: `config/app.php timezone => 'UTC'`, все Carbon-значения пишутся в UTC;
- API: ISO-8601 UTC;
- Отображение менеджерам: `users.timezone` (default `Europe/Moscow`), форматирование через accessor/`Carbon::timezone()`;
- сырые события с сайтов: `occurred_at` приходит с сайта в UTC (JS-пиксель конвертирует локальное время браузера в UTC);
- отчёты «за день» — по Europe/Moscow (конфиг `reports.timezone`), пересчёт UTC→MSK в запросах отчётов.

Единый принцип: **храним UTC, показываем локально**.

### 19.3. Soft deletes (п.40)

| Сущность | Удаление |
|----------|----------|
| customers | soft (merge → soft + merged_into) |
| leads | soft |
| deals | soft |
| orders | **без удаления** — только статус cancelled (финансовый документ) |
| payments | **запрещено любое удаление** (ни soft, ни hard); исправление — новая запись refund/cancelled |
| touchpoints, marketing_events | hard delete по ретеншен-политике (180 дней для events) — это операционные данные |
| conversion_events, audit_logs | hard delete по ретеншену (12/24 мес) с выгрузкой-архивом |
| users | soft + is_active (история назначений должна жить) |

### 19.4. Privacy (п.41)

- Хранятся минимально необходимые ПДн: имя, телефон, email, адрес (по данным самой заявки).
- IP не хранится в чистом виде — только `ip_hash` (SHA-256 с суточной солью) в marketing_events.
- User-agent — только в marketing_events (для отладки), не в карточках.
- Рекламные идентификаторы (yclid, client_id) — не ПДн, но живут только в attribution-контурах.
- Право на удаление (152-ФЗ / GDPR-подобный запрос): hard delete клиента = анонимизация (ФИО/телефон/email → обезличены, финансовая цепочка orders/payments сохраняется в обезличенном виде — требование бухгалтерского учёта).
- Доступ к ПДн — только authenticated CRM-пользователи с permission `*.view`; экспорт — только `reports.view` + аудит.
- Секреты (API secrets, токены Яндекса) — `.env` / зашифрованные колонки, никогда в логах и audit.

### 19.5. Backup (п.42)

- PostgreSQL: ежедневный `pg_dump -Fc` (или `pg_basebackup` + WAL, если хостинг позволяет PITR) в защищённое хранилище вне web-root;
- Retention: 14 ежедневных, 8 еженедельных, 6 ежемесячных;
- Проверка восстановления: ежемесячный автоматический `pg_restore` в тестовую БД + smoke-запросы (этап реализации);
- Laravel `storage/` (загрузки — появятся в v2): резервное копирование при появлении файлов;
- `.env`/секреты: вне бэкапов БД, хранятся у владельца + в менеджере паролей проекта;
- Что где: БД-дампы — object storage/дисковый backup SWeb; код — Git; секреты — не в Git.

### 19.6. Logging (п.43)

- Каналы: `single`/`daily` application log; отдельный канал `api` (входящие запросы: site, endpoint, status, request_id, длительность — без PII-телец); отдельный канал `integrations` (каждый запрос/ответ Yandex c маской секретов).
- `failed_jobs` — в БД + лог.
- Ошибки конверсий — в `conversion_events.last_error` (первичный источник), не только в логах.
- **Никогда не логируются**: пароли, API secrets, полные токены, DB passwords, полные тела /leads (только request_id + хэш).
- Формат: JSON-строки для machine-парсинга, `request_id` сквозной (middleware генерирует, отдаёт в ошибках).

### 19.7. Monitoring (п.44)

- `GET /health` (без auth, только базовые данные): Laravel ок, PostgreSQL ping, queue: pending jobs count + oldest job age, disk space (если `disk_free_space` доступен);
- ответы: `200 ok` / `503 degraded` + JSON статусов;
- внешний uptime-проверщик (cron с другого хоста / хостинг-мониторинг) — на этапе реализации;
- алерты: v1 — email админу (queue:monitor, failed conversions digest, backup-проверка).

---

## 20. Deployment и workflow

### 20.1. Конвейер (п.45)

```text
MacBook (dev) → Git → GitHub (private) → CI (tests) → deploy на SWeb (production)
```

- В Git: код, миграции, сиды, конфиги `config/*.php`, `composer.json/lock`, `package.json/lock`, Vite-конфиг, `.env.example` (без значений).
- Не в Git: `.env`, секреты, `vendor/`, `node_modules/`, `public/build/` (артефакты), дампы БД, приватные ключи.
- `.env` на сервере: только в production-директории, права `600`, владелец — деплой-пользователь.
- Production secrets: в `.env` сервера + менеджер паролей владельца; ротация API-ключей сайтов — через UI `settings/sites`.
- Migration при деплое: `php artisan migrate --force` (только additive-миграции, reversible; без `migrate:fresh`);
- Rollback БД: даун-миграции для всего, что можно откатить; для разрушительных изменений — policy «сначала widen, потом migrate, потом shorten» + backup перед деплоем;
- Frontend: `npm ci && npm run build` на CI, артефакт `public/build` выкладывается на сервер (на сервере npm-сборка не обязательна);
- Проверка deployment: после релиза — `/health`, smoke-тест `POST /api/v1/leads` с тестовым ключом на staging (или на prod с тестовым site_code + последующей отметкой), `php artisan about`, `queue:work --once`;
- Атомарность: release-директории + symlink `current` (Deployer или простой скрипт), `php artisan config:cache route:cache view:cache` + `opcache_reset` после переключения symlink.

### 20.2. Development workflow (п.46)

```text
main            — всегда стабильная, защищённая, деплоится
feature/*       — новые функции
fix/*           — исправления
```

Правила:

- `main` защищена, изменения только через Pull Request;
- CI на PR: PHPUnit/Pest + PHPStan (уровень 5+) + Pint (стили) + сборка фронта;
- merge — squash, после зелёного CI + review (даже при одном разработчике — самоление через PR создаёт историю);
- релиз: tag `v1.x.x` → деплой tag'а (не произвольного коммита);
- hotfix: branch `fix/*` от main → PR → релизный tag.

---

## 21. Тестирование

### 21.1. Unit tests (чистые, без БД)

- Attribution: `UtmParser`, `SourceResolver`, first/last-логика (на фейковых репозиториях/в памяти);
- Duplicate detection / `CustomerMatcher` (матрица приоритетов phone/email);
- Money: арифметика, округления, line_amount;
- Status machines: разрешённые/запрещённые переходы (data provider по всем парам);
- Phone/Email normalizer (таблицы кейсов: `8 999...`, `+7...`, `7 999...`, мусор).

### 21.2. Feature tests

- API: создание лида (успех, валидация, 401 подпись, 429, 409/200 идемпотентность, duplicate detection);
- авторизация UI: login, password reset, блокировка is_active;
- permissions: каждой роли — что можно/нельзя (Pest dataset по permissions);
- заказы/платежи: частичные оплаты, refund, пересчёт сумм, запрет удаления;
- conversion_events: создание, статусы, retry, нет двойной отправки.

### 21.3. Integration tests

- PostgreSQL: миграции + специфичные фичи (citext, partial unique, trigram) — на реальном PostgreSQL в CI (Docker-образ postgres:16), sqlite для этих тестов не используется;
- Yandex API: контрактные тесты клиентов с `Http::fake()` (маппинг 4xx/5xx, формат payload);
- email: `Mail::fake()` assertions (кому, шаблон, без PII в теме).

Инструменты: Pest (или PHPUnit — на этапе реализации), `RefreshDatabase` на PostgreSQL, PHPStan + Pint статически.

---

## 22. MVP (v1) и V2

### 22.1. V1 (входит)

```text
Authentication (Fortify) + Users + Roles (admin/manager) + RBAC
Sites + Sources (справочники, UI settings)
Customers + идентификация + merge
Leads (приём, статусы, история, распределение)
Deals (конвертация, статусы, история)
Orders + order_items + products (базовый справочник)
Payments (+ частичные, refund)
Attribution: touchpoints + first/last + UTM + yclid + Yandex ClientID
Marketing events (приём событий)
REST API: /leads, /events, /orders, /payments (+ HMAC auth, idempotency, rate limit, honeypot)
Dashboard (CRM + Marketing секции)
Conversion events + Yandex pipeline (Метрика цели + Direct offline, outbox, retry)
Audit log + Notes + Activities
Health endpoint
```

### 22.2. V1 НЕ включает

Tasks, Calendar, attachments, tags, campaigns (таблица), advanced analytics/когортные модели, автоматизации, Telegram/WhatsApp, телефония, email-интеграция (отправка писем клиентам — только уведомления менеджерам), CAPTCHA на стороне CRM, мультиязычность UI, мобильное приложение, публичные GET API для сайтов, 2FA, тонкая пермит-изоляция «свой/чужой», хранилище файлов.

### 22.3. V2 (откладываем)

```text
Tasks + напоминания + календарь
Attachments (КП, файлы)
Tags/сегментация
Campaigns как сущность + управленческие отчёты
Advanced analytics (LTV, когорты, multi-touch модели)
Automations (правила назначения, авто-статусы, SLA)
Telegram-бот уведомления / WhatsApp
Телефония (запись, клика-ту-лид)
Email-интеграция (письма клиентам, трекинг открытий)
Advanced reports + экспорт в BI
2FA, тонкие права видимости
Роли sales_manager / analyst / operator
```

---

## 23. Risks

| # | Риск | Влияние | Митигция |
|---|------|---------|----------|
| R1 | Shared-хостинг SWeb: нет Redis/supervisor, cron с ограничениями | очередь/worker нестабильны | database queue + cron `--stop-when-empty`; cron-retry конверсий; проверка окружения на этапе внедрения |
| R2 | Изменение/отзыв Яндекс API (offline conversions, goals) | конверсии не уходят | изоляция клиентов в Integrations, контрактные тесты, мониторинг failed, fallback на JS-цели |
| R3 | Дубли клиентов при «телефон + email из разных карточек» | искажённая аналитика | приоритет телефона, merge-панель, аудит |
| R4 | Потеря client_id/yclid при кросс-доменном переходе us812↔zoloto | атрибуция рвётся | первый party cookie на каждом домене + client_key; принятие того, что кросс-доменный путь не полный (фиксируется как ограничение) |
| R5 | Неточная JS-атрибуция (adblock, cookie-ограничения браузеров) | недооценка каналов | серверный приём на редиректах формы, first-party cookie, UTM в URL-параметрах формы как fallback |
| R6 | Один человек = несколько клиентов из-за опечатки телефона | дубли | нормализация E.164, частичный поиск, merge; опечатки не устранить полностью |
| R7 | Прямое создание/правка данных мимо Actions | распад first/last, нет истории | конвенция + PHPStan rules + Observers; запрет `Model::create` вне Domain в code review |
| R8 | Утечка PII через логи/экспорт | комплаенс | маскирование, request_id вместо тел, аудит экспорта |
| R9 | Разрастание marketing_events (объём) | стоимость/производительность | ретеншен 180 дней, индексы только нужные, партиционирование при росте (порог: >5 млн строк) |
| R10 | Несогласованность first/last кэша с touchpoints | неверные отчёты | единый Action-путь записи + cron-проверка консистентности (diff выборка) |
| R11 | Идемпотентность на 24ч не покрывает ручное повторное введение | двойные лиды | duplicate detection 13.3 (по содержимому) |
| R12 | Зависимость от точности времени сайта (occurred_at) | кривой порядок касаний | допуск ±24ч, серверное received_at как арбитраж при конфликтах |
| R13 | Бюджеты/стоимость лида не в CRM | неполный ROI | осознанное ограничение v1; интеграция бюджета — v2 |
| R14 | Нет резервного копирования на старте | потеря данных | backup обязателен до продакшена (входит в definition of done v1) |

---

## 24. ADR

Формат: Decision / Alternatives / Pros / Cons / Reason. Статус всех — PROPOSED (до утверждения владельцем).

### ADR-001 Laravel architecture
- Decision: монолит Laravel 11+, Actions + Domain-слой, без Repository/Service-слоя ради слоя.
- Alternatives: микросервисы; чистый «толстый контроллер»; DDD-пакеты (nwidart modules).
- Pros: скорость, один деплой, ясность, тестопригодность.
- Cons: рост монолита в будущем.
- Reason: команда 1–3 человека, один домен, ясные границы модулей внутри приложения.

### ADR-002 PostgreSQL schema
- Decision: PostgreSQL как основная БД; citext, partial unique, timestamptz, jsonb для снимков/атрибуции.
- Alternatives: MySQL.
- Pros: строгость, частичные индексы, jsonb, надёжность для финансов.
- Cons: требует расширения pg_trgm/citext.
- Reason: хостинг уже ориентирован на PostgreSQL; нужны именно эти механизмы.

### ADR-003 Authentication (CRM)
- Decision: Fortify + Sanctum + spatie/laravel-permission; 2FA — отложен.
- Alternatives: Breeze; Jetstream; self-built.
- Pros: контроль UI (Livewire), стандартные потоки, роли «из коробки».
- Cons: свой UI-слой для login/reset.
- Reason: UI всё равно Livewire/Tailwind — готовый Blade-шаблон Breeze не экономит время.

### ADR-004 API authentication
- Decision: HMAC-подпись (api_key_id + secret, timestamp, nonce).
- Alternatives: статичный API key; OAuth2/JWT.
- Pros: секрет не передаётся, replay-защита, прост на стороне MODX (curl + hash_hmac).
- Cons: чуть больше кода подписи на сайте.
- Reason: machine-to-machine, без пользовательских токенов; баланс безопасности/сложности лучший.

### ADR-005 Attribution model
- Decision: гибрид — touchpoints (истина) + first/last кэш на customers + snapshot на deals.
- Alternatives: только поля; только touchpoints.
- Pros: история + скорость отчётов + неизменяемость атрибуции сделки.
- Cons: поддержка согласованности.
- Reason: ТЗ требует и историю, и быстрые first/last; snapshot защищает отчёты от переатрибуции.

### ADR-006 Customer deduplication
- Decision: приоритет phone(E.164) → email → пара; identities-таблица; merge с soft delete.
- Alternatives: только email; ручное решение всех конфликтов.
- Pros: минимум авто-слияний ошибок, расширяемость идентификаторов.
- Cons: merge-панель обязательна (затраты UI).
- Reason: телефон — основной канал в этом бизнесе; email реже.

### ADR-007 Yandex integration
- Decision: два канала (Метрика goals + Direct offline conversions), outbox + queue + retry, идемпотентный external_event_id.
- Alternatives: только JS-цели на сайте; только offline; синхронная отправка.
- Pros: реальные суммы в Директе, гарантия доставки, нет дублей.
- Cons: сложнее, зависит от двух API.
- Reason: ТЗ требует order_paid с суммой и «не отправить дважды».

### ADR-008 Queue strategy
- Decision: database queue + cron worker (`--stop-when-empty`), отдельные очереди default/conversions, outbox + cron-retry.
- Alternatives: Redis+supervisor; sync.
- Pros: работает на shared-хостинге, наблюдаемо.
- Cons: задержка до 1 мин, нет мгновенной обработки.
- Reason: гарантии Redis/supervisor на SWeb не подтверждены; конверсии не требуют секундной задержки.

### ADR-009 Deployment
- Decision: GitHub + CI (tests+build) + release-директории/symlink + `migrate --force`, smoke-проверка `/health`.
- Alternatives: ручной FTP-upload; zero-downtime Blue/Green.
- Pros: воспроизводимость, откат, контроль.
- Cons: настройка CI.
- Reason: production-данные требуют дисциплины релизов.

### ADR-010 Money representation
- Decision: `numeric(14,2)` + `currency`, value object Money.
- Alternatives: integer мин. единиц; float (отклонено).
- Pros: точность, читаемость, простота для одной валюты.
- Cons: при мульти-валюте с эквайрингом нужен слой.
- Reason: RUB основной, суммы ручные; точность numeric достаточна.

### ADR-011 Lead vs Deal separation
- Decision: отдельные сущности, Deal только из qualified Lead.
- Alternatives: одна сущность «заявка».
- Pros: чистые метрики, роли, разные конверсии.
- Cons: больше сущностей/экранов.
- Reason: план масштабирования (менеджеры, квалификация) в ТЗ.

### ADR-012 Statuses as enums
- Decision: PHP enum + StateMachine + `*_status_history`; без табличных справочников статусов.
- Alternatives: таблицы статусов с настройкой из UI.
- Pros: единый источник истины, типобезопасность, нет «зоопарка».
- Cons: изменение набора = деплой.
- Reason: статусы не настраиваются пользователем; требования фиксированы.

### ADR-013 Idempotency
- Decision: `Idempotency-Key` + таблица (24ч) + содержательный duplicate detection (10 мин).
- Alternatives: только уникальный индекс (phone+site+time); ничего.
- Pros: корректный повтор, защита от double-submit.
- Cons: таблица + cron-очистка.
- Reason: явное требование ТЗ «не создать двух одинаковых лидов».

### ADR-014 Timezone
- Decision: хранение UTC, отображение Europe/Moscow, отчёты по MSK.
- Alternatives: хранение MSK.
- Pros: корректность при интеграциях/будущих часовых поясах.
- Cons: пересчёт в отчётах.
- Reason: рекламные API и timestamps — UTC по умолчанию.

### ADR-015 Soft delete policy
- Decision: soft для customers/leads/deals/users; orders/payments — без удаления.
- Alternatives: soft везде; hard везде.
- Pros: финансовая целостность.
- Cons: нельзя «удалить ошибку» заказа — только cancel.
- Reason: требования аудита и учёта.

---

## 25. Итоговые таблицы

### 25.1. API (сводка)

| Method | Endpoint | Purpose | Auth |
|--------|----------|---------|------|
| POST | /api/v1/leads | создание лида | Site HMAC + Idempotency |
| POST | /api/v1/events | приём маркетинг-события | Site HMAC |
| POST | /api/v1/orders | создание заказа | Site HMAC + Idempotency |
| POST | /api/v1/payments | приём платежа | Site HMAC + Idempotency |
| GET | /api/v1/leads/{id} | карточка лида | Sanctum (staff) |
| GET | /api/v1/customers | список клиентов | Sanctum (staff) |
| GET | /api/v1/reports/conversions | выгрузка конверсий | Sanctum (staff) |
| GET | /health | мониторинг | open (без данных) |

### 25.2. Database (сущности и связи)

| Таблица | Родители | Ключевые связи |
|---------|----------|----------------|
| sites | — | → leads, marketing_events, touchpoints, deals, orders |
| sources | — | → touchpoints, leads, customers(first/last) |
| users | roles/permissions | → leads(deals/orders).assigned, notes, audit |
| customers | sources(first/last) | → leads, deals, orders, touchpoints, notes, identities |
| customer_identities | customers | type/value матчинг |
| marketing_events | sites | → touchpoints (дедуп) |
| leads | sites, customers(0..1), deals(0..1), users | → touchpoints, conversion_events, lead_status_history |
| deals | customers, leads, sites, users | → orders, conversion_events, deal_status_history |
| orders | deals, customers, sites, users | → order_items, payments, conversion_events |
| order_items | orders, products | |
| products | — | → order_items |
| payments | orders, users | → conversion_events |
| touchpoints | customers, sites, sources, leads | |
| conversion_events | customers/leads/deals/orders/payments | outbox → Yandex |
| lead_status_history | leads, users | |
| deal_status_history | deals, users | |
| audit_logs | users + polymorphic | |
| notes | users + polymorphic | |
| activities | users + polymorphic | |
| api_idempotency_keys | sites | |
| roles/permissions (spatie) | — | users |

### 25.3. Security

| Мера | Реализация |
|------|-----------|
| HTTPS | обязателен (prod), HSTS |
| API auth | HMAC (secret не в сети) + timestamp + nonce |
| Идемпотентность | ключ + таблица, 24ч |
| Rate limiting | по сайту и IP |
| RBAC | Fortify + spatie permissions + Policies |
| Секреты | `.env` (600), Crypt для api_secret, не в Git/логах |
| Аудит | audit_logs + status histories |
| PII | минимум, ip_hash, анонимизация по запросу, контроль экспорта |
| Валидация | Form Request, белый список полей, санитизация UTM |
| Бэкапы | ежедневные, вне web-root, проверка восстановления |
| Удаление финансов | запрещено (orders/payments) |

### 25.4. Attribution (полный процесс)

```text
Визит с рекламы (yclid/UTM) → JS-пиксель собирает utm/yclid/client_id/landing/referrer
   → POST /api/v1/events → marketing_events (сырьё)
   → SourceResolver → source_id
   → при заявке: POST /api/v1/leads
        → IngestLeadAction: CustomerMatcher (phone→email→пара) → customer (или новый)
        → RecordTouchpointAction: touchpoint(lead) + first/last кэш на customer
        → lead (снимок utm/yclid/client_id)
        → LeadCreated → conversion_events(lead_created) → queue → Метрика
   → квалификация → LeadQualified → conversion_events(lead_qualified)
   → ConvertLeadToDeal → deal + attribution_snapshot (фиксация first/last)
        → DealCreated → conversion_events(deal_created)
   → Order → order_created → Метрика
   → Payment(paid) → order_paid: сумма платежа + yclid из snapshot
        → queue → Direct Offline Conversions API (по yclid) + Метрика цель
   → выручка = Σ payments.paid (реальные деньги)
```

### 25.5. Yandex pipeline

```text
Domain Event → SendYandexConversion (queued listener)
   → conversion_events(pending, external_event_id uuid)  [в транзакции Action]
   → SendConversionJob (queue conversions)
        → MetricaGoalsClient | DirectOfflineConversionsClient
        → успех: sent + sent_at
        → 5xx/timeout: attempts++, retry backoff (cron conversions:retry)
        → 4xx: failed + алерт (не ретраить)
   → нет дублей: unique external_event_id + проверка status!=sent перед отправкой
```

### 25.6. MVP

См. 22.1 — включает authentication, users, customers, leads, deals, orders, payments, attribution, UTM, ClientID, yclid, API, dashboard, audit log, conversion pipeline.

### 25.7. V2

См. 22.3 — tasks, calendar, advanced analytics, automations, telegram/whatsapp, телефония, email-интеграция, advanced reports, 2FA, тонкие права.

### 25.8. Risks

См. раздел 23 (R1–R14).

### 25.9. ADR (список для утверждения)

| ADR | Тема | Статус |
|-----|------|--------|
| 001 | Laravel architecture | PROPOSED |
| 002 | PostgreSQL schema | PROPOSED |
| 003 | Authentication (CRM) | PROPOSED |
| 004 | API authentication | PROPOSED |
| 005 | Attribution model | PROPOSED |
| 006 | Customer deduplication | PROPOSED |
| 007 | Yandex integration | PROPOSED |
| 008 | Queue strategy | PROPOSED |
| 009 | Deployment | PROPOSED |
| 010 | Money representation | PROPOSED |
| 011 | Lead vs Deal separation | PROPOSED |
| 012 | Statuses as enums | PROPOSED |
| 013 | Idempotency | PROPOSED |
| 014 | Timezone | PROPOSED |
| 015 | Soft delete policy | PROPOSED |

---

## Финальная отметка

Архитектурный документ подготовлен. **Реализация не выполнялась**: не созданы миграции, модели, контроллеры, сервисы, Livewire-компоненты, Blade-страницы, API-эндпоинты, таблицы БД, пользователи, роли, очереди, cron, webhook, интеграции; не установлены Composer/npm-пакеты; не изменены production, `.env`, PostgreSQL.

Документ передан владельцу проекта на проверку. После утверждения будет выдано отдельное ТЗ на реализацию MVP.
