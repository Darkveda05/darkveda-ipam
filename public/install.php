<?php
declare(strict_types=1);

/**
 * DarkVeda IPAM — web installer.
 *
 * Walks through requirements, database connection, schema import and the
 * first administrator account, then writes config/config.php.
 *
 * It refuses to run once the system is installed (config present + an admin
 * with a real password), so leaving it on disk cannot be used to take over a
 * running instance — but you should still delete it when you are done.
 *
 *   >>> DELETE public/install.php AFTER INSTALLATION <<<
 */

const PLACEHOLDER_HASH = '$2y$10$PLACEHOLDER_HASH_REPLACED_BY_INSTALLER';
const MIN_PASSWORD     = 10;

$root       = dirname(__DIR__);
$configFile = $root . '/config/config.php';
$schemaFile = $root . '/database/schema.sql';

/* ------------------------------------------------------------------ */
/* Requirement checks                                                   */
/* ------------------------------------------------------------------ */
$requirements = [
    ['PHP 8.1 or newer', PHP_VERSION_ID >= 80100, PHP_VERSION, true],
    ['PDO extension', extension_loaded('pdo'), extension_loaded('pdo') ? 'loaded' : 'missing', true],
    ['pdo_mysql driver', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'loaded' : 'missing', true],
    ['config/ writable', is_writable($root . '/config') || is_writable($configFile), is_writable($root . '/config') ? 'writable' : 'not writable', true],
    ['database/schema.sql present', is_readable($schemaFile), is_readable($schemaFile) ? 'found' : 'missing', true],
    ['public/uploads writable', is_dir($root . '/public/uploads')
        ? is_writable($root . '/public/uploads')
        : is_writable($root . '/public'),
        is_dir($root . '/public/uploads') ? 'writable' : 'will be created', false],
    ['SNMP extension (discovery)', extension_loaded('snmp'), extension_loaded('snmp') ? 'loaded' : 'optional — not loaded', false],
    ['cURL extension (Zabbix)', extension_loaded('curl'), extension_loaded('curl') ? 'loaded' : 'optional — not loaded', false],
    ['sockets extension (NetBIOS/mDNS)', extension_loaded('sockets'), extension_loaded('sockets') ? 'loaded' : 'optional — not loaded', false],
    ['Phar extension (backups)', extension_loaded('Phar'), extension_loaded('Phar') ? 'loaded' : 'optional — not loaded', false],
];
$blocking = array_filter($requirements, fn($r) => $r[3] && !$r[1]);

/* ------------------------------------------------------------------ */
/* Already installed?                                                   */
/* ------------------------------------------------------------------ */
$alreadyInstalled = false;
if (is_file($configFile)) {
    try {
        $cfg = require $configFile;
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['db']['host'], $cfg['db']['port'], $cfg['db']['name'], $cfg['db']['charset']),
            $cfg['db']['user'], $cfg['db']['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
        );
        $row = $pdo->query("SELECT password_hash FROM users WHERE username = 'admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['password_hash'] !== PLACEHOLDER_HASH) {
            $alreadyInstalled = true;
        }
    } catch (Throwable) {
        // unreachable or not set up yet — installer stays available
    }
}

