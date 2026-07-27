# HatShop — Shopify Order Webhook Listener

**English** | [עברית](#עברית--hatshop--מאזין-webhooks-להזמנות-shopify)

**Live demo:** https://hatsshopify.onrender.com (free tier — first load after idle can take ~1 min)

An interview demo project: a Shopify `orders/create` webhook listener built on
Laravel 13, paired with a hat-product admin, two Claude-powered AI features
(product descriptions, order-trend insights), and a Printful print-on-demand
Design Studio (real hat mockups + draft supplier orders).

## What's real vs. what's simulated

| Piece | Real or mock? |
|---|---|
| The app itself | **Real** — Laravel 13 + Postgres, deployed on Render via Docker, CI-gated deploys |
| Shopify webhooks | **Real mechanics, test data** — a real Shopify **development store** sends genuine `orders/create` webhooks, verified with real HMAC-SHA256 signatures. Orders are test orders: no real customers or money |
| AI features | **Real AI** — the description generator and order insights make live calls to Anthropic's Claude API. The **AI** badge means Claude actually wrote it; a **fallback** badge means the API was unavailable and you're seeing deterministic, non-AI text |
| Printful catalog & mockups | **Real** (when `PRINTFUL_API_KEY` is set) — products, color variants, and mockup photos come from Printful's live API; mockups are photorealistic renders of products they actually manufacture. Without the key, the studio shows a hardcoded 3-item shortlist and can't generate mockups |
| Supplier orders | **Real but draft-only** — "Order from supplier" creates a genuine order in your Printful account in `draft` status. It becomes a physical hat **only** after you review, confirm, and pay in Printful's own dashboard. The code never sends a confirm/payment parameter (asserted by tests) |
| Seeded hats | **Demo data** — 5 sample hats are seeded on every deploy (idempotently) so the dashboard is never empty |
| Tests / CI | **All external APIs faked** — the PHPUnit suite uses `Http::fake()`; CI never calls Shopify, Anthropic, or Printful |

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

## Print-on-demand (Printful)

The **Design Studio** (`/studio`, linked from the dashboard navbar and from
each hat card) lets a merchant put a design on a Printful headwear product
and push it to a supplier as a draft order — without ever touching a
persistent filesystem (Render has none) or accidentally paying for
anything.

```
Pick base hat            Create design             Generate mockup                 Order from supplier
(Printful catalog)   →   (upload image OR      →   POST design → DB           →    POST /orders (draft only,
GET /products             text→canvas→PNG)          POST /mockup-generator/         no `confirm` param —
category_id=24                                       create-task/{id}                merchant confirms &
                                                       poll GET /mockup-generator/     pays in their own
                                                       task until completed             Printful dashboard)
```

Design images (uploads or the canvas-rendered text design) are stored as
bytes in the `design_files` table (Postgres `bytea` / SQLite `blob`) and
served back out over a public, cacheable route so Printful's mockup
generator and order API can fetch them by URL.

### Endpoints

| Method | Path | Description |
|---|---|---|
| `GET` | `/api/printful/products` | Curated headwear catalog (category 24), cached 1h. Falls back to a 3-item hardcoded shortlist (`live: false`) if Printful is unconfigured/unavailable |
| `GET` | `/api/printful/products/{id}` | A catalog product + its color/style variants, cached 1h |
| `POST` | `/api/design-files` | Store a design — multipart `file` (image, max 4MB) or JSON `{data_url}` (base64). Returns `{id, url}` |
| `GET` | `/design-files/{designFile}` | Public: streams a stored design's bytes with the correct content type |
| `POST` | `/api/hats/{hat}/mockup` | Start a Printful mockup-generation task for `{printful_product_id, printful_variant_id, design_file_id}`; persists the ids on the hat. `422` with `{error}` if Printful fails |
| `GET` | `/api/mockup-tasks/{taskKey}` | Poll a mockup task. Pass `?hat_id=` to persist `mockup_urls` + cover `image_url` on that hat once it completes |
| `POST` | `/api/hats/{hat}/supplier-order` | Create a **draft** Printful order for `{recipient, quantity}`. Requires the hat already has a variant + design. `422` with `{error}` if unconfigured/missing/failed |

### Never auto-confirmed

`PrintfulService::createDraftOrder()` never sends a `confirm` (or payment)
parameter — Printful orders default to `draft` status, so every order
created through the Design Studio sits in the merchant's Printful dashboard
for manual review and payment. This is asserted directly in
`tests/Feature/PrintfulTest.php` (the outgoing request body is checked for
the *absence* of a `confirm` key).

### Environment variable

| Variable | Purpose |
|---|---|
| `PRINTFUL_API_KEY` | Printful API key (`Authorization: Bearer`). If unset, catalog/mockup/order calls degrade gracefully — the catalog falls back to a hardcoded shortlist, and mockup/order actions return a `422` rather than crashing |

## Local-free workflow note

This repository is developed without running `php`, `composer`, `npm`, or
`artisan` locally — all changes are made directly to source files and
verified by the CI pipeline (PHPUnit over SQLite) and the Render deploy.
Frontend assets (Tailwind v4 + daisyUI via `@plugin "daisyui"` in
`resources/css/app.css`) are compiled entirely inside the Docker build, so
`npm install` never needs to run outside of CI/the build step.

---

<div dir="rtl" align="right">

# עברית — HatShop — מאזין Webhooks להזמנות Shopify

**דמו חי:** <https://hatsshopify.onrender.com> (שרת חינמי — טעינה ראשונה אחרי חוסר פעילות יכולה לקחת כדקה)

פרויקט הדגמה לראיון עבודה: אפליקציה שמאזינה ל־webhook מסוג `orders/create` של Shopify, בנויה על Laravel 13, עם ממשק ניהול לקטלוג כובעים, שתי יכולות AI מבוססות Claude (כתיבת תיאורי מוצר ותובנות על הזמנות), וסטודיו עיצוב בחיבור ל־Printful — ספק הדפסה־לפי־דרישה אמיתי (הדמיות פוטוריאליסטיות של הכובע + הזמנות טיוטה מהספק).

## מה אמיתי ומה מדומה (Mock)?

| רכיב | אמיתי או מדומה? |
|---|---|
| האפליקציה עצמה | **אמיתית** — Laravel 13 + Postgres, פרוסה ב־Render בעזרת Docker, עם CI שחוסם דיפלוי אם בדיקות נכשלות |
| Webhooks של Shopify | **מנגנון אמיתי, נתוני בדיקה** — חנות פיתוח (dev store) אמיתית של Shopify שולחת webhooks אמיתיים, שמאומתים בחתימת HMAC-SHA256 אמיתית. ההזמנות הן הזמנות בדיקה: אין לקוחות אמיתיים ואין כסף אמיתי |
| יכולות ה־AI | **AI אמיתי** — מחולל התיאורים ופאנל התובנות מבצעים קריאות חיות ל־API של Claude (Anthropic). תג **AI** אומר ש־Claude באמת כתב את הטקסט; תג **fallback** אומר שה־API לא היה זמין והטקסט חושב מקומית ללא AI |
| קטלוג והדמיות Printful | **אמיתי** (כאשר מוגדר `PRINTFUL_API_KEY`) — המוצרים, הצבעים וההדמיות מגיעים מה־API החי של Printful; ההדמיות הן צילומים פוטוריאליסטיים של מוצרים שהם באמת מייצרים. בלי המפתח — הסטודיו מציג רשימה קבועה של 3 מוצרים ואינו יכול לייצר הדמיות |
| הזמנות מהספק | **אמיתי אבל טיוטה בלבד** — כפתור "Order from supplier" יוצר הזמנה אמיתית בחשבון Printful שלך בסטטוס `draft`. היא תהפוך לכובע פיזי **רק** אחרי שתאשר ותשלם בעצמך בדשבורד של Printful. הקוד לעולם לא שולח פרמטר אישור/תשלום (יש בדיקה אוטומטית שמוודאת זאת) |
| כובעי הדוגמה | **נתוני דמו** — 5 כובעים לדוגמה נזרעים בכל דיפלוי (באופן אידמפוטנטי) כדי שהדשבורד לא יהיה ריק |
| בדיקות / CI | **כל ה־API החיצוניים מזויפים** — חבילת הבדיקות משתמשת ב־`Http::fake()`; ה־CI לעולם לא פונה ל־Shopify, ל־Anthropic או ל־Printful |

## מה יש באפליקציה

- **קליטת Webhooks** — אימות חתימת HMAC על כל בקשה, תיעוד מלא בטבלת `webhook_events`, ושמירת הזמנות אידמפוטנטית לפי `shopify_order_id` (Shopify שולחת webhooks חוזרים — כפילויות חייבות להיות בטוחות).
- **קטלוג כובעים** — CRUD מלא (שם, צבע, סגנון, מחיר, תיאור) עם אפשרות לתיאור שנכתב על ידי AI בלחיצת כפתור.
- **קישור הזמנה→כובע** — הזמנה נכנסת משודכת לכובע לפי שם הפריט (ללא תלות ברישיות); הזמנה ללא התאמה נשמרת עם `hat_id` ריק ולא נזרקת.
- **דשבורד** — כרטיסי סטטיסטיקה, פאנל תובנות AI, טבלת הזמנות אחרונות וניהול הקטלוג — הכול Blade + Tailwind v4 + daisyUI עם JavaScript ונילי בלבד (בלי SPA).
- **סטודיו עיצוב (Printful)** — בחירת דגם כובע אמיתי מהקטלוג, העלאת לוגו או עיצוב טקסט על קנבס, יצירת הדמיה פוטוריאליסטית של הכובע עם העיצוב, ויצירת הזמנת טיוטה אצל הספק.

## איך מדגימים (Demo)

1. פותחים את הדשבורד ~5 דקות לפני (השרת החינמי "נרדם").
2. יוצרים כובע חדש → לוחצים "✨ Generate with AI" לתיאור.
3. יוצרים הזמנת בדיקה בחנות הפיתוח של Shopify → ההזמנה מופיעה בדשבורד עם הכובע המקושר.
4. לוחצים "Generate insights" → סיכום מגמות כתוב על ידי Claude.
5. בסטודיו: בוחרים דגם, מעצבים, מייצרים הדמיה אמיתית → "Order from supplier" → טיוטה בחשבון Printful.

</div>
