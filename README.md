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
  style, size, price, description), with an optional AI-generated description.
  Every hat **must** carry an image: `hats.image_url` is `NOT NULL`, rejected
  when blank by the API, and a merchant can satisfy it by pasting a URL,
  uploading a photo, or generating artwork (see below).
- **Generated hat artwork** — `GET /hat-art/{style}?color=…` draws the hat as
  an SVG from its own style and color, so a product is never imageless. It's
  what the seeder and the not-null backfill migration point at.
- **Virtual try-on** — `/try-on/{hat?}`: the shopper gives it a photo, the
  browser finds their face, measures their skull, and puts a 3D hat on their
  head that they can rotate, move, and resize with the mouse (see below).
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
| `POST` | `/api/try-on/recommend` | Head measurements in (pixels or cm), hat size out. No image data |
| `GET` | `/hat-art/{style}?color=` | Generated hat artwork as SVG |
| `GET` | `/try-on/{hat?}` | Virtual try-on page, optionally pre-selecting a hat |
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

## Virtual try-on (3D)

`/try-on` answers the question a hat shop can't otherwise answer online:
*will this actually fit my head, and what do I look like in it?*

```
photo (never leaves the browser)
   │
   ├─ MediaPipe FaceLandmarker (WASM, in-page) ─► 478 face landmarks (x, y, z)
   │        │
   │        ├─ eye distance in px  ─┐
   │        └─ face width in px    ─┤
   │                                ▼
   │                    POST /api/try-on/recommend   ← two numbers, no image
   │                                │
   │                    HatSizingService (server)
   │                      IPD 63 mm = the ruler → mm per pixel
   │                      face width × 1.12     → skull breadth
   │                      breadth ÷ 0.78        → skull depth (cephalic index)
   │                      Ramanujan ellipse     → head circumference (cm)
   │                      circumference         → XS / S / M / L / XL + fit note
   │
   └─ the same landmarks, as geometry ─► a 3D model of the shopper
            │
            ├─ Delaunay-triangulate the 468 surface points, drop everything
            │  outside the face oval, lift onto the landmarks' own z depth
            ├─ project the photo on as the texture — the landmarks are
            │  normalised image coordinates, so they *are* the UVs
            ├─ mount it on an ellipsoid skull fitted to the same landmarks and
            │  painted the hair colour sampled from the photo, so the head has
            │  volume and the hat has something to sit on
            └─ hat: procedural geometry per style, banded on the hairline
               landmark, oriented by the head's own axes

drag = turn the head and hat together (±40°) · scroll = zoom · ⇧drag = nudge
```

The flat photo stays underneath so the first frame is seamless, then fades as
the model turns — and the head drifts to centre stage, where there's room to
zoom into it. The orbit stops at ~40° because a single photo knows nothing
about the back of a head; closing the skull for a full 360° is a v2 job.

