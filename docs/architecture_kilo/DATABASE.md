# PostgreSQL Schema

## Типы данных

| Колонка | Тип | Почему |
|---|---|---|
| `amount`, `total_amount`, `paid_amount` | `NUMERIC(12,2)` | Деньги, без потерь |
| `phone` | `VARCHAR(20)` | E.164 формат |
| `email` | `VARCHAR(255)` | lowercase |
| `utm_*` | `VARCHAR(100)` / `VARCHAR(255)` | Ограничить размер |
| `ip_address` | `VARCHAR(45)` | IPv6 совместимость |
| timestamps | `TIMESTAMP` | UTC, без timezone |
| `deleted_at` | `TIMESTAMP NULL` | Soft deletes |
| `idempotency_key` | `VARCHAR(128)` | UUID |

## Индексы

```sql
-- customers
UNIQUE(phone), UNIQUE(email), UNIQUE(yandex_client_id), INDEX(yclid)
-- leads
INDEX(customer_id), INDEX(site_id), INDEX(status_id), INDEX(manager_id), INDEX(created_at),
UNIQUE(idempotency_key), INDEX(utm_source), INDEX(utm_campaign)
-- deals
INDEX(customer_id), INDEX(lead_id), INDEX(status_id), INDEX(manager_id), INDEX(created_at)
-- orders
INDEX(customer_id), INDEX(deal_id), INDEX(status_id), INDEX(number), INDEX(created_at)
-- payments
INDEX(order_id), INDEX(customer_id), INDEX(status_id), UNIQUE(external_id), INDEX(paid_at)
-- touchpoints
INDEX(customer_id), INDEX(lead_id), INDEX(site_id), INDEX(occurred_at), INDEX(yandex_client_id)
-- audit_logs
INDEX(user_id), INDEX(auditable_type, auditable_id), INDEX(created_at)
-- conversion_events
INDEX(status), INDEX(created_at), UNIQUE(external_event_id)
```

## Целостность данных

- **FK** с `ON DELETE RESTRICT` (не удалять связанные записи).
- **UNIQUE** на phone, email, yandex_client_id, idempotency_key, external_id.
- **Soft deletes** через `deleted_at` (scope `WHERE deleted_at IS NULL`).
- **Транзакции** на операциих создания lead (customer + lead + touchpoint + audit атомарно).

## Timezone

- **PostgreSQL timezone:** `UTC`
- **Laravel timezone:** `UTC` (`app.timezone = UTC`)
- **Хранение:** все timestamps в UTC
- **Отображение:** конвертация в `Europe/Moscow` на фронте (JS) или Blade `->setTimezone()`

## Money

- Используем **`NUMERIC(12,2)`** — точность, простота, понятность.
- Никогда не `float`/`double`.
- Максимум 99 999 999 999.99 RUB — хватит.

## Soft deletes

| Сущность | Soft delete | Почему |
|---|---|---|
| customers | Да | важные данные, можно восстановить |
| leads | Да | история касаний |
| deals | Да | финансы |
| orders | Да | финансы |
| payments | Да | финансы — никогда не удалять |
| users | Да | деактивировать, не удалять |
| touchpoints | **Нет** | история, можно чистить (>2 лет) |
| audit_logs | **Нет** | не удалять никогда |
| conversion_events | Да | можно восстановить |
| notes | Да | можно восстановить |

## Schema dump (DDL)

```sql
CREATE TABLE sites (
    id BIGSERIAL PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    active BOOLEAN DEFAULT true,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

CREATE TABLE customers (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(20) UNIQUE,
    email VARCHAR(255) UNIQUE,
    yandex_client_id VARCHAR(128) UNIQUE,
    yclid VARCHAR(128),
    first_touch_source VARCHAR(100),
    first_touch_medium VARCHAR(100),
    first_touch_campaign VARCHAR(100),
    first_touch_content VARCHAR(255),
    first_touch_term VARCHAR(255),
    first_landing_page TEXT,
    first_referrer TEXT,
    first_touch_at TIMESTAMP,
    last_touch_source VARCHAR(100),
    last_touch_medium VARCHAR(100),
    last_touch_campaign VARCHAR(100),
    last_touch_content VARCHAR(255),
    last_touch_term VARCHAR(255),
    last_landing_page TEXT,
    last_referrer TEXT,
    last_touch_at TIMESTAMP,
    type VARCHAR(20) DEFAULT 'physical',
    company_name VARCHAR(255),
    inn VARCHAR(20),
    address TEXT,
    comment TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    deleted_at TIMESTAMP
);
```