/* ------------------------------------------------------------------ */
/* Handle submission                                                    */
/* ------------------------------------------------------------------ */
$errors  = [];
$done    = false;
$summary = [];
$in = [
    'db_host' => $_POST['db_host'] ?? '127.0.0.1',
    'db_port' => $_POST['db_port'] ?? '3306',
    'db_name' => $_POST['db_name'] ?? 'darkveda_ipam',
    'db_user' => $_POST['db_user'] ?? 'darkveda',
    'app_tz'  => $_POST['app_tz']  ?? 'Asia/Kuala_Lumpur',
    'zbx_url' => $_POST['zbx_url'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyInstalled && !$blocking) {
    $dbHost = trim((string)$in['db_host']);
    $dbPort = (int)$in['db_port'] ?: 3306;
    $dbName = trim((string)$in['db_name']);
    $dbUser = trim((string)$in['db_user']);
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $admPw  = (string)($_POST['admin_password'] ?? '');
    $admPw2 = (string)($_POST['admin_password2'] ?? '');
    $admMail= trim((string)($_POST['admin_email'] ?? 'admin@darkveda.local'));

    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        $errors[] = 'Database host, name and user are all required.';
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
        $errors[] = 'Database name may contain only letters, numbers and underscores.';
    }
    if (strlen($admPw) < MIN_PASSWORD) {
        $errors[] = 'The administrator password must be at least ' . MIN_PASSWORD . ' characters.';
    }
    if ($admPw !== $admPw2) {
        $errors[] = 'The two administrator passwords do not match.';
    }
    if (!filter_var($admMail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid administrator email address.';
    }

    if (!$errors) {
        try {
            // 1. connect to the server (no database selected yet)
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $dbHost, $dbPort);
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 8,
            ]);
            $summary[] = 'Connected to MariaDB/MySQL at ' . $dbHost . ':' . $dbPort . '.';

            // 2. create the database when the account is allowed to
            try {
                $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $summary[] = 'Database `' . $dbName . '` is ready.';
            } catch (PDOException $e) {
                $summary[] = 'Could not create the database (' . $e->getCode() . ') — assuming it already exists.';
            }
            $pdo->exec('USE `' . $dbName . '`');

            // 3. import the schema, unless tables are already present
            $existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            if ($existing && !isset($_POST['force_schema'])) {
                $summary[] = 'Found ' . count($existing) . ' existing table(s) — schema import skipped.';
            } else {
                $sql = (string)file_get_contents($schemaFile);
                // the shipped schema selects its own database name; retarget it
                $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS\s+\S+.*?;/is', '', $sql);
                $sql = preg_replace('/USE\s+\S+\s*;/i', '', $sql);
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                $count = 0;
                foreach (splitSql($sql) as $stmt) {
                    $pdo->exec($stmt);
                    $count++;
                }
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                $summary[] = 'Imported the schema (' . $count . ' statements).';
            }

            // 4. administrator account
            $hash = password_hash($admPw, PASSWORD_BCRYPT, ['cost' => 12]);
            $admin = $pdo->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($admin) {
                $st = $pdo->prepare('UPDATE users SET password_hash = ?, email = ?, is_active = 1, role_id = 1 WHERE id = ?');
                $st->execute([$hash, $admMail, $admin['id']]);
                $summary[] = 'Administrator account updated.';
            } else {
                $st = $pdo->prepare('INSERT INTO users (username, email, password_hash, role_id, is_active) VALUES (?,?,?,1,1)');
                $st->execute(['admin', $admMail, $hash]);
                $summary[] = 'Administrator account created.';
            }

            // 5. uploads directory
            $up = $root . '/public/uploads';
            if (!is_dir($up)) {
                @mkdir($up, 0775, true);
            }
            if (is_dir($up)) {
                @file_put_contents($up . '/.htaccess', "php_flag engine off\nOptions -ExecCGI -Indexes\n");
                $summary[] = 'Uploads directory ready at public/uploads.';
            } else {
                $summary[] = 'Could not create public/uploads — create it manually for photos and documents.';
            }

            // 6. write config
            $config = renderConfig([
                'db_host' => $dbHost, 'db_port' => $dbPort, 'db_name' => $dbName,
                'db_user' => $dbUser, 'db_pass' => $dbPass,
                'app_tz'  => trim((string)$in['app_tz']) ?: 'UTC',
                'zbx_url' => trim((string)$in['zbx_url']),
                'zbx_tok' => trim((string)($_POST['zbx_token'] ?? '')),
            ]);
            if (@file_put_contents($configFile, $config) === false) {
                $errors[] = 'Everything is set up, but config/config.php could not be written. '
                          . 'Copy the configuration shown below into that file manually.';
                $summary['config_manual'] = $config;
            } else {
                @chmod($configFile, 0640);
                $summary[] = 'Configuration written to config/config.php.';
            }

            // 7. record the installed version
            try {
                $st = $pdo->prepare("INSERT INTO app_settings (skey, sval) VALUES ('installed_version', ?)
                                     ON DUPLICATE KEY UPDATE sval = VALUES(sval)");
                $st->execute(['3.0.0']);
            } catch (Throwable) {
                // app_settings only exists from 3.0 onwards
            }

            $done = true;
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        } catch (Throwable $e) {
            $errors[] = 'Installation failed: ' . $e->getMessage();
        }
    }
}

