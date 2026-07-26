# Shopify App – Interview Prep Project (BOA Ideas) — 2026 Edition

## Context
- **Candidate:** Amir Lifshitz (fullstack developer – 7 years Laravel/React/Vue)
- **Interview:** BOA Ideas (agency, 1,500+ sites, Shopify Plus specialist)
- **Interview date:** Day after tomorrow (2026-07-29)
- **Build time:** ~2 days (today 2 hrs + tomorrow full day)
- **Goal:** Demonstrate Shopify knowledge + backend integration strength + **modern AI integration**

---

## Why This Project?
BOA Ideas builds for **enterprise merchants** (SodaStream, Electra, Fox, Yad2). They need developers who understand:
- Shopify ecosystem (GraphQL Admin API, webhooks, custom apps)
- Real merchant problems
- Backend integrations & event-driven architecture
- Where AI actually adds merchant value (not gimmicks)

This project shows Amir can **learn Shopify quickly**, **solve real problems**, and ship **current-generation** (not 2023-era) integrations.

---

## What to Build: AI-Enhanced Order Webhook Listener

### MVP Features
1. **Shopify custom app** (Dev Dashboard) subscribed to order webhooks
2. **Webhook receiver** – captures `orders/create` events, HMAC-verified
3. **Order storage** – persists webhook data to database (idempotent on `shopify_order_id`)
4. **Hat product management** – CRUD for hat designs (name, color, style, price)
5. **Order-to-product linking** – orders reference which hat design was purchased
6. **Dashboard** – recent orders with hat details (order ID, customer, design, amount, date)
7. **🆕 AI features (Claude API):**
   - **AI hat description generator** — one click on the hat form generates marketing copy from name/color/style (`claude-haiku-4-5`: fast + cheap)
   - **AI order insights** — dashboard panel summarizing recent order trends in plain language (best sellers, revenue pattern, anomalies)

### What This Demonstrates
✅ Shopify custom app setup + access scopes
✅ Webhook handling (async, event-driven, HMAC signature verification)
✅ **GraphQL Admin API awareness** (REST Admin API is legacy for new apps — talking point!)
✅ CRUD + data relationships (orders ↔ hat products)
✅ **LLM integration done right**: server-side API calls, prompt design, graceful fallback when AI is unavailable
✅ Full-stack thinking (API + admin UI + webhooks + tests + CI/CD)

---

## Tech Stack (2026-current)

### Backend
- **Framework:** Laravel 13 (latest major)
- **PHP:** 8.5 (via `php.new` standalone toolchain locally; 8.5 in CI/Render)
- **Database:** SQLite (persisted on Render disk) — right-sized, zero-config
- **Queue/Jobs:** Webhook returns 200 immediately; processing is inline but wrapped in try/catch with a `webhook_events` audit table. (Talking point: "in production I'd push to a queue — Laravel makes that a one-line change.")
- **AI:** Anthropic Claude API via Laravel HTTP client (no SDK needed)
  - `claude-haiku-4-5` for descriptions (speed/cost)
  - Key in `.env` (`ANTHROPIC_API_KEY`), never in code

### Shopify (current best practice)
- **API:** GraphQL Admin API, latest stable version — REST Admin API is legacy for new apps
- **Scopes:** `read_orders` (webhook payloads include protected customer data — fine for custom apps on a dev store; public apps need protected-data approval — talking point!)
- **Webhook:** `orders/create` topic → HTTPS endpoint, verify `X-Shopify-Hmac-SHA256` with `hash_equals`
- **App type:** Custom app created in the store admin (Settings → Apps and sales channels → Develop apps)

### Frontend
- **Views:** Blade templates (minimal, fast)
- **Styling:** Tailwind CSS v4 + **daisyUI** components (professional look, minutes not hours)
- **Look:** Shopify-Polaris-inspired admin (clean tables, status badges, stat cards)
- **Interactivity:** vanilla JS + fetch for the AI-generate button and hat CRUD forms (no SPA overhead)

### Deployment
- **Host:** Render.com free tier + persistent disk for SQLite
- **Caveat to know for the demo:** free tier spins down when idle (~50s cold start) — open the app 5 min before demoing
- **CI/CD:** GitHub Actions — push to `main` → PHPUnit → on green, hit Render deploy hook

