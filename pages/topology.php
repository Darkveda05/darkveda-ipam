<?php
use DarkVeda\{Auth, Database};
use function DarkVeda\e;

$proto = in_array($_GET['proto'] ?? 'all', ['all', 'lldp', 'cdp'], true) ? ($_GET['proto'] ?? 'all') : 'all';

$where = $proto === 'all' ? '' : ' WHERE protocol = ' . ($proto === 'lldp' ? "'lldp'" : "'cdp'");
$links = Database::q('SELECT * FROM topology_links' . $where . ' ORDER BY last_seen DESC LIMIT 500');

// Build node set: local devices (known by IP) + remote devices (known by name)
$nodes = [];
$addNode = function (string $key, array $attrs) use (&$nodes): void {
    if (!isset($nodes[$key])) {
        $nodes[$key] = $attrs + ['id' => $key, 'degree' => 0];
    } else {
        foreach ($attrs as $k => $v) {
            if (($nodes[$key][$k] ?? null) === null && $v !== null) {
                $nodes[$key][$k] = $v;
            }
        }
    }
    $nodes[$key]['degree']++;
};

// Map known IPs to their IPAM records so nodes can link into the app
$known = [];
foreach (Database::q('SELECT id, address, subnet_id, hostname FROM ip_addresses') as $r) {
    $known[$r['address']] = $r;
}

$edges = [];
foreach ($links as $l) {
    $a = $l['local_ip'];
    $b = trim((string)$l['remote_name']) !== '' ? $l['remote_name'] : ($l['remote_ip'] ?? 'unknown');

    $addNode($a, [
        'label'     => $l['local_name'] ?: $l['local_ip'],
        'ip'        => $l['local_ip'],
        'managed'   => isset($known[$l['local_ip']]),
        'ip_id'     => $known[$l['local_ip']]['id'] ?? null,
        'subnet_id' => $known[$l['local_ip']]['subnet_id'] ?? null,
        'descr'     => null,
    ]);
    $addNode($b, [
        'label'     => $b,
        'ip'        => $l['remote_ip'],
        'managed'   => $l['remote_ip'] !== null && isset($known[$l['remote_ip']]),
        'ip_id'     => $l['remote_ip'] !== null ? ($known[$l['remote_ip']]['id'] ?? null) : null,
        'subnet_id' => $l['remote_ip'] !== null ? ($known[$l['remote_ip']]['subnet_id'] ?? null) : null,
        'descr'     => $l['remote_descr'],
    ]);

    $edges[] = [
        'source'   => $a,
        'target'   => $b,
        'protocol' => $l['protocol'],
        'sport'    => $l['local_port'],
        'tport'    => $l['remote_port'],
        'seen'     => $l['last_seen'],
    ];
}