This is the technique behind [PyFace3D](https://github.com/Dor-sketch/PyFace3D)
and Babylon's [facecap](https://github.com/imerso/facecap), done in Three.js.

### The hats themselves

`Desert Bucket Hat` renders from a **real photogrammetry scan** — PierreB3D's
fisherman's hat via [Poly Haven](https://polyhaven.com), CC0/public domain:
10,304 triangles with 1k diffuse, normal and ARM maps, so you see actual
fabric weave, stitching and wear rather than shaded geometry.

Calibration is derived rather than eyeballed. The scan is at real-world scale
(0.324 m across the brim); the loader normalises width to 2 units, which would
leave the head opening at ~0.56 instead of 1, because a bucket hat's brim is
roughly twice its head opening. Hence `scale: 1.8` in
`public/models/hats/manifest.json`, and an offset that drops the model so its
own `y=0` — where brim meets crown — lands on the band line.

The other styles still use procedural geometry, and **the UI says so**: a
badge reads *Photoreal 3D scan* or *Generated 3D preview*, and the page opens
on a scanned product. A hat shop that overstates what a preview shows earns
returns.

Adding more scans is a manifest entry and a file — `resources/js/tryon/hat-asset.js`
renders the procedural hat first and swaps in a real model when one exists, so
a missing or broken asset degrades instead of breaking.

**Why there's only one scan:** every free image-to-3D model (TRELLIS,
Hunyuan3D, TripoSR, stable-fast-3d) runs on HuggingFace ZeroGPU, which gates
GPU time on a header their edge injects for browser sessions — an API caller
can't supply it, [including PRO
subscribers](https://discuss.huggingface.co/t/incapable-to-use-zerogpu-resource-via-hugging-face-pro-quota-with-gradio-api/132840).
Verified by control test: a CPU endpoint on the same Space returns
`event: complete`, the GPU one returns `event: error`. Poly Haven has exactly
one hat, ambientCG's models are all terrain, and the baseball caps on GitHub
are mirrors whose licensing can't be verified. So: one scan, honestly
labelled, rather than an asset with murky provenance.

Design notes worth saying out loud in an interview:

- **The photo never leaves the device.** Face detection is WASM running in the
  page; only two scalar pixel measurements are POSTed. There is no endpoint
  that accepts an image of a person, so there's nothing to leak or store.
- **The geometry lives on the server**, in `App\Services\HatSizingService`, so
  the sizing table is unit-tested and can't drift between the try-on page and
  anything else that needs a size (`tests/Unit/HatSizingServiceTest.php`).
- **It degrades.** No WebGL, no camera, CDN blocked, or no face found — the
  page falls back to a manual centimetre slider and a hat you position
  yourself. Nothing about the page hard-fails.
- **The scan is deliberately visible** — the landmark cloud lights up under a
  sweeping band before the measurement lines are drawn, so the shopper can
  see what was measured rather than being handed a number.

MediaPipe's library and model are pulled from a CDN on first use, so they cost
nothing in the app bundle; Three.js is bundled by Vite into a chunk that only
the try-on page loads.

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
- **תמונה חובה לכל כובע** — לכל מוצר חייבת להיות תמונה: העמודה `image_url` היא `NOT NULL`, וה־API דוחה ערך ריק (גם רווחים בלבד). אפשר להדביק כתובת, להעלות תמונה, או ללחוץ "Use generated art" — הדמיה וקטורית (SVG) שנוצרת בשרת לפי הסגנון והצבע של הכובע עצמו.
- **מדידה ותצוגה תלת־ממדית (Virtual Try-On)** — מעלים תמונת פנים, הדפדפן מזהה 478 נקודות ציון על הפנים, מודד את רוחב הגולגולת ומחשב היקף ראש בסנטימטרים והמלצת מידה — ואז מציב כובע תלת־ממדי אמיתי על הראש שאפשר לסובב, להזיז ולהגדיל עם העכבר. **התמונה לעולם לא עוזבת את המכשיר** — רק שני מספרים (מרחק בין האישונים ורוחב הפנים, בפיקסלים) נשלחים לשרת לצורך חישוב המידה.

## איך מדגימים (Demo)

1. פותחים את הדשבורד ~5 דקות לפני (השרת החינמי "נרדם").
2. יוצרים כובע חדש → לוחצים "✨ Generate with AI" לתיאור.
3. יוצרים הזמנת בדיקה בחנות הפיתוח של Shopify → ההזמנה מופיעה בדשבורד עם הכובע המקושר.
4. לוחצים "Generate insights" → סיכום מגמות כתוב על ידי Claude.
5. בסטודיו: בוחרים דגם, מעצבים, מייצרים הדמיה אמיתית → "Order from supplier" → טיוטה בחשבון Printful.
6. לוחצים "🪞 Try it on" על כרטיס כובע → מעלים תמונת פנים (או מצלמים במצלמה) → רואים את סריקת הפנים, את היקף הראש והמידה המומלצת, ואת הכובע התלת־ממדי על הראש.

</div>
