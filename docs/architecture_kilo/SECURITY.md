# Security (RBAC, Audit, Privacy)

## RBAC

```text
users
  id BIGINT PK
  name VARCHAR(255)
  email VARCHAR(255) UNIQUE
  password VARCHAR(255)           # bcrypt/hash
  phone VARCHAR(20)
  avatar VARCHAR(255)
  role_id BIGINT FK → roles
  active BOOLEAN DEFAULT true
  last_login_at TIMESTAMP
  email_verified_at TIMESTAMP
  remember_token VARCHAR(100)
  created_at
  updated_at
  deleted_at                       # soft delete

roles
  id BIGINT PK
  name VARCHAR(50) UNIQUE          # admin, manager
  display_name VARCHAR(255)

permissions
  id BIGINT PK
  name VARCHAR(100) UNIQUE          # leads.create
  module VARCHAR(50)                # leads

role_permissions
  role_id FK → roles
  permission_id FK → permissions
```

## Права

```text
customers.view   customers.create   customers.update
leads.view       leads.create      leads.update
deals.view       deals.create      deals.update
orders.view      orders.create     orders.update
payments.view
users.manage
settings.manage
reports.view
audit.view
```

## Роли vs права

| Разрешение | admin | manager |
|---|---|---|
| customers.* | да | да |
| leads.* | да | да |
| deals.* | да | да |
| orders.* | да | да |
| payments.view | да | да |
| users.manage | да | нет |
| settings.manage | да | нет |
| audit.view | да | нет |
| reports.view | да | да |

**Проверка:** `Gate::allows('leads.create', $lead)` или `middleware('can:leads.update')`.

## Audit log

```text
audit_logs
  id BIGINT PK
  user_id BIGINT FK → users nullable
  event VARCHAR(100)        # lead.status_changed
  auditable_type VARCHAR(255)
  auditable_id BIGINT
  old_values JSON
  new_values JSON
  comment TEXT
  ip_address VARCHAR(45)
  user_agent TEXT
  created_at TIMESTAMP
```

## События для аудита

- lead.status_changed
- lead.assigned
- customer.updated
- deal.status_changed
- order.updated
- payment.created
- payment.status_changed
- user.created
- user.deleted

## Privacy

| Данные | Правило |
|---|---|
| phone | E.164, не передавать третьим |
| email | lowercase, не продавать |
| имя | только от клиента |
| ip_address | хранить 30 дней, анонимизировать |
| referrer | хранить, не профилировать |
| yandex_client_id, yclid | для attribution, не для таргетинга |
| пароли | только bcrypt-хэш |
| API tokens | только hash, truncate в логах |

## Web root security

- `.env`, `composer.json`, `vendor/`, `storage/`/`framework/`, `bootstrap/` — недоступны через HTTP (404, проверено).
- Web root = `public/` — только публичные файлы.
- `public/.htaccess` — стандартный Laravel (без секретов).

## API security

- HMAC-подпись (не передаёт секрет).
- Rate limiting (60/min per API key + IP).
- IP whitelist (опционально, в панели).
- HTTPS-only (HSTS включить при наличии сертификата).
