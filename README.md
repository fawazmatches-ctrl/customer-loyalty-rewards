# Apex Customer Loyalty Rewards System

A full-stack **Factory-to-Consumer Loyalty & Anti-Counterfeit Rewards Platform** built with **PHP 8, MySQL, HTML5, CSS3, and Vanilla JavaScript**.

Factories selling physical products in sealed boxes through retail distributors often lack direct visibility into end-customers. This system embeds unique, tamper-evident codes inside product boxes so buyers can prove purchase, earn loyalty points, and redeem rewards—while giving the factory owner real-time batch tracking, CSV printer exports, and fraud monitoring.

---

## Core Features

1. **Proof of Purchase (`public/index.php` & `api/claim_code.php`):**
   - Customers enter the unique 12-character code (`BOX-XXXX-XXXX-XXXX`) printed inside their sealed product box along with their mobile number.
   - Instant verification awards SKU-specific loyalty points to the customer's mobile wallet.
2. **Fraud & Code Reuse Prevention (`config/security.php`):**
   - **Single-Use Atomic Locking:** Uses MySQL `InnoDB` transactions with `SELECT ... FOR UPDATE` row locking so a box code can never be claimed twice—even under simultaneous requests.
   - **Cryptographic Generation & Signing:** Codes are generated using `random_int()` over an unambiguous 31-character alphabet (no `0/O` or `1/I/L`) and paired with an `HMAC-SHA256` signature.
   - **Brute-Force Rate Limiting:** Blocks any IP address for 15 minutes after 5 failed claim attempts and logs every failure.
   - **Batch Recalls & Account Freezing:** Factory owners can void entire batches or freeze suspicious customer wallets.
3. **Factory Owner Control Center (`admin/dashboard.php`):**
   - KPI overview (total codes printed, verified claim rate, active customers, 24h fraud blocks, pending fulfillments).
   - Create factory product SKUs and generate up to 500 unique box codes per manufacturing batch.
   - Export printer-ready CSV files (`admin/export_codes.php`) containing codes, HMAC signatures, and pre-filled claim URLs.
   - Manage and fulfill customer reward redemptions (`VCH-XXXXXXXX`).

---

## Tech Stack

- **Backend:** PHP 8.x (PDO with prepared statements & transactions)
- **Database:** MySQL 8.0 / MariaDB 10.4+ (`InnoDB`)
- **Frontend:** HTML5, CSS3, Vanilla JavaScript (`Fetch API`)
- **Testing:** CLI Automated Test Runner (`tests/test_runner.php`) + Documented Manual Test Plan (`tests/MANUAL_TEST_PLAN.md`)

---

## Setup & Run Instructions (XAMPP / Local PHP + MySQL)

1. **Clone / Extract Project:**
   Place the `customer-loyalty-rewards` folder inside your web root (e.g., `C:\xampp\htdocs\customer-loyalty-rewards`).

2. **Configure Environment Variables:**
   ```bash
   cp .env.example .env
   ```
   Edit `.env` if your MySQL port (e.g., `3306` or `3307`) or password differs from XAMPP defaults.

3. **Initialize MySQL Database & Sample Data:**
   Open **phpMyAdmin** (or MySQL CLI) and import both SQL files in order:
   1. `sql/schema.sql` (creates `loyalty_rewards_db` and all 8 tables)
   2. `sql/seed.sql` (populates sample products, rewards, and demo box codes)

4. **Start Apache & MySQL and Open the App:**
   - **Customer Portal:** `http://localhost:8080/customer-loyalty-rewards/public/index.php` (adjust port `8080` or `80` to match your Apache config)
   - **Factory Owner Admin Portal:** `http://localhost:8080/customer-loyalty-rewards/admin/login.php`
     - Default Username: `admin`
     - Default Password: `ChangeMe123!` (configured in `.env`)

5. **Run Automated Tests:**
   ```bash
   php tests/test_runner.php
   ```

---

## Demo Test Codes (Pre-Seeded in `sql/seed.sql`)

| Box Code | Status | Purpose |
| :--- | :--- | :--- |
| `BOX-A7K9-M2P4-X8Q3` | Unclaimed | Test valid purchase claim (+150 pts) |
| `BOX-D9P2-W4M7-Q6R8` | Unclaimed | Test valid purchase claim (+80 pts) |
| `BOX-USED-9999-TEST` | Claimed | Test duplicate / code reuse fraud prevention |
| `BOX-R1C9-L8B2-M4V7` | Recalled Batch | Test recalled batch rejection |

---

## Repository Documentation
- [`ASSUMPTIONS.md`](ASSUMPTIONS.md) — Documented business and engineering assumptions
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — Stack rationale, ER diagram, and anti-fraud flow
- [`AI_LOG.md`](AI_LOG.md) — Key AI prompts, mistakes caught, and fixes applied
- [`tests/MANUAL_TEST_PLAN.md`](tests/MANUAL_TEST_PLAN.md) — Manual test cases and execution results
