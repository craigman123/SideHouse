# Side House

A court booking system for padel and pickleball, built on Laravel. Supports both
guest (no account) and signed-in bookings, equipment rental, membership discounts,
and GCash/Landbank payment confirmation via SMS webhook.

## Tech Stack

- **Backend:** Laravel (PHP)
- **Database:** MySQL (via XAMPP or your own server)
- **Frontend:** Blade templates + vanilla JS, built assets via Vite
- **Payments:** GCash & Landbank, confirmed through an SMS-forwarding webhook (no payment gateway API — see [Payment Webhook Setup](#payment-webhook-setup))

## Requirements

- PHP 8.1+
- Composer
- MySQL (XAMPP, or a standalone install)
- Node.js + npm
- A phone with SMS-forwarding capability, if you want live payment confirmation (optional for local dev)

## 1. Clone & Install Dependencies

```bash
git clone <your-repo-url> SideHouse
cd SideHouse

# PHP dependencies — this is the step that's missing if you see
# "Failed to open stream: vendor/autoload.php" when running artisan.
composer install

# JS dependencies
npm install
```

## 2. Environment Setup

```bash
cp .env.example .env
php artisan key:generate
```

Open `.env` and set your database credentials:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=side_house
DB_USERNAME=root
DB_PASSWORD=
```

Create the database itself (e.g. in phpMyAdmin, or via the MySQL CLI):

```sql
CREATE DATABASE side_house;
```

## 3. Migrate & Seed

```bash
php artisan migrate

# If you have seeders for courts, equipment, and default business hours:
php artisan db:seed
```

This creates all tables, including `courts`, `bookings`, `booking_slots`,
`booking_equipment`, `equipment`, `business_settings`, `court_closures`,
`memberships`, and `unmatched_payments`.

## 4. Build Frontend Assets

```bash
npm run dev      # local development, with hot reload
# or
npm run build    # production build
```

## 5. Run the App

```bash
php artisan serve
```

Visit **http://127.0.0.1:8000**. The landing page is the guest booking flow —
no login required. Signed-in users get a fuller dashboard at `/my-dashboard`
after registering/logging in.

## Payment Webhook Setup

GCash and Landbank payments aren't confirmed through a payment gateway API —
they're confirmed by forwarding the bank/e-wallet's own "money received" SMS
to this app. This only matters for **live payment confirmation**; you can
develop and test the booking flow itself without it.

1. Set up GCash for Business (merchant QR) and/or a Landbank account with SMS
   transfer alerts enabled.
2. Install an SMS-forwarding app (e.g. an Android "SMS Forwarder" app) on a
   phone with that SIM.
3. Configure it to POST every incoming SMS to:
   - `https://yourdomain.com/webhooks/gcash-sms`
   - `https://yourdomain.com/webhooks/landbank-sms`

   with header `X-Webhook-Token: <your-secret>` and JSON body
   `{"message": "<full SMS text>"}`.
4. Add the matching secrets to `.env`:

   ```env
   GCASH_SMS_WEBHOOK_SECRET=your-secret-here
   LANDBANK_SMS_WEBHOOK_SECRET=your-secret-here

   # Optional: lock the webhook to the forwarding phone's IP/VPN egress
   GCASH_SMS_ALLOWED_IPS=
   LANDBANK_SMS_ALLOWED_IPS=

   # Temporarily true while tuning the SMS parser, then back to false
   SMS_WEBHOOK_LOG_RAW=false
   ```

5. For local testing without a real phone, you can POST a sample SMS body to
   the webhook endpoint directly with `curl` or Postman, using the same
   header/secret.

> **Note:** the Landbank SMS wording in the webhook parser is an unverified
> placeholder — capture a real SMS (`SMS_WEBHOOK_LOG_RAW=true`) and adjust
> `LandbankWebhookController::parseLandbankSms()` to match before relying on
> it for real bookings.

## Scheduled Tasks

Run the scheduler so pending bookings expire correctly and old unmatched-SMS
records get cleaned up:

```bash
php artisan schedule:work
```

(In production, this is a single cron entry calling `php artisan schedule:run`
every minute — see Laravel's [task scheduling docs](https://laravel.com/docs/scheduling).)

## Project Structure Notes

- **Guest booking flow** — `app/Http/Controllers/Guest/GuestBookingController.php`, `resources/views/landing.blade.php`, `public/js/guest-book.js`
- **Signed-in user booking flow** — `app/Http/Controllers/User/User_UserController.php`, `resources/views/user/bookings/book.blade.php`, `public/js/book.js`
- **Admin** — courts, bookings, activity logs, announcements, reports, and business-hours/closure configuration under `app/Http/Controllers/Admin/`
- **Payment webhooks** — `app/Http/Controllers/Webhooks/`
- **Booking hours & closures** — `app/Models/BusinessSetting.php`, `app/Models/CourtClosure.php`, configured from the admin Configuration page

## Troubleshooting

| Symptom | Fix |
|---|---|
| `Failed to open stream: vendor/autoload.php` | Run `composer install` — dependencies were never installed. |
| `SQLSTATE[HY000] [1049] Unknown database` | Create the database from step 2, and confirm `.env` matches. |
| `No application encryption key has been specified` | Run `php artisan key:generate`. |
| Styles/JS not loading | Run `npm run dev` or `npm run build`, and make sure `php artisan serve` is running from the project root. |
| GCash/Landbank payments never confirm | Expected in local dev without the SMS forwarder set up — see [Payment Webhook Setup](#payment-webhook-setup). |

## License

The Laravel framework this project is built on is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).