<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

$phone = preg_replace('/[^0-9]/', '', $_GET['phone'] ?? '');
if (strlen($phone) < 10) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid 10-digit mobile number.']);
    exit;
}

$pdo = getDBConnection();

$custStmt = $pdo->prepare("SELECT id, full_name, phone, points_balance, total_points_earned, is_flagged FROM customers WHERE phone = ?");
$custStmt->execute([$phone]);
$customer = $custStmt->fetch();

if (!$customer) {
    echo json_encode(['success' => false, 'message' => 'No wallet found for this number yet. Claim your first box code to create one!']);
    exit;
}

$claimsStmt = $pdo->prepare("
    SELECT bc.code_plain, bc.claimed_at, p.name AS product_name, p.points_value, b.batch_code
    FROM box_codes bc
    JOIN batches b ON bc.batch_id = b.id
    JOIN products p ON b.product_id = p.id
    WHERE bc.claimed_by_customer_id = ?
    ORDER BY bc.claimed_at DESC
");
$claimsStmt->execute([$customer['id']]);
$claims = $claimsStmt->fetchAll();

$redStmt = $pdo->prepare("
    SELECT r.voucher_code, r.points_spent, r.status, r.redeemed_at, rc.title
    FROM redemptions r
    JOIN reward_catalog rc ON r.reward_id = rc.id
    WHERE r.customer_id = ?
    ORDER BY r.redeemed_at DESC
");
$redStmt->execute([$customer['id']]);
$redemptions = $redStmt->fetchAll();

echo json_encode([
    'success' => true,
    'customer' => $customer,
    'claims' => $claims,
    'redemptions' => $redemptions
]);
