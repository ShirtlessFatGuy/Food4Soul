# Food4Soul Analytics Portal: PHP / MySQL implementation

The working implementation of the system described in Chapter 2 of the capstone
paper: a three-tier architecture (HTML/CSS/JS presentation layer, PHP application
layer, MySQL data layer) with Role-Based Access Control for Admin, Supplier and
Reseller users.

## 1. Requirements
- PHP 8.0+ and MySQL 8+ / MariaDB 10.2+ (XAMPP bundles all of this)
- A modern browser

## 2. Database setup
1. Start **Apache** and **MySQL** in the XAMPP control panel.
2. Open phpMyAdmin → **Import** → choose `database/food4soul_schema.sql` → **Go**.
   This creates the `food4soul` database, all ERD tables, sample products,
   suppliers, orders, 14 days of sample sales, and the initial low-stock alerts.
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
cd f4s-backend
php -S localhost:8000
```
Then open <http://localhost:8000/login.php>.

**Option B: XAMPP Apache**
Copy the *contents* of this folder directly into `htdocs/` (not into a sub-folder),
or create an Apache VirtualHost whose DocumentRoot is this folder. Then open
<http://localhost/login.php>.

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
| REQ-003 | `admin/dashboard.php` + `api/dashboard_stats.php` (Chart.js, auto-refresh every 30 s) |
| REQ-004 | `includes/functions.php::update_stock()` raises Low Stock alerts |
| REQ-005 | `supplier/orders.php`, `supplier/dashboard.php` |
| REQ-006 | `admin/reports.php` (print-friendly) |
| REQ-007, REQ-010 | bcrypt hashing; foreign keys in the schema |

## 7. Known gaps (be upfront about these)
- **Not implemented:** two-step verification and automated scheduled/e-mailed weekly
  reports (mentioned in Chapter 1). Reports are generated on demand.
- **Not implemented:** admin screens to create users, products, suppliers or restock
  orders; those records are managed through the seed data / phpMyAdmin for now.
- **Scope note:** Chapter 1 limits the system to "no AI/ML", but the Specific
  Objectives section also lists an AI/LLM feedback summarizer and a customer-feedback
  mechanism. Neither is built, and the paper contradicts itself here, so settle it
  with your adviser before the defense.
- Alerts are never marked "Read" in the UI yet.
- This has **not been run against a live PHP/MySQL stack in the authoring
  environment** (no PHP available there), so do a full click-through on XAMPP first.