$stats = [
    'nodes' => count($nodes),
    'edges' => count($edges),
    'lldp'  => count(array_filter($links, fn($l) => $l['protocol'] === 'lldp')),
    'cdp'   => count(array_filter($links, fn($l) => $l['protocol'] === 'cdp')),
];
$payload = json_encode(['nodes' => array_values($nodes), 'edges' => $edges], JSON_UNESCAPED_SLASHES);
?>
<div class="row g-3 mb-3">
  <?php foreach ([
      ['Nodes', $stats['nodes'], 'bi-hdd-network'],
      ['Links', $stats['edges'], 'bi-share'],
      ['LLDP edges', $stats['lldp'], 'bi-diagram-2'],
      ['CDP edges', $stats['cdp'], 'bi-diagram-3'],
  ] as [$label, $value, $icon]): ?>
  <div class="col-6 col-lg-3">
    <div class="card"><div class="dv-stat">
      <i class="bi <?= e($icon) ?>"></i>
      <div><div class="dv-stat-value"><?= (int)$value ?></div><div class="dv-stat-label"><?= e($label) ?></div></div>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><i class="bi bi-diagram-3"></i> Topology map</span>
    <div class="btn-group btn-group-sm">
      <?php foreach (['all' => 'All', 'lldp' => 'LLDP', 'cdp' => 'CDP'] as $k => $lbl): ?>
        <a class="btn btn-outline-secondary <?= $proto === $k ? 'active' : '' ?>"
           href="/?page=topology&proto=<?= $k ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card-body p-0 position-relative">
    <?php if (!$edges): ?>
      <div class="p-5 text-center text-secondary">
        <i class="bi bi-diagram-3 d-block mb-2" style="font-size:2rem"></i>
        No topology data yet. Configure an <a href="/?page=snmp">SNMP profile</a> and run a
        <a href="/?page=discovery">discovery scan</a> — LLDP and CDP neighbours are collected automatically.
      </div>
    <?php else: ?>
      <div id="topoWrap" style="height:560px; overflow:hidden">
        <svg id="topo" width="100%" height="560" style="cursor:grab"></svg>
      </div>
      <div id="topoTip" class="position-absolute d-none p-2 rounded"
           style="pointer-events:none; background:rgba(15,15,25,.95); border:1px solid #3a3a55; font-size:.8rem; z-index:5"></div>
    <?php endif; ?>
  </div>
  <?php if ($edges): ?>
  <div class="card-footer small text-secondary">
    Drag nodes to rearrange · scroll to zoom · click a managed node to open its IP record.
    Violet = managed in IPAM, grey = neighbour seen only via LLDP/CDP.
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-header"><i class="bi bi-list-ul"></i> Neighbour table</div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0">
      <thead><tr>
        <th>Proto</th><th>Local device</th><th>Local port</th>
        <th>Remote device</th><th>Remote port</th><th>Remote IP</th><th>Platform</th><th>Last seen</th>
      </tr></thead>
      <tbody>
      <?php foreach ($links as $l): ?>
        <tr>
          <td><span class="badge text-bg-<?= $l['protocol'] === 'lldp' ? 'primary' : 'info' ?>"><?= e($l['protocol']) ?></span></td>
          <td>
            <?php if (isset($known[$l['local_ip']])): ?>
              <a href="/?page=subnet_view&id=<?= (int)$known[$l['local_ip']]['subnet_id'] ?>"><?= e($l['local_name'] ?: $l['local_ip']) ?></a>
            <?php else: ?>
              <?= e($l['local_name'] ?: $l['local_ip']) ?>
            <?php endif; ?>
            <div class="small text-secondary"><code><?= e($l['local_ip']) ?></code></div>
          </td>
          <td><code class="small"><?= e($l['local_port'] ?: '—') ?></code></td>
          <td><?= e($l['remote_name'] ?: '—') ?></td>
          <td><code class="small"><?= e($l['remote_port'] ?: '—') ?></code></td>
          <td><code class="small"><?= e($l['remote_ip'] ?: '—') ?></code></td>
          <td class="text-secondary small"><?= e($l['remote_descr'] ?: '—') ?></td>
          <td class="small text-secondary"><?= e($l['last_seen']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$links): ?>
        <tr><td colspan="8" class="text-center text-secondary py-4">No neighbour entries.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($edges): ?>
