<?php
declare(strict_types=1);

/**
 * DarkVeda IPAM — one-time setup page.
 *
 * Sets the initial admin password via the browser (no CLI needed).
 * It ONLY works while the admin account still has the factory
 * placeholder hash. Once a real password is set, this page locks
 * itself and refuses to run again.
 *
 * >>> DELETE THIS FILE AFTER SETUP <<<
 */

const PLACEHOLDER = '$2y$10$PLACEHOLDER_HASH_REPLACED_BY_INSTALLER';
const MIN_LEN     = 10;

$base = dirname(__DIR__);
require_once $base . '/src/App.php';
$config = \DarkVeda\App::config();

$error = null;
$done  = false;
$locked = false;
$dbError = null;

try {
    $db = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['db']['host'],
            $config['db']['port'],
            $config['db']['name'],
            $config['db']['charset']
        ),
        $config['db']['user'],
        $config['db']['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $db->query("SELECT id, password_hash FROM users WHERE username = 'admin' LIMIT 1");
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        $dbError = "No 'admin' user found — has database/schema.sql been imported?";
    } elseif ($admin['password_hash'] !== PLACEHOLDER) {
        // Admin password already set: this page is permanently disabled.
        $locked = true;
    }

    if (!$locked && !$dbError && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['password2'] ?? '');

        if (strlen($p1) < MIN_LEN) {
            $error = 'Password must be at least ' . MIN_LEN . ' characters.';
        } elseif ($p1 !== $p2) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($p1, PASSWORD_BCRYPT, ['cost' => (int)($config['security']['bcrypt_cost'] ?? 12)]);
            $upd = $db->prepare('UPDATE users SET password_hash = ?, is_active = 1 WHERE id = ?');
            $upd->execute([$hash, $admin['id']]);
            $db->prepare("INSERT INTO audit_logs (user_id, action, entity_type, details, ip_address)
                          VALUES (?, 'setup', 'user', 'admin password set via setup.php', ?)")
               ->execute([$admin['id'], $_SERVER['REMOTE_ADDR'] ?? null]);
            $done = true;
        }
    }
} catch (PDOException $e) {
    $dbError = 'Database connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>DarkVeda IPAM — Setup</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { min-height: 100vh; display: flex; align-items: center; background: #0b0b12; }
  .card { max-width: 460px; margin: auto; border: 1px solid #2a2a3a; background: #14141f; }
  .brand { color: #8b5cf6; font-weight: 700; letter-spacing: .5px; }
  .btn-violet { background: #8b5cf6; border-color: #8b5cf6; color: #fff; }
  .btn-violet:hover { background: #7c4ddc; border-color: #7c4ddc; color: #fff; }
</style>
</head>
<body>
<div class="card shadow p-4 w-100">
  <h1 class="h4 mb-1 brand">DarkVeda IPAM</h1>
  <p class="text-secondary mb-4">First-time setup</p>

<?php if ($dbError): ?>
  <div class="alert alert-danger"><?= $dbError ?></div>
  <p class="small text-secondary mb-0">Check <code>config/config.php</code> (DB host, user, password) and that the schema is imported, then reload this page.</p>

<?php elseif ($done): ?>
  <div class="alert alert-success">
    <strong>Admin password set.</strong> You can now log in as <code>admin</code>.
  </div>
  <div class="alert alert-warning">
    <strong>Important:</strong> delete this file now:<br>
    <code>public/setup.php</code>
  </div>
  <a class="btn btn-violet w-100" href="/">Go to login</a>

<?php elseif ($locked): ?>
  <div class="alert alert-warning">
    <strong>Setup already completed.</strong> The admin password has been set, so this page is disabled.
  </div>
  <p class="small text-secondary">Please delete <code>public/setup.php</code> from the server. To reset a forgotten password, use <code>bin/set-admin-password.php</code> or update the database directly.</p>
  <a class="btn btn-violet w-100" href="/">Go to login</a>

<?php else: ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <p class="small text-secondary">Set the password for the <code>admin</code> account (minimum <?= MIN_LEN ?> characters).</p>
  <form method="post" autocomplete="off">
    <div class="mb-3">
      <label class="form-label">New admin password</label>
      <input type="password" name="password" class="form-control" minlength="<?= MIN_LEN ?>" required autofocus>
    </div>
    <div class="mb-4">
      <label class="form-label">Confirm password</label>
      <input type="password" name="password2" class="form-control" minlength="<?= MIN_LEN ?>" required>
    </div>
    <button type="submit" class="btn btn-violet w-100">Set password</button>
  </form>
  <p class="small text-secondary mt-3 mb-0">After setup, this page disables itself — but you should still <strong>delete <code>public/setup.php</code></strong>.</p>
<?php endif; ?>
</div>
</body>
</html>
