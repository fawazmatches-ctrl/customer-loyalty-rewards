# Design Assumptions (`ASSUMPTIONS.md`)

Because the factory owner's initial brief leaves operational details open, the following engineering and business assumptions were made and implemented:

1. **Proof of Purchase via Inside-Box Unique Scratch/QR Codes:**
   - *Gap:* Physical boxes sold through third-party distributors/retailers do not give the factory direct access to retail POS receipts.
   - *Decision:* During factory packaging, a unique, high-entropy 12-character alphanumeric code (e.g., `BOX-A7K9-M2P4-X8Q3`) plus an HMAC-SHA256 signature is printed on an insert card or under a scratch-off label **inside the sealed box**. Only a buyer who opens the box can view and claim the code.

2. **Mobile Phone Number as Passwordless Customer Identity:**
   - *Gap:* Asking buyers to complete lengthy email/password signups reduces scan conversion rates.
   - *Decision:* Customers are identified by their 10-digit mobile phone number (`customers.phone`), creating a frictionless wallet on their first valid box claim. (In production, an SMS OTP gateway can be plugged into `api/claim_code.php` before crediting points.)

3. **Unambiguous Character Set for Printed Codes:**
   - *Gap:* Customers typing codes printed on cardboard often confuse `0` with `O` or `1` with `I`/`L`.
   - *Decision:* `generateBoxCode()` uses a 31-character Crockford-inspired alphabet (`23456789ABCDEFGHJKMNPQRSTUVWXYZ`) that removes ambiguous characters while still providing $31^{12} \approx 7.8 \times 10^{17}$ possible combinations.

4. **Point Valuation & Reward Catalog:**
   - *Gap:* Reward tiers and fulfillment mechanics were unspecified.
   - *Decision:* Each factory SKU is assigned a fixed `points_value` (e.g., 80 to 150 points per box). Customers redeem accumulated points for factory-controlled rewards (cashback vouchers, branded merchandise, extended warranty), generating a unique `VCH-XXXXXXXX` code that the factory owner marks as `fulfilled` in the admin dashboard.

5. **Batch-Level Recall & Expiry Controls:**
   - *Gap:* Factories occasionally recall defective batches or run time-bound promotions.
   - *Decision:* Every box code belongs to a `batches` record. If the factory owner recalls a batch in the dashboard, all unclaimed codes in that batch are atomically set to `voided` so they cannot be redeemed.
