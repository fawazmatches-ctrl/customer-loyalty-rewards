<?php
/**
 * Automated Verification & Anti-Fraud Test Suite
 * Run via CLI: php tests/test_runner.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

echo "=== Apex Customer Loyalty Rewards System — Automated Test Suite ===\n\n";

$pdo = getDBConnection();
$passed = 0;
$failed = 0;

function assertTrue($condition, $testName) {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $testName\n";
        $passed++;
    } else {
        echo "[FAIL] $testName\n";
        $failed++;
    }
}

// Test 1: Cryptographic Box Code Format & Unambiguous Alphabet
$sampleCode = generateBoxCode('BOX');
assertTrue(preg_match('/^BOX-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}$/', $sampleCode) === 1,
    "Code Generator produces valid 12-char unambiguous token ($sampleCode)");

// Test 2: HMAC Tamper Signature Consistency
$sig1 = signBoxCode($sampleCode);
$sig2 = signBoxCode(strtolower($sampleCode));
assertTrue(strlen($sig1) === 8 && $sig1 === $sig2,
    "HMAC-SHA256 signature is deterministic and case-insensitive ($sig1)");

// Test 3: Database Schema & Seed Integrity
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$expectedTables = ['products', 'batches', 'customers', 'box_codes', 'reward_catalog', 'redemptions', 'claim_attempts', 'admin_users'];
$missing = array_diff($expectedTables, $tables);
assertTrue(count($missing) === 0, "All 8 core MySQL tables exist");

// Test 4: Atomic Single-Use Code Claim & Duplicate Reuse Prevention
$testBatchCode = 'BATCH-TEST-' . rand(1000, 9999);
$testPhone = '999000' . rand(1000, 9999);
$testBoxCode = generateBoxCode('TST');

try {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO batches (batch_code, product_id, manufactured_date, quantity_generated, status) VALUES (?, 1, CURDATE(), 1, 'active')")
        ->execute([$testBatchCode]);
    $batchId = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO box_codes (batch_id, code_plain, code_signature, status) VALUES (?, ?, ?, 'unclaimed')")
        ->execute([$batchId, $testBoxCode, signBoxCode($testBoxCode)]);
    $pdo->commit();

    // Simulate first claim
    $pdo->beginTransaction();
    $row = $pdo->query("SELECT id, status FROM box_codes WHERE code_plain = '$testBoxCode' FOR UPDATE")->fetch();
    assertTrue($row && $row['status'] === 'unclaimed', "Freshly minted box code starts in 'unclaimed' status");

    $pdo->prepare("INSERT INTO customers (full_name, phone, points_balance, total_points_earned) VALUES ('Test Buyer', ?, 150, 150)")
        ->execute([$testPhone]);
    $custId = $pdo->lastInsertId();

    $pdo->prepare("UPDATE box_codes SET status = 'claimed', claimed_by_customer_id = ?, claimed_at = NOW() WHERE id = ?")
        ->execute([$custId, $row['id']]);
    $pdo->commit();

    // Verify second claim sees 'claimed'
    $rowAfter = $pdo->query("SELECT status FROM box_codes WHERE code_plain = '$testBoxCode'")->fetch();
    assertTrue($rowAfter['status'] === 'claimed', "Box code transitions permanently to 'claimed' after first redemption");

    // Cleanup test records
    $pdo->prepare("DELETE FROM box_codes WHERE batch_id = ?")->execute([$batchId]);
    $pdo->prepare("DELETE FROM batches WHERE id = ?")->execute([$batchId]);
    $pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$custId]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    assertTrue(false, "Transaction test threw exception: " . $e->getMessage());
}

// Test 5: Brute-Force Rate Limiting Detection
$testIp = '198.51.100.42';
$pdo->prepare("DELETE FROM claim_attempts WHERE ip_address = ?")->execute([$testIp]);
for ($i = 0; $i < 5; $i++) {
    logClaimAttempt($pdo, $testIp, '9999999999', 'BOX-FAKE-0000-0000', false, 'Automated test');
}
assertTrue(checkRateLimit($pdo, $testIp) === true, "Rate limiter locks out IP after 5 failed attempts");
$pdo->prepare("DELETE FROM claim_attempts WHERE ip_address = ?")->execute([$testIp]);

echo "\n=== Summary: $passed Passed, $failed Failed ===\n";
exit($failed > 0 ? 1 : 0);
