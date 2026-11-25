<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$pdo = getDBConnection();
$ip = getClientIp();

// 1. Check Brute-Force Rate Limit
if (checkRateLimit($pdo, $ip)) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Too many invalid attempts from your network. Please wait 15 minutes before trying again.'
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$csrfToken = $input['csrf_token'] ?? '';

if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
    exit;
}

$fullName = trim($input['full_name'] ?? '');
$phone = preg_replace('/[^0-9]/', '', $input['phone'] ?? '');
$email = trim($input['email'] ?? '');
$rawCode = strtoupper(trim($input['box_code'] ?? ''));

// Basic input validation
if (strlen($phone) < 10 || strlen($phone) > 15) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid 10-digit mobile number.']);
    exit;
}

if (empty($rawCode)) {
    echo json_encode(['success' => false, 'message' => 'Please enter the unique code printed inside your product box.']);
    exit;
}

try {
    // 2. Begin Atomic Transaction with Row-Level Lock (FOR UPDATE) to prevent race-condition double claims
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT bc.id, bc.code_plain, bc.status, bc.claimed_at,
               b.batch_code, b.status AS batch_status, b.expiry_date,
               p.name AS product_name, p.points_value
        FROM box_codes bc
        JOIN batches b ON bc.batch_id = b.id
        JOIN products p ON b.product_id = p.id
        WHERE bc.code_plain = ?
        FOR UPDATE
    ");
    $stmt->execute([$rawCode]);
    $codeRow = $stmt->fetch();

    if (!$codeRow) {
        $pdo->rollBack();
        logClaimAttempt($pdo, $ip, $phone, $rawCode, false, 'Code not found');
        echo json_encode(['success' => false, 'message' => 'Invalid box code. Please check the characters inside your box and try again.']);
        exit;
    }

    if ($codeRow['status'] === 'claimed') {
        $pdo->rollBack();
        logClaimAttempt($pdo, $ip, $phone, $rawCode, false, 'Duplicate claim attempt');
        echo json_encode([
            'success' => false,
            'message' => 'Fraud Alert: This box code has already been redeemed on ' . date('d M Y, h:i A', strtotime($codeRow['claimed_at'])) . '. Each box code can only be claimed once.'
        ]);
        exit;
    }

    if ($codeRow['status'] === 'voided' || $codeRow['batch_status'] === 'recalled') {
        $pdo->rollBack();
        logClaimAttempt($pdo, $ip, $phone, $rawCode, false, 'Recalled or voided batch');
        echo json_encode([
            'success' => false,
            'message' => 'This product batch (' . htmlspecialchars($codeRow['batch_code']) . ') has been voided or recalled by the factory. Contact support.'
        ]);
        exit;
    }

    if (!empty($codeRow['expiry_date']) && strtotime($codeRow['expiry_date']) < strtotime(date('Y-m-d'))) {
        $pdo->rollBack();
        logClaimAttempt($pdo, $ip, $phone, $rawCode, false, 'Expired batch');
        echo json_encode(['success' => false, 'message' => 'This promotional box code expired on ' . $codeRow['expiry_date'] . '.']);
        exit;
    }

    // 3. Find or Create Customer Record
    $custStmt = $pdo->prepare("SELECT id, full_name, points_balance, is_flagged FROM customers WHERE phone = ? FOR UPDATE");
    $custStmt->execute([$phone]);
    $customer = $custStmt->fetch();

    if ($customer) {
        if ((int)$customer['is_flagged'] === 1) {
            $pdo->rollBack();
            logClaimAttempt($pdo, $ip, $phone, $rawCode, false, 'Flagged customer blocked');
            echo json_encode(['success' => false, 'message' => 'Your account is temporarily under security review. Please contact customer support.']);
            exit;
        }
        $customerId = $customer['id'];
        $newBalance = (int)$customer['points_balance'] + (int)$codeRow['points_value'];

        $updCust = $pdo->prepare("
            UPDATE customers
            SET points_balance = points_balance + ?,
                total_points_earned = total_points_earned + ?,
                full_name = IF(? != '', ?, full_name)
            WHERE id = ?
        ");
        $updCust->execute([$codeRow['points_value'], $codeRow['points_value'], $fullName, $fullName, $customerId]);
    } else {
        if (empty($fullName)) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Welcome! Since this is your first claim, please enter your Full Name.']);
            exit;
        }
        $insCust = $pdo->prepare("
            INSERT INTO customers (full_name, phone, email, points_balance, total_points_earned)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insCust->execute([$fullName, $phone, $email ?: null, $codeRow['points_value'], $codeRow['points_value']]);
        $customerId = $pdo->lastInsertId();
        $newBalance = (int)$codeRow['points_value'];
    }

    // 4. Mark Box Code as Claimed
    $updCode = $pdo->prepare("
        UPDATE box_codes
        SET status = 'claimed',
            claimed_by_customer_id = ?,
            claimed_at = NOW(),
            claim_ip = ?
        WHERE id = ?
    ");
    $updCode->execute([$customerId, $ip, $codeRow['id']]);

    $pdo->commit();
    logClaimAttempt($pdo, $ip, $phone, $rawCode, true, 'Success');

    echo json_encode([
        'success' => true,
        'message' => "Verified! You earned +{$codeRow['points_value']} points for purchasing {$codeRow['product_name']}.",
        'points_earned' => (int)$codeRow['points_value'],
        'new_balance' => $newBalance,
        'product_name' => $codeRow['product_name'],
        'batch_code' => $codeRow['batch_code']
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Claim Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred while verifying your code.']);
}
