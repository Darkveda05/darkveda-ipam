<?php
use DarkVeda\{Auth, Database, Zabbix, Uploads};
use function DarkVeda\e;

$canManage = Auth::can('racks.manage');
$sites = Database::q('SELECT id, name FROM sites ORDER BY name');
$racks = Database::q(
    'SELECT r.*, s.name AS site_name,
            (SELECT COUNT(*) FROM rack_items ri WHERE ri.rack_id = r.id) AS mounted,
            (SELECT COALESCE(SUM(ri.u_size),0) FROM rack_items ri WHERE ri.rack_id = r.id) AS used_u
     FROM racks r LEFT JOIN sites s ON s.id = r.site_id
     ORDER BY s.name, r.name'
);

$selectedId = (int)($_GET['id'] ?? 0);
if (!$selectedId && $racks) {
    $selectedId = (int)$racks[0]['id'];
}
$rack = $selectedId
    ? Database::one('SELECT r.*, s.name AS site_name FROM racks r LEFT JOIN sites s ON s.id = r.site_id WHERE r.id = ?', [$selectedId])
    : null;

$face = ($_GET['face'] ?? 'front') === 'rear' ? 'rear' : 'front';
$editItemId = (int)($_GET['item'] ?? 0);

$items = [];
$byU   = [];
if ($rack) {
    $items = Database::q(
        'SELECT ri.*, i.address, i.subnet_id, i.hostname AS ip_hostname,
                dt.image_path AS type_image,
                TRIM(CONCAT(COALESCE(v.name, ""), " ", dt.model)) AS type_label,
                COALESCE(NULLIF(ri.photo_path, ""), dt.image_path) AS display_image
         FROM rack_items ri
         LEFT JOIN ip_addresses i ON i.id = ri.ip_id
         LEFT JOIN device_types dt ON dt.id = i.device_type_id
         LEFT JOIN vendors v ON v.id = dt.vendor_id
         WHERE ri.rack_id = ?
         ORDER BY ri.u_position DESC',
        [$selectedId]
    );
    foreach ($items as $it) {
        if ($it['face'] === $face) {
            $byU[(int)$it['u_position']] = $it;
        }
    }
}
$editItem = null;
foreach ($items as $it) {
    if ((int)$it['id'] === $editItemId) {
        $editItem = $it;
    }
}

$availableIps = Database::q(
    'SELECT i.id, i.address, i.hostname, s.cidr,
            COALESCE((SELECT dt.u_height FROM device_types dt WHERE dt.id = i.device_type_id), 1) AS u_height
     FROM ip_addresses i JOIN subnets s ON s.id = i.subnet_id
     WHERE NOT EXISTS (SELECT 1 FROM rack_items ri WHERE ri.ip_id = i.id AND ri.id <> ?)
     ORDER BY i.address_bin LIMIT 400',
    [$editItemId]
);

$mon = Zabbix::statusMap();
$kinds = ['device' => 'Device', 'patch_panel' => 'Patch panel', 'screen' => 'Screen / KVM',
          'power' => 'Power / PDU / UPS', 'shelf' => 'Shelf', 'blank' => 'Blanking plate', 'other' => 'Other'];
