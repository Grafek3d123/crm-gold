# Сущности CRM

## Обязательные (v1)

| Сущность | Зачем нужна |
|---|---|
| **`users`** | Менеджеры, админы, auth |
| **`customers`** | Клиенты (физлица/юристы) |
| **`leads`** | Заявки с сайтов |
| **`deals`** | Сделки (квалификация → оплата) |
| **`orders`** | Заказы |
| **`payments`** | Платежи |
| **`touchpoints`** | История всех касаний клиента |
| **`marketing_events`** | События маркетинга (опционально) |
| **`lead_statuses`** | Справочник статусов лидов |
| **`deal_statuses`** | Справочник статусов сделок |
| **`payment_statuses`** | Справочник статусов оплат |
| **`order_statuses`** | Справочник статусов заказов |
| **`sites`** | Справочник сайтов |
| **`sources`** | Справочник источников (Google, Yandex, direct) |
| **`campaigns`** | Кампании (source × utm_campaign) |
| **`roles`** | Роли менеджеров |
| **`permissions`** | Права доступа |
| **`audit_logs`** | Аудит действий |
| **`conversion_events`** | Исходящие события в Yandex |
| **`lead_status_history`** | История статусов лидов |
| **`deal_status_history`** | История статусов сделок |
| **`webhook_events`** | Входящие webhook (Yandex, оплата) |

## Связи (ERD)

```
customers
    │
    ├── 1..N leads
    │      │
    │      └── 0..N deals
    │             │
    │             └── 0..N orders
    │                    │
    │                    └── 0..N payments
    │
    └── 1..N touchpoints

sites
    └── 1..N leads
    └── 1..N deals
    └── 1..N orders

leads
    └── 0..N lead_status_history

deals
    └── 0..N deal_status_history
```

## Customers (поля)

```text
id                      BIGINT PK
name                    VARCHAR(255)     NOT NULL
phone                   VARCHAR(20)      nullable  UNIQUE (E.164)
email                   VARCHAR(255)     nullable  UNIQUE (lowercase)
yandex_client_id        VARCHAR(128)     nullable  UNIQUE
yclid                   VARCHAR(128)     nullable
first_touch_source      VARCHAR(100)     nullable
first_touch_medium      VARCHAR(100)     nullable
first_touch_campaign    VARCHAR(100)     nullable
first_touch_content     VARCHAR(255)     nullable
first_touch_term        VARCHAR(255)     nullable
first_landing_page      TEXT             nullable
first_referrer          TEXT             nullable
first_touch_at          TIMESTAMP        nullable
last_touch_source       VARCHAR(100)     nullable
last_touch_medium       VARCHAR(100)     nullable
last_touch_campaign     VARCHAR(100)     nullable
last_touch_content      VARCHAR(255)     nullable
last_touch_term         VARCHAR(255)     nullable
last_landing_page       TEXT             nullable
last_referrer           TEXT             nullable
last_touch_at           TIMESTAMP        nullable
type                    VARCHAR(20)      DEFAULT 'physical'
company_name            VARCHAR(255)     nullable
inn                     VARCHAR(20)      nullable
address                 TEXT             nullable
comment                 TEXT             nullable
created_at              TIMESTAMP
updated_at              TIMESTAMP
deleted_at              TIMESTAMP        # soft delete
```

### Нормализация

- **Телефон:** E.164 (`+7XXXXXXXXXX`). Индекс UNIQUE.
- **Email:** lowercase, trim. UNIQUE.
- **Поиск:** индексы на `phone`, `email`, `yandex_client_id`, `yclid`.

### Дедупликация (стратегия)

**Приоритет совпадения:**
1. **phone** (самый надёжный)
2. **email**
3. **yandex_client_id**
4. **phone + email** (если оба есть)

**Алгоритм при создании лида:**
1. Нормализуем phone, email.
2. Ищем customer по phone (приоритет 1).
3. Если не найден — по email.
4. Если не найден — по yandex_client_id.
5. Если найден — привязываем лид к существующему customer.
6. Если не найден — создаём нового customer.