---

## Database Schema

### hats
```
id, name, color, style, description (AI-generatable), image_url, price, timestamps
```

### orders
```
id, shopify_order_id (unique), hat_id (FK, nullable — order may not match a hat),
customer_email, customer_name, order_number, total_price, currency,
status, quantity, order_data (json full payload), timestamps
```

### webhook_events (audit/debug)
```
id, topic, payload (json), status (success|failed), error, processed_at
```

---

## API Endpoints
- `POST /webhook/shopify/orders-create` — HMAC middleware → store event → upsert order → 200
- `GET|POST /api/hats`, `GET|PUT|DELETE /api/hats/{id}` — hat CRUD (JSON)
- `POST /api/hats/generate-description` — Claude-powered copy from {name, color, style}
- `GET /api/orders`, `GET /api/orders/{id}` — orders with linked hat
- `GET /api/orders/insights` — Claude-powered trends summary (cached 10 min)
- `GET /dashboard` — orders + hat admin UI + AI insights panel

---

## Testing Strategy (PHPUnit / Laravel 13 test runner)
- **Webhook:** valid HMAC passes; invalid/tampered rejected (401); duplicate order idempotent; malformed payload logged as failed, still 200
- **Hat CRUD:** create/list/update/delete + validation errors
- **Linking:** order links to hat by SKU/title match; unmatched order stored with null hat
- **AI endpoints:** Claude HTTP calls faked with `Http::fake()` — tests never hit the real API (talking point: testing external integrations)

### CI (GitHub Actions)
```yaml
on: push → setup PHP 8.4 → composer install → php artisan test → curl Render deploy hook (main, on green)
```

---

## Key Implementation Details
- **HMAC verification:** raw request body + app's webhook secret → base64 HMAC-SHA256 → `hash_equals` compare (constant-time)
- **Idempotency:** `updateOrCreate` on `shopify_order_id` (Shopify retries webhooks — duplicates WILL happen)
- **Respond fast:** Shopify drops webhook subscriptions after repeated slow/failing responses
- **AI fallbacks:** if Claude call fails, description field stays editable and insights panel shows a static summary — AI augments, never blocks
- **Secrets:** `.env` only — `SHOPIFY_WEBHOOK_SECRET`, `ANTHROPIC_API_KEY`

---

## Timeline

### Today (2 hours)
- [x] Toolchain (PHP 8.4 + Composer via php.new)
- [ ] Scaffold Laravel 13 project + git + GitHub repo
- [ ] Migrations + models (hats, orders, webhook_events)
- [ ] Shopify dev store: create custom app, get webhook secret, register `orders/create` webhook (Amir, in parallel)

### Tomorrow (full day)
- [ ] Webhook receiver + HMAC middleware + tests
- [ ] Hat CRUD API + tests
- [ ] Order storage + hat linking + tests
- [ ] Dashboard (orders table, hat admin, design preview)
- [ ] Claude API: description generator + order insights (+ `Http::fake` tests)
- [ ] GitHub Actions CI → Render deploy
- [ ] End-to-end: real Shopify test order → live dashboard
- [ ] README with API docs + architecture notes

### Day After (interview prep)
- [ ] Architecture walkthrough rehearsal
- [ ] Demo script: create hat → AI description → test order → dashboard + insights
- [ ] Prep answers: "Why GraphQL over REST?", "How would you scale webhooks?" (queues, dead-letter, replay), "Where else would AI help merchants?"

---

## Interview Talking Points
**"I built an AI-enhanced Shopify webhook app in 2 days to learn the ecosystem."**
- Event-driven backend: HMAC-verified webhooks, idempotent ingestion, audit trail
- Current platform knowledge: GraphQL Admin API, protected customer data rules, webhook retry behavior
- Pragmatic AI: Claude generates product copy and order insights — with tests (`Http::fake`) and graceful degradation
- Honest framing: "I learned Shopify for this interview — here's a working, deployed app with CI/CD"

## Extensions (if asked)
Queue-based webhook processing • Shopify product/variant sync via GraphQL • CSV export • Slack notifications • inventory sync • MCP server exposing shop data to AI assistants
