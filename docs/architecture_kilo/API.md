# API и аутентификация

## API endpoints

| Method | Endpoint | Purpose | Auth |
|---|---|---|---|
| POST | `/api/v1/leads` | Создать лид | API key + HMAC |
| POST | `/api/v1/events` | Touchpoint (visit) | API key + HMAC |
| POST | `/api/v1/orders` | Создать заказ | API key + HMAC |
| POST | `/api/v1/payments` | Создать оплату | API key + HMAC |
| POST | `/api/v1/conversions` | Конверсия в Yandex | API key + HMAC |
| GET | `/api/v1/health` | Health check | публично |
| GET | `/api/v1/leads` | Список лидов | Bearer (Sanctum) |
| GET | `/api/v1/leads/{id}` | Лид | Bearer |
| PATCH | `/api/v1/leads/{id}` | Обновить лид | Bearer |
| GET | `/api/v1/customers` | Список клиентов | Bearer |
| GET | `/api/v1/customers/{id}` | Клиент | Bearer |
| PATCH | `/api/v1/customers/{id}` | Обновить клиента | Bearer |
| GET | `/api/v1/deals` | Список сделок | Bearer |
| PATCH | `/api/v1/deals/{id}` | Обновить сделку | Bearer |
| GET | `/api/v1/orders` | Список заказов | Bearer |
| GET | `/api/v1/payments` | Список оплат | Bearer |
| GET | `/api/v1/analytics/summary` | Сводка | Bearer |
| GET | `/api/v1/touchpoints` | История касаний | Bearer |

## API аутентификация: HMAC + API key

```text
Хедеры запроса:
  X-Api-Key:    <api_key>
  X-Timestamp:  <unix_ts>
  X-Signature:  HMAC-SHA256(api_secret, "METHOD\nPATH\nTIMESTAMP\nBODY")

Проверка:
  1. Api-Key существует → получаем api_secret
  2. Timestamp не старше 5 мин (replay protection)
  3. Пересчитываем подпись — сравниваем
  4. Проверяем idempotency_key
```

## Request (пример создания лида)

```json
POST /api/v1/leads
{
  "site_code": "us812",
  "name": "Иван Иванов",
  "phone": "+79001234567",
  "email": "ivan@example.com",
  "request_type": "ring",
  "message": "Хочу кольцо",
  "utm_source": "google",
  "utm_medium": "cpc",
  "utm_campaign": "spring_sale",
  "yclid": "abc123",
  "yandex_client_id": "client_123",
  "referrer": "https://google.com/...",
  "landing_page": "https://us812.ru/catalog/ring/",
  "idempotency_key": "uuid-from-browser"
}
```

## Response

**Успех (201):**
```json
{
  "data": {
    "id": 123,
    "status": "new",
    "customer_id": 45,
    "created_at": "2026-10-08T20:55:00Z"
  }
}
```

**Ошибки:**
```json
// 422
{ "error": "validation_error", "message": "...", "errors": {...} }

// 401
{ "error": "unauthorized", "message": "Invalid API key or signature" }

// 429
{ "error": "rate_limit_exceeded", "retry_after": 60 }
```

## Idempotency

- `idempotency_key` — клиент генерирует UUID при отправке.
- Ключ хранится 24 часа в `leads.idempotency_key` (UNIQUE).
- Повторный запрос с тем же ключом → возвращает существующий lead (200).
- После 24ч — ключ удаляется (job очистки).

## Rate limiting

- **60 req/min** на API-key + IP.
- **100 req/min** с общего IP (анти-бот).
- Header: `Retry-After` при превышении.

## Валидация

Через Form Request:
- phone — E.164 (`+7XXXXXXXXXX`)
- email — валидный формат, lowercase
- site_code — должен существовать в `sites`
- idempotency_key — required, UUID
- UTM — nullable, строки

## CRM auth (менеджеры)

- **Laravel Sanctum** (SPA cookies).
- `users` таблица + `password_hash`.
- `role_id` → `roles`.
- Password reset через `breeze:ui` или руками.
