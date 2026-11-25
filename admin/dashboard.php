<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getDBConnection();
$flashMessage = '';
$flashType = 'success';

// Handle Admin Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $flashMessage = 'Security token mismatch.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Add New Product
        if ($action === 'add_product') {
            $sku = strtoupper(trim($_POST['sku'] ?? ''));
            $name = trim($_POST['name'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $points = (int)($_POST['points_value'] ?? 50);

            if ($sku && $name && $points > 0) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO products (sku, name, category, points_value) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$sku, $name, $category ?: 'General', $points]);
                    $flashMessage = "Product '{$name}' added successfully.";
                } catch (PDOException $e) {
                    $flashMessage = "Error adding product (SKU may already exist).";
                    $flashType = 'error';
                }
            }
        }

        // 2. Generate Manufacturing Batch & Unique Box Codes
        if ($action === 'generate_batch') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $batchCode = strtoupper(trim($_POST['batch_code'] ?? ''));
            $mfgDate = $_POST['manufactured_date'] ?? date('Y-m-d');
            $expDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
            $qty = min(500, max(1, (int)($_POST['quantity'] ?? 10)));

            if ($productId > 0 && $batchCode !== '') {
                try {
                    $pdo->beginTransaction();
                    $bStmt = $pdo->prepare("
                        INSERT INTO batches (batch_code, product_id, manufactured_date, expiry_date, quantity_generated, status)
                        VALUES (?, ?, ?, ?, ?, 'active')
                    ");
                    $bStmt->execute([$batchCode, $productId, $mfgDate, $expDate, $qty]);
                    $batchId = $pdo->lastInsertId();

                    $cStmt = $pdo->prepare("INSERT INTO box_codes (batch_id, code_plain, code_signature) VALUES (?, ?, ?)");
                    $generated = 0;
                    while ($generated < $qty) {
                        $code = generateBoxCode('BOX');
                        $sig = signBoxCode($code);
                        try {
                            $cStmt->execute([$batchId, $code, $sig]);
                            $generated++;
                        } catch (PDOException $dup) {
                            // Collision retry
                        }
                    }
                    $pdo->commit();
                    $flashMessage = "Generated batch '{$batchCode}' with {$qty} unique tamper-evident box codes.";
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $flashMessage = "Batch creation failed: " . $e->getMessage();
                    $flashType = 'error';
                }
            }
        }

        // 3. Recall / Void Batch
        if ($action === 'recall_batch') {
            $batchId = (int)($_POST['batch_id'] ?? 0);
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE batches SET status = 'recalled' WHERE id = ?")->execute([$batchId]);
            $pdo->prepare("UPDATE box_codes SET status = 'voided' WHERE batch_id = ? AND status = 'unclaimed'")->execute([$batchId]);
            $pdo->commit();
            $flashMessage = "Batch recalled and all unclaimed box codes in this batch have been voided.";
        }

        // 4. Fulfill Redemption
        if ($action === 'fulfill_redemption') {
            $redId = (int)($_POST['redemption_id'] ?? 0);
            $pdo->prepare("UPDATE redemptions SET status = 'fulfilled' WHERE id = ?")->execute([$redId]);
            $flashMessage = "Redemption marked as fulfilled.";
        }

        // 5. Toggle Customer Fraud Flag
        if ($action === 'toggle_flag') {
            $custId = (int)($_POST['customer_id'] ?? 0);
            $pdo->prepare("UPDATE customers SET is_flagged = 1 - is_flagged WHERE id = ?")->execute([$custId]);
            $flashMessage = "Customer security status updated.";
        }
    }
}

// Fetch KPI Metrics
$totalCodes = (int)$pdo->query("SELECT COUNT(*) FROM box_codes")->fetchColumn();
$claimedCodes = (int)$pdo->query("SELECT COUNT(*) FROM box_codes WHERE status = 'claimed'")->fetchColumn();
$claimRate = $totalCodes > 0 ? round(($claimedCodes / $totalCodes) * 100, 1) : 0;
$totalCustomers = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$failedAttempts24h = (int)$pdo->query("SELECT COUNT(*) FROM claim_attempts WHERE success = 0 AND attempted_at >= (NOW() - INTERVAL 24 HOUR)")->fetchColumn();
$pendingRedemptions = (int)$pdo->query("SELECT COUNT(*) FROM redemptions WHERE status = 'pending'")->fetchColumn();