$uploadsOk = Uploads::writable();
?>
<?php if (!$uploadsOk): ?>
<div class="alert alert-warning">
  <strong>Uploads directory is not writable.</strong> Equipment photos and documentation cannot be saved until
  <code>public/uploads</code> exists and is writable by the web server
  (on linuxserver images: <code>mkdir -p public/uploads &amp;&amp; chown -R abc:abc public/uploads</code>).
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-3">
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-grid-3x3"></i> Racks</span>
        <a class="btn btn-sm btn-outline-secondary" href="/?page=rack3d" title="3D view"><i class="bi bi-box"></i> 3D</a>
      </div>
      <ul class="list-group list-group-flush">
        <?php foreach ($racks as $r): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center"
              style="<?= (int)$r['id'] === $selectedId ? 'background:rgba(139,92,246,.15)' : 'background:transparent' ?>">
            <a href="/?page=racks&id=<?= (int)$r['id'] ?>" class="text-decoration-none flex-grow-1">
              <strong><?= e($r['name']) ?></strong>
              <div class="small text-secondary">
                <?= e($r['site_name'] ?? '—') ?> · <?= (int)$r['u_height'] ?>U · <?= (int)$r['used_u'] ?>U used
              </div>
            </a>
            <?php if ($canManage): ?>
            <form method="post" class="m-0" data-confirm="Delete rack <?= e($r['name']) ?>? Mounted items are removed from the rack (IP records are kept).">
              <?= Auth::csrfField() ?>
              <input type="hidden" name="action" value="rack_delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
        <?php if (!$racks): ?>
          <li class="list-group-item text-secondary" style="background:transparent">No racks yet.</li>
        <?php endif; ?>
      </ul>
    </div>

    <?php if ($canManage): ?>
    <div class="card">
      <div class="card-header"><i class="bi bi-plus-square"></i> New rack</div>
      <div class="card-body">
        <form method="post" class="row g-2">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="rack_create">
          <div class="col-12">
            <label class="form-label">Name *</label>
            <input name="name" class="form-control" required placeholder="RACK-A1">
          </div>
          <div class="col-12">
            <label class="form-label">Site *</label>
            <select name="site_id" class="form-select" required>
              <option value="">—</option>
              <?php foreach ($sites as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Height (U)</label>
            <input name="u_height" type="number" min="1" max="60" value="42" class="form-control">
          </div>
          <div class="col-12">
            <label class="form-label">Description</label>
            <input name="description" class="form-control">
          </div>
          <div class="col-12 d-grid">
            <button class="btn btn-primary"><i class="bi bi-plus-lg"></i> Create rack</button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-5">
    <?php if (!$rack): ?>
      <div class="card"><div class="card-body text-center text-secondary py-5">
        Create a rack to start mapping equipment.
      </div></div>
    <?php else: ?>
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>
          <i class="bi bi-hdd-stack"></i> <?= e($rack['name']) ?>
          <small class="text-secondary"><?= e($rack['site_name'] ?? '') ?> · <?= (int)$rack['u_height'] ?>U</small>
        </span>
        <div class="d-flex gap-2">
          <div class="btn-group btn-group-sm">
            <a class="btn btn-outline-secondary <?= $face === 'front' ? 'active' : '' ?>" href="/?page=racks&id=<?= $selectedId ?>&face=front">Front</a>
            <a class="btn btn-outline-secondary <?= $face === 'rear' ? 'active' : '' ?>" href="/?page=racks&id=<?= $selectedId ?>&face=rear">Rear</a>
          </div>
          <?php if ($canManage): ?>
          <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#editrack" title="Edit rack">
            <i class="bi bi-pencil"></i> Edit
          </button>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($canManage): ?>
      <div class="collapse" id="editrack">
        <div class="p-3 dv-nested border-bottom">
          <form method="post" class="row g-2">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="rack_update">
            <input type="hidden" name="id" value="<?= $selectedId ?>">
            <div class="col-md-4">
              <label class="form-label">Name *</label>
              <input name="name" class="form-control" required value="<?= e($rack['name']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Site *</label>
              <select name="site_id" class="form-select" required>
                <?php foreach ($sites as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= (int)$rack['site_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Height (RU) *</label>
              <input name="u_height" type="number" min="1" max="60" class="form-control" required value="<?= (int)$rack['u_height'] ?>">
            </div>
            <div class="col-md-9">
              <label class="form-label">Description</label>
              <input name="description" class="form-control" value="<?= e($rack['description'] ?? '') ?>">
            </div>
            <div class="col-md-3 d-flex align-items-end">
              <button class="btn btn-primary w-100"><i class="bi bi-check-lg"></i> Save rack</button>
            </div>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <div class="card-body">
        <div class="dv-rack">
          <?php
          $height = (int)$rack['u_height'];
          $cover = [];
          foreach ($byU as $pos => $it) {
              for ($k = 0; $k < (int)$it['u_size']; $k++) {
                  $cover[$pos + $k] = $pos;
              }
          }
          for ($u = $height; $u >= 1; $u--):
              if (isset($cover[$u]) && $cover[$u] !== $u) {
                  continue;
              }
              $dev   = $byU[$u] ?? null;
              $span  = $dev ? max(1, (int)$dev['u_size']) : 1;
              $state = ($dev && $dev['address']) ? ($mon[$dev['address']]['state'] ?? null) : null;
              $top   = $dev ? $u + $span - 1 : $u;
          ?>
            <div class="dv-u <?= $dev ? 'dv-u-filled' : 'dv-u-empty' ?>" style="height: <?= 34 * $span ?>px">
              <span class="dv-u-num"><?= $span > 1 ? $top . '–' . $u : $u ?></span>
              <?php if ($dev): $img = $dev['display_image'] ?? null; ?>
                <span class="dv-u-body <?= $img ? 'dv-u-has-photo' : 'dv-u-no-photo' ?>">
                  <?php if ($img): ?>
                    <img src="/<?= e($img) ?>" alt="" class="dv-u-faceplate">
                  <?php else: ?>
                    <span class="dv-u-blank" aria-hidden="true"></span>
                  <?php endif; ?>
                  <span class="dv-u-label"
                        title="<?= e(($dev['type_label'] ?: ($kinds[$dev['kind']] ?? $dev['kind']))
                                     . ($dev['address'] ? ' · ' . $dev['address'] : '')
                                     . ' · U' . $u . ($span > 1 ? '–U' . ($u + $span - 1) : '')) ?>">
                    <?php if ($state): ?><span class="dv-dot dv-dot-<?= e($state) ?>"></span><?php endif; ?>
                    <strong><?= e($dev['name']) ?></strong>
                  </span>
                </span>
                <span class="dv-u-eject d-flex gap-1">
                  <?php if ($dev['subnet_id']): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="/?page=subnet_view&id=<?= (int)$dev['subnet_id'] ?>" title="Open IP record"><i class="bi bi-box-arrow-up-right"></i></a>
                  <?php endif; ?>
                  <?php if ($canManage): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="/?page=racks&id=<?= $selectedId ?>&face=<?= $face ?>&item=<?= (int)$dev['id'] ?>#itemform" title="Edit"><i class="bi bi-pencil"></i></a>
                    <form method="post" class="m-0" data-confirm="Remove <?= e($dev['name']) ?> from the rack?">
                      <?= Auth::csrfField() ?>
                      <input type="hidden" name="action" value="rack_item_delete">
                      <input type="hidden" name="id" value="<?= (int)$dev['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger" title="Unmount"><i class="bi bi-box-arrow-up"></i></button>
                    </form>
                  <?php endif; ?>
                </span>
              <?php else: ?>
                <?php if ($canManage): ?>
                  <a class="dv-u-body text-secondary small text-decoration-none"
                     href="/?page=racks&id=<?= $selectedId ?>&face=<?= $face ?>&u=<?= $u ?>#itemform">
                    <i class="bi bi-plus-lg me-1"></i> empty — click to mount
                  </a>
                <?php else: ?>
                  <span class="dv-u-body text-secondary small">empty</span>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          <?php endfor; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-4">
    <?php if ($rack && $canManage): ?>
    <div class="card mb-3" id="itemform">
      <div class="card-header">
        <i class="bi bi-box-arrow-in-down"></i>
        <?= $editItem ? 'Edit ' . e($editItem['name']) : 'Mount equipment' ?>
        <?php if ($editItem): ?>
          <a class="btn btn-sm btn-outline-secondary float-end" href="/?page=racks&id=<?= $selectedId ?>&face=<?= $face ?>">Cancel</a>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-2">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="rack_item_save">
          <input type="hidden" name="rack_id" value="<?= $selectedId ?>">
          <input type="hidden" name="item_id" value="<?= (int)($editItem['id'] ?? 0) ?>">

          <div class="col-12">
            <label class="form-label">Type</label>
            <select name="kind" class="form-select">
              <?php foreach ($kinds as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= ($editItem['kind'] ?? 'device') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12">
            <label class="form-label">Linked IP record <span class="text-secondary">(optional)</span></label>
            <select name="ip_id" class="form-select" id="itemIp">
              <option value="">— passive equipment, no IP —</option>
              <?php foreach ($availableIps as $a): ?>
                <option value="<?= (int)$a['id'] ?>" data-u="<?= (int)$a['u_height'] ?>"
                        <?= (int)($editItem['ip_id'] ?? 0) === (int)$a['id'] ? 'selected' : '' ?>>
                  <?= e($a['address']) ?><?= $a['hostname'] ? ' · ' . e($a['hostname']) : '' ?> (<?= e($a['cidr']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Leave empty for screens, patch panels, PDUs and other passive gear.</div>
          </div>

          <div class="col-12">
            <label class="form-label">Label *</label>
            <input name="name" class="form-control" id="itemName" required
                   value="<?= e($editItem['name'] ?? '') ?>" placeholder="24-port patch panel">
          </div>

          <div class="col-4">
            <label class="form-label">Bottom U *</label>
            <input name="u_position" type="number" min="1" max="<?= (int)$rack['u_height'] ?>" class="form-control" required
                   value="<?= (int)($editItem['u_position'] ?? ($_GET['u'] ?? 1)) ?>">
          </div>
          <div class="col-4">
            <label class="form-label">Size (U) *</label>
            <input name="u_size" type="number" min="1" max="20" class="form-control" id="itemSize" required
                   value="<?= (int)($editItem['u_size'] ?? 1) ?>">
          </div>
          <div class="col-4">
            <label class="form-label">Face</label>
            <select name="face" class="form-select">
              <option value="front" <?= ($editItem['face'] ?? $face) === 'front' ? 'selected' : '' ?>>front</option>
              <option value="rear"  <?= ($editItem['face'] ?? $face) === 'rear'  ? 'selected' : '' ?>>rear</option>
            </select>
          </div>
          <div class="col-12">
            <div class="form-text">A 2U screen with bottom U 9 and size 2 occupies U9 and U10 together.</div>
          </div>

          <div class="col-12">
            <label class="form-label">Photo override <span class="text-secondary">(optional)</span></label>
            <input type="file" name="photo" class="form-control"
                   accept=".png,.jpg,.jpeg,.svg,.webp,image/png,image/jpeg,image/svg+xml,image/webp" <?= $uploadsOk ? '' : 'disabled' ?>>
            <div class="form-text">
              Devices show the image saved against their model under <a href="/?page=devices">Devices</a>.
              Upload here only to override it for this one unit.
            </div>
            <?php if ($editItem && $editItem['photo_path']): ?>
              <div class="mt-2">
                <img src="/<?= e($editItem['photo_path']) ?>" alt=""
                     style="width:100%;max-height:60px;object-fit:cover;border-radius:4px;background:#08080e">
                <span class="small text-secondary">current override</span>
              </div>
            <?php endif; ?>
          </div>

          <div class="col-12">
            <label class="form-label">Notes</label>
            <input name="description" class="form-control" value="<?= e($editItem['description'] ?? '') ?>">
          </div>

          <div class="col-12 d-grid">
            <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= $editItem ? 'Save changes' : 'Mount' ?></button>
          </div>
        </form>

        <?php if ($editItem && $editItem['photo_path']): ?>
        <form method="post" class="mt-2" data-confirm="Remove the photo from this item?">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="rack_item_photo_delete">
          <input type="hidden" name="id" value="<?= (int)$editItem['id'] ?>">
          <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-image"></i> Remove photo</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($rack): ?>
    <div class="card">
      <div class="card-header"><i class="bi bi-list-ul"></i> Mounted equipment</div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead><tr><th>U</th><th>Face</th><th>Item</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($items as $it):
              $st  = $it['address'] ? ($mon[$it['address']]['state'] ?? null) : null;
              $top = (int)$it['u_position'] + (int)$it['u_size'] - 1;
          ?>
            <tr>
              <td><code><?= (int)$it['u_size'] > 1 ? (int)$it['u_position'] . '–' . $top : (int)$it['u_position'] ?></code></td>
              <td class="small text-secondary"><?= e($it['face']) ?></td>
              <td>
                <?php if ($it['display_image']): ?>
                  <img src="/<?= e($it['display_image']) ?>" alt="" style="height:20px;width:44px;object-fit:contain;vertical-align:middle;margin-right:.35rem;border-radius:3px;background:#08080e">
                <?php endif; ?>
                <?php if ($it['subnet_id']): ?>
                  <a href="/?page=subnet_view&id=<?= (int)$it['subnet_id'] ?>"><?= e($it['name']) ?></a>
                <?php else: ?>
                  <?= e($it['name']) ?>
                <?php endif; ?>
                <div class="small text-secondary">
                  <?= e($kinds[$it['kind']] ?? $it['kind']) ?><?= $it['address'] ? ' · ' . e($it['address']) : '' ?>
                </div>
              </td>
              <td>
                <?php if ($st): ?>
                  <span class="badge text-bg-<?= $st === 'online' ? 'success' : ($st === 'offline' ? 'danger' : 'secondary') ?>"><?= e($st) ?></span>
                <?php else: ?>
                  <span class="text-secondary small">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$items): ?>
            <tr><td colspan="4" class="text-center text-secondary py-4">Nothing mounted in this rack.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  var ip = document.getElementById('itemIp');
  var nameField = document.getElementById('itemName');
  var sizeField = document.getElementById('itemSize');
  if (!ip) return;
  ip.addEventListener('change', function () {
    var opt = ip.options[ip.selectedIndex];
    if (!opt || !opt.value) return;
    if (nameField && !nameField.value.trim()) {
      nameField.value = opt.textContent.trim().split(' · ').slice(-1)[0].replace(/\s*\(.*\)$/, '');
    }
    var u = parseInt(opt.getAttribute('data-u') || '1', 10);
    if (sizeField && u > 0) sizeField.value = u;
  });
})();
</script>
