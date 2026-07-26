# HatShop — Shopify Order Webhook Listener

An interview demo project: a Shopify `orders/create` webhook listener built on
Laravel 13, paired with a small hat-product admin and two Claude-powered AI
features (product descriptions, order-trend insights).

## What it is

- **Webhook receiver** — verifies Shopify's HMAC signature on every request,
  persists an audit trail (`webhook_events`), and idempotently upserts orders
  keyed on `shopify_order_id` (Shopify retries webhooks, so duplicates must be
  safe).
- **Hat catalog** — simple CRUD for the products being sold (name, color,
  style, price, description), with an optional AI-generated description.
- **Order-to-hat linking** — incoming orders are matched to a hat by
  case-insensitive line-item title; unmatched orders are kept with a null
  `hat_id` rather than dropped.
- **Dashboard** — a single admin page: stat cards, an AI insights panel,
  a recent-orders table, and a hat management grid — all server-rendered
  Blade + Tailwind v4 + daisyUI, with vanilla JS/`fetch` for the interactive
  bits (no SPA framework).

## Architecture sketch

```
Shopify (dev store)
   │  orders/create webhook (HMAC-SHA256 signed)
   ▼
POST /webhook/shopify/orders-create
   │  VerifyShopifyWebhook middleware: hash_equals(HMAC) or 401
   ▼
ShopifyWebhookController
   │  upsert Order (idempotent on shopify_order_id)
   │  match line_items[0].title -> Hat (case-insensitive), else null
   │  always record a WebhookEvent (success|failed) and return 200
   ▼
Postgres (Render) / SQLite (local, CI)

Dashboard & JSON API (routes/api.php, /api/*)
   │
   ├─ Hat CRUD ────────────────► ClaudeService ─► Anthropic Messages API
   │                              (2-3 sentence product description)
   │
   └─ Order insights ──────────► ClaudeService ─► Anthropic Messages API
                                  (plain-language trend summary,
                                   cached 10 min, deterministic fallback
                                   if the API is unavailable)
```

Both AI-calling paths go through `App\Services\ClaudeService`, which never
throws — any failure (missing key, HTTP error, timeout, malformed response)
degrades to `null` (description) or a deterministic stats-based summary
(insights), so the product never blocks on an external API.

## API endpoints

| Method | Path | Description |
|---|---|---|
| `GET` | `/api/hats` | Paginated hat list (15/page), newest first |
| `POST` | `/api/hats` | Create a hat |
| `GET` | `/api/hats/{hat}` | Show a hat (404 if missing) |
| `PUT` | `/api/hats/{hat}` | Partial update (all fields `sometimes`) |
| `DELETE` | `/api/hats/{hat}` | Delete a hat (204) |
| `POST` | `/api/hats/generate-description` | AI-generate a description from `{name, color, style}` |
| `GET` | `/api/orders` | Paginated order list (20/page), latest first, with `hat` eager-loaded |
| `GET` | `/api/orders/{order}` | Show an order, with `hat` eager-loaded |
| `GET` | `/api/orders/insights` | AI (or fallback) merchant summary of order trends, cached 10 min |
| `POST` | `/webhook/shopify/orders-create` | Shopify webhook receiver (HMAC-verified) |
| `GET` | `/dashboard` | Admin dashboard (redirected to from `/`) |

## Environment variables

| Variable | Purpose |
|---|---|
| `SHOPIFY_WEBHOOK_SECRET` | Signing secret used to verify `X-Shopify-Hmac-SHA256` |
| `ANTHROPIC_API_KEY` | Anthropic API key for description/insight generation. If unset, both AI features fall back gracefully (no crash) |
| `ANTHROPIC_MODEL` | Overrides the model used for Claude calls (default `claude-haiku-4-5`) |
| `DB_CONNECTION` / `DB_URL` | Postgres in production (Render), SQLite locally and in CI |
| `APP_ENV`, `APP_KEY`, `APP_DEBUG` | Standard Laravel bootstrap config |

See `.env.example` for the full list.

## Deploy notes

- **Render blueprint** (`render.yaml`): a Docker web service plus a managed
  Postgres database. `SHOPIFY_WEBHOOK_SECRET` and `ANTHROPIC_API_KEY` are
  marked `sync: false` — set them once in the Render dashboard.
- **Docker** (`Dockerfile`): multi-stage build — Node stage compiles Tailwind
  v4 + daisyUI assets via Vite, PHP stage runs `composer install --no-dev` and
  copies the compiled `public/build` output. No local Node/PHP toolchain is
  required to build the image.
- **Boot** (`docker/start.sh`): generates an `APP_KEY` if unset, runs
  `php artisan migrate --force` then `php artisan db:seed --force` on every
  boot. The seeder (`HatSeeder`) uses `firstOrCreate` keyed on hat name, so
  it's safe to run on every deploy without duplicating rows or crashing.
- **CI/CD** (`.github/workflows/ci.yml`): every push runs the PHPUnit suite
  against SQLite; on a green build to `main`, it curls the Render deploy
  hook (`RENDER_DEPLOY_HOOK` secret) to trigger a redeploy.

## Local-free workflow note

This repository is developed without running `php`, `composer`, `npm`, or
`artisan` locally — all changes are made directly to source files and
verified by the CI pipeline (PHPUnit over SQLite) and the Render deploy.
Frontend assets (Tailwind v4 + daisyUI via `@plugin "daisyui"` in
`resources/css/app.css`) are compiled entirely inside the Docker build, so
`npm install` never needs to run outside of CI/the build step.
