USE loyalty_rewards_db;

-- Seed Factory Products
INSERT IGNORE INTO products (id, sku, name, category, points_value) VALUES
(1, 'APX-PWR-01', 'Apex Heavy-Duty Power Drill Box', 'Power Tools', 150),
(2, 'APX-LED-02', 'Apex Smart Industrial LED Floodlight (Pack of 2)', 'Lighting', 80),
(3, 'APX-FLT-03', 'Apex Multi-Stage Water Purifier Cartridge', 'Home Appliances', 100);

-- Seed Manufacturing Batches
INSERT IGNORE INTO batches (id, batch_code, product_id, manufactured_date, expiry_date, quantity_generated, status) VALUES
(1, 'BATCH-2026-001', 1, '2026-10-01', '2028-10-01', 5, 'active'),
(2, 'BATCH-2026-002', 2, '2026-10-15', '2028-10-15', 5, 'active'),
(3, 'BATCH-2026-RECALL', 3, '2026-09-01', '2027-09-01', 2, 'recalled');

-- Seed Sample Box Codes (Ready for testing & demo!)
INSERT IGNORE INTO box_codes (batch_id, code_plain, code_signature, status) VALUES
(1, 'BOX-A7K9-M2P4-X8Q3', 'demo0001', 'unclaimed'),
(1, 'BOX-B3N8-R5T2-Y9W4', 'demo0002', 'unclaimed'),
(1, 'BOX-C4V6-H8J2-Z3K7', 'demo0003', 'unclaimed'),
(2, 'BOX-D9P2-W4M7-Q6R8', 'demo0004', 'unclaimed'),
(2, 'BOX-E5T3-K9N4-P2X6', 'demo0005', 'unclaimed'),
(3, 'BOX-R1C9-L8B2-M4V7', 'demo0006', 'unclaimed');

-- Seed Reward Catalog
INSERT IGNORE INTO reward_catalog (id, title, description, points_required, stock_available) VALUES
(1, '₹250 Direct Cashback Voucher', 'Instant UPI / Bank cashback voucher redeemable on verified purchase.', 200, 500),
(2, 'Apex Branded Heavy-Duty Tool Bag', 'Water-resistant industrial canvas tool bag shipped free to your address.', 350, 75),
(3, '1-Year Extended Factory Warranty', 'Adds 12 months of priority replacement coverage on any Apex product.', 150, 999);

-- Seed Demo Customer
INSERT IGNORE INTO customers (id, full_name, phone, email, points_balance, total_points_earned) VALUES
(1, 'Rohan Desai', '9876543210', 'rohan.desai@example.com', 150, 150);

-- Pre-claim one code so duplicate/reuse prevention can be tested immediately
INSERT IGNORE INTO box_codes (batch_id, code_plain, code_signature, status, claimed_by_customer_id, claimed_at, claim_ip) VALUES
(1, 'BOX-USED-9999-TEST', 'demo9999', 'claimed', 1, NOW(), '127.0.0.1');
