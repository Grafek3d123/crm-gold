# Архитектура CRM: Золото Петербурга

## 1. Общая архитектура

```text
                        ┌─────────────────────────────┐
                        │      us812.ru (лендинг)     │
                        │   GitHub Pages / статика    │
                        └─────────────┬───────────────┘
                                      │
                        ┌─────────────┴───────────────┐
                        │   zoloto-spb.ru (MODX)      │
                        │   ювелирная студия           │
                        └─────────────┬───────────────┘
                                      │
                        ┌─────────────┴───────────────┐
                        │   crm.zoloto-spb.ru         │
                        │   Laravel CRM (отдельное)   │
                        │                             │
                        │   ┌─────────────────────┐   │
                        │   │ REST API v1         │   │
                        │   │ + auth (HMAC+key)   │   │
                        │   └──────────┬──────────┘   │
                        │              │              │
                        │   ┌──────────┴──────────┐   │
                        │   │ Domain layer        │   │
                        │   │ (Services/Actions)  │   │
                        │   └──────────┬──────────┘   │
                        │              │              │
                        │   ┌──────────┴──────────┐   │
                        │   │ PostgreSQL          │   │
                        │   │ + queue (database)  │   │
                        │   └─────────────────────┘   │
                        └─────────────────────────────┘
                                      │
              ┌───────────────────────┼───────────────────────┐
              │                       │                       │
              ▼                       ▼                       ▼
        Yandex API               Email (SMTP)           Yandex Metrica
        (конверсии)             (уведомления)           (данные)
```

**Ключевые улучшения исходной схемы:**
- Оба сайта отправляют данные в CRM через **один и тот же API** — сайты не зависят друг от друга.
- CRM — отдельное приложение, **не зависит от MODX**. MODX остаётся только источником лидов.
- Внутри CRM чёткие слои: API → Domain → PostgreSQL.
- Yandex-конверсии, email, queue — отдельные исходящие потоки.

## 2. Основные принципы

| Принцип | Реализация |
|---|---|
| Независимость от MODX | CRM не читает MODX-БД, не использует MODX-шаблоны. Только приём данных через API. |
| Единая CRM | Оба сайта пишут в одну БД, один набор сущностей. |
| API-first | Весь функционал доступен через REST API. Frontend — потребитель. |
| Безопасность | API key + HMAC, rate limiting, валидация, idempotency. |
| Расширяемость | Справочники `sites`, `sources`, `campaigns` — не хардкод. |
| Автоматизация | Laravel Events + queue для Yandex, email, аудит. |
| Несколько менеджеров | RBAC, сессии, audit log. |
| Третий сайт | Добавление site = добавление записи в `sites`. Никакого кода. |
| Рекламные интеграции | Yandex Direct API, ClientID, yclid, UTM — модуль attribution. |

## 3. Модули CRM

```
app/
├── Models/                  # Eloquent models
├── Http/
│   ├── Controllers/
│   │   ├── Api/             # Внешние API (сайты)
│   │   │   ├── LeadController.php
│   │   │   ├── OrderController.php
│   │   │   ├── PaymentController.php
│   │   │   └── ConversionController.php
│   │   └── Web/             # CRM UI (менеджеры)
│   │       ├── DashboardController.php
│   │       ├── LeadController.php
│   │       ├── CustomerController.php
│   │       ├── DealController.php
│   │       ├── OrderController.php
│   │       ├── PaymentController.php
│   │       ├── MarketingController.php
│   │       ├── SettingsController.php
│   │       └── UserController.php
│   ├── Requests/            # Form Request валидация
│   ├── Resources/           # API Resources (JSON)
│   └── Middleware/
│       ├── ApiKeyAuth.php
│       ├── HmacSignature.php
│       ├── Idempotency.php
│       ├── RateLimit.php
│       └── Auth.php
├── Services/
│   ├── Lead/
│   │   ├── LeadService.php
│   │   └── LeadQualificationService.php
│   ├── Customer/
│   │   ├── CustomerService.php
│   │   └── CustomerDeduplicator.php
│   ├── Deal/
│   │   └── DealService.php
│   ├── Order/
│   │   ├── OrderService.php
│   │   └── PaymentService.php
│   ├── Attribution/
│   │   ├── TouchpointService.php
│   │   └── AttributionService.php
│   ├── Yandex/
│   │   ├── YandexClient.php
│   │   └── ConversionService.php
│   ├── Duplicate/
│   │   └── DuplicateDetector.php
│   ├── Notification/
│   │   └── NotificationService.php
│   └── Audit/
│       └── AuditService.php
├── Events/                  # LeadCreated, PaymentReceived ...
├── Listeners/               # SendYandexConversion, CreateActivity ...
├── Jobs/                    # Асинхронные задачи
├── Policies/                # RBAC проверки
├── Providers/               # Service Providers
├── Support/                 # Утилиты
│   ├── PhoneNormalizer.php
│   ├── EmailNormalizer.php
│   ├── Money.php
│   └── IdGenerator.php
└── Integrations/
    ├── Yandex/
    │   ├── YandexDirectClient.php
    │   └── YandexMetricaClient.php
    └── Email/
        └── EmailService.php
```

### Где что

- **API** — `Http/Controllers/Api/` + `Http/Resources/`
- **CRM UI** — `Http/Controllers/Web/`
- **Бизнес-логика** — `Services/*`
- **Attribution** — `Services/Attribution/`
- **Yandex** — `Services/Yandex/` + `Integrations/Yandex/`
- **Лиды** — `Services/Lead/LeadService`