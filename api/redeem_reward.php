<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
if (!verifyCsrfToken($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF security token.']);
    exit;
}

$phone = preg_replace('/[^0-9]/', '', $input['phone'] ?? '');
$rewardId = (int)($input['reward_id'] ?? 0);

if (strlen($phone) < 10 || $rewardId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid phone number and reward selection are required.']);
    exit;
}

$pdo = getDBConnection();

try {
    $pdo->beginTransaction();

    $custStmt = $pdo->prepare("SELECT id, full_name, points_balance, is_flagged FROM customers WHERE phone = ? FOR UPDATE");
    $custStmt->execute([$phone]);
    $customer = $custStmt->fetch();

    if (!$customer) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'No customer account found for this mobile number.']);
        exit;
    }

    if ((int)$customer['is_flagged'] === 1) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Account flagged for review. Redemption blocked.']);
        exit;
    }

    $rewStmt = $pdo->prepare("SELECT id, title, points_required, stock_available, is_active FROM reward_catalog WHERE id = ? FOR UPDATE");
    $rewStmt->execute([$rewardId]);
    $reward = $rewStmt->fetch();

    if (!$reward || (int)$reward['is_active'] !== 1) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Selected reward is currently unavailable.']);
        exit;
    }

    if ((int)$reward['stock_available'] <= 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Sorry, this reward is out of stock.']);
        exit;
    }

    if ((int)$customer['points_balance'] < (int)$reward['points_required']) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => "Insufficient points. You have {$customer['points_balance']} pts, but need {$reward['points_required']} pts."
        ]);
        exit;
    }

    // Deduct points & decrement stock atomically
    $newBalance = (int)$customer['points_balance'] - (int)$reward['points_required'];
    $voucherCode = 'VCH-' . strtoupper(bin2hex(random_bytes(4)));

    $pdo->prepare("UPDATE customers SET points_balance = ? WHERE id = ?")->execute([$newBalance, $customer['id']]);
    $pdo->prepare("UPDATE reward_catalog SET stock_available = stock_available - 1 WHERE id = ?")->execute([$reward['id']]);
    $pdo->prepare("
        INSERT INTO redemptions (customer_id, reward_id, points_spent, voucher_code, status)
        VALUES (?, ?, ?, ?, 'pending')
    ")->execute([$customer['id'], $reward['id'], $reward['points_required'], $voucherCode]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Redeemed '{$reward['title']}'! Your voucher code is {$voucherCode}.",
        'voucher_code' => $voucherCode,
        'new_balance' => $newBalance
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Redeem Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to process redemption.']);
}
