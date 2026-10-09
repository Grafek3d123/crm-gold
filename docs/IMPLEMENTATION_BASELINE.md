# IMPLEMENTATION_BASELINE — CRM Gold

**Дата:** 2026-10-10  
**Режим:** локальная разработка, Docker + PostgreSQL, production/SWeb не использовался  
**Статус:** первый вертикальный срез завершён, тесты зелёные

---

## 1. Среда

| Компонент | Версия | Путь |
|---|---|---|
| PHP | 8.4.35 | Docker `php:8.4-apache` |
| Laravel | 13.35.0 | `src/` |
| PostgreSQL | 16 | Docker `postgres:16` |
| Тестовая БД | `crm_test` | pgsql |
| Apache | 2.4.68 | DocumentRoot = `src/public` |
| PHPUnit | 12.5.38 | PostgreSQL, не SQLite |
| Composer | 2.8 | в образе |
| Docker | 29.8.2 | локальный daemon |

Приложение: `http://localhost:8000` (порт 8000 → контейнерный 80).

---

## 2. Структура репозитория

```
crm/
├── Dockerfile
├── docker-compose.yml
├── .gitignore
├── docs/
│   ├── ARCHITECTURE.md
│   ├── ARCHITECTURE_FINAL.md
│   ├── CRM_PRODUCT_BRIEF.md
│   ├── API_CONTRACT.md
│   ├── IMPLEMENTATION_BASELINE.md
│   └── IMPLEMENTATION_DECISIONS.md
├── src/                      ← Laravel 13 приложение
│   ├── app/Enums, Domain, Actions, Policies, Http
│   ├── database/migrations, seeders, factories
│   ├── routes/{web,api}.php
│   ├── resources/views/
│   └── tests/{Unit,Feature}
└── tasks/
```

`.gitignore` исключает `src/vendor`, `src/node_modules`, `src/.env`, `src/public/build`, `server.md`, `AGENTS.md`, `README.md`, `tasks/`.

---

## 3. Состояние БД

Миграции (16):
users (+ role/is_active), cache, jobs, sites, sources, customers, customer_identities, inquiries, inquiry_status_history, activities, marketing_events, touchpoints, audit_logs, business_events, outbox, idempotency, api_credentials.

Seeders (идемпотентные): sites, sources, api_credentials, users (admin/manager/inactive).

---

## 4. Проверки

| Проверка | Результат |
|---|---|
| `php artisan test` | **30 passed** (95 assertions) |
| `/login` | 200 |
| `/dashboard` (гость) | 302 → `/login` |
| `/` | 302 → `/dashboard` |
| `POST /api/v1/inquiries` без заголовков | 401 |
| `migrate:fresh --seed` | успешно |
| Повторный `db:seed` | успешно (идемпотентность) |

---

## 5. Инструменты

- Docker daemon запущен локально через Docker Desktop;
- `sudo` недоступен (пароль), поэтому Homebrew PHP не установлен — использован Docker;
- Node/npm недоступны локально (frontend build на этап не выполнялся).

---

## 6. Расхождения и ограничения

1. **PHP 8.4 вместо 8.3** — Laravel 13.35.0 требует `>= 8.4.1`. Для production требует решения (open question Q-2).
2. Frontend (Vite/npm) не запускался — `npm run build` не выполнялся.
3. Livewire не установлен — UI на Blade.
4. Live delivery в рекламное приложение выключена; outbox пишется.