/* ------------------------------------------------------------------ */
/* Helpers                                                              */
/* ------------------------------------------------------------------ */
function splitSql(string $sql): Generator
{
    $len = strlen($sql);
    $buf = '';
    $inS = $inD = $inB = $esc = false;
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($esc) { $buf .= $ch; $esc = false; continue; }
        if ($ch === '\\' && ($inS || $inD)) { $buf .= $ch; $esc = true; continue; }
        if (!$inS && !$inD && !$inB && $ch === '-' && ($sql[$i + 1] ?? '') === '-'
            && (trim($buf) === '' || str_ends_with($buf, "\n"))) {
            while ($i < $len && $sql[$i] !== "\n") { $i++; }
            continue;
        }
        if (!$inD && !$inB && $ch === "'") { $inS = !$inS; }
        elseif (!$inS && !$inB && $ch === '"') { $inD = !$inD; }
        elseif (!$inS && !$inD && $ch === '`') { $inB = !$inB; }
        if ($ch === ';' && !$inS && !$inD && !$inB) {
            $s = trim($buf);
            if ($s !== '') { yield $s; }
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    $s = trim($buf);
    if ($s !== '') { yield $s; }
}

function renderConfig(array $v): string
{
    $q = fn($s) => var_export((string)$s, true);
    return <<<PHP
<?php
declare(strict_types=1);

/**
 * DarkVeda IPAM — configuration
 * Generated by public/install.php on {$GLOBALS['generatedAt']}.
 * Every value can be overridden with an environment variable.
 */
return [
    'app' => [
        'name'     => 'DarkVeda IPAM',
        'version'  => '3.0.0',
        'debug'    => filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL),
        'timezone' => getenv('APP_TZ') ?: {$q($v['app_tz'])},
    ],
    'db' => [
        'host'     => getenv('DB_HOST') ?: {$q($v['db_host'])},
        'port'     => (int)(getenv('DB_PORT') ?: {$v['db_port']}),
        'name'     => getenv('DB_NAME') ?: {$q($v['db_name'])},
        'user'     => getenv('DB_USER') ?: {$q($v['db_user'])},
        'password' => getenv('DB_PASS') ?: {$q($v['db_pass'])},
        'charset'  => 'utf8mb4',
    ],
    'zabbix' => [
        // Zabbix 6.0+ / 7.x endpoint, e.g. http://zabbix.example.com/zabbix/api_jsonrpc.php
        'url'        => getenv('ZABBIX_URL') ?: {$q($v['zbx_url'])},
        'token'      => getenv('ZABBIX_TOKEN') ?: {$q($v['zbx_tok'])},
        'verify_tls' => filter_var(getenv('ZABBIX_VERIFY_TLS') ?: 'true', FILTER_VALIDATE_BOOL),
        'timeout'    => (int)(getenv('ZABBIX_TIMEOUT') ?: 10),
    ],
    'security' => [
        'session_name'     => 'dvipam_session',
        'session_lifetime' => 3600 * 8,
        'bcrypt_cost'      => 12,
    ],
];

PHP;
}
$GLOBALS['generatedAt'] = date('Y-m-d H:i:s');

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>DarkVeda IPAM — Installation</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body { background:#0b0b12; padding: 2.5rem 1rem; }
  .wrap { max-width: 860px; margin: 0 auto; }
  .card { background:#14141f; border:1px solid #2a2a3a; }
  .brand { color:#8b5cf6; font-weight:700; letter-spacing:.5px; }
  .btn-violet { background:#8b5cf6; border-color:#8b5cf6; color:#fff; }
  .btn-violet:hover { background:#7c4ddc; border-color:#7c4ddc; color:#fff; }
  code, pre { color:#c4b5fd; }
  pre { background:#0e0e18; padding:.8rem; border-radius:6px; max-height:340px; overflow:auto; }
</style>
</head>
<body>
<div class="wrap">
  <h1 class="h3 mb-1 brand"><i class="bi bi-hdd-network"></i> DarkVeda IPAM</h1>
  <p class="text-secondary mb-4">Installation</p>

<?php if ($alreadyInstalled): ?>
  <div class="card"><div class="card-body">
    <div class="alert alert-warning">
      <strong>Already installed.</strong> A configuration exists and the administrator account has a real password,
      so the installer is disabled.
    </div>
    <p class="small text-secondary">
      Delete <code>public/install.php</code> from the server. To change the database connection, edit
      <code>config/config.php</code>. To reset a forgotten password, use <code>bin/set-admin-password.php</code>.
    </p>
    <a class="btn btn-violet" href="/">Go to DarkVeda IPAM</a>
  </div></div>

<?php elseif ($blocking): ?>
  <div class="card"><div class="card-body">
    <div class="alert alert-danger"><strong>Requirements not met.</strong> Fix the items below, then reload this page.</div>
    <?= requirementsTable($requirements) ?>
  </div></div>

<?php elseif ($done && !$errors): ?>
  <div class="card"><div class="card-body">
    <div class="alert alert-success"><strong>Installation complete.</strong></div>
    <ul class="small">
      <?php foreach ($summary as $k => $line): if ($k === 'config_manual') continue; ?>
        <li><?= h((string)$line) ?></li>
      <?php endforeach; ?>
    </ul>
    <div class="alert alert-warning">
      <strong>Delete the installer now:</strong> <code>public/install.php</code>
    </div>
    <a class="btn btn-violet" href="/">Sign in as <code>admin</code></a>
  </div></div>

<?php else: ?>
  <?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= h($err) ?></div>
  <?php endforeach; ?>
  <?php if (isset($summary['config_manual'])): ?>
    <div class="card mb-3"><div class="card-body">
      <h6>Paste this into <code>config/config.php</code></h6>
      <pre><?= h((string)$summary['config_manual']) ?></pre>
    </div></div>
  <?php endif; ?>

  <div class="card mb-3"><div class="card-body">
    <h5 class="mb-3"><i class="bi bi-check2-square"></i> Requirements</h5>
    <?= requirementsTable($requirements) ?>
  </div></div>

  <form method="post" class="card"><div class="card-body">
    <h5 class="mb-3"><i class="bi bi-database"></i> Database</h5>
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label class="form-label">Host *</label>
        <input name="db_host" class="form-control" required value="<?= h((string)$in['db_host']) ?>">
        <div class="form-text">Use the container/host IP, not <code>localhost</code>, when the database runs elsewhere.</div>
      </div>
      <div class="col-md-2">
        <label class="form-label">Port</label>
        <input name="db_port" type="number" class="form-control" value="<?= h((string)$in['db_port']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Database name *</label>
        <input name="db_name" class="form-control" required value="<?= h((string)$in['db_name']) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">User *</label>
        <input name="db_user" class="form-control" required value="<?= h((string)$in['db_user']) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Password</label>
        <input name="db_pass" type="password" class="form-control" autocomplete="new-password">
      </div>
      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="force_schema" id="force" value="1">
          <label class="form-check-label" for="force">
            Re-import the schema even if tables already exist
            <span class="text-danger">— destroys existing data</span>
          </label>
        </div>
      </div>
    </div>

    <h5 class="mb-3"><i class="bi bi-person-gear"></i> Administrator</h5>
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label class="form-label">Email *</label>
        <input name="admin_email" type="email" class="form-control" required
               value="<?= h((string)($_POST['admin_email'] ?? 'admin@darkveda.local')) ?>">
        <div class="form-text">The username is always <code>admin</code>.</div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Password * <span class="text-secondary">(min <?= MIN_PASSWORD ?>)</span></label>
        <input name="admin_password" type="password" class="form-control" minlength="<?= MIN_PASSWORD ?>" required autocomplete="new-password">
      </div>
      <div class="col-md-3">
        <label class="form-label">Confirm *</label>
        <input name="admin_password2" type="password" class="form-control" minlength="<?= MIN_PASSWORD ?>" required autocomplete="new-password">
      </div>
    </div>

    <h5 class="mb-3"><i class="bi bi-sliders"></i> Optional</h5>
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <label class="form-label">Timezone</label>
        <input name="app_tz" class="form-control" value="<?= h((string)$in['app_tz']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Zabbix API URL</label>
        <input name="zbx_url" class="form-control" placeholder="http://zabbix/zabbix/api_jsonrpc.php"
               value="<?= h((string)$in['zbx_url']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Zabbix API token</label>
        <input name="zbx_token" type="password" class="form-control" autocomplete="off">
      </div>
      <div class="col-12">
        <div class="form-text">Both Zabbix fields can be left blank and filled in later from the Monitoring page.</div>
      </div>
    </div>

    <button class="btn btn-violet w-100"><i class="bi bi-play-fill"></i> Install DarkVeda IPAM</button>
    <p class="small text-secondary mt-3 mb-0">
      The installer writes <code>config/config.php</code>, imports <code>database/schema.sql</code>
      and creates the administrator. Delete this file once you are finished.
    </p>
  </div></form>
<?php endif; ?>
</div>
</body>
</html>
<?php
function requirementsTable(array $reqs): string
{
    $html = '<table class="table table-sm mb-0"><tbody>';
    foreach ($reqs as [$label, $ok, $detail, $required]) {
        $icon = $ok
            ? '<i class="bi bi-check-circle-fill text-success"></i>'
            : ($required ? '<i class="bi bi-x-circle-fill text-danger"></i>'
                         : '<i class="bi bi-dash-circle text-warning"></i>');
        $html .= '<tr><td style="width:32px">' . $icon . '</td><td>' . h($label)
              . ($required ? '' : ' <span class="badge text-bg-secondary">optional</span>')
              . '</td><td class="text-secondary small">' . h((string)$detail) . '</td></tr>';
    }
    return $html . '</tbody></table>';
}