**Повторные обращения:** один клиент → много лидов, много сделок, много заказов.

## Leads

```text
id                      BIGINT PK
site_id                 BIGINT FK → sites       NOT NULL
customer_id             BIGINT FK → customers   nullable
source_id               BIGINT FK → sources     nullable
campaign_id             BIGINT FK → campaigns   nullable
manager_id              BIGINT FK → users       nullable
status_id               BIGINT FK → lead_statuses NOT NULL, default 'new'
request_type            VARCHAR(100)            nullable
service                 VARCHAR(255)            nullable
message                 TEXT                    nullable
name                    VARCHAR(255)            nullable
phone                   VARCHAR(20)             nullable
email                   VARCHAR(255)            nullable
utm_source              VARCHAR(100)            nullable
utm_medium              VARCHAR(100)            nullable
utm_campaign            VARCHAR(100)            nullable
utm_content             VARCHAR(255)            nullable
utm_term                VARCHAR(255)            nullable
yclid                   VARCHAR(128)            nullable
yandex_client_id        VARCHAR(128)            nullable
referrer                TEXT                    nullable
landing_page            TEXT                    nullable
ip_address              VARCHAR(45)             nullable
user_agent              TEXT                    nullable
idempotency_key         VARCHAR(128)            nullable  UNIQUE
utm_received_at         TIMESTAMP               nullable
created_at              TIMESTAMP
updated_at              TIMESTAMP
deleted_at              TIMESTAMP
```

## Deals

```text
id                      BIGINT PK
customer_id             BIGINT FK → customers  NOT NULL
lead_id                 BIGINT FK → leads      nullable
site_id                 BIGINT FK → sites      nullable
status_id               BIGINT FK → deal_statuses  default 'new'
manager_id              BIGINT FK → users      nullable
title                   VARCHAR(255)           NOT NULL
amount                  NUMERIC(12,2)          DEFAULT 0
currency                VARCHAR(3)     DEFAULT 'RUB'
comment                 TEXT                   nullable
created_at              TIMESTAMP
updated_at              TIMESTAMP
deleted_at              TIMESTAMP
```

## Orders

```text
id                      BIGINT PK
customer_id             BIGINT  FK → customers NOT NULL
deal_id                 BIGINT  FK → deals nullable
site_id                 BIGINT  FK → sites nullable
number                  VARCHAR(50)  UNIQUE NOT NULL
status_id               BIGINT  FK → order_statuses NOT NULL, default 'new'
manager_id              BIGINT  FK → users nullable
currency                VARCHAR(3)  DEFAULT 'RUB'
total_amount            NUMERIC(12,2) NOT NULL DEFAULT 0
discount_amount         NUMERIC(12,2) DEFAULT 0
paid_amount             NUMERIC(12,2) DEFAULT 0
comment                 TEXT nullable
created_at              TIMESTAMP
updated_at              TIMESTAMP
deleted_at              TIMESTAMP

order_items
  id, order_id FK, product_id FK, name, quantity, unit_price, total_price
```

## Payments

```text
id                      BIGINT PK
order_id                BIGINT  FK → orders  NOT NULL
customer_id             BIGINT  FK → customers NOT NULL
status_id               BIGINT  FK → payment_statuses  default 'pending'
amount                  NUMERIC(12,2) NOT NULL
currency                VARCHAR(3) DEFAULT 'RUB'
method                  VARCHAR(50) nullable   # card, cash, sbp, transfer
external_id             VARCHAR(128) nullable  UNIQUE
paid_at                 TIMESTAMP nullable
comment                 TEXT nullable
created_at              TIMESTAMP
updated_at              TIMESTAMP
deleted_at              TIMESTAMP
```

## Таблицы справочников

```text
sites:        id, code UNIQUE, name, domain, active
sources:      id, code UNIQUE, name, active
campaigns:    id, source_id FK, name, utm_campaign, active
lead_statuses: id, code UNIQUE, name, display_order, is_terminal
deal_statuses: id, code UNIQUE, name, display_order, is_won, is_lost
```
