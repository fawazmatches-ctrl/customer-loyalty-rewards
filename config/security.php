<?php
require_once __DIR__ . '/env_loader.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

/**
 * Generates a cryptographically strong, unambiguous box code (e.g., BOX-A7K9-M2P4-X8Q3)
 * Excludes confusing characters: 0, O, 1, I, L
 */
function generateBoxCode($prefix = 'BOX') {
    $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    $maxIndex = strlen($alphabet) - 1;
    $segments = [];
    for ($s = 0; $s < 3; $s++) {
        $seg = '';
        for ($i = 0; $i < 4; $i++) {
            $seg .= $alphabet[random_int(0, $maxIndex)];
        }
        $segments[] = $seg;
    }
    return strtoupper($prefix) . '-' . implode('-', $segments);
}

/**
 * Generates a HMAC-SHA256 signature for a code so printed QR/box labels are tamper-evident
 */
function signBoxCode($code) {
    $secret = getenv('APP_SECRET_KEY') ?: 'fallback_dev_secret_key';
    return substr(hash_hmac('sha256', strtoupper(trim($code)), $secret), 0, 8);
}

function getClientIp() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function checkRateLimit(PDO $pdo, $ipAddress) {
    $maxAttempts = (int)(getenv('MAX_FAILED_ATTEMPTS') ?: 5);
    $lockoutMins = (int)(getenv('LOCKOUT_MINUTES') ?: 15);

    $stmt = $pdo->prepare("
        SELECT COUNT(*) as fail_count
        FROM claim_attempts
        WHERE ip_address = ?
          AND success = 0
          AND attempted_at >= (NOW() - INTERVAL ? MINUTE)
    ");
    $stmt->execute([$ipAddress, $lockoutMins]);
    $row = $stmt->fetch();

    return ($row['fail_count'] ?? 0) >= $maxAttempts;
}

function logClaimAttempt(PDO $pdo, $ipAddress, $phone, $codeSubmitted, $success, $reason) {
    $stmt = $pdo->prepare("
        INSERT INTO claim_attempts (ip_address, phone_number, code_submitted, success, failure_reason)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$ipAddress, $phone, $codeSubmitted, $success ? 1 : 0, $reason]);
}
