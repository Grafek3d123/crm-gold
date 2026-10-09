# API_CONTRACT — machine API v1

**Базовый URL:** `https://crm.zoloto-spb.ru/api/v1`  
**Аутентификация:** HMAC-SHA256 подпись запроса  
**Версия контракта:** 1

---

## 1. Заголовки подписи

Каждый запрос обязан содержать:

| Заголовок | Описание |
|---|---|
| `X-Api-Key` | Публичный идентификатор ключа сайта (`key_id`) |
| `X-Timestamp` | Unix-время в секундах на момент отправки |
| `X-Signature` | HEX-подпись (нижний регистр, 64 символа) |
| `Content-Type` | `application/json` |
| `Idempotency-Key` | Уникальный ключ операции (обязателен для создания обращения) |

---

## 2. Алгоритм подписи

```
canonical = UPPERCASE_METHOD + "\n" +
            PATH_WITHOUT_QUERY + "\n" +
            TIMESTAMP_AS_SENT + "\n" +
            LOWERCASE_HEX_SHA256_OF_EXACT_RAW_BODY_BYTES

signature = LOWERCASE_HEX( HMAC_SHA256( api_secret, canonical ) )
```

Правила:

- хешируется **точное тело** HTTP-запроса; не пересериализовывать JSON;
- `PATH_WITHOUT_QUERY` — путь без строки запроса, например `/api/v1/inquiries`;
- сравнение подписей выполняется constant-time (`hash_equals`);
- окно допуска по времени — ±300 секунд;
- при неверной подписи, просроченной метке или неактивном ключе — `401 fail closed`;
- `site_id` **не принимается из тела запроса** — сайт определяется по проверенному ключу.

---

## 3. POST /api/v1/inquiries

### Тело запроса

```json
{
  "name": "Иванов Иван",
  "phone": "+7 (999) 123-45-67",
  "email": "ivanov@example.com",
  "message": "Хочу изготовить кольцо",
  "external_id": "site-side-unique-id",
  "landing_path": "/calc/ring",
  "referrer": "https://yandex.ru/",
  "client_id": "170000000000000000",
  "yclid": "170000000000000000",
  "utm": {
    "source": "yandex",
    "medium": "cpc",
    "campaign": "ring-2026",
    "content": "banner-a",
    "term": "купить кольцо"
  }
}
```

| Поле | Тип | Обяз. | Описание |
|---|---|---|---|
| `phone` | string | нет* | Телефон (нормализуется) |
| `email` | string | нет* | Email (приводится к нижнему регистру) |
| `name` | string | нет | Имя |
| `message` | string | нет | Текст обращения |
| `external_id` | string | нет | Внешний ID с сайта |
| `landing_path` | string | нет | Посадочная страница |
| `referrer` | string | нет | Референсер |
| `client_id` | string | нет | Yandex ClientID |
| `yclid` | string | нет | Yandex click ID |
| `utm` | object | нет | UTM-метки |

\* хотя бы одно из `phone` / `email` обязательно.

Неизвестные поля из тела не сохраняются — принимается только список разрешённых полей.

### Ответ 201

```json
{
  "data": {
    "inquiry_id": 1,
    "customer_id": 1,
    "status": "new",
    "site": "us812"
  }
}
```

---

## 3.1 POST /api/v1/events

Приём маркетинговых событий сайта. События хранятся в `marketing_events` и **не**
превращаются автоматически в touchpoints.

### Тело запроса

```json
{
  "events": [
    {
      "event_key": "site-side-event-id-1",
      "event_type": "page_view",
      "path": "/calc/ring",
      "utm": { "source": "yandex", "medium": "cpc", "campaign": "ring-2026" },
      "client_id": "170000000000000000",
      "yclid": "170000000000000000",
      "referrer": "https://yandex.ru/",
      "occurred_at": "2026-10-10T12:00:00+03:00",
      "metadata": { "screen": "1440x900" }
    }
  ]
}
```

| Поле | Тип | Обяз. | Описание |
|---|---|---|---|
| `events` | array | да | от 1 до 100 событий |
| `events[].event_key` | string | да | ключ дедупликации в рамках сайта |
| `events[].event_type` | string | да | тип события |
| `events[].path` | string | нет | путь страницы |
| `events[].utm` | object | нет | UTM-метки |
| `events[].client_id` | string | нет | Yandex ClientID |
| `events[].yclid` | string | нет | Yandex click ID |
| `events[].referrer` | string | нет | реферер |
| `events[].occurred_at` | date | нет | время события |
| `events[].metadata` | object | нет | произвольная metadata |

Сохраняются только перечисленные поля; неизвестные ключи из тела отклоняются/игнорируются.

### Ответ 202

```json
{
  "data": {
    "accepted": 1,
    "duplicates": 0
  }
}
```

Дедупликация по `(site_id, event_key)`: повторная доставка того же `event_key` не создаёт
дубль и попадает в счётчик `duplicates`.

---

## 4. Ошибки

| HTTP | `error.code` | Когда |
|---|---|---|
| 401 | `missing_headers` | Нет `X-Api-Key` / `X-Timestamp` / `X-Signature` |
| 401 | `unknown_credential` | Ключ не найден или неактивен |
| 401 | `invalid_timestamp` | Метка времени не число |
| 401 | `stale_timestamp` | Отклонение больше ±300 с |
| 401 | `invalid_signature` | Подпись не совпала |
| 422 | `missing_idempotency_key` | Нет заголовка `Idempotency-Key` |
| 422 | `idempotency_key_reuse` | Ключ использован с другим телом |
| 422 | `invalid_payload` | Невалидные данные (или нет phone/email) |
| 429 | — | Превышен лимит 60 запросов в минуту на ключ |

```json
{
  "error": {
    "code": "invalid_signature",
    "message": "Неверная подпись"
  }
}
```

---

## 5. Idempotency

Область ключа: `inquiry:{key_id}` + `Idempotency-Key`.

- повтор с тем же телом → исходный ответ, дублей нет;
- повтор с другим телом → `422 idempotency_key_reuse`;
- запись idempotency и бизнес-записи пишутся в одной транзакции;
- retention записей — 30 дней.

---

## 6. Test vectors

Значения ниже — **плейсхолдеры**, не реальные секреты.

```
api_secret = "test-secret-placeholder-not-a-real-key"

method   = POST
path     = /api/v1/inquiries
timestamp = 1700000000
body     = {"phone":"+79991234567"}

sha256(body) = <вычисляется клиентом>
canonical    = POST\n/api/v1/inquiries\n1700000000\n<sha256>
signature    = HMAC_SHA256("test-secret-placeholder-not-a-real-key", canonical)
```

Проверка test vectors выполняется в `tests/Feature/IngestInquiryApiTest.php`.

---

## 7. Чего в v1 нет

`POST /api/v1/orders`, `POST /api/v1/payments` и `/conversions` — эти endpoint'ы не реализованы
в первом вертикальном срезе и будут согласованы отдельно.
