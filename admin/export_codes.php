<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$batchId = (int)($_GET['batch_id'] ?? 0);
if ($batchId <= 0) {
    die("Invalid batch ID.");
}

$pdo = getDBConnection();
$stmt = $pdo->prepare("
    SELECT bc.code_plain, bc.code_signature, bc.status, b.batch_code, p.sku, p.name AS product_name, p.points_value
    FROM box_codes bc
    JOIN batches b ON bc.batch_id = b.id
    JOIN products p ON b.product_id = p.id
    WHERE b.id = ?
    ORDER BY bc.id ASC
");
$stmt->execute([$batchId]);
$rows = $stmt->fetchAll();

if (!$rows) {
    die("No codes found for this batch.");
}

$filename = preg_replace('/[^A-Za-z0-9_-]/', '_', $rows[0]['batch_code']) . '_box_codes.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Batch Code', 'SKU', 'Product Name', 'Points', 'Box Code', 'HMAC Signature', 'Claim URL', 'Status']);
foreach ($rows as $r) {
    $claimUrl = 'http://localhost:8080/customer-loyalty-rewards/public/index.php?code=' . urlencode($r['code_plain']);
    fputcsv($out, [
        $r['batch_code'],
        $r['sku'],
        $r['product_name'],
        $r['points_value'],
        $r['code_plain'],
        $r['code_signature'],
        $claimUrl,
        $r['status']
    ]);
}
fclose($out);
exit;
