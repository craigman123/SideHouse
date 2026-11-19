# AGENTS.md — Side House

Instructions for AI coding agents working in this repo. Read this first, then `DESIGN.md` for anything visual.

## Project

Side House is a padel and pickleball court booking system for a family sports venue in Minglanilla, Cebu.

- Offers padel, pickleball and equipment rental.
- Players range from casual to competitive. Night play runs until 12 AM.
- Rates are positioned as affordable.
- Three experiences: **guest**, **user** and **admin** dashboards.

## Stack

- Laravel 12 (PHP), Supabase / PostgreSQL
- Plain CSS, no Tailwind. Separate CSS file per page.
- Blade views with an SPA-style pattern: page switching through the JS `ShowPage()` function
- Fetch-based JSON interactions, toast notifications for feedback
- Deployed on Render via Docker
- Email goes through the `BrevoMailer` HTTP API helper. SMTP does not work on Render's free tier, so never switch back to SMTP.

## Commands (standard Laravel, confirm against the repo)

```bash
composer install
php artisan serve
php artisan migrate
php artisan test
```

## Payments (critical, handle with care)

- Payments run through PayMongo QR Ph in `PaymongoQrPhController`. The old GCash/Landbank SMS webhook matching is gone.
- One `payment_reference` per checkout. One checkout can create multiple `bookings` rows (one per date).
- Webhook confirmation is by reference number only.
- `UnmatchedPayment` rows park payments that have no matching booking yet. Retroactive claim logic and the `PaymentReference` normalizer live in `app/Support/`.
- Overnight bookings use per-slot dates in `booking_slots`. Do not collapse slots into a single date.
- Once a QR Ph payment intent exists, `cancel()` for a single date must stay blocked (422). The guest uses `cancelAll()` instead.
- `updateReference()` was removed on purpose. Do not bring it back.
- The old screenshot proof-of-payment flow is retired. Do not reintroduce it.

## Security rules

- Never weaken existing protections: MFA rate limiting, row locks on guest cancel/payment, `payment_method=qrph` enforcement, escaped wildcards in `ILIKE` lookups.
- Escape everything rendered from user or API data in JS and Blade (a stored XSS bug was already found in the equipment cards).
- Any new endpoint needs authorization checks and a rate limit. Any money or booking state change needs a DB transaction with row locking.
- Set timeouts on every outbound HTTP call.
- No secrets in the repo. Keys come from environment variables.

## Frontend rules

- Follow `DESIGN.md`. Do not invent new colors, fonts or spacing.
- No Tailwind and no CSS frameworks. Add or edit the CSS file for that page, not inline styles.
- Dark theme with green accents and Montserrat, defined in `DESIGN.md` (base `#0A0A0A`, surface `#141414`, brand green `#009D57`). Use the CSS variables from `tokens.css`, never hard-coded colors. The old GitHub-style palette (`#0d1117`, `#161b22`, `#3fb950`) is being replaced.
- During the redesign, never rename or remove ids, `data-*` attributes or classes that JavaScript depends on.
- Use fetch with JSON responses, and show success or errors through the existing toast system.
- Keep the Blade SPA pattern (`ShowPage()`). Do not convert pages to a different framework.

## Assets and folder layout

- CSS and JS are served straight from `public/` through `asset()`. Docker does not run a Node/Vite build, so Vite (`resources/css/app.css`, `resources/js/app.js`, `welcome.blade.php`) is an unused leftover. Ignore it.
- CSS lives in `public/css/{admin,auth,booking,guest,landing,user}/`, plus shared `public/css/app.css` and `skeleton.css`.
- JS lives in `public/js/{admin,auth,booking,config,guest,landing,payment,toast,user}/`, plus shared `public/js/app.js` and `bootstrap.js`.
- Views are in `resources/views/` (`admin`, `auth`, `emails`, `errors`, `guest`, `layouts`, `maintenance`, `user`, plus `landing`, `main_dashboard`, `welcome`). There are no Blade partials or components. Pages add their own assets with `@push('styles')` and `@push('scripts')` into `layouts/app.blade.php` and `layouts/user.blade.php`.
- Every `asset()` path must match a real file with exact letter case. Development is on Windows (case-insensitive) but Render runs Linux (case-sensitive).
- Shared design tokens go in `public/css/tokens.css`, loaded by both layouts before page styles.
- Out of redesign scope for now: email templates, error pages, maintenance page and `welcome.blade.php`.
- Controllers: no changes for the redesign. The only allowed controller edit is fixing a view name that points to a file that does not exist, and only if a route actually uses it. No logic changes.

## Priorities until the final defense (December 8)

(Added as a suggestion, edit if you disagree.)

- A full UI redesign is planned. It follows `DESIGN.md` and is CSS and Blade markup only, done page by page on a separate branch.
- Outside the redesign: bug fixes and testing only. No backend refactors, no new libraries, no framework changes.
- Suggested schedule (edit as needed): finish the redesign by about Nov 30, then use Dec 1-7 for payment testing, the security list and bug fixes. No big changes in the last few days before the defense.
- Keep changes small and focused, so any one change is easy to revert.
- Do not touch working payment code unless the task is about a payment bug.
- After a change, say what you changed, which files, and how to test it.

## Known open items (last noted early September, verify before relying on them)

- Retroactive unmatched-payment claim flow still being debugged.
- Multi-date checkout display in the waiting-payment Blade views.
- `payment-waiting.blade.php` still has reference-fix UI that should be removed now that `updateReference()` is gone.
- Audit findings that may still be open: `cancelAll()` does not lock or check `payment_intent_id`, authenticated user QR generation is broken (`user-waiting.js` sends no token), `/cron/expire-unconfirmed` is unthrottled, `handleExpired()` only logs instead of releasing the booking, and PayMongo HTTP calls lack timeouts.

## Git

- History was wiped and rebuilt after NativePHP `.exe` artifacts broke pushes. Never commit build artifacts or binaries (`.exe` and similar).
- Do not force push or rewrite history without asking.

## How agents should work

- Read the relevant files before editing. Do not guess table or column names.
- Ask before changing the database schema, payment logic or auth.
- Prefer the smallest change that fixes the problem. Do not rename or reorganize files you were not asked to touch.
- Never delete data, migrations or existing routes without asking.