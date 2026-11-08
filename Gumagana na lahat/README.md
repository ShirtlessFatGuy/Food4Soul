# Food4Soul Inventory System: PHP / MySQL implementation

The working implementation of the system described in Chapter 2 of the capstone
paper: a three-tier architecture (HTML/CSS/JS presentation layer, PHP application
layer, MySQL data layer) with Role-Based Access Control for Admin, Supplier and
Reseller users.

## 1. Requirements
- PHP 8.0+ and MySQL 8+ / MariaDB 10.2+
- A modern browser

## 2. Database setup
1. Start **Apache** and **MySQL** in the XAMPP control panel.
2. Open phpMyAdmin → **Import** → choose `database/food4soul_schema.sql` → **Go**.
   This creates the `food4soul` database, all ERD tables, sample products,
   suppliers, orders, 14 days of sample sales, and the initial low-stock alerts.
   Already imported an older copy? Import `database/migration_payments.sql` once to add the QR payments table.
3. If your MySQL login is not `root` with an empty password, edit `config/db.php`.

## 3. Set real passwords for the demo accounts
The SQL file seeds three users with placeholder passwords (it never contains real
hashes). From a terminal in this folder run once:

```
php database/generate_demo_users.php
```

| Role     | Email                    | Password    |
|----------|--------------------------|-------------|
| Admin    | admin@food4soul.com      | admin123    |
| Supplier | supplier@food4soul.com   | supplier123 |
| Reseller | reseller@food4soul.com   | reseller123 |

> In XAMPP, the `php` command lives in `C:\xampp\php\php.exe`. Run it as
> `C:\xampp\php\php.exe database\generate_demo_users.php`.

## 4. Run it
All links in the app are root-relative (`/login.php`, `/assets/css/style.css`), so the
site must be served from the **root** of a host, not from a sub-folder. Pick one:

**Option A: PHP built-in server (simplest, great for demos)**
```
cd food4soul3
php -S localhost:8000
```
Then open <http://localhost:8000/login.php>.

**Option B: XAMPP Apache**
Copy the *contents* of this folder directly into `htdocs/` (not into a sub-folder),
or create an Apache VirtualHost whose DocumentRoot is this folder. Then open
<http://localhost/login.php>.

## 4b. QR payments (reseller checkout)
Choosing **QR payment** at checkout does not record the sale yet. It creates a *Pending* payment
(`payments` table) and opens `reseller/pay.php`, which shows a QR code, the amount, a 15-minute
countdown and polls for confirmation every 3 s. The sale is recorded and stock deducted only when
the payment becomes *Paid*; expired, cancelled and failed payments never touch stock.

Settings are in `config/payment.php`:
- `mode = 'demo'` (default): the pay page has a **Simulate payment** button standing in for the bank. No real money moves.
- `mode = 'live'`: no button. A gateway (PayMongo, Xendit, ...) must call `api/payment_webhook.php`
  with header `X-Signature: HMAC-SHA256(body, webhook_secret)` and body
  `{"reference":"F4S-...","status":"paid","provider_ref":"..."}`. Change `webhook_secret` first.
  To go live you also need a gateway account, a publicly reachable HTTPS URL for the webhook, and
  code that asks the gateway for its QR string and saves it in `payments.QR_Payload`.

## 4c. Order cycle and live sync (admin ↔ supplier ↔ reseller)
Pages share the database and a small live-sync layer, so you can demo the whole cycle with
two browsers (or one normal window + one private window, since each login needs its own session):

1. **Admin** → *Restock Orders* → **New restock order**: pick a supplier, set quantities, *Place restock order*.
   (On *Inventory* / *Alerts*, the **Reorder** button opens the form pre-filled for a low-stock product.)
2. **Supplier** → *Assigned Orders*: the order appears by itself (no refresh). Set it to *Processing*, then *Delivered*.
3. Marking **Delivered** adds the ordered quantities to each product's stock, exactly once, in one
   transaction. Low-stock alerts that are no longer true are cleared, and the admin gets a *Delivery* alert.
   *Delivered* and *Cancelled* are final; the admin can *Cancel* an order that isn't delivered yet.
4. Meanwhile the admin's *Dashboard*, *Inventory*, *Stock Movements*, *Alerts* and *Restock Orders* pages, the supplier's pages and the
   reseller's catalog all update within ~2 s, with the changed values highlighted. A reseller checkout
   lowers the same stock numbers everywhere, live.

How it works: `assets/js/live.js` polls `api/live.php` (a tiny fingerprint of stock, orders, alerts and sales)
every 2 s. When it changes, the page re-fetches itself and swaps the regions marked `data-live="..."`;
the charts refresh through `Live.on(...)`. A region is skipped while you are typing in it, and the
poller pauses in background tabs. The "Live" pill in the sidebar shows the connection state.
No new tables or migrations are needed. For two simultaneous users prefer XAMPP/Apache; with the PHP
built-in server use `PHP_CLI_SERVER_WORKERS=4 php -S localhost:8000` (not supported on Windows, where
a single user plus the polling still works).

