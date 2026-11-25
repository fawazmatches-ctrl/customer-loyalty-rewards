# AI Collaboration & Decision Log (`AI_LOG.md`)

This log documents key prompts used to design, build, audit, and fix the Customer Loyalty Rewards System, including instances where the AI produced flawed initial outputs that I caught and corrected.

---

### Interaction 1: Designing the Proof-of-Purchase Mechanism
* **Prompt:** *"We are building a loyalty system for a factory selling boxed goods through retail stores. How should a buyer prove purchase without uploading paper receipts, and how do we generate codes that customers won't misread?"*
* **AI Output:** Suggested printing unique codes inside each sealed box using `uniqid()` or `md5(rand())` in PHP.
* **Critical Audit & Fix:** `uniqid()` is timestamp-based and predictable, and `md5()` outputs lowercase hex strings containing `0`, `1`, `e`, and `b` that look confusing when printed on cardboard. I rejected `uniqid()`/`md5()` and directed the AI to implement `generateBoxCode()` using PHP's cryptographically secure `random_int()` with an unambiguous 31-character alphabet (excluding `0, O, 1, I, L`) plus an HMAC-SHA256 signature.

---

### Interaction 2: Database Schema & Concurrency Race Condition (AI Mistake #1)
* **Prompt:** *"Write the MySQL schema and PHP endpoint `api/claim_code.php` to verify a box code and add points to the customer's balance."*
* **Where the AI Was Wrong:** In its first draft, the AI ran a plain `SELECT * FROM box_codes WHERE code_plain = ?`, checked `if ($row['status'] == 'unclaimed')` in PHP, and then executed two separate `UPDATE` queries (`box_codes` and `customers`) **without a database transaction or row lock**. If a user double-clicked "Submit" or sent two concurrent API requests, both requests would read `status = 'unclaimed'` and award double points.
* **How I Noticed & Fixed It:** During code review of `api/claim_code.php`, I spotted the TOCTOU (Time-of-Check to Time-of-Use) race condition. I instructed the AI to wrap the entire lookup, customer balance update, and code status update inside `$pdo->beginTransaction()` and append `FOR UPDATE` to the `SELECT` query so MySQL locks the row until commit.

---

### Interaction 3: Password Storage & Admin Authentication (AI Mistake #2)
* **Prompt:** *"Create the factory owner login page `admin/login.php` using credentials configured in `.env`."*
* **Where the AI Was Wrong:** The AI initially wrote `if ($_POST['username'] === getenv('ADMIN_USER') && $_POST['password'] === getenv('ADMIN_PASS'))`, comparing plaintext passwords directly without hashing or session ID regeneration, and lacked a database table for admin users.
* **How I Noticed & Fixed It:** Hardcoding plaintext comparison in authentication logic is a security anti-pattern and prevents multi-admin auditing. I directed the AI to create an `admin_users` table, seed the initial owner account using `password_hash($defaultPass, PASSWORD_DEFAULT)`, verify logins with `password_verify()`, and call `session_regenerate_id(true)` upon login to prevent session fixation.

---

### Interaction 4: Adding Brute-Force Rate Limiting & Audit Logging
* **Prompt:** *"What prevents an attacker from writing a script to guess valid `BOX-XXXX-XXXX-XXXX` codes on the public claim form?"*
* **AI Output:** Suggested adding a simple JavaScript cooldown timer on the submit button.
* **Critical Audit & Fix:** Client-side JavaScript timers are trivially bypassed using `curl` or Postman. I instructed the AI to add a `claim_attempts` table in MySQL and enforce server-side IP rate limiting (`checkRateLimit()` in `config/security.php`) that blocks an IP for 15 minutes after 5 failed attempts and logs every failed attempt for the factory owner's fraud monitor.

---

### Interaction 5: Batch Recall & Printer CSV Export Workflow
* **Prompt:** *"How can the factory owner send generated codes to the box printing machine and revoke a batch if boxes are stolen or defective?"*
* **AI Output:** Built `admin/export_codes.php` to stream a CSV containing the batch code, SKU, plain box code, HMAC signature, and pre-filled claim URL, plus a `Recall Batch` action in `admin/dashboard.php` that atomically marks the batch `recalled` and voids all remaining `unclaimed` codes in that batch.
* **Verification:** Tested recalling `BATCH-2026-RECALL` and verified that `BOX-R1C9-L8B2-M4V7` is immediately rejected on the customer portal.

---

### Interaction 6: Automated Verification Suite
* **Prompt:** *"Write a standalone PHP CLI test script `tests/test_runner.php` that verifies code entropy, HMAC signatures, schema integrity, single-use status transitions, and rate-limit lockouts."*
* **Result:** Created `tests/test_runner.php` which runs 5 automated assertions against MySQL and cleans up its test rows after execution.
