<?php
use DarkVeda\{App, Auth};
use function DarkVeda\e;

$appName = App::config()['app']['name'];
$user    = Auth::user();
$current = $_GET['page'] ?? 'dashboard';
$unread  = \DarkVeda\Database::q(
    'SELECT message, created_at FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY id DESC LIMIT 10',
    [Auth::id()]
);

$nav = [
    ['dashboard', 'Dashboard',        'bi-speedometer2', 'dashboard.view'],
    ['sites',     'Sites',            'bi-buildings',    'ipam.view'],
    ['vlans',     'VLANs',            'bi-layers',       'ipam.view'],
    ['devices',   'Devices',          'bi-hdd-rack',     'devices.view'],
    ['subnets',   'Subnets & IPs',    'bi-diagram-3',    'ipam.view'],
    ['racks',     'Racks',            'bi-hdd-stack',    'racks.view'],
    ['rack3d',    '3D Server Room',   'bi-box',          'racks.view'],
    ['documents', 'Documentation',    'bi-folder2-open', 'docs.view'],
    ['discovery', 'Discovery',        'bi-radar',        'discovery.view'],
    ['topology',  'Topology',         'bi-share',        'topology.view'],
    ['monitoring','Monitoring',       'bi-activity',     'monitoring.view'],
    ['snmp',      'SNMP Profiles',    'bi-key',          'snmp.manage'],
    ['users',     'Users',            'bi-people',       'users.manage'],
    ['backup',    'Backup & Restore', 'bi-download',     'backup.manage'],
    ['audit',     'Audit Log',        'bi-journal-text', 'audit.view'],
];
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'DarkVeda IPAM') ?> · <?= e($appName) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="/assets/css/darkveda.css" rel="stylesheet">
</head>
<body>
<div class="dv-layout">

  <aside class="dv-sidebar">
    <div class="dv-brand">
      <i class="bi bi-motherboard"></i>
      <span>DarkVeda <strong>IPAM</strong></span>
    </div>
    <nav class="nav flex-column">
      <?php foreach ($nav as [$slug, $label, $icon, $perm]): if (!Auth::can($perm)) continue; ?>
        <a class="nav-link <?= $current === $slug || ($slug === 'subnets' && $current === 'subnet_view') ? 'active' : '' ?>"
           href="/?page=<?= e($slug) ?>">
          <i class="bi <?= e($icon) ?>"></i> <?= e($label) ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="dv-sidebar-footer">
      v<?= e(App::config()['app']['version']) ?>
    </div>
  </aside>

  <div class="dv-main">
    <header class="dv-topbar">
      <span class="dv-page-title"><?= e($title ?? '') ?></span>
      <div class="d-flex align-items-center gap-3">
        <div class="dropdown">
          <button class="btn btn-sm btn-outline-secondary position-relative" data-bs-toggle="dropdown" title="Notifications">
            <i class="bi bi-bell"></i>
            <?php if ($unread): ?>
              <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= count($unread) ?></span>
            <?php endif; ?>
          </button>
          <div class="dropdown-menu dropdown-menu-end p-0" style="min-width: 320px">
            <div class="list-group list-group-flush">
              <?php foreach ($unread as $n): ?>
                <div class="list-group-item small" style="background:transparent">
                  <?= e($n['message']) ?>
                  <div class="text-secondary" style="font-size:.75rem"><?= e($n['created_at']) ?></div>
                </div>
              <?php endforeach; ?>
              <?php if (!$unread): ?>
                <div class="list-group-item small text-secondary" style="background:transparent">No unread notifications.</div>
              <?php else: ?>
                <form method="post" class="p-2 m-0">
                  <?= Auth::csrfField() ?>
                  <input type="hidden" name="action" value="notif_read">
                  <input type="hidden" name="back" value="<?= e($current) ?>">
                  <button class="btn btn-sm btn-outline-secondary w-100">Mark all read</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <button class="btn btn-sm btn-outline-secondary" id="themeToggle" title="Toggle dark / light">
          <i class="bi bi-moon-stars"></i>
        </button>
        <span class="text-secondary small">
          <i class="bi bi-person-circle"></i>
          <?= e($user['full_name'] ?: $user['username']) ?>
          <span class="badge text-bg-secondary ms-1"><?= e($user['role']) ?></span>
        </span>
        <form method="post" class="m-0">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="logout">
          <button class="btn btn-sm btn-outline-danger"><i class="bi bi-box-arrow-right"></i></button>
        </form>
      </div>
    </header>

    <main class="dv-content">
      <?php foreach (App::takeFlashes() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show">
          <?= e($f['message']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endforeach; ?>
