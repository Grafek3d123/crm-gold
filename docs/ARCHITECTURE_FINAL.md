# ARCHITECTURE_FINAL — CRM `crm.zoloto-spb.ru`

> **Status:** FINAL (rev.2, aligned with ТЗ №4), ready for implementation review.
> **Canonical basis:** Koda Code architecture (`ARCHITECTURE(1).md`) is canonical; Kilo Code materials (`Archive.zip`) were used as production/SWeb/API sanity-check; **ТЗ №4 (`tasks/Task04.md`) is the authoritative requirements source.**
> **Rev.2 changes vs rev.1:** terminology aligned with ТЗ №4 (`inquiry`/`lead` split), new entities: `contracts`, `expenses`, `files`; order lifecycle with production status and completion; financial metrics split (estimate vs actual, profit only after completion); **direct Yandex integration removed — CRM delivers business events to the separate advertising application via a webhook contract** (§14).
> **This document supersedes all previous architecture drafts.**
> **Scope of this document:** architecture only. No code, migrations, models, controllers, routes, UI, production or deployment artifacts are part of this deliverable.

---

## Table of Contents

1. [Goals](#1-goals)
2. [Constraints](#2-constraints)
3. [Architecture Diagram](#3-architecture-diagram)
4. [Stack](#4-stack)
5. [Domain Model](#5-domain-model)
6. [Database Model](#6-database-model)
7. [Customer Identity & Matching](#7-customer-identity--matching)
8. [Attribution](#8-attribution)
9. [Lifecycle: Inquiry → Lead → Contract → Order → Payment](#9-lifecycle-inquiry--lead--contract--order--payment)
10. [API](#10-api)
11. [Security](#11-security)
12. [Authentication & RBAC](#12-authentication--rbac)
13. [Queue & Transactional Outbox](#13-queue--transactional-outbox)
14. [Separate Advertising Application Integration](#14-separate-advertising-application-integration)
15. [CRM UI](#15-crm-ui)
16. [Testing Strategy](#16-testing-strategy)
17. [Deployment](#17-deployment)
18. [SWeb Constraints (Runbook Level)](#18-sweb-constraints-runbook-level)
19. [MVP Core](#19-mvp-core)
20. [MVP Integrations](#20-mvp-integrations)
21. [V2 / Backlog](#21-v2--backlog)
22. [ADR](#22-adr)
23. [Risks](#23-risks)
24. [Open Questions](#24-open-questions)

---

## 1. Goals

Build a **standalone Laravel CRM** at `crm.zoloto-spb.ru` that:

1. Accepts **inquiries and marketing events** from independent websites (`us812.ru`, `zoloto-spb.ru`) via a hardened REST API; supports **manually registered incoming calls** as first-class inquiries.
2. Maintains a **single Customer card** per physical person or organization, with reliable matching and manual merge.
3. Tracks the **commercial lifecycle**: Inquiry → Lead → Contract → Order → Payment, with real (paid) revenue as the only source of financial truth; supports **repeated negotiation rounds and price approvals** (activities + lead status history).
4. Manages **Contracts** (number, status, prepared/sent/signed dates, file, contract sum with components, change history) and **Orders with costs** (payments, categorized expenses, estimate-vs-actual split, overall `in_production` status, completion with final result calculation).
5. Stores **marketing attribution history** (touchpoints) as the canonical source of truth: first touch, last touch, full history, UTM, Yandex ClientID, yclid; **never guesses an unknown source**.
6. **Delivers business events to the separate advertising application** (Yandex Direct/Metrica management lives there, **not** in CRM) through a transactional outbox with a versioned webhook contract (§14): inquiry/lead/contract/payment/order events, idempotent delivery, no financial duplication on redelivery.
7. Provides a **CRM UI** for managers/admins (Blade + Livewire) with file/sketch/document storage, audit logging and access rights.
8. Is **fully independent of MODX**: no dependency on MODX database, structure, or code; site forms keep sending their own email notifications and additionally submit to CRM API.

### Non-goals (first MVP)

Telegram/WhatsApp, telephony, call tracking, full email client, calendar, complex task manager, BI, multi-touch attribution models, 2FA, Horizon, React, microservices. **Direct Yandex Direct/Metrica management and ad adjustment — belongs to the advertising application, not CRM.** Detailed production stage tracking (per-op steps), messengers. The architecture must allow adding these later without rewrites.

---

## 2. Constraints

### 2.1 Production environment (SWeb shared hosting) — verified

| Constraint | Consequence |
|---|---|
| Production site runs **PHP 8.3** (domain-level handler) | Web runtime is correct for Laravel 13 |
| Default CLI `php` on SWeb may be **PHP 5.2** | **All CLI commands must explicitly use `php8.3`** (artisan, composer) |
| Composer is a separate binary (`~/composer` / `composer.phar`) with its own PHP config | Run `php8.3 ~/composer.phar install`, or configure PATH accordingly |
| PostgreSQL lives on an **external SWeb PostgreSQL host** | DB credentials in `.env`; connection latency considered; no local socket assumptions |
| Web root is Laravel `public/`; SWeb convention `public_html` | `public_html → public` symlink (already present on the app server) |
| **No Docker, no systemd, no Supervisor** guaranteed | Queue workers and scheduler run via **cron** (see §13.4, §18) |
| Production must not depend on a Git repository at runtime | Deploy = upload/rsync artifact; Git lives on MacBook + GitHub |
| Laravel 13 already installed at `~/crm_zoloto_spb_ru` (`vendor/` present) | Architecture targets this existing installation path |

### 2.2 Architectural constraints

- No MODX coupling (DB, code, structure).
- Money is never float/double (see ADR-004).
- Orders and Payments are immutable financial history — never physically deleted, never soft-deleted (see ADR-011).
- Yandex ClientID is **not** a person's unique identity (see ADR-009).
- API must be idempotent for inquiry/order/payment ingestion (see ADR-015).
- **CRM does not manage ad campaigns or send events directly to Yandex.** The separate advertising application owns Yandex Direct/Metrica integration. CRM delivers business events via an outbound webhook contract (§14).
- **Forecast profit is never stored or transmitted as fact.** Final profit is computed only after order completion + expense confirmation (see §9.8).
- **Customer's metal (material supplied by the client) is never treated as an expense that reduces profit** (see §9.8).
- The advertising application's webhook endpoints/formats must be re-verified against its documentation **immediately before implementation** — no invented contracts (see §14).

---

## 3. Architecture Diagram

```text
us812.ru ────────────┐
                     │  REST API / JSON (HMAC-signed)
zoloto-spb.ru ───────┤        (site forms keep sending their own emails)
                     ▼
   manual incoming   ┌────────────────────────────────┐
   calls (entered    │        Laravel CRM             │
   in CRM UI) ──────►│      crm.zoloto-spb.ru         │
                     │                                │
                     │  ┌──────────────────────────┐  │
                     │  │ API Layer (v1)           │  │  X-Api-Key / HMAC / Idempotency
                     │  ├──────────────────────────┤  │
                     │  │ Actions (use cases)      │  │  IngestInquiry, RegisterPayment, ...
                     │  ├──────────────────────────┤  │
                     │  │ Domain: state machines,  │  │  Enums, transitions, policies
                     │  │ matching, attribution,   │  │  contracts, orders, expenses,
                     │  │ finance (estimate/actual)│  │  profit calculation
                     │  ├──────────────────────────┤  │
                     │  │ CRM UI (Blade/Livewire)  │  │  Sanctum session, RBAC, files
                     │  ├──────────────────────────┤  │
                     │  │ Queue (database driver)  │  │  cron workers + scheduler
                     │  ├──────────────────────────┤  │
                     │  │ Outbox: business_events  │──┼──► Job ──► Integrations/AdvertisingApp
                     │  └──────────────────────────┘  │      (HMAC-signed webhook)
                     └──────────────┬─────────────────┘
                                    ▼
                          PostgreSQL (SWeb external host)

   ┌────────────────────────────┐   outbound HTTPS (webhook, §14)
   │   Advertising application  │ ◄── business + financial events
   │ (Yandex Direct / Metrica)  │     CRM never calls Yandex itself
   └────────────────────────────┘
```

MODX is **not** in this diagram. It does not exist as far as the CRM domain is concerned. Yandex is **not** in this diagram either — the advertising application owns that boundary.

---

## 4. Stack

| Layer | Choice | Notes |
|---|---|---|
| Runtime | **PHP 8.3** | CLI must be invoked as `php8.3` |
| Framework | **Laravel 13** | Already installed on server |
| DB | **PostgreSQL** | SWeb external host |
| Templating | **Blade** | |
| Interactive UI | **Livewire 3** | Kept in MVP (ADR-002) |
| JS micro-interactions | **Alpine.js** | Ships with Livewire |
| CSS | **Tailwind CSS** | |
| Asset build | **Vite** | Built on MacBook, artifacts deployed |
| CRM auth/session | **Laravel Sanctum** (SPA/session mode) | For Livewire/XHR session interaction |
| Queue | **database driver** | No Horizon in MVP (ADR-013) |
| Scheduler/queue run | **cron** + `php8.3 artisan schedule:run`, `queue:work --stop-when-empty` | See §13.4 |
| API auth (machine) | **HMAC-SHA256** scheme (§11) | Not Sanctum — different credential class |
| SPA frameworks | **Not used** | React/Vue require separate ADR |

---

## 5. Domain Model

### 5.1 Core entities

```text
users                       CRM operators (admin, manager)

customers                   single card per physical client
customer_identities         external identifiers (yandex_client_id, yclid, future)

sites                       us812.ru, zoloto-spb.ru (+ extensible)
sources                     marketing sources (utm_source normalization dictionary)

marketing_events            technical events: visit, page_view, form_submit, click
touchpoints                 CRM attribution interactions (source of truth)

inquiries                       incoming inquiries
inquiry_status_history         append-only state transitions

leads                       qualified opportunities
lead_status_history         append-only state transitions

contracts                   standalone contracts (number, status, dates, file, sum + components, history)

products                    catalog reference data
orders                      commercial orders (financial history, immutable)
order_items                 snapshot lines (name, qty, unit_price at order time)
expenses                    categorized costs per order (metal, stones, work…)
expense_categories          reference: metal, stones, modeling, printing, casting, finishing, setting,
                            hallmarking, packaging, delivery, other

payments                    payment records (financial history, immutable)

business_events             internal outbox for outbound events to the advertising application
                             (renamed from conversion_events)

notes                       manager comments
activities                  timeline entries (call, email, status change, system, file_added)

audit_logs                  security/compliance trail (append-only)

api_idempotency_keys        deduplication of critical API operations
```

### 5.2 Key relationships

```text
Customer 1─N CustomerIdentity
Customer 1─N Inquiry
Customer 1─N Lead
Customer 1─N Contract
Customer 1─N Order
Customer 1─N Touchpoint
Customer 1─N Note / Activity

Inquiry    N─1 Site
Inquiry    N─1 Source (nullable, resolved from UTM)
Inquiry    1─N InquiryStatusHistory
Inquiry    1─0..1 Lead                      (qualification creates Lead)

Lead    N─1 Customer
Lead    1─N LeadStatusHistory
Lead    1─0..N Contract                     (a contract belongs to a lead; lead may have
                                            several drafts before a signed one)

Contract N─1 Customer
Contract N─1 Lead (nullable), N─1 Order (nullable — order may appear after signing)
Contract 1─N ContractSumComponent           (contract sum and its components)
Contract 1─N ContractStatusHistory          (append-only)

Order   N─1 Customer
Order   N─1 Lead (nullable)
Order   N─1 Contract (nullable — order usually follows a signed contract)
Order   1─N OrderItem
Order   N─1 Product via OrderItem (snapshot fields coexist)
Order   1─N Payment
Order   1─N Expense
Order   1─N File (sketches, photos, calculations, documents)

Expense N─1 Order (NOT NULL), N─1 ExpenseCategory,
        N─1 File (optional quote/receipt), material_origin enum:
        `workshop` (paid by workshop) | `customer` (client's own metal/stones)

Payment N─1 Contract (nullable), N─1 Order (NOT NULL)
File    polymorphic attachment (inquiry/lead/contract/order/expense) + uploader user

Touchpoint N─1 Site, N─1 Customer (nullable until matched),
           N─1 Inquiry/Lead context (nullable), N─1 Source (nullable)

MarketingEvent N─1 Site; linked to Touchpoint when promoted (touchpoint.marketing_event_id)

BusinessEvent (outbox) N─1 any source entity (inquiry/lead/contract/order/payment) via
             (entity_type, entity_id)

User   1─N AuditLog (actor); User 1─N Lead (responsible)
```

### 5.3 Business invariants

1. **One Customer per matched person/organization** — matching rules in §7; duplicates only via manual merge.
2. **Revenue = confirmed Payments only.** `Inquiry ≠ Revenue`, `Lead ≠ Revenue`, `Contract sent ≠ Revenue`, `Contract signed ≠ Revenue`, `Order ≠ Paid Revenue`.
3. **Touchpoints are append-only attribution truth.** First/last touch on Customer/Lead are projections, never the source.
4. **ClientID alone never merges Customers** — it is a hint stored in `customer_identities`, not a matching key of first priority.
5. **Orders/Payments are never deleted.** Cancellation/refund are state transitions.
6. **Forecast profit is never recorded or exposed as fact.** Only estimate (explicitly flagged) exists until the order is completed and all expenses are confirmed actual (§9.8).
7. **Client-supplied metal/stones (`material_origin = customer`) never reduce profit** — they are recorded for traceability but excluded from cost sums (§9.8).
8. **Every critical mutation writes an AuditLog entry.**

---

## 6. Database Model

Conventions: PostgreSQL; `BIGSERIAL` PKs; FKs indexed; `created_at`/`updated_at` `TIMESTAMPTZ`; **all timestamps stored in UTC**; money `NUMERIC(12,2)`; enums stored as `VARCHAR(32)` + PHP backed enum (see ADR-010); JSON payloads as `JSONB`.

### 6.1 users

| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL | PK |
| name | VARCHAR(120) | |
| email | VARCHAR(190) | UNIQUE |
| password | VARCHAR | hashed |
| role | VARCHAR(16) | enum: `admin`, `manager` |
| is_active | BOOLEAN | default true |
| last_login_at | TIMESTAMPTZ | |
| soft deletes | yes | `deleted_at` |

### 6.2 sites

| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL | PK |
| domain | VARCHAR(190) | UNIQUE (`us812.ru`, `zoloto-spb.ru`) |
| name | VARCHAR(120) | |
| is_active | BOOLEAN | |

Seed: exactly the two production sites.

### 6.3 sources

| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL | PK |
| code | VARCHAR(64) | UNIQUE (normalized `utm_source` value) |
| name | VARCHAR(120) | |
| medium | VARCHAR(64) | default medium mapping, nullable |

### 6.4 customers

| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL | PK |
| name | VARCHAR(160) | nullable (may arrive nameless) |
| phone | VARCHAR(32) | **normalized** (E.164-like digits), indexed |
| phone_raw | VARCHAR(64) | as entered |
| email | VARCHAR(190) | **normalized** (lowercase, trimmed), indexed |
| email_raw | VARCHAR(190) | as entered |
| comment | TEXT | free-form |
| merged_into_id | BIGINT FK→customers | set when this card was manually merged into another |
| first_touch_at / last_touch_at | TIMESTAMPTZ | projection cache (ADR-008) |
| first_touch_source_id / last_touch_source_id | BIGINT FK→sources | projection cache |
| soft deletes | yes | |

**No `yandex_client_id` column on customers** (ADR-006/009). Uniqueness of a person is **not** enforced by a single DB constraint; it is enforced by the matching Action + partial unique indexes below.

Recommended partial unique indexes (allow NULLs, prevent exact-duplicate active cards):

```sql
CREATE UNIQUE INDEX customers_phone_uniq ON customers (phone)
  WHERE phone IS NOT NULL AND merged_into_id IS NULL;
CREATE UNIQUE INDEX customers_email_uniq ON customers (email)
  WHERE email IS NOT NULL AND merged_into_id IS NULL;
```

> Rationale: a phone/email can arrive without the other; partial unique indexes keep the strongest automatic guarantee without blocking legitimately sparse cards. Matching logic (§7) runs before insert; the index is the last-resort race guard.

### 6.5 customer_identities

| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL | PK |
| customer_id | BIGINT FK | NOT NULL |
| type | VARCHAR(32) | enum: `yandex_client_id`, `yclid`, future |
| value | VARCHAR(190) | NOT NULL |
| source_site_id | BIGINT FK→sites | where it was observed |
| verified_at | TIMESTAMPTZ | nullable; set by human/system confirmation |
| created_at / updated_at | TIMESTAMPTZ | |

```sql
CREATE UNIQUE INDEX cust_identity_uniq ON customer_identities (type, value);
CREATE INDEX cust_identity_customer ON customer_identities (customer_id);
```

Uniqueness here is **identity-scoped** (one ClientID maps to one current card), which is *different* from person-uniqueness — a shared-device ClientID is simply "the last card we associated with it". This never triggers automatic person merge (§7).

### 6.6 inquiries

| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL | PK |
| customer_id | BIGINT FK | NOT NULL (create-or-find happens atomically, §7.4) |
| site_id | BIGINT FK | NOT NULL |
| source_id | BIGINT FK | nullable, resolved from utm_source |
| status | VARCHAR(32) | enum: `new`, `qualified`, `converted`, `rejected`, `duplicate`, `archived` |
| phone / email | VARCHAR | normalized copies as received |
| name | VARCHAR(160) | |
| message | TEXT | |
| landing_url | VARCHAR(500) | |
| referrer | VARCHAR(500) | |
| utm_source / utm_medium / utm_campaign / utm_content / utm_term | VARCHAR(190) | five columns |
| utm_raw | JSONB | full original params |
| yandex_client_id | VARCHAR(190) | indexed |
| yclid | VARCHAR(190) | indexed |
| ip_hash | VARCHAR(64) | SHA-256 of IP + salt, privacy (§11.5) |
| user_agent | VARCHAR(500) | truncated |
| external_id | VARCHAR(190) | source-system id, nullable |
| meta | JSONB | other source metadata |
| soft deletes | yes | |

Indexes: customer_id, site_id, status, created_at, yandex_client_id, yclid, external_id.

### 6.7 inquiry_status_history (append-only)

| Column | Type |
|---|---|
| id | BIGSERIAL |
| inquiry_id | BIGINT FK NOT NULL |
| from_status | VARCHAR(32) NULL (initial) |
| to_status | VARCHAR(32) NOT NULL |
| user_id | BIGINT FK NULL (system = NULL) |
| comment | TEXT NULL |
| created_at | TIMESTAMPTZ |

No updates/deletes; no soft delete.

### 6.8 leads

| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL | PK |
| customer_id | BIGINT FK | NOT NULL |
| inquiry_id | BIGINT FK | nullable (leads can be created manually) |
| status | VARCHAR(32) | enum: `new`, `in_progress`, `won`, `lost`, `cancelled` |
| responsible_user_id | BIGINT FK→users | nullable |
| amount_expected | NUMERIC(12,2) | nullable |
| closed_at | TIMESTAMPTZ | |
| soft deletes | yes | |

### 6.9 lead_status_history — same shape as inquiry_status_history (`lead_id` instead of `inquiry_id`), append-only.

### 6.10 products

| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL | PK |
| sku | VARCHAR(64) | UNIQUE, nullable |
| name | VARCHAR(190) | |
| unit_price | NUMERIC(12,2) | current reference price |
| is_active | BOOLEAN | |

### 6.11 contracts (Task04 §6)

| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL PK | |
| number | VARCHAR(64) | UNIQUE, human contract number |
| customer_id | BIGINT FK→customers | NOT NULL |
| lead_id | BIGINT FK→leads | nullable |
| order_id | BIGINT FK→orders | nullable — order may appear after signing |
| status | VARCHAR(32) | `draft` → `sent` → `signed` → `paid` → `rejected` / `cancelled`; history in contract_status_history |
| contract_date | DATE | contract date |
| signed_date | DATE | actual signing date |
| file_id | BIGINT FK→files | nullable — signed contract file |
| sum | NUMERIC(14,2) | contract sum |
| valid_until | DATE | optional validity period |
| created_by | BIGINT FK→users | |
| created_at / updated_at | TIMESTAMPTZ | |

`contract_status_history`: contract_id, from_status, to_status, changed_by, comment, created_at.
`contract_sum_components`: contract_id, kind enum (`metal`, `stones`, `work`, `other`), name, amount — components of the contract sum.

> A sent/signed contract is **not** revenue. Only Payments booked against the contract count. A contract may be sent-but-not-signed, or signed-but-not-paid; both are tracked by status + history.

### 6.12 orders — commercial orders (financial history, immutable)

| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL PK | |
| number | VARCHAR(64) | UNIQUE, human order number |
| customer_id | BIGINT FK→customers | NOT NULL |
| lead_id | BIGINT FK→leads | nullable |
| contract_id | BIGINT FK→contracts | nullable — order usually follows a signed contract |
| status | VARCHAR(32) | `draft` → `confirmed` → `in_production` → `delivered` / `cancelled` / `completed`; history in order_status_history |
| amount | NUMERIC(14,2) | order amount |
| sum_contract | NUMERIC(14,2)? | agreed contract sum |
| sum_paid | NUMERIC(14,2)? | total paid amount |
| sum_expenses | NUMERIC(14,2)? | total expenses |
| estimated_profit | NUMERIC(14,2)? | estimate only (sum_contract - sum_expenses) |
| actual_profit | NUMERIC(14,2)? | **only after completion**, sum_contract - actual expenses |
| confirmed_at | TIMESTAMPTZ | |
| completed_at | TIMESTAMPTZ | order completion |
| created_at / updated_at | TIMESTAMPTZ | **no deleted_at** |

> **Estimated profit is never recorded as fact.** `actual_profit` is set only when `status = completed` and all expenses are confirmed actual (Task04 §6, §8).
> **Client-supplied metal (`material_origin = customer`) never reduces profit.** It is recorded for traceability but excluded from cost sums (Task04 §6).

### 6.13 order_items — snapshot at order time

| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL PK | |
| order_id | BIGINT FK→orders | NOT NULL |
| product_id | BIGINT FK→products | nullable |
| name | VARCHAR(255) | snapshot |
| quantity | NUMERIC(10,3) | |
| unit_price | NUMERIC(14,2) | snapshot |
| total | NUMERIC(14,2) | qty × unit_price |
| metal_weight | NUMERIC(10,3)? | snapshot |
| insert_weight | NUMERIC(10,3)? | snapshot |
| created_at | TIMESTAMPTZ | |

Historical order totals never change when `products.unit_price` changes.

### 6.14 expenses (Task04 §6)

| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL PK | |
| order_id | BIGINT FK→orders | NOT NULL |
| category_id | BIGINT FK→expense_categories | |
| name | VARCHAR(255) | |
| amount | NUMERIC(14,2) | expense amount |
| cost_kind | VARCHAR(16) | `estimate` (предварительная) / `actual` (фактическая) — Task04 §7 |
| expense_date | DATE | |
| material_origin | VARCHAR(32) | `workshop` (paid by workshop) / `customer` (client's own metal/stones) |
| quote_file_id | BIGINT FK→files | optional quote/receipt |
| created_by | BIGINT FK→users | |
| created_at / updated_at | TIMESTAMPTZ | |

`expense_categories`: id, name (metal, stones, modeling, printing, casting, finishing, setting, hallmarking, packaging, delivery, other).

> **Client-supplied metal/stones (`material_origin = customer`) never reduce profit.** They are recorded for traceability but excluded from cost sums.

### 6.15 payments — financial history, immutable (ADR-011)

| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL | PK |
| order_id | BIGINT FK | NOT NULL |
| contract_id | BIGINT FK | nullable — payment links to order **and** contract (Task04 §7) |
| customer_id | BIGINT FK | denormalized for fast revenue queries |
| kind | VARCHAR(32) | enum (Task04 §7): `advance` (first prepayment — key advertising signal), `progress` (additional / stones prepayment), `final_settlement`, `refund` |
| amount | NUMERIC(12,2) | NOT NULL |
| currency | CHAR(3) | default `RUB` |
| status | VARCHAR(32) | enum: `registered`, `confirmed`, `reversed`, `cancelled` |
| paid_at | TIMESTAMPTZ | actual payment time |
| external_id | VARCHAR(190) | idempotency anchor |
| meta | JSONB | |
| created_at / updated_at | TIMESTAMPTZ | **no deleted_at** |

```sql
CREATE UNIQUE INDEX payments_order_external_uniq ON payments (order_id, external_id)
  WHERE external_id IS NOT NULL;
```

Payments are the **only** source of revenue. Wrong payment ⇒ `reversed`/`cancelled` status transition + optional corrective payment; refunds are `refund`-kind rows. Never DELETE.

### 6.16 files — uploaded documents (Task04 §5, §6)

| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL | PK |
| attachable_type / attachable_id | polymorphic | inquiry / lead / contract / order / expense |
| kind | VARCHAR(32) | `contract`, `quote`, `sketch`, `photo`, `calculation`, `document`, `other` |
| disk | VARCHAR(32) | private disk |
| path | VARCHAR(512) | private storage path, never web-served directly |
| original_name | VARCHAR(255) | |
| mime | VARCHAR(128) | |
| size | BIGINT | |
| uploaded_by | BIGINT FK→users | |
| created_at | TIMESTAMPTZ | |

Covers Task04 §5: links, photos, sketches, calculations, documents. Access through CRM UI with RBAC only.

### 6.17 marketing_events

| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL | PK |
| site_id | BIGINT FK | NOT NULL |
| client_key | VARCHAR(64) | site-side anonymous session key (cookie), nullable |
| yandex_client_id | VARCHAR(190) | indexed |
| yclid | VARCHAR(190) | indexed |
| event_type | VARCHAR(32) | enum: `visit`, `page_view`, `form_submit`, `click`, `other` |
| landing_url | VARCHAR(500) | |
| referrer | VARCHAR(500) | |
| utm_source / medium / campaign / content / term | VARCHAR(190) | |
| utm_raw | JSONB | |
| fp_hash | VARCHAR(64) | safe technical fingerprint hash, nullable (§11.5) |
| payload | JSONB | event-specific data |
| occurred_at | TIMESTAMPTZ | NOT NULL, indexed |

Retention policy: see §24 (open question OQ-4). Partitioning by month is the recommended mechanism if volume requires it.

### 6.18 touchpoints — canonical attribution history (ADR-007), append-only

| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL | PK |
| site_id | BIGINT FK | NOT NULL |
| customer_id | BIGINT FK | nullable until/at match time |
| inquiry_id | BIGINT FK | nullable context |
| lead_id | BIGINT FK | nullable context |
| marketing_event_id | BIGINT FK | nullable — set when promoted from a marketing event (§8.3) |
| touch_type | VARCHAR(32) | enum: `visit`, `inquiry`, `call`, `email`, `order`, `other` |
| source_id | BIGINT FK | nullable, resolved from UTM |
| landing_url | VARCHAR(500) | |
| referrer | VARCHAR(500) | |
| utm_source / medium / campaign / content / term | VARCHAR(190) | |
| yandex_client_id | VARCHAR(190) | indexed |
| yclid | VARCHAR(190) | indexed |
| occurred_at | TIMESTAMPTZ | NOT NULL |
| meta | JSONB | |

Indexes: customer_id + occurred_at, yandex_client_id, yclid, inquiry_id, lead_id.

### 6.19 business_events — internal outbox (ADR-012)

| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL | PK |
| event_type | VARCHAR(32) | enum: `inquiry_received`, `qualified_lead`, `contract_sent`, `contract_signed`, `first_deposit_received`, `payment_received`, `order_completed` |
| entity_type | VARCHAR(32) | `inquiry`, `lead`, `contract`, `order`, `payment` |
| entity_id | BIGINT | |
| payload | JSONB | snapshot of data needed by consumers |
| target | VARCHAR(32) | enum: `advertising_app`, `email`, `internal` |
| status | VARCHAR(16) | enum: `pending`, `processing`, `sent`, `failed`, `abandoned` |
| attempts | INT | default 0 |
| next_attempt_at | TIMESTAMPTZ | backoff control |
| processed_at | TIMESTAMPTZ | |
| last_error | TEXT | |
| event_key | VARCHAR(190) | UNIQUE idempotency/external event key |
| created_at | TIMESTAMPTZ | |

Index on (status, next_attempt_at) for the dispatcher.

### 6.20 notes

| Column | Type |
|---|---|
| id | BIGSERIAL |
| customer_id | BIGINT FK NOT NULL |
| lead_id / inquiry_id / order_id | BIGINT FK NULL (context) |
| user_id | BIGINT FK NOT NULL |
| body | TEXT |
| soft deletes | yes |

### 6.21 activities — append-only timeline

| Column | Type |
|---|---|
| id | BIGSERIAL |
| subject_type / subject_id | polymorphic (customer/inquiry/lead/order/payment) |
| type | VARCHAR(32) — `note`, `call`, `email`, `status_changed`, `created`, `merged`, `system` |
| user_id | BIGINT FK NULL (system = NULL) |
| description | TEXT |
| properties | JSONB (diff/context) |
| created_at | TIMESTAMPTZ |

### 6.22 audit_logs — append-only, **no soft delete** (ADR-011 class)

| Column | Type |
|---|---|
| id | BIGSERIAL |
| actor_user_id | BIGINT FK NULL (system = NULL) |
| action | VARCHAR(64) — `login`, `logout`, `login_failed`, `customer.updated`, `customer.merged`, `inquiry.status_changed`, `order.status_changed`, `payment.registered`, `settings.changed`, ... |
| subject_type / subject_id | polymorphic |
| old_values | JSONB |
| new_values | JSONB |
| ip_hash | VARCHAR(64) |
| user_agent | VARCHAR(500) |
| created_at | TIMESTAMPTZ |

### 6.23 api_idempotency_keys

| Column | Type | Notes |
|---|---|---|
| id | BIGSERIAL | PK |
| site_id | BIGINT FK | scoping per credential |
| operation | VARCHAR(32) | `inquiries.create`, `orders.create`, `payments.create` |
| idempotency_key | VARCHAR(190) | |
| request_hash | VARCHAR(64) | SHA-256 of body; mismatch ⇒ 422 (key reuse with different payload) |
| response_code | SMALLINT | cached |
| response_body | JSONB | cached for replay |
| created_at / expires_at | TIMESTAMPTZ | TTL cleanup via scheduled job |

```sql
CREATE UNIQUE INDEX idempotency_uniq ON api_idempotency_keys (site_id, operation, idempotency_key);
```

### 6.24 Index summary

Covered throughout: phone, email (normalized, partial-unique), external ids (`inquiries.external_id`, `orders.external_id`, `payments(order_id, external_id)`), ClientID, yclid, `created_at`/`occurred_at` timestamps, all FKs, status columns, attribution fields (`customer_id + occurred_at` on touchpoints).

---

## 7. Customer Identity & Matching

### 7.1 Customer

Customer = single card of a **physical person/client**. Minimal fields: id, name, phone, email, comment, timestamps. Marketing identifiers (ClientID, yclid, future ids) live exclusively in `customer_identities` — Customer never becomes a dumping ground for identifiers.

### 7.2 Customer identities

Purpose: store Yandex ClientID, yclid, and future external identifiers without polluting Customer. One identity `(type, value)` is currently associated with exactly one card (unique index), **but this is device/cookie-scoped association, not person identity.**

### 7.3 Matching rules (`FindOrCreateCustomerAction`)

Priority ladder — evaluated in order, first match wins:

| # | Rule | Behaviour |
|---|---|---|
| 1 | **Normalized phone** (digits, leading 7/8 collapsed to 7) | exact match ⇒ existing Customer |
| 2 | **Normalized email** (lowercase, trimmed) | exact match ⇒ existing Customer |
| 3 | **phone + email both present and both match the same Customer** | confirmed match |
| 4 | **phone matches A, email matches B (conflict)** | **do not auto-resolve**: prefer phone match (A), attach identity hint, create `activity` flag for manager review; never merge A and B automatically |
| 5 | **External identifiers (ClientID/yclid)** | used only as a **display hint** ("possible related card") — never as automatic matching input |
| 6 | Nothing matched | create new Customer |

Hard rule: **`Yandex ClientID ≠ person identity`.** Two customers are never merged automatically by ClientID. Only a human can merge.

### 7.4 Atomicity

Customer create/find runs inside the same DB transaction as Inquiry creation and touchpoint recording (§13.3, Scenario 1). The partial unique indexes (§6.4) are the race guard: on conflict, re-run match → use existing row.

### 7.5 Manual merge (`MergeCustomersAction`)

- Allowed for: `admin` (and `manager` if policy opens it).
- Effect: keep target card; move inquiries, leads, orders, payments, touchpoints, notes, activities, identities to target; set `merged_into_id` on source; source is excluded from active matching automatically via `merged_into_id IS NULL` predicate in the unique indexes; write audit (`customer.merged`) + activity on both cards.
- Merge is **not reversible** by UI (documented); audit preserves full data.
- Identity conflicts during merge (same `(type,value)` on both cards): target wins; source identity rows are deleted (identity is association, not financial history — allowed).

---

## 8. Attribution

### 8.1 Source of truth

`touchpoints` is the canonical, append-only attribution history (ADR-007). Attribution is **never** represented as a bag of overwritten fields on Customer.

### 8.2 Projections

`customers.first_touch_at / last_touch_at / first_touch_source_id / last_touch_source_id` (and the same pair optionally cached on Lead) are **projections/cache** (ADR-008):

- Updated transactionally when a new touchpoint is recorded.
- Can be fully rebuilt from `touchpoints` by a console command (`attribution:rebuild`) — projections are disposable, history is not.

### 8.3 Marketing events vs touchpoints (kept distinct)

| | marketing_events | touchpoints |
|---|---|---|
| Nature | technical/analytics events: `visit`, `page_view`, `form_submit`, `click` | CRM business interactions: `inquiry`, `call`, `email`, `order`, meaningful `visit` |
| Link to Customer | usually anonymous (client_key / ClientID) | tied to Customer when known |
| Retention | policy-limited (§24 OQ-4) | kept as long as Customer |

Promotion pipeline:

```text
marketing event ──► attribution processing ──► touchpoint (marketing_event_id set)
                    (only when it becomes a CRM-relevant interaction)
```

Not every marketing event becomes a touchpoint. MVP promotion rules: (a) inquiry submission; (b) the first identified visit for a matched client key. Everything else stays in `marketing_events` only. No event-analytics platform is built.

### 8.4 Attribution context

For every Customer/Inquiry/Lead the UI shows **history, not only last value**: first touch, last touch, full touchpoint list, UTM, ClientID, yclid, source, landing, referrer (§15.3).

### 8.5 UTM

Five standard columns (`utm_source/medium/campaign/content/term`) + `utm_raw` JSONB everywhere UTM is accepted. `utm_source` is resolved to `sources.code` (dictionary); unresolved values auto-create inactive `sources` rows for review. Distinguish three concepts in UI/reports: **first touch**, **last touch**, **current inquiry attribution** (UTM carried by the inquiry itself).

---

## 9. Lifecycle: Inquiry → Lead → Contract → Order → Payment

### 9.1 Statuses: enums + state machines (ADR-010)

No lookup tables for statuses. PHP backed enums with explicit allowed transitions enforced in domain:

**Inquiry:** `new → qualified → converted`, `new → rejected`, `new → duplicate`, `any non-final → archived`.

**Lead:** `new → in_progress → won | lost`, `new | in_progress → cancelled`.

**Contract:** `draft → sent → signed → paid`, `draft | sent → cancelled`, `sent → rejected` (not signed), `signed → cancelled` (before any payment).

**Order:** `draft → confirmed → in_production → delivered → completed`, `any non-final → cancelled`.

**Payment:** `registered → confirmed`, `registered | confirmed → reversed | cancelled`.

Every transition writes to the corresponding `*_status_history` table (`inquiry_status_history`, `lead_status_history`, `contract_status_history`, `order_status_history` — append-only) plus an audit entry. Invalid transition ⇒ domain exception, never silent write.

### 9.2 Inquiry

Represents an incoming inquiry — a site form submission **or a manually registered incoming phone call** (created in CRM UI, `source_kind = call`). Required: `customer_id` (via §7 matching), `site_id` (or NULL for calls), status, contact fields, UTM (when from site), landing, referrer, ClientID, yclid, external id, meta. Creation is one atomic operation (§13.3).

**Repeat calls/emails on an existing inquiry are recorded as activities on the same inquiry — they never auto-create new leads or orders** (Task04 §5).

### 9.3 Inquiry → Lead

`ConvertInquiryToLeadAction`: validates transition, creates Lead (customer + inquiry + responsible + expected amount), sets inquiry `converted`, writes histories/audit. One inquiry converts to at most one lead; one customer may have many inquiries and many leads over time.

**Negotiation rounds and price approvals** (Task04 §4): repeated calls, emails, meetings and price agreements are stored as `activities` on the lead (`type = call|email|meeting|note`, `direction = in|out`), each with date and author. The full negotiation history is the activity timeline + lead status history — no separate "deal" entity.

### 9.4 Contract flow (Task04 §6)

`CreateContractAction`: number generation, status machine (§9.1), dates `prepared_at`/`sent_at`/`signed_at`, signed file stored in `files` (private disk), contract sum + components (`contract_sum_components`: metal/stones/work/other), change history (`contract_status_history`, append-only).

- **Sent-but-not-signed** and **signed-but-no-prepayment** contracts are reportable states (status + history queries).
- **Sending or signing a contract is never revenue.** Only payments count (§9.5).

### 9.5 Order, order items, expenses

`CreateOrderAction`: number generation (`ORD-YYYY-NNNNNN`), item snapshots, totals computed from lines, idempotent by `external_id`. Order links to its contract (`contract_id`) and lead.

Order totals and contract sums are **estimates until confirmed**; corrections after `confirmed` create status transitions or new documents per business rules, never silent rewrites.

`RegisterExpenseAction`: category (metal, stones, modeling, printing, casting, finishing, setting, hallmarking, packaging, delivery, other), amount, date, order, `cost_kind = estimate | actual`, `material_origin = workshop | customer`. Client's own metal (`customer`) is recorded but **never counted into costs/profit** (§9.8).

### 9.6 Payment & real revenue

`RegisterPaymentAction`: idempotent by `(order_id, external_id)`; payment kinds (Task04 §7): `advance` (first prepayment — the key advertising signal), `progress` (additional, incl. stones prepayment), `final_settlement`, `refund`. Each payment links to order **and contract**. Recalculates order paid sum. **Revenue for conversion/analytics = SUM(confirmed payments)** — nothing else.

### 9.7 Completion and financial result (Task04 §7)

`CompleteOrderAction`: allowed only from `delivered`; verifies all expenses are `actual`; computes and stores the final result:

| Metric | Meaning | When valid |
|---|---|---|
| `estimate_cost` | preliminary order cost | from draft |
| `contract_sum` | agreed contract sum | from contract signed |
| `revenue` | SUM(confirmed payments) | always (fact) |
| `actual_cost` | SUM(expenses where `cost_kind = actual` AND `material_origin = workshop`) | as expenses register |
| `final_cost` | final agreed cost | at completion |
| `profit` | `revenue − actual_cost` | **only after completion + expense confirmation** |

**Forecast profit is never stored or shown as fact** — UI marks it "estimate". **Client's metal never reduces profit.**

### 9.8 Financial immutability

Orders, payments and expenses: no soft delete, no physical delete (ADR-011). Cancel/refund/reverse are transitions. Corrections after completion are new rows (negative refund payment, corrective expense). `audit_logs` likewise append-only.

---

## 10. API

### 10.1 Versioning & endpoints

All under `/api/v1/`. Authenticated machine endpoints (HMAC, §11):

| Endpoint | Purpose | Idempotency |
|---|---|---|
| `POST /api/v1/inquiries` | site submits inquiry | **required** (`Idempotency-Key` or `external_id`) |
| `POST /api/v1/events` | site submits marketing events (batch) | best-effort (event key dedup) |
| `POST /api/v1/orders` | external system submits order | required (`external_id`) |
| `POST /api/v1/payments` | external system submits payment | required (`external_id`) |

**No `POST /api/v1/conversions` endpoint exists.** Business events are created only by CRM internals (§13.2).

CRM-UI-facing session/XHR endpoints are separate (Sanctum, same-origin) and not part of the machine API surface.

### 10.2 Responses

- Success returns created resource id + minimal projection.
- Idempotent replay returns the **cached original response** (§6.20).
- Key reuse with different body hash ⇒ `422 idempotency_key_reused`.
- Validation errors: structured field errors; no data leaked.

### 10.3 Inquiry ingestion flow (Scenario 1)

```text
POST /api/v1/inquiries
 → timestamp window check
 → HMAC signature verification
 → rate limit / credential scope (site)
 → validation
 → idempotency check (replay? return cached)
 → DB transaction:
     customer match/create ─► touchpoint(inquiry) ─► inquiry ─► status history
     ─► business_event(inquiry_received) ─► audit
 → commit
 → dispatch queue job (after commit)
 → 201 response
```

Scenario 2 (repeat inquiry): matching finds existing Customer via phone/email → new Inquiry + new Touchpoint + updated last-touch projection. **No second Customer.**

---

## 11. Security

### 11.1 Machine API authentication (ADR-014)

Headers:

```text
X-Api-Key       — identifies the site credential
X-Timestamp     — Unix seconds, client clock
X-Signature     — HMAC-SHA256
X-Request-Id    — (optional) client trace id
Idempotency-Key — (required for POST inquiries/orders/payments)
```

Signature:

```text
HMAC_SHA256(
  api_secret,
  METHOD    + "\n" +
  PATH      + "\n" +
  TIMESTAMP + "\n" +
  SHA256(BODY)
)
```

> Note: the digest of BODY (rather than raw BODY) is used inside the HMAC input for bounded-length signing; this is fixed by this architecture and must be mirrored in site-side signers.

Verification steps (all mandatory, fail-closed):

1. Timestamp within ±**300 s** window ⇒ else `401 stale_timestamp`.
2. `X-Api-Key` resolves to an active site credential.
3. Recompute HMAC; compare with `hash_equals` ⇒ else `401 invalid_signature`.
4. Rate limit per credential (e.g. 60 req/min burst; configurable).

### 11.2 Credentials

- Per-site `api_key/secret` pair — **different credentials per site** (us812.ru ≠ zoloto-spb.ru).
- Stored in CRM `.env` / server secrets (never in Git, never in frontend JS).
- Rotation procedure: two active secrets per site during migration window (documented runbook step).

### 11.3 Idempotency (ADR-015)

Enforced via `api_idempotency_keys` (§6.23) for `inquiries`, `orders`, `payments`:

- First request: operation runs in transaction; response cached.
- Replay with same key + same hash: cached response returned (no duplicate — Scenario 5).
- Same key, different hash: `422`.
- Keys TTL-cleaned by scheduled job (e.g. 30 days).
- Secondary business anchor: `external_id` unique constraints on orders/payments.

### 11.4 Transport & platform

- HTTPS only (HSTS on CRM domain).
- CSRF: machine endpoints are stateless (no cookies) — CSRF middleware excluded for `/api/*`; session-based UI uses standard Sanctum/session CSRF.
- Secrets in logs: request signing headers and `X-Api-Key` values are redacted in logs.

### 11.5 Privacy (§44)

- IP stored only as `ip_hash` (SHA-256 + app salt) in inquiries/audit; raw IP not persisted (short-lived in web-server logs only).
- User-Agent stored truncated (500) — needed for security context only.
- Fingerprint: only `fp_hash` (technical hash) in marketing events — no raw fingerprint.
- Marketing payload: sites must not send personal data beyond what the inquiry requires; API validation strips unknown keys.

---

## 12. Authentication & RBAC

### 12.1 CRM UI authentication

- Standard Laravel session auth for `crm.zoloto-spb.ru` (Blade/Livewire, same-origin).
- **Sanctum** used for SPA/session token interaction where Livewire/XHR needs it.
- Login, logout, password reset (email), session security (regenerate on login, timeout policy).
- `users.is_active=false` blocks login immediately.

### 12.2 RBAC

Roles: **`admin`**, **`manager`** (column `users.role`).

| Capability | admin | manager |
|---|---|---|
| customers/inquiries/leads/orders/payments CRUD | ✅ | ✅ (no delete of anything; no settings) |
| manual customer merge | ✅ | ❌ (policy-controlled) |
| status transitions | ✅ | ✅ within state machine |
| payments registration | ✅ | ✅ (audit-heavy) |
| sites/sources/settings management | ✅ | ❌ |
| users management | ✅ | ❌ |
| audit log viewing | ✅ | ❌ |

Enforcement: **Policies + Gates only** — never Blade-only checks. Livewire components call `authorize()`; API/UI boundary reuses identical policies.

---

## 13. Queue & Transactional Outbox

### 13.1 Queue driver

`database` (ADR-013). Jobs: `SendBusinessEvent`, `SendEmailNotification`, `CleanupIdempotencyKeys`, `RebuildAttributionProjection`, `PruneMarketingEvents`.

Requirements per job: `tries`, exponential backoff, `failed()` handling, structured logging. No Horizon in MVP.

### 13.2 business_events = internal outbox (ADR-012)

Created **only by CRM** on business events: `inquiry_received`, `qualified_lead`, `contract_sent`, `contract_signed`, **`first_deposit_received`** (first payment — the conversion-critical event), `payment_received` (subsequent payments), `order_completed`.

Outbox row carries: event type, entity type/id, payload snapshot, target, status, attempts, `next_attempt_at`, `processed_at`, `last_error`, unique `event_key`.

### 13.3 Transactional outbox pattern (Scenario 4 — payment)

```text
BEGIN
  RegisterPaymentAction:
    payment insert/update
    order status transition
    business_events insert (first_deposit_received or payment_received, event_key = payment:<id>:paid)
    audit insert
COMMIT
        │
        ▼
   dispatch (after commit) ──► SendBusinessEvent job
        │
        ▼
   Integrations\AdvertisingApp\AdvertisingAppClient ──► advertising app webhook
```

**Invariant:** a Payment and its BusinessEvent are created in the **same transaction**. The queue job only *delivers* what is already durably recorded. A crashed queue never loses a conversion — the dispatcher (§13.4) re-scans `status=pending AND next_attempt_at <= now()`.

### 13.4 Execution on SWeb (no Supervisor)

Cron entries (each guarded by `flock` to prevent overlap):

```cron
* * * * *  cd ~/crm_zoloto_spb_ru && flock -n /tmp/crm_sched.lock php8.3 artisan schedule:run >> /dev/null 2>&1
* * * * *  cd ~/crm_zoloto_spb_ru && flock -n /tmp/crm_q1.lock   php8.3 artisan queue:work database --stop-when-empty --max-time=50 --tries=3 >> /dev/null 2>&1
```

- `schedule:run` drives scheduler tasks incl. a scheduled `outbox:dispatch` command (scans pending outbox rows, pushes jobs) and `queue:prune-failed`.
- `queue:work --stop-when-empty` keeps memory flat on shared hosting.
- Failed jobs: `queue:retry` runbook + `last_error` on outbox row; `abandoned` after N attempts for human review.

---

## 14. Separate Advertising Application Integration

> **Architectural boundary (Task04 §3):** Yandex Direct/Metrica management lives in the separate advertising application, **not** in CRM. CRM collects inquiries/leads/contracts/payments and **passes business events** to that application through a versioned webhook contract.

### 14.1 Layering

```text
Domain event ──► business_events (outbox) ──► SendBusinessEvent job
                                                  └─► App\Integrations\AdvertisingApp\AdvertisingAppClient
```

- Namespace `App\Integrations\AdvertisingApp` — isolated DTOs, config, HTTP client, error mapping.
- **No external API calls in Controllers/Models/Actions.**
- Config (webhook URL, HMAC secret) via `.env`; separate credentials per site where advertising app accounts differ.

### 14.2 Data collected from sites and passed to the advertising application

- **Yandex ClientID**: collected on sites, transmitted via API, stored in `marketing_events`, `touchpoints`, `inquiries`, and `customer_identities` (type `yandex_client_id`). Never the sole Customer identity (ADR-009).
- **yclid**: collected on sites, transmitted, stored in the same attribution fields, retained for Yandex Direct analytics.
- **Business events passed to the advertising application**: `inquiry_received` (lead created), `qualified_lead`, `contract_sent`, `contract_signed`, `first_deposit_received` (first payment — conversion-critical), `payment_received` (subsequent payments), `order_completed`.

### 14.3 Webhook contract (CRM → advertising application)

CRM sends `POST /webhooks/crm-events` to the advertising application with:

```json
{
  "event_key": "payment:<id>:paid",
  "event_type": "first_deposit_received",
  "occurred_at": "2026-07-02T10:00:00Z",
  "site": "sweb-ru",
  "inquiry_id": 12345,
  "customer_id": 678,
  "attribution": { "utm_source": "yandex-direct", "utm_campaign": "...", "yclid": "...", "yandex_client_id": "..." },
  "financial": { "amount": 150000.00, "currency": "RUB", "kind": "advance" },
  "schema_version": 1
}
```

- **Auth:** HMAC-SHA256 signature header `X-CRM-Signature: hex(hmac(payload, secret))`.
- **Idempotency:** the advertising application deduplicates by `event_key`; retries from CRM always reuse the same key.
- **Redelivery:** exponential backoff (1 min → 5 → 30 → 2 h → 6 h); `abandoned` after N attempts for manual replay.
- **Schema versioning:** `schema_version` integer; breaking changes require a new version; old versions supported during a transition window.

### 14.4 Endpoint & payload verification (hard rule)

Concrete advertising application endpoints, request formats and auth headers **must be re-verified against the current official documentation immediately before implementation** and recorded as an implementation ADR/appendix. Inventing endpoints is prohibited. The architecture only fixes: outbox → job → isolated client → external webhook API.

---

## 15. CRM UI

### 15.1 Stack & sections

Blade layouts + Livewire 3 tables/forms + Alpine + Tailwind, built with Vite.

```text
/dashboard

/customers            /customers/{id}
/inquiries                /inquiries/{id}
/leads                /leads/{id}
/contracts            /contracts/{id}
/orders               /orders/{id}
/payments               /orders/{id}/payments
/expenses               /orders/{id}/expenses

/analytics

/settings             (admin)
```

### 15.2 Dashboard (future-proof for MVP data)

Inquiries (by period/status), leads by stage, contracts by status (incl. **sent-not-signed**, **signed-no-prepayment**), orders by production status, **revenue = confirmed payments**, expenses (workshop vs customer metal), **profit only for completed orders** (estimate clearly marked), conversion rates (inquiry→lead→first deposit), breakdowns by source / campaign / site. All computable from MVP schema — no new tables needed.

### 15.3 Attribution panel (every Customer/Inquiry/Lead card)

```text
First touch | Last touch | Full touchpoint history
UTM (5 fields) | ClientID | yclid | Source | Landing | Referrer
```

History is always visible, not just the last value (§8.4).

### 15.4 CRM work entities

`notes` + `activities` power the customer timeline (manager comment, call, email, status change, system event). Tasks/calendar are V2 — schema must not block them (activities polymorphic subject is sufficient groundwork).

---

## 16. Testing Strategy

| Level | Covers |
|---|---|
| **Unit** | customer matching ladder (§7.3 incl. conflict case), attribution projection rebuild, state machines (all transitions + all invalid transitions), money math (NUMERIC, rounding), HMAC signature verification (clock skew, tampered body) |
| **Feature** | API auth (valid/invalid/stale), inquiry creation (Scenarios 1–2), idempotency (replay, key reuse), customer dedup + manual merge, Inquiry→Lead, Contract lifecycle (sent-not-signed / signed-no-prepayment states, status history), Order creation (snapshot immutability), Expense registration (`material_origin = customer` excluded from cost sums; estimate vs actual), Payment registration + order status, **CompleteOrderAction (actual_profit set only when all expenses are actual; client metal never reduces profit)**, conversion outbox atomicity (Scenario 4), **replayed webhook produces no duplicate (Scenario 5)** |
| **Integration** | Advertising app client against **fake HTTP** (never live API), webhook contract tests |

Rules: PostgreSQL for tests (partial-unique index behaviour must match production — SQLite is not acceptable for customer/payment tests). External APIs are mocked/faked in automated tests. Outbox atomicity tested by asserting row presence after commit-failure simulation.

---

## 17. Deployment

### 17.1 Git workflow

```text
MacBook ── feature branch ── tests ── GitHub ── main ── SWeb deployment
```

- Git lives on MacBook + GitHub. **Production has no Git repository** (not required at runtime).
- Production secrets (`.env`, api secrets, DB creds, advertising app credentials) never committed.

### 17.2 Deploy procedure (manual first, SWeb)

```text
1. Build frontend on MacBook: npm run build → upload artifacts (public/build)
2. rsync application code (excluding .env, storage/, vendor/) → ~/crm_zoloto_spb_ru
3. php8.3 ~/composer.phar install --no-dev --optimize-autoloader   (explicit PHP 8.3)
4. php8.3 artisan migrate --force                                   (only additive, reviewed)
5. php8.3 artisan config:cache && route:cache && view:cache
6. Verify public_html → public symlink intact; storage:link must not overwrite it
7. Verify cron entries exist (§13.4); php8.3 artisan schedule:list
8. Smoke: POST /api/v1/inquiries with test credential, dashboard login
```

CI/CD automation is a later improvement; the first deploys are manual SSH.

---

## 18. SWeb Constraints (Runbook Level)

Verified facts this architecture depends on (re-check at deploy time):

| # | Fact | Rule |
|---|---|---|
| 1 | Domain `crm.zoloto-spb.ru` handler = PHP 8.3 | confirm in hosting panel per deploy |
| 2 | Default CLI `php` = old version (5.2 observed) | **never call bare `php`**; use `php8.3` everywhere (cron included) |
| 3 | Composer is a local binary with its own config | run via `php8.3 ~/composer.phar …` |
| 4 | PostgreSQL on external SWeb host | `.env` uses host/port/user/pass; test connection in deploy step 4; expect non-zero latency (indexes per §6.24) |
| 5 | Web root `public/`, SWeb `public_html` symlink | symlink already present; verify, don't force |
| 6 | No Docker/systemd/Supervisor | queue+scheduler via cron+flock (§13.4) |
| 7 | App path `~/crm_zoloto_spb_ru`, Laravel 13 installed | deploy targets this path |
| 8 | Shared hosting process limits | `queue:work --stop-when-empty --max-time=50`; no long-running daemons |

---

## 19. MVP Core

Must-have for first release:

- Authentication (session + Sanctum), RBAC (`admin`/`manager`, Policies)
- Customers + customer matching + manual merge
- Customer identities
- Sites, Sources
- Inquiries + status state machine + history
- Leads + state machine + history
- **Contracts + expenses + files** (Task04 §6: contract as CRM artifact, sum + components, categorized expenses, polymorphic file attachments)
- **Orders with costs** (production status, completion with final result calculation, estimate-vs-actual split)
- **Payments** (record-only, no payment provider)
- Attribution: touchpoints (canonical) + first/last projections
- Notes, Activities (timeline)
- Audit logs (all critical actions)
- API v1: `POST /api/v1/inquiries`, `POST /api/v1/events`
- HMAC authentication, per-site credentials
- Idempotency (`api_idempotency_keys`)
- Database queue + cron workers
- Business outbox (`business_events`, transactional)
- UI: dashboard, customers, inquiries, leads, orders, payments, settings
- Testing suite per §16

## 20. MVP Integrations

- `us812.ru` → CRM (inquiry + events submission, site-side signer)
- `zoloto-spb.ru` → CRM (same)
- Yandex ClientID collection + transmission
- yclid collection + transmission
- UTM capture (5 fields + raw)
- Advertising app webhook delivery (`first_deposit_received` outbox → job → AdvertisingAppClient; endpoints verified per §14.4 before code)
- Email notifications (inquiry created — to managers)

---

## 21. V2 / Backlog

Explicitly **not** in MVP; architecture already accommodates each without redesign:

Telegram / WhatsApp / telephony integration; email client; calendar + tasks (activities schema is the hook); 2FA; Horizon; BI/advanced reporting; multi-touch attribution models; marketing-event analytics platform; additional sites (just rows in `sites`); other integrations via new `Integrations/*` namespaces + new outbox targets; CI/CD automation; read-replica reporting if DB load demands.

---

## 22. ADR

| ID | Decision | Summary & rationale |
|---|---|---|
| ADR-001 | **Laravel 13 + PHP 8.3** | Matches installed production; modern enums/readonly improve domain expressiveness |
| ADR-002 | **Blade + Livewire 3 + Alpine + Tailwind + Vite** | One-team-friendly stack; no SPA framework without separate ADR |
| ADR-003 | **PostgreSQL** | SWeb platform DB; JSONB, partial-unique indexes, NUMERIC, TIMESTAMPTZ all used by design |
| ADR-004 | **Money = NUMERIC(12,2)** | Exact decimal; float/double banned for money, ever |
| ADR-005 | **UTC timestamps** | TIMESTAMPTZ stored UTC; app-level timezone policy explicit; display timezone (Europe/Moscow) is presentation-only |
| ADR-006 | **customer_identities separate entity** | External identifiers never bloat Customer; extensible to future ID types |
| ADR-007 | **touchpoints = attribution source of truth** | Append-only history; attribution is not overwritten fields |
| ADR-008 | **First/last attribution = projection/cache** | Disposable, rebuildable from touchpoints |
| ADR-009 | **ClientID ≠ person identity** | No auto-merge by ClientID; unique association is device-scoped |
| ADR-010 | **Enums + state machines, no status lookup tables** | Fixed business status sets; transitions enforced in domain; history tables append-only |
| ADR-011 | **Orders/Payments/Contracts/Expenses immutable history** | No soft/physical delete; cancel/refund/reverse via transitions; contract sum change = new version row, old version retained; expenses never auto-created from order; audit_logs also append-only |
| ADR-012 | **business_events = transactional outbox** | Payment+event atomic commit; delivery decoupled and retryable; `first_deposit_received` delivered to the **separate advertising application** webhook (CRM never calls Yandex directly — OQ-2) |
| ADR-013 | **Database queue** | No Redis/Horizon dependency on SWeb; cron+flock workers |
| ADR-014 | **HMAC-SHA256 API authentication** | Per-site credentials, timestamp window, constant-time compare |
| ADR-015 | **Idempotency keys** | `api_idempotency_keys` + cached responses; external_id business anchors |
| ADR-016 | **Actions instead of generic Services** | `IngestInquiryAction`, `RecordTouchpointAction`, `ConvertInquiryToLeadAction`, `CreateOrderAction`, `RegisterPaymentAction`, `MergeCustomersAction`, `FindOrCreateCustomerAction`; no `CrmService` god objects |
| ADR-017 | **GitHub + manual SWeb deployment** | No production Git; explicit `php8.3` runbook; rsync artifact deploy |
| ADR-018 | **Contract = standalone entity** (Task04 §6) | Number, status machine, dates, file, sum + components, change history; sent/signed ≠ revenue; separate from Order |
| ADR-019 | **Two-tier profit model** (Task04 §7) | `estimated_profit` never presented as fact; `actual_profit` computed only at completion with all expenses confirmed actual; client-supplied metal excluded from cost sums |
| ADR-020 | **No direct Yandex integration** (Task04 §9) | CRM is the source of truth for business/financial facts; the advertising application owns Yandex Direct/Metrica; delivery via outbox + versioned HMAC webhook (§14) |

Supporting decisions (already fixed by the brief, recorded for completeness): no Repository pattern by default (Eloquent sufficient); Events/Listeners only for side-effect fan-out — critical transitions stay explicit and testable; controllers thin (validate → authorize → Action → response); no MODX coupling.

---

## 23. Risks

| # | Risk | Impact | Mitigation |
|---|---|---|---|
| R1 | Bare `php` used in cron/deploy by mistake | Runtime crashes (PHP 5.2) | runbook + cron templates with explicit `php8.3`; deploy verification step |
| R2 | Advertising app webhook format changes / endpoint assumptions | Broken event delivery | isolated integration layer; mandatory pre-implementation doc verification (§14.4); outbox retains failed events for replay |
| R3 | Queue workers starve on shared hosting limits | Delayed conversions/emails | `--stop-when-empty --max-time=50`, dispatcher re-scan of pending outbox (durability independent of worker liveness) |
| R4 | Customer matching conflicts (phone A vs email B) | Wrong merges / duplicates | conflict never auto-resolved; flag + manual merge only; audit trail |
| R5 | Race on concurrent first-inquiry for same phone/email | Duplicate customers | partial unique indexes + transaction retry |
| R6 | External PostgreSQL latency/failover | API timeouts | short transactions, index discipline, connection timeout + retry policy in deploy config |
| R7 | marketing_events volume growth | DB bloat | retention policy + monthly partitioning path (OQ-4) |
| R8 | Credential leak (api_secret) | Impersonated inquiries | secrets in `.env` only, redaction in logs, rotation runbook with dual-secret window |
| R9 | Site-side signers implement HMAC differently | Rejected valid requests | published signer spec (§11.1) + contract test vectors for each site |
| R10 | Idempotency table growth | Slow lookups | TTL cleanup job + unique index on (site_id, operation, key) |

---

## 24. Open Questions

Only items genuinely requiring a decision before coding. Everything else is settled by this document.

### OQ-1 — Source of orders/payments: API vs manual entry

- **Question:** Will Orders/Payments arrive from an external system (site/back office) via `POST /api/v1/orders|payments`, or will managers enter them in the CRM UI only at MVP?
- **Why it matters:** Determines whether those endpoints + their idempotency paths are in MVP Core scope or V2; affects site-side work and acceptance tests.
- **Recommended default:** Ship endpoints in MVP (schema supports both anyway), but make **manual registration the primary MVP flow**; activate external submission when the source system is ready.

### OQ-2 — Advertising app webhook: concrete endpoint and auth format

- **Question:** What is the exact webhook URL, HMAC secret management process, and payload schema version accepted by the advertising application? Does it forward `first_deposit_received` to Yandex Direct server-side API, Metrica, or both?
- **Why it matters:** CRM delivers events to the advertising app; the app owns the Yandex conversion decision. CRM needs the concrete webhook endpoint and auth format before implementation.
- **Recommended default:** Coordinate with the advertising app team; record the agreed schema version and endpoint in an implementation appendix before coding.

### OQ-3 — Revenue currency reality

- **Question:** Are all MVP payments strictly RUB?
- **Why it matters:** `currency CHAR(3)` exists, but multi-currency requires rate snapshots on Payment and conversion payload rules.
- **Recommended default:** RUB only in MVP; currency column kept; multi-currency deferred with rate snapshot requirement.

### OQ-4 — Marketing events retention period

- **Question:** How long must raw `marketing_events` be retained (90 days / 180 / 12 months)?
- **Why it matters:** Drives partitioning and pruning job defaults; touches privacy minimization (§11.5).
- **Recommended default:** 180 days retention, monthly partitions, `PruneMarketingEvents` scheduled job; touchpoints are retained indefinitely (business history).

### OQ-5 — Password reset email channel

- **Question:** Is SMTP available/configured on SWeb for CRM (password reset + inquiry notifications)?
- **Why it matters:** MVP includes email notifications; if SWeb mail is unreliable, the outbox must also cover email retries (it can — target `email` already defined).
- **Recommended default:** Use SWeb mail with queue+outbox retries; verify SMTP credentials during first deploy (runbook step).

---

*End of ARCHITECTURE_FINAL.md — no code, migrations, models, controllers, routes, UI, composer/npm installs, production or deployment changes were made in the course of producing this document.*