## 4d. Plain-language forecast summary (Gemini)
The Sales Forecast page (`admin/forecast.php`) opens with an **"In plain words"** box that explains the
forecast for non-technical admins: what has been happening, what to expect, things to watch, suggested
next steps and how far to trust it.

- **Setup:** get a key at https://aistudio.google.com/apikey, then either set the `GEMINI_API_KEY`
  environment variable (Hostinger: `SetEnv GEMINI_API_KEY ...` in `.htaccess`) or paste it into
  `config/gemini.php`. Without a key the box simply says the summary is switched off; nothing else breaks.
  Requires the PHP **cURL** extension (on by default in XAMPP and Hostinger). Do not commit a real key.
- **Division of labour:** every number still comes from Holt-Winters (`includes/forecasting.php`).
  `includes/gemini.php` condenses the results into a small fact sheet and asks Gemini only to reword it.
  Gemini never sees raw database rows and cannot change a forecast. Its reply is shown as plain text only.
- **Cost control:** the summary is saved for 24 h per data/settings combination (a temp folder), so
  reopening the page does not call Gemini again. **Regenerate** forces a fresh one. Failures are never saved.
- **Endpoint:** `api/forecast_summary.php` (Admin only; regenerate needs the CSRF token).
- **Paper:** the Limitations section says the system "will not integrate AI". This feature uses an LLM for
  explanation only, so update that sentence (the Objectives already list LLM summaries).

## 5. Role routing and security (answers for the defense panel)
- Each role is redirected to its own portal after login; every protected page calls
  `require_role()` in `includes/auth.php`, so access is enforced **server-side**,
  not just hidden in the menu.
- Passwords: bcrypt via `password_hash()` / `password_verify()` (REQ-007).
- All SQL uses PDO prepared statements; all output is escaped with `htmlspecialchars()`.
- All state-changing forms carry a CSRF token; the session ID is regenerated on login.
- Suppliers can only edit **their own** orders and products (ownership checks in
  `supplier/orders.php` and `supplier/inventory.php`).
- Checkout runs in a database transaction, rejects quantities above available stock,
  decrements inventory and records the sale atomically.

## 6. Paper → code map
| Paper item | Where |
|---|---|
| Three-Tier Architecture (Fig. 3) | `assets/` + page templates · `includes/` + role folders · MySQL via `config/db.php` |
| ERD / data dictionary (Fig. 9) | `database/food4soul_schema.sql` |
| REQ-001, REQ-002 | `login.php`, `includes/auth.php` |
| REQ-003 | `admin/dashboard.php` + `api/dashboard_stats.php`: stock vs reorder level, low-stock list, incoming deliveries, stock movement (Chart.js, live) |
| Stock movement ledger | `admin/movements.php`, `includes/functions.php::movement_union_sql()`: deliveries in (Delivered restock orders) and reseller orders out (derived, no extra table) |
| REQ-004 | `includes/functions.php::update_stock()` raises Low Stock alerts |
| REQ-005 | `supplier/orders.php`, `supplier/dashboard.php`; delivery adds stock via `includes/functions.php::set_order_status()` |
| Restock ordering | `admin/orders.php` + `create_restock_order()` |
| Live sync | `api/live.php` + `assets/js/live.js` |
| REQ-006 | `admin/reports.php` + `api/movement_trend.php`: inventory report (stock status, units received/issued, fastest mover), print-friendly |
| REQ-007, REQ-010 | bcrypt hashing; foreign keys in the schema |

## 7. Known gaps (be upfront about these)
- **Not implemented:** real e-bank / e-wallet gateway (QR checkout runs in demo mode, see section 4b).
- **Not implemented:** two-step verification and automated scheduled/e-mailed weekly
  reports (mentioned in Chapter 1). Reports are generated on demand.
- **Not implemented:** admin screens to create users, products or suppliers; those records are managed
  through the seed data / phpMyAdmin for now. (Restock orders *can* be created in the portal.)
- **Scope note:** Chapter 1 limits the system to "no AI/ML", but the Specific
  Objectives section also lists an AI/LLM feedback summarizer and a customer-feedback
  mechanism. The only LLM use built so far is the Gemini forecast summary (section 4d); the
  feedback summarizer and feedback mechanism are not built. The paper contradicts itself here,
  so settle it with your adviser before the defense.
- Alerts are in-app only (no e-mail/SMS), and there are no alerts yet for delivery status changes or system errors.
- This has **not been run against a live PHP/MySQL stack in the authoring
  environment** (no PHP available there), so do a full click-through on XAMPP first.
