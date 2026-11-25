# System Architecture (`ARCHITECTURE.md`)

## 1. Tech Stack & Rationale
* **Backend:** PHP 8.x (with PDO prepared statements) — Zero external framework overhead, runs natively on XAMPP/Apache/Nginx, and supports strict transactional locking (`InnoDB` + `SELECT ... FOR UPDATE`).
* **Database:** MySQL / MariaDB (`InnoDB` engine) — Provides ACID transactions essential for preventing double-spending of box codes and loyalty points under concurrent requests.
* **Frontend:** Semantic HTML5, Vanilla CSS3 (responsive grid layout), and Vanilla JavaScript (`Fetch API`) — Fast load times on mobile browsers when customers scan a box code in-store or at home.
* **Security:** CSRF session tokens, `password_hash()` (bcrypt) for admin credentials, HMAC-SHA256 code signatures, and IP-based sliding-window rate limiting.

## 2. System Flow & Anti-Fraud Diagram

```mermaid
flowchart TD
    A[Factory Admin Dashboard] -->|1. Create SKU & Batch| B[(MySQL: batches & box_codes)]
    A -->|2. Export CSV for Box Printer| C[Physical Product Box with Inside Label]
    C -->|3. Customer Opens Box & Enters Code| D[Public Customer Portal]
    D -->|4. POST /api/claim_code.php + CSRF| E{Rate Limit Check<br/>Max 5 fails / 15m}
    E -->|Blocked| F[HTTP 429 Lockout + Log Attempt]
    E -->|Allowed| G[BEGIN TRANSACTION<br/>SELECT ... FOR UPDATE]
    G --> H{Code Valid, Unclaimed,<br/>& Batch Active?}
    H -->|No: Already Claimed / Recalled| I[ROLLBACK + Log Fraud Attempt]
    H -->|Yes| J[Credit Customer Wallet<br/>Mark Code 'claimed' + COMMIT]
    J --> K[Customer Redeems Points for Vouchers]
```

## 3. Entity-Relationship Data Model

```mermaid
erDiagram
    PRODUCTS ||--o{ BATCHES : "manufactured in"
    BATCHES ||--o{ BOX_CODES : "contains"
    CUSTOMERS ||--o{ BOX_CODES : "claims"
    CUSTOMERS ||--o{ REDEMPTIONS : "places"
    REWARD_CATALOG ||--o{ REDEMPTIONS : "fulfilled via"

    PRODUCTS {
        int id PK
        string sku UK
        string name
        int points_value
    }
    BATCHES {
        int id PK
        string batch_code UK
        int product_id FK
        date manufactured_date
        enum status
    }
    BOX_CODES {
        bigint id PK
        int batch_id FK
        string code_plain UK
        string code_signature
        enum status
        int claimed_by_customer_id FK
        datetime claimed_at
    }
    CUSTOMERS {
        int id PK
        string full_name
        string phone UK
        int points_balance
        tinyint is_flagged
    }
    REWARD_CATALOG {
        int id PK
        string title
        int points_required
        int stock_available
    }
    REDEMPTIONS {
        int id PK
        int customer_id FK
        int reward_id FK
        int points_spent
        string voucher_code UK
        enum status
    }
    CLAIM_ATTEMPTS {
        bigint id PK
        string ip_address
        string code_submitted
        tinyint success
        string failure_reason
    }
```

## 4. How Core Requirements Are Addressed
1. **Proof of Purchase:** Every box receives a unique `BOX-XXXX-XXXX-XXXX` code generated via `random_int()` and signed with `hash_hmac('sha256')` stored inside the sealed packaging.
2. **Fraud & Reuse Prevention:**
   - **Row-Level Locking:** `api/claim_code.php` wraps verification and point crediting inside a MySQL transaction using `SELECT ... FOR UPDATE`. If two requests submit the same code at the exact same millisecond, the second request waits for the first commit and is immediately rejected as already claimed.
   - **Brute-Force Protection:** `checkRateLimit()` blocks any IP with 5 failed claims within 15 minutes.
   - **Account Freeze:** Admins can flag suspicious customer accounts (`is_flagged = 1`) to block further claims or redemptions.
3. **Factory Owner Monitoring:** `admin/dashboard.php` tracks total codes printed vs. claimed scan rate, exports printer-ready CSVs, allows one-click batch recalls, queues reward redemptions, and displays a live feed of blocked fraud attempts.
