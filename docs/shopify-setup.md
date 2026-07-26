# Shopify Setup — Do This Yourself (~15 min)

You already have a dev store. Do these steps in the store admin while the code is being built.

## 1. Create the custom app (today)
1. Dev store admin → **Settings → Apps and sales channels → Develop apps**
2. If prompted, click **Allow custom app development**
3. **Create an app** → name it `Hat Orders Listener`
4. Tab **Configuration → Admin API integration → Configure**:
   - Enable scopes: **`read_orders`** (add `read_products` too — harmless, useful later)
   - Save
5. Tab **API credentials** → **Install app**
   - Copy the **Admin API access token** (shown ONCE — save it somewhere safe)
   - Copy the **API secret key** as well

## 2. Get the webhook signing secret (today)
We'll register the webhook through the store admin UI (simplest path):
1. **Settings → Notifications → Webhooks** (scroll to the bottom of Notifications)
2. Note the line at the bottom: *"All your webhooks will be signed with `xxxx`"* — **that hex string is your `SHOPIFY_WEBHOOK_SECRET`**
   - (This is the secret used for admin-created webhooks. Webhooks created via the API are signed with the app's API secret key instead — good interview trivia.)

## 3. Register the webhook (tomorrow, AFTER deploy)
The URL must be public HTTPS, so this waits until the Render deploy is live:
1. **Settings → Notifications → Webhooks → Create webhook**
2. Event: **Order creation** · Format: **JSON** · API version: latest
3. URL: `https://<your-app>.onrender.com/webhook/shopify/orders-create`
4. Click **Send test notification** → the order should appear in the dashboard
5. Real test: create a draft order in admin (**Orders → Create order**, mark as paid) — the `orders/create` webhook fires

## 4. Anthropic API key (today, ~2 min)
For the AI description/insights features:
1. https://console.anthropic.com → API Keys → Create key
2. Save it as `ANTHROPIC_API_KEY` (goes in `.env` / Render env vars, never in code)

## What goes where
| Secret | Used for | Env var |
|---|---|---|
| Webhook signing secret | HMAC verification of incoming webhooks | `SHOPIFY_WEBHOOK_SECRET` |
| Admin API access token | (later/extension) calling the GraphQL Admin API | `SHOPIFY_ADMIN_TOKEN` |
| Anthropic key | AI descriptions + order insights | `ANTHROPIC_API_KEY` |
