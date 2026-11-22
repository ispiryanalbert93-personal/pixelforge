# AGENTS.md

Guidance for coding agents working in **PixelCanvas** (`pixelforge`), a Laravel 13 + Inertia v2 + Vue 3 studio integrating the Pixelforge image-generation API (`https://pixelforge.promptedit.dev/v1`).

---

## Tech Stack & Environment

- **Backend:** PHP `^8.4` (`ext-bcmath`, `RoundingMode::HalfAwayFromZero`), Laravel `^13.0`, Inertia Laravel `^2.0`, Ziggy `^2.4`, Pusher PHP Server `^7.2`.
- **Frontend:** Vue 3 (`<script setup lang="ts">`), TypeScript, Inertia Vue 3 (`@inertiajs/vue3`), Tailwind CSS v3 + `shadcn-vue` (`components.json`), Laravel Echo + `pusher-js`.
- **Database:** MySQL in local/production runtime (configured via `.env`, `config/database.php` supports both `DB_DATABASE` and Wasmer-injected `DB_NAME`); `:memory:` SQLite for PHPUnit (`phpunit.xml`).
- **Seeded Demo Accounts (`DatabaseSeeder`, password `password`):**
  - Admin: `admin@example.com` (`is_admin = true`, restricted to `/admin/reports`)
  - Customer: `test@example.com` (`is_admin = false`, seeded with `$25.00` wallet credit)

---

## Commands

```powershell
# Local dev stack (Laravel server, queue worker, Pail logs, Vite)
composer dev

# Backend tests (in-memory SQLite via phpunit.xml)
php artisan test

# PHP formatting check / fix
php vendor/bin/pint --test
php vendor/bin/pint

# Frontend lint, typecheck, format check, and production build
npx eslint resources/js
npx vue-tsc --noEmit
npm run format:check
npm run build
```

> **Note:** When migrating/testing real MySQL constraints after editing migrations, verify against a temporary MySQL database before wiping live local data, since `php artisan test` runs on SQLite.

---

## Architecture & Invariants

### 1. Financial Precision & FE/BE Pricing Parity
- Store and pass money as **exact integer nanodollars** (`Money::NANODOLLARS_PER_USD = 1_000_000_000`, `BIGINT` columns).
- Use `App\Support\Money` (`bcmath` + `RoundingMode::HalfAwayFromZero`) on the backend and mirror formulas 1:1 with `BigInt` in `resources/js/lib/pricing.ts` (`prompt.trim()`, UTF-8 code-point length, 6-decimal `text_token_variance`, 9-decimal rate scaling, half-away-from-zero rounding).
- Never use floating-point arithmetic for monetary totals or markups (`Money::DEFAULT_MARKUP_PERCENT = 25`).

### 2. Live Pricing Only (No Hardcoded Fallbacks)
- `App\Services\Pixelforge\PricingService` fetches rates and token rules strictly from `GET /v1/pricing`, validates every field, and caches under `PricingService::CACHE_KEY` for `PIXELFORGE_PRICING_CACHE_TTL` seconds (default `3600`).
- Never silently fall back to hardcoded prices when `/v1/pricing` fails or returns malformed data; surface the error cleanly (`Dashboard` banner / validation error) without caching invalid responses.

### 3. Wallet Pre-Auth Holds, Lock Order & Idempotency
- **Pessimistic lock order:** Always lock `User` / `Wallet` **before** `Generation` inside `DB::transaction()` (`LockUserWallet`, `LockGeneration`, `FinalizeWalletSettlement`) to prevent deadlocks.
- **Idempotent settlement:** `FinalizeWalletSettlement` checks `$lockedGeneration->settled_at !== null` inside the row lock so retries or duplicate webhooks can never double-charge or double-release.
- **Failed generations:** Always settle at `0` provider cost and `0` user cost, releasing 100% of `reserved_user_cost_nano`.

### 4. Webhooks & Realtime Updates (Pusher, No Polling)
- Outbound Pixelforge calls (`App\Services\Pixelforge\Client`) attach `Idempotency-Key` on `POST /v1/generations`, whitelist documented parameters (`prompt`, `size`, `quality`, `reference_images`, `metadata`), and retry transient `ConnectionException` / `503 overloaded` responses only.
- Incoming webhooks (`POST /api/webhooks/pixelforge`) are verified in `VerifyPixelforgeWebhookSignature` (`HMAC-SHA256`, `t=` + `v1=`, `hash_equals()`, 300s replay window) and handled idempotently in `App\Services\Pixelforge\WebhookService`:
  - Per-event `Cache::lock` + `webhook_events.event_id` uniqueness
  - Early-webhook race fallback via `metadata.local_generation_id`
  - Monotonic status transitions via `GenerationStatus::canTransitionTo()`
- Realtime UI updates use `GenerationUpdated` (`ShouldBroadcastNow`) over Pusher private channel `App.Models.User.{id}`. Do **not** add client-side or server-side polling fallbacks.

### 5. Role Separation & Data Privacy
- `User::$hidden` includes `is_admin`; never expose `is_admin` in frontend props.
- Route middleware aliases (`bootstrap/app.php`) isolate portals: `admin` (`EnsureUserIsAdmin`) protects `/admin/reports`, and `customer` (`EnsureUserIsCustomer`) isolates `/dashboard`, `/generations/history`, `/generations`, and `/wallet`.
- Never expose `actual_provider_cost_*` or `locked_rates` in customer-facing payloads (`GenerationResource`, `GenerationHistoryResource`); provider reconciliation lives solely in `AdminReportService` + `UsageReportResource` + `/admin/reports`.

---

## Code Conventions

- **Controllers:** Keep controllers thin (`GenerationController`, `GenerationHistoryController`, `WalletController`, `AdminReportController`, `PixelforgeWebhookController`) — delegate business logic to `app/Services/` and `app/Actions/`, validation to `app/Http/Requests/`, and serialization to `app/Http/Resources/`.
- **Pixelforge Services (`app/Services/Pixelforge/`):** Do not repeat `Pixelforge` in class/file names inside this namespace (`Client`, `GenerationService`, `PricingCalculator`, `PricingService`, `UsageService`, `WebhookService`, `DTO/*`).
- **Eloquent Models (`app/Models/`):** Keep sections ordered consistently: properties (`$fillable`, `$hidden`, `$with`) → `casts()` / `booted()` → `Attribute::get(...)` accessors → relationships → custom methods.
- **Resources:** `GenerationResource` holds the canonical studio/broadcast generation payload; `GenerationHistoryResource` provides the slim `/generations/history` table shape.
- **Migrations (`database/migrations/`):** Ordered chronologically (`2026_11_21_161200..161206`).
- **Vue Components (`<script setup lang="ts">`):** Follow top-to-bottom order in custom `.vue` files: imports → compiler macros (`defineOptions`, `defineProps`, `defineEmits`, `defineModel`) → page/composables/constants → `ref` state → `computed` → `watch` → handlers/functions → lifecycle hooks (`onMounted`, `onBeforeUnmount`). Do not modify `resources/js/components/ui/*` unless updating `shadcn-vue` primitives.
- **Secrets:** Never commit `.env` or print API keys, webhook secrets, Pusher secrets, or DB passwords in code, logs, or responses.
