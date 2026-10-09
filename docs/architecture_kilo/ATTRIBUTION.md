# Attribution / UTM / Yandex ClientID / yclid

## Архитектура: touchpoints + customer cache

**Решение:** комбинированный подход — история в `touchpoints`, а first/last-touch кэшируются на `customers` для быстрых запросов.

## Touchpoints

```text
touchpoints
  id                      BIGINT PK
  customer_id             BIGINT FK → customers
  lead_id                 BIGINT FK → leads nullable
  site_id                 BIGINT FK → sites nullable
  source_id               BIGINT FK → sources nullable
  campaign_id             BIGINT FK → campaigns nullable
  type                    VARCHAR(50)  # visit, lead, call, email
  utm_source              VARCHAR(100)
  utm_medium              VARCHAR(100)
  utm_campaign            VARCHAR(100)
  utm_content             VARCHAR(255)
  utm_term                VARCHAR(255)
  yclid                   VARCHAR(128)
  yandex_client_id        VARCHAR(128)
  referrer                TEXT
  landing_page            TEXT
  ip_address              VARCHAR(45)
  user_agent              TEXT
  occurred_at             TIMESTAMP
  created_at              TIMESTAMP
```

## First-touch / Last-touch на customers

```text
customers.first_touch_source      VARCHAR(100)
customers.first_touch_medium      VARCHAR(100)
customers.first_touch_campaign    VARCHAR(100)
customers.first_touch_content     VARCHAR(255)
customers.first_touch_term        VARCHAR(255)
customers.first_landing_page      TEXT
customers.first_referrer          TEXT
customers.first_touch_at          TIMESTAMP
customers.last_touch_source       VARCHAR(100)
customers.last_touch_medium       VARCHAR(100)
customers.last_touch_campaign     VARCHAR(100)
customers.last_touch_content      VARCHAR(255)
customers.last_touch_term         VARCHAR(255)
customers.last_landing_page       TEXT
customers.last_referrer           TEXT
customers.last_touch_at           TIMESTAMP
```

## Когда создаётся touchpoint

| Событие | type | Кто создаёт |
|---|---|---|
| Заход на сайт | `visit` | JS на сайте (Yandex ClientID + UTM) |
| Создание лида | `lead` | CRM API |
| Звонок | `call` | Интеграция (SIP/телефон) |
| Email | `email` | CRM / сайт |

## Как избежать мусорных touchpoints

- **Dedup:** не создавать `visit`, если ClientID+occurred_at в пределах 5 мин — старый.
- **Идентификатор обязателен:** минимум один из yandex_client_id, phone или email.
- **Боты:** фильтровать по User-Agent (bot, crawler).

## Yandex ClientID

- **Получает сайт:** Yandex.Metrika JS (`/getClientID`).
- **Передаёт:** в куках и UTM-параметрах при визите и создании лида.
- **Хранит:** `customers.yandex_client_id` (UNIQUE), `touchpoints.yandex_client_id`.
- **Связь с customer:** поиск по ClientID → если нет, создаём customer с ClientID.
- **Отсутствует:** fallback на IP+UA, либо ждём phone/email из формы.

## yclid

- **Источник:** URL (`?yclid=...`) или куки Yandex.Direct.
- **Как сохраняется:**
  - **first-touch:** первый touchpoint с yclid задаёт `customers.first_touch_*` (не меняется).
  - **last-touch:** каждый новый touchpoint с yclid обновляет `customers.last_touch_*`.
  - **история:** `touchpoints.yclid` (полная история визитов).

## UTM

**Хранение:**
- `leads.utm_source/medium/campaign/content/term` — сырой вариант для аудита.
- `touchpoints.utm_*` — в истории.
- `customers.first_touch_*` / `last_touch_*` — кэш.

**Отсутствие UTM:** source = `direct`, medium = `none`.

**Повторный визит с другой кампанией:**
- Новый touchpoint с новыми UTM.
- `last_touch` обновляется.
- `first_touch` НЕ меняется.

## First-touch → Deal → Order → Payment pipeline

```text
lead_created
  → touchpoint (type=lead)
  → customer.first_touch_* если новый
  
deal_created
  → touchpoint (type=deal)
  → last_touch_* обновление

order_created
  → touchpoint (type=order)

payment_received
  → conversion event в Yandex
  → last_touch_* обновление
```

## Аудитория (multi-touch)

Текущая архитектура сохраняет **полную историю touchpoints**, поэтому в v2 можно построить:
- First-touch attribution
- Last-touch attribution
- Linear attribution (равномерное распределение)
- Time-decay attribution

Все модели поддерживаются, т.к. история сохраняется.
