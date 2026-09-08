# St. Mark's Laptop Tracking System — v2

A redesigned, mobile-friendly upgrade of the existing PHP/MySQL laptop tracking
system, with parent SMS notifications, overdue alerts, bulk CSV import, a
mobile-first scanner, and a dark-mode theme.

> Drop this folder into your existing PHP server (Apache/Nginx + PHP 7.4+ +
> MySQL) and point your browser at `index.php`. The same database as the old
> system is used — schema upgrades run automatically on first page load.

---

## What's new

### Visual & UX
- New unified design system (`assets/css/theme.css`) with **light + dark mode**.
  Theme is toggled from the topbar and remembered per browser.
- Modern indigo → teal palette, Inter font, rounded cards, soft shadows.
- Collapsible sidebar on desktop, off-canvas drawer on mobile, sticky topbar.
- Refreshed **dashboard** with KPI tiles, lab status, weekly chart, and recent
  activity feed.
- Refreshed **login** screen.

### New features
- **Mobile-first QR scanner** (`pages/scan.php`) — back camera by default,
  manual-entry mode, smart action buttons that adapt to current laptop status,
  and an optional **due-date** field captured at issue time.
- **Bulk CSV import** (`pages/import.php`) for students, parents, and laptops.
  Includes downloadable templates and inline error reporting.
- **Overdue Laptops** page (`pages/overdue.php`) listing every overdue laptop,
  with one-click and bulk SMS to parents.
- **SMS Settings** page (`pages/sms_settings.php`) — pick provider, enter
  credentials, and send a test message.
- **SMS notifications** — when a laptop is issued / returned / out for project
  / taken home, the parent on file gets an SMS automatically (when configured).
- **Cron job** (`cron/check_overdue.php`) — sends a parent SMS for any laptop
  past its due date, deduplicated to one message per laptop per 12 hours.

### Data model additions (created automatically)
- `laptops.due_date` — DATETIME, when the laptop must be returned.
- `laptops.issued_at` — DATETIME, last time the laptop was issued.
- `settings` (key/value) — stores SMS provider config.
- `notifications_log` — audit trail of every SMS attempt.
- `overdue_alerts` — used by the cron to deduplicate overdue reminders.

No manual migration needed — `includes/migrate.php` runs idempotently on every
page load (cheap `SHOW COLUMNS` checks).

---

## Setup

### 1. Database
Use your existing database. Open `config/config.php` and set credentials:
```php
$conn = new mysqli('localhost', 'db_user', 'db_pass', 'db_name');
```

### 2. Web server
Point your virtual host's document root at this folder. Make sure:
- PHP 7.4 or newer with `mysqli`, `curl`, and `mbstring` extensions.
- The `qrcodes/` and `uploads/` folders are writable by PHP.

### 3. SMS provider (optional but recommended)
Open `pages/sms_settings.php` and configure either:
- **Twilio** — Account SID, Auth Token, and the sending phone number, OR
- **Africa's Talking** — username (`sandbox` for testing) + API key, plus
  sender ID / shortcode.

You can also override any setting via environment variables:
`SMS_PROVIDER`, `SMS_FROM`, `SMS_ACCOUNT_SID`, `SMS_AUTH_TOKEN`,
`SMS_AT_USERNAME`, `SMS_AT_API_KEY`.

Phone numbers are normalised automatically — `07XX...` is converted to
`+256...` (Uganda) when no country code is present. Other formats should be
entered in international form.

### 4. Cron (overdue reminders)
Add the following line to your server's crontab to run every 30 minutes:
```
*/30 * * * * /usr/bin/php /var/www/laptop_tracking_system/cron/check_overdue.php >> /var/log/lts_overdue.log 2>&1
```
(Replace the path and PHP binary as needed.)

---

## File map

| Path | Purpose |
|---|---|
| `assets/css/theme.css` | Unified design system (light + dark). |
| `assets/js/theme.js`   | Theme + sidebar persistence. |
| `includes/header.php`  | Shared `<head>` + topbar + page shell. |
| `includes/sidebar.php` | Modern navigation. |
| `includes/footer.php`  | Shared close + Bootstrap JS. |
| `includes/sms.php`     | SMS sending (Twilio / Africa's Talking) + audit log. |
| `includes/migrate.php` | Idempotent schema upgrades. |
| `pages/dashboard.php`  | New dashboard. |
| `pages/scan.php`       | Mobile-first QR scanner. |
| `pages/import.php`     | CSV bulk import. |
| `pages/overdue.php`    | Overdue list with bulk SMS. |
| `pages/sms_settings.php` | SMS provider configuration. |
| `cron/check_overdue.php` | Cron job for overdue reminders. |

All other pages (laptops, students, parents, logs, reports, etc.) keep their
original logic but now share the new layout via `header.php` / `footer.php`.

---

## Tips for the lab

- The scanner works on phones, tablets, and laptops with a webcam — no app
  install needed. Allow camera access on first use.
- Use the **manual** tab on the scanner page if a QR is damaged — works with
  USB barcode wedges too.
- When issuing a laptop, set the **Return by** date — it's what overdue alerts
  use. Returning the laptop clears the date automatically.
- The dark mode toggle (moon/sun icon top-right) saves per browser.
- Test SMS from `SMS Settings` before going live. Keep an eye on the
  **Recent messages** column there for delivery status.