<script>
(function () {
  const data = <?= $payload ?>;
  const svg  = document.getElementById('topo');
  const tip  = document.getElementById('topoTip');
  if (!svg) return;

  const W = svg.clientWidth || 900, H = 560;
  const NS = 'http://www.w3.org/2000/svg';

  // ---- seed positions on a circle, then relax with a small force sim ----
  const nodes = data.nodes.map((n, i) => {
    const a = (i / data.nodes.length) * Math.PI * 2;
    return Object.assign({}, n, {
      x: W / 2 + Math.cos(a) * Math.min(W, H) * 0.32,
      y: H / 2 + Math.sin(a) * Math.min(W, H) * 0.32,
      vx: 0, vy: 0
    });
  });
  const index = new Map(nodes.map(n => [n.id, n]));
  const edges = data.edges.filter(e => index.has(e.source) && index.has(e.target));

  for (let iter = 0; iter < 320; iter++) {
    // repulsion
    for (let i = 0; i < nodes.length; i++) {
      for (let j = i + 1; j < nodes.length; j++) {
        const a = nodes[i], b = nodes[j];
        let dx = a.x - b.x, dy = a.y - b.y;
        let d2 = dx * dx + dy * dy || 0.01;
        const f = 5200 / d2;
        const d = Math.sqrt(d2);
        const ux = dx / d, uy = dy / d;
        a.vx += ux * f; a.vy += uy * f;
        b.vx -= ux * f; b.vy -= uy * f;
      }
    }
    // springs
    edges.forEach(e => {
      const a = index.get(e.source), b = index.get(e.target);
      const dx = b.x - a.x, dy = b.y - a.y;
      const d = Math.sqrt(dx * dx + dy * dy) || 0.01;
      const f = (d - 130) * 0.02;
      const ux = dx / d, uy = dy / d;
      a.vx += ux * f; a.vy += uy * f;
      b.vx -= ux * f; b.vy -= uy * f;
    });
    // centre pull + integrate
    nodes.forEach(n => {
      n.vx += (W / 2 - n.x) * 0.002;
      n.vy += (H / 2 - n.y) * 0.002;
      n.x += n.vx * 0.5; n.y += n.vy * 0.5;
      n.vx *= 0.82; n.vy *= 0.82;
      n.x = Math.max(40, Math.min(W - 40, n.x));
      n.y = Math.max(30, Math.min(H - 30, n.y));
    });
  }

  const root = document.createElementNS(NS, 'g');
  svg.appendChild(root);
  const gEdges = document.createElementNS(NS, 'g');
  const gNodes = document.createElementNS(NS, 'g');
  root.appendChild(gEdges); root.appendChild(gNodes);

  const edgeEls = edges.map(e => {
    const line = document.createElementNS(NS, 'line');
    line.setAttribute('stroke', e.protocol === 'lldp' ? '#6366f1' : '#0ea5e9');
    line.setAttribute('stroke-width', '1.6');
    line.setAttribute('stroke-opacity', '.55');
    gEdges.appendChild(line);
    return { el: line, e };
  });

  const nodeEls = nodes.map(n => {
    const g = document.createElementNS(NS, 'g');
    g.setAttribute('cursor', 'pointer');

    const c = document.createElementNS(NS, 'circle');
    c.setAttribute('r', String(Math.min(20, 9 + n.degree)));
    c.setAttribute('fill', n.managed ? '#8b5cf6' : '#4b5563');
    c.setAttribute('stroke', '#0b0b12');
    c.setAttribute('stroke-width', '2');
    g.appendChild(c);

    const t = document.createElementNS(NS, 'text');
    t.textContent = n.label.length > 22 ? n.label.slice(0, 21) + '…' : n.label;
    t.setAttribute('font-size', '11');
    t.setAttribute('text-anchor', 'middle');
    t.setAttribute('fill', 'currentColor');
    t.setAttribute('y', '-16');
    g.appendChild(t);

    g.addEventListener('mouseenter', ev => {
      tip.classList.remove('d-none');
      tip.innerHTML = '<strong>' + esc(n.label) + '</strong>'
        + (n.ip ? '<br><code>' + esc(n.ip) + '</code>' : '')
        + (n.descr ? '<br><span class="text-secondary">' + esc(n.descr) + '</span>' : '')
        + '<br><span class="text-secondary">' + n.degree + ' link(s)'
        + (n.managed ? ' · managed' : ' · discovered') + '</span>';
    });
    g.addEventListener('mousemove', ev => {
      const r = svg.getBoundingClientRect();
      tip.style.left = (ev.clientX - r.left + 12) + 'px';
      tip.style.top  = (ev.clientY - r.top + 12) + 'px';
    });
    g.addEventListener('mouseleave', () => tip.classList.add('d-none'));
    g.addEventListener('click', () => {
      if (n.subnet_id) location.href = '/?page=subnet_view&id=' + n.subnet_id;
    });

    // drag
    let dragging = false;
    g.addEventListener('mousedown', ev => { dragging = true; ev.preventDefault(); });
    window.addEventListener('mouseup', () => { dragging = false; });
    window.addEventListener('mousemove', ev => {
      if (!dragging) return;
      const r = svg.getBoundingClientRect();
      n.x = (ev.clientX - r.left - pan.x) / pan.k;
      n.y = (ev.clientY - r.top  - pan.y) / pan.k;
      draw();
    });

    gNodes.appendChild(g);
    return { g, n };
  });

  // pan + zoom
  const pan = { x: 0, y: 0, k: 1 };
  let panning = false, sx = 0, sy = 0;
  svg.addEventListener('mousedown', ev => {
    if (ev.target === svg) { panning = true; sx = ev.clientX - pan.x; sy = ev.clientY - pan.y; svg.style.cursor = 'grabbing'; }
  });
  window.addEventListener('mouseup', () => { panning = false; svg.style.cursor = 'grab'; });
  window.addEventListener('mousemove', ev => {
    if (!panning) return;
    pan.x = ev.clientX - sx; pan.y = ev.clientY - sy; draw();
  });
  svg.addEventListener('wheel', ev => {
    ev.preventDefault();
    const f = ev.deltaY < 0 ? 1.12 : 0.89;
    pan.k = Math.max(0.3, Math.min(3, pan.k * f));
    draw();
  }, { passive: false });

  function draw() {
    root.setAttribute('transform', 'translate(' + pan.x + ',' + pan.y + ') scale(' + pan.k + ')');
    edgeEls.forEach(({ el, e }) => {
      const a = index.get(e.source), b = index.get(e.target);
      el.setAttribute('x1', a.x); el.setAttribute('y1', a.y);
      el.setAttribute('x2', b.x); el.setAttribute('y2', b.y);
    });
    nodeEls.forEach(({ g, n }) => g.setAttribute('transform', 'translate(' + n.x + ',' + n.y + ')'));
  }
  function esc(s) {
    return String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  }
  draw();
})();
</script>
<?php endif; ?>
