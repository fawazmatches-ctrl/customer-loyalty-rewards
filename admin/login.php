<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

$pdo = getDBConnection();

// Auto-bootstrap default admin from .env if no admin exists yet
$count = (int)$pdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
if ($count === 0) {
    $defaultUser = getenv('ADMIN_DEFAULT_USER') ?: 'admin';
    $defaultPass = getenv('ADMIN_DEFAULT_PASS') ?: 'ChangeMe123!';
    $hash = password_hash($defaultPass, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO admin_users (username, password_hash, role) VALUES (?, ?, 'owner')");
    $stmt->execute([$defaultUser, $hash]);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = $pdo->prepare("SELECT id, username, password_hash FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factory Owner Login | Apex Loyalty Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="auth-body">
    <div class="card auth-card">
        <div class="brand" style="margin-bottom: 1rem;">
            <span class="brand-badge">APEX</span>
            <span>Factory Owner Portal</span>
        </div>
        <h2>Admin Sign In</h2>
        <p class="muted">Manage product batches, generate box codes, and monitor fraud alerts.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required placeholder="admin">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required placeholder="••••••••">
            </div>
            <button type="submit" class="btn btn-primary btn-block">Login to Dashboard</button>
        </form>
        <p style="margin-top: 1rem; text-align: center;">
            <a href="../public/index.php" class="nav-link">&larr; Back to Customer Portal</a>
        </p>
    </div>
</body>
</html>