$products = $pdo->query("SELECT * FROM products ORDER BY id DESC")->fetchAll();
$batches = $pdo->query("
    SELECT b.*, p.name AS product_name, p.sku,
           (SELECT COUNT(*) FROM box_codes bc WHERE bc.batch_id = b.id AND bc.status = 'claimed') AS claimed_count
    FROM batches b
    JOIN products p ON b.product_id = p.id
    ORDER BY b.id DESC
")->fetchAll();

$recentCodes = $pdo->query("
    SELECT bc.*, b.batch_code, p.name AS product_name, c.phone AS customer_phone
    FROM box_codes bc
    JOIN batches b ON bc.batch_id = b.id
    JOIN products p ON b.product_id = p.id
    LEFT JOIN customers c ON bc.claimed_by_customer_id = c.id
    ORDER BY bc.id DESC
    LIMIT 15
")->fetchAll();

$fraudLogs = $pdo->query("
    SELECT * FROM claim_attempts
    WHERE success = 0
    ORDER BY attempted_at DESC
    LIMIT 15
")->fetchAll();

$redemptions = $pdo->query("
    SELECT r.*, c.full_name, c.phone, rc.title AS reward_title
    FROM redemptions r
    JOIN customers c ON r.customer_id = c.id
    JOIN reward_catalog rc ON r.reward_id = rc.id
    ORDER BY r.redeemed_at DESC
    LIMIT 15
")->fetchAll();

$customers = $pdo->query("SELECT * FROM customers ORDER BY total_points_earned DESC LIMIT 15")->fetchAll();

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factory Owner Dashboard | Apex Loyalty</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <header class="navbar">
        <div class="container nav-inner">
            <div class="brand">
                <span class="brand-badge">APEX ADMIN</span>
                <span>Factory Control Center</span>
            </div>
            <nav>
                <a href="../public/index.php" class="nav-link" target="_blank">View Customer Portal &nearr;</a>
                <a href="logout.php" class="nav-link admin-pill">Logout (<?= htmlspecialchars($_SESSION['admin_username']) ?>)</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <?php if ($flashMessage): ?>
            <div class="alert alert-<?= $flashType ?>" style="margin-top: 1.5rem;"><?= htmlspecialchars($flashMessage) ?></div>
        <?php endif; ?>

        <!-- KPI Summary Row -->
        <section class="kpi-grid">
            <div class="card kpi-card">
                <span class="kpi-label">Total Box Codes Printed</span>
                <strong class="kpi-value"><?= number_format($totalCodes) ?></strong>
            </div>
            <div class="card kpi-card">
                <span class="kpi-label">Verified Claims (Scan Rate)</span>
                <strong class="kpi-value"><?= number_format($claimedCodes) ?> <small>(<?= $claimRate ?>%)</small></strong>
            </div>
            <div class="card kpi-card">
                <span class="kpi-label">Registered Customers</span>
                <strong class="kpi-value"><?= number_format($totalCustomers) ?></strong>
            </div>
            <div class="card kpi-card">
                <span class="kpi-label">Blocked / Fraud Attempts (24h)</span>
                <strong class="kpi-value text-danger"><?= number_format($failedAttempts24h) ?></strong>
            </div>
            <div class="card kpi-card">
                <span class="kpi-label">Pending Reward Fulfillments</span>
                <strong class="kpi-value"><?= number_format($pendingRedemptions) ?></strong>
            </div>
        </section>

        <!-- Batch Code Generator & Product Creation -->
        <div class="grid-2">
            <div class="card">
                <h2>Generate Factory Batch & Box Codes</h2>
                <p class="muted">Create a production batch and generate unique codes to print inside boxes.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="generate_batch">

                    <div class="form-group">
                        <label>Select Factory Product *</label>
                        <select name="product_id" required>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['sku'] . ' - ' . $p['name'] . ' (' . $p['points_value'] . ' pts)') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Batch Code *</label>
                        <input type="text" name="batch_code" value="BATCH-<?= date('Y') ?>-<?= rand(100, 999) ?>" required>
                    </div>

                    <div class="grid-2-tight">
                        <div class="form-group">
                            <label>Manufactured Date</label>
                            <input type="date" name="manufactured_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Expiry Date (Optional)</label>
                            <input type="date" name="expiry_date">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Number of Box Codes to Generate (1–500)</label>
                        <input type="number" name="quantity" min="1" max="500" value="10" required>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Generate Batch & Unique Codes</button>
                </form>
            </div>

            <div class="card">
                <h2>Add New Factory SKU</h2>
                <p class="muted">Register a new boxed product line and define its loyalty point reward.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="add_product">

                    <div class="form-group">
                        <label>Product SKU *</label>
                        <input type="text" name="sku" placeholder="e.g., APX-MTR-04" required>
                    </div>

                    <div class="form-group">
                        <label>Product Name *</label>
                        <input type="text" name="name" placeholder="e.g., Apex Industrial Angle Grinder" required>
                    </div>

                    <div class="form-group">
                        <label>Category</label>
                        <input type="text" name="category" placeholder="e.g., Power Tools">
                    </div>

                    <div class="form-group">
                        <label>Points Per Verified Box *</label>
                        <input type="number" name="points_value" min="10" max="5000" value="100" required>
                    </div>

                    <button type="submit" class="btn btn-secondary btn-block">Save Product SKU</button>
                </form>
            </div>
        </div>

        <!-- Production Batches Table -->
        <section class="card section-block">
            <h2>Manufacturing Batches & Printer CSV Export</h2>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Batch Code</th>
                            <th>Product</th>
                            <th>Mfg Date</th>
                            <th>Claimed / Total</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batches as $b): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($b['batch_code']) ?></strong></td>
                            <td><?= htmlspecialchars($b['product_name']) ?></td>
                            <td><?= htmlspecialchars($b['manufactured_date']) ?></td>
                            <td><?= (int)$b['claimed_count'] ?> / <?= (int)$b['quantity_generated'] ?></td>
                            <td>
                                <span class="badge badge-<?= $b['status'] ?>"><?= strtoupper($b['status']) ?></span>
                            </td>
                            <td class="actions-cell">
                                <a href="export_codes.php?batch_id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-secondary">Export CSV</a>
                                <?php if ($b['status'] === 'active'): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Recall this batch and void all unclaimed codes?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="recall_batch">
                                    <input type="hidden" name="batch_id" value="<?= (int)$b['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Recall Batch</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Latest Generated Box Codes -->
        <section class="card section-block">
            <h2>Latest Box Codes (Live Status)</h2>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Box Code</th>
                            <th>Batch</th>
                            <th>Product</th>
                            <th>Status</th>
                            <th>Claimed By</th>
                            <th>Claim IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentCodes as $c): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($c['code_plain']) ?></code></td>
                            <td><?= htmlspecialchars($c['batch_code']) ?></td>
                            <td><?= htmlspecialchars($c['product_name']) ?></td>
                            <td><span class="badge badge-<?= $c['status'] ?>"><?= strtoupper($c['status']) ?></span></td>
                            <td><?= htmlspecialchars($c['customer_phone'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($c['claim_ip'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Two Column: Fraud Log & Redemptions -->
        <div class="grid-2 section-block">
            <div class="card">
                <h2>Anti-Fraud & Blocked Claim Monitor</h2>
                <p class="muted">Tracks duplicate code reuse, invalid guesses, and recalled batch attempts.</p>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>IP Address</th>
                                <th>Code Entered</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($fraudLogs)): ?>
                                <tr><td colspan="4" class="muted">No failed or suspicious attempts logged yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($fraudLogs as $f): ?>
                                <tr>
                                    <td><?= date('d M H:i', strtotime($f['attempted_at'])) ?></td>
                                    <td><code><?= htmlspecialchars($f['ip_address']) ?></code></td>
                                    <td><code><?= htmlspecialchars($f['code_submitted']) ?></code></td>
                                    <td><span class="text-danger"><?= htmlspecialchars($f['failure_reason']) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <h2>Reward Redemptions Queue</h2>
                <p class="muted">Approve and fulfill customer voucher/merchandise claims.</p>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Voucher</th>
                                <th>Customer</th>
                                <th>Reward</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($redemptions)): ?>
                                <tr><td colspan="5" class="muted">No redemptions yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($redemptions as $r): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($r['voucher_code']) ?></code></td>
                                    <td><?= htmlspecialchars($r['full_name']) ?><br><small><?= htmlspecialchars($r['phone']) ?></small></td>
                                    <td><?= htmlspecialchars($r['reward_title']) ?></td>
                                    <td><span class="badge badge-<?= $r['status'] ?>"><?= strtoupper($r['status']) ?></span></td>
                                    <td>
                                        <?php if ($r['status'] === 'pending'): ?>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="action" value="fulfill_redemption">
                                            <input type="hidden" name="redemption_id" value="<?= (int)$r['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-primary">Mark Fulfilled</button>
                                        </form>
                                        <?php else: ?>
                                            <span class="muted">Done</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Customer Leaderboard & Fraud Flagging -->
        <section class="card section-block">
            <h2>Customer Accounts & Security Flags</h2>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Customer Name</th>
                            <th>Phone</th>
                            <th>Current Balance</th>
                            <th>Lifetime Points</th>
                            <th>Account Status</th>
                            <th>Control</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $cust): ?>
                        <tr>
                            <td><?= htmlspecialchars($cust['full_name']) ?></td>
                            <td><?= htmlspecialchars($cust['phone']) ?></td>
                            <td><strong><?= (int)$cust['points_balance'] ?> pts</strong></td>
                            <td><?= (int)$cust['total_points_earned'] ?> pts</td>
                            <td>
                                <?php if ((int)$cust['is_flagged'] === 1): ?>
                                    <span class="badge badge-recalled">FLAGGED / FROZEN</span>
                                <?php else: ?>
                                    <span class="badge badge-active">ACTIVE</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="toggle_flag">
                                    <input type="hidden" name="customer_id" value="<?= (int)$cust['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-secondary">
                                        <?= (int)$cust['is_flagged'] === 1 ? 'Unfreeze Account' : 'Flag Account' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
