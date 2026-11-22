# PixelCanvas (`pixelforge`)

A Laravel 13 + Inertia v2 + Vue 3 studio integrating the **Pixelforge** asynchronous image-generation API (`https://pixelforge.promptedit.dev/v1`).

## Features

- **Image Studio (`/dashboard`):** Write prompts (up to 4,000 Unicode code points), pick size (`1024x1024`, `1536x1024`, `1024x1536`), quality (`low`, `medium`, `high`), and up to 4 reference image URLs with a live upfront cost breakdown (min / midpoint / max hold) before submitting.
- **Credit Wallet & Pre-Auth Holds:** Simulated USD credit top-ups (`Buy credits`), max-estimate pre-auth holds at submission, exact settlement on completion, automatic 100% refund on failed generations, and full transaction ledger.
- **Live Status & History (`/generations/history`):** Real-time updates over Pusher private channels (`GenerationUpdated`), server-side status tabs and search by Pixelforge ID, token usage inspection, image preview/download, and manual sync (`POST /generations/{id}/sync`).
- **Operator Usage & Profit Report (`/admin/reports`):** Daily and per-user credit spend, provider costs, and profit reconciled against `GET /v1/usage` (grouped by UTC `completed_at` day).

## Tech Stack

- **Backend:** PHP 8.4 (`ext-bcmath`), Laravel 13, Inertia Laravel v2, Ziggy, Pusher PHP Server
- **Frontend:** Vue 3 (`<script setup lang="ts">`), TypeScript, Tailwind CSS v3, `shadcn-vue`, Laravel Echo + `pusher-js`
- **Database:** MySQL (runtime) / `:memory:` SQLite (PHPUnit test suite)

## Local Setup

1. Install dependencies:
   ```bash
   composer install
   npm install
   ```
2. Configure environment:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   Set your database credentials, Pusher credentials (`PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, `PUSHER_APP_CLUSTER`), and Pixelforge credentials:
   - `PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1`
   - `PIXELFORGE_API_KEY=<your key>`
   - `PIXELFORGE_WEBHOOK_SECRET=<your whsec_ secret>`
   - `PIXELFORGE_REQUESTS_ENABLED=true`
3. Migrate and seed demo accounts:
   ```bash
   php artisan migrate --seed
   ```
   Seeded demo users (password: `password`):
   - **Customer:** `test@example.com` (seeded with `$25.00` credit)
   - **Operator Admin:** `admin@example.com` (accesses `/admin/reports`)
4. Start the local stack (Laravel server, queue worker, Pail logs, Vite):
   ```bash
   composer dev
   ```
   For webhooks in local development, expose your local server via Expose, ngrok, or Cloudflare Tunnel and register `https://<tunnel-host>/api/webhooks/pixelforge` in the Pixelforge dashboard.

## Architecture & Design Decisions

- **Exact Integer Money (`App\Support\Money` & `resources/js/lib/pricing.ts`):** All money values are stored as exact integer nanodollars (`1 USD = 1,000,000,000`, `BIGINT`) and computed using `bcmath` (`RoundingMode::HalfAwayFromZero`) on the backend and `BigInt` on the frontend.
- **Live Pricing Only (`PricingService`):** Rates and token rules are fetched from `GET /v1/pricing` and cached (`PIXELFORGE_PRICING_CACHE_TTL=3600`). No hardcoded fallback prices are used. If a `202` creation response returns updated rates, the pricing cache is invalidated immediately and the generation's locked rates and hold delta are adjusted.
- **Pre-Auth Holds, Lock Order & Idempotency:** Pessimistic row locks are always acquired in canonical order (`Wallet` before `Generation`) inside a single DB transaction (`LockGeneration`, `FinalizeWalletSettlement`). Settlement checks `settled_at !== null` inside the row lock so duplicate webhooks or syncs can never double-charge or double-release. If mid-flight provider rate increases or post-timeout webhook recovery exceed the held reserve, actual cost is still charged in full so customer billing stays reconciled with `/v1/usage`.
- **Submission & Retries (`SubmitGenerationToPixelforge`):** Submissions use a per-generation UUID `Idempotency-Key`. Defaults to `QUEUE_CONNECTION=sync` (for single-runtime hosts like Wasmer); when running an async queue worker (`QUEUE_CONNECTION=database`), `429` (`rate_limit_exceeded`, `concurrency_limit_exceeded`), `503 overloaded`, and transient `ConnectionException` / 5xx errors retry with `Retry-After` and capped exponential backoff. Ambiguous local failures (e.g. submission timeouts) remain recoverable via `metadata.local_generation_id` if a late webhook arrives.
- **Webhooks & Reconciliation (`VerifyPixelforgeWebhookSignature`, `WebhookService`, `ProcessPixelforgeWebhook`):** Incoming webhooks verify HMAC-SHA256 signatures against raw request bodies with constant-time comparison (`hash_equals`) and a 300s replay window, deduplicate atomically via `webhook_events.event_id` unique constraint, and process via `ProcessPixelforgeWebhook` while enforcing monotonic status transitions. Stuck generations can also be reconciled on demand via the UI Sync button or `php artisan generations:reconcile --minutes=10`.
- **Testing Keywords:** In `local` / `development` environments, the studio suggestion bar includes Pixelforge test keywords (`[succeed]`, `[fail]`, `[slow]`, `[duplicate]`, `[overloaded]`, `[blocked]`).

## Verification

```bash
php artisan test
php vendor/bin/pint --test
npx eslint resources/js
npx vue-tsc --noEmit
npm run format:check
npm run build
```
