<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

$pdo = getDBConnection();
$csrfToken = generateCsrfToken();

// Fetch active rewards for the catalog
$rewards = $pdo->query("SELECT * FROM reward_catalog WHERE is_active = 1 ORDER BY points_required ASC")->fetchAll();
$prefillCode = htmlspecialchars($_GET['code'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apex Factory Rewards | Verify Product & Earn</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <header class="navbar">
        <div class="container nav-inner">
            <div class="brand">
                <span class="brand-badge">APEX</span>
                <span>Factory Loyalty Portal</span>
            </div>
            <nav>
                <a href="#claim-section" class="nav-link">Claim Code</a>
                <a href="#wallet-section" class="nav-link">My Wallet</a>
                <a href="#rewards-section" class="nav-link">Rewards</a>
                <a href="../admin/login.php" class="nav-link admin-pill">Factory Owner Login</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <section class="hero">
            <h1>Genuine Factory Product? <span class="highlight">Claim Your Rewards.</span></h1>
            <p>Find the tamper-evident scratch code printed on the inside flap of your product box to verify authenticity and collect loyalty points.</p>
        </section>

        <div class="grid-2" id="claim-section">
            <!-- Left Card: Claim Code -->
            <div class="card">
                <h2>1. Verify Box & Claim Points</h2>
                <p class="muted">Each unique code can only be claimed once.</p>
                <form id="claimForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    
                    <div class="form-group">
                        <label for="box_code">Unique Box Code *</label>
                        <input type="text" id="box_code" name="box_code" placeholder="e.g., BOX-A7K9-M2P4-X8Q3" value="<?= $prefillCode ?>" required style="text-transform: uppercase; font-family: monospace; letter-spacing: 1px;">
                    </div>

                    <div class="form-group">
                        <label for="phone">Mobile Number (Wallet ID) *</label>
                        <input type="tel" id="phone" name="phone" placeholder="10-digit mobile number (e.g., 9876543210)" required>
                    </div>

                    <div class="form-group">
                        <label for="full_name">Full Name (Required for new users)</label>
                        <input type="text" id="full_name" name="full_name" placeholder="Enter your full name">
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address (Optional)</label>
                        <input type="email" id="email" name="email" placeholder="you@example.com">
                    </div>

                    <button type="submit" class="btn btn-primary btn-block" id="claimBtn">Verify Purchase & Claim Points</button>
                </form>
                <div id="claimAlert" class="alert hidden"></div>
            </div>

            <!-- Right Card: Wallet Lookup -->
            <div class="card" id="wallet-section">
                <h2>2. Check Wallet & History</h2>
                <p class="muted">Enter your registered mobile number to inspect your balance and verified purchases.</p>
                <form id="walletForm" class="inline-form">
                    <input type="tel" id="wallet_phone" placeholder="Enter mobile number (e.g., 9876543210)" required>
                    <button type="submit" class="btn btn-secondary">Load Wallet</button>
                </form>

                <div id="walletAlert" class="alert hidden"></div>

                <div id="walletDisplay" class="wallet-box hidden">
                    <div class="wallet-header">
                        <div>
                            <h3 id="wName">Customer Name</h3>
                            <span class="muted" id="wPhone"></span>
                        </div>
                        <div class="points-pill">
                            <span id="wBalance">0</span> pts
                        </div>
                    </div>

                    <h4>Verified Box Claims</h4>
                    <ul id="wClaimsList" class="history-list"></ul>

                    <h4>Redeemed Vouchers</h4>
                    <ul id="wRedemptionsList" class="history-list"></ul>
                </div>
            </div>
        </div>

        <!-- Rewards Catalog -->
        <section class="section-block" id="rewards-section">
            <h2>3. Redeem Loyalty Points</h2>
            <p class="muted">Enter your mobile number above or below to redeem available points for factory rewards.</p>
            <div class="grid-3">
                <?php foreach ($rewards as $r): ?>
                <div class="card reward-card">
                    <div class="reward-tag"><?= (int)$r['points_required'] ?> Points</div>
                    <h3><?= htmlspecialchars($r['title']) ?></h3>
                    <p><?= htmlspecialchars($r['description']) ?></p>
                    <div class="reward-footer">
                        <span class="stock-label">In Stock: <?= (int)$r['stock_available'] ?></span>
                        <button class="btn btn-primary btn-sm redeem-btn"
                                data-reward-id="<?= (int)$r['id'] ?>"
                                data-reward-title="<?= htmlspecialchars($r['title']) ?>"
                                data-points="<?= (int)$r['points_required'] ?>"
                                data-csrf="<?= htmlspecialchars($csrfToken) ?>">
                            Redeem Now
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div id="redeemAlert" class="alert hidden"></div>
        </section>
    </main>

    <footer class="footer">
        <div class="container">
            <p>&copy; <?= date('Y') ?> Apex Manufacturing Pvt. Ltd. — Anti-Counterfeit & Customer Loyalty System.</p>
        </div>
    </footer>

    <script src="../assets/js/app.js"></script>
</body>
</html>
