<?php use AIPanel\Http\View; $title = 'Dashboard — aipanel'; require __DIR__ . '/layout/header.php'; ?>

<h1>Dashboard</h1>
<p class="sub">Every website is its own tenant: jailed user, own repo, AI-authored changes, main deploys live.</p>

<h2>Sites</h2>
<div class="card">
  <?php if ($sites === []): ?>
    <p class="empty">No sites yet. <a href="/sites">Add your first website</a>.</p>
  <?php else: ?>
  <table>
    <tr><th>Domain</th><th>Repository</th><th>Live commit</th><th>Node</th><th>Status</th></tr>
    <?php foreach ($sites as $site): ?>
    <tr>
      <td><a href="/sites/<?= (int) $site['id'] ?>"><?= View::e($site['domain']) ?></a>
          <div class="muted mono" style="font-size:11px"><?= View::e($site['site_user'] ?? '') ?></div></td>
      <td class="mono"><?= View::e($site['repo'] ?? '—') ?></td>
      <td class="mono"><?= View::e(substr((string) ($site['live_commit'] ?? '—'), 0, 8)) ?></td>
      <td class="mono"><?= View::e($site['node_hostname'] ?? '') ?></td>
      <td><span class="pill <?= View::e($site['status']) ?>"><?= View::e($site['status']) ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<h2>Recent AI change requests</h2>
<div class="card">
  <?php if ($changes === []): ?>
    <p class="empty">No change requests yet.</p>
  <?php else: ?>
  <table>
    <tr><th>Site</th><th>Request</th><th>State</th><th>Pull request</th></tr>
    <?php foreach ($changes as $change): ?>
    <tr>
      <td><?= View::e($change['domain']) ?></td>
      <td><?= View::e(mb_strimwidth((string) $change['instruction'], 0, 90, '…')) ?></td>
      <td><span class="pill <?= View::e($change['state']) ?>"><?= View::e($change['state']) ?></span></td>
      <td><?php if (!empty($change['pull_request_url'])): ?>
            <a href="<?= View::e($change['pull_request_url']) ?>" rel="noopener">open</a>
          <?php else: ?><span class="muted">—</span><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<h2>Fleet</h2>
<div class="grid">
  <?php foreach ($nodes as $node): $facts = json_decode((string) ($node['facts'] ?? '{}'), true) ?: []; ?>
  <div class="card">
    <strong class="mono"><?= View::e($node['hostname']) ?></strong>
    <span class="pill <?= View::e($node['status']) ?>" style="float:right"><?= View::e($node['status']) ?></span>
    <div class="muted" style="margin-top:8px">role <?= View::e($node['role']) ?></div>
    <?php if (isset($facts['load']['1m'])): ?>
      <div class="muted">load <?= View::e($facts['load']['1m']) ?> · <?= View::e($facts['sites'] ?? 0) ?> sites</div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if ($nodes === []): ?><div class="card"><p class="empty">No nodes registered.</p></div><?php endif; ?>
</div>

<h2>Recent jobs</h2>
<div class="card">
  <?php if ($jobs === []): ?>
    <p class="empty">Nothing has run yet.</p>
  <?php else: ?>
  <table>
    <tr><th>#</th><th>Task</th><th>State</th><th>Attempts</th><th>Finished</th></tr>
    <?php foreach ($jobs as $job): ?>
    <tr>
      <td class="mono"><?= (int) $job['id'] ?></td>
      <td class="mono"><?= View::e($job['task']) ?></td>
      <td><span class="pill <?= View::e($job['state']) ?>"><?= View::e($job['state']) ?></span>
          <?php if (!empty($job['error'])): ?><div class="muted" style="font-size:11px"><?= View::e(mb_strimwidth((string) $job['error'], 0, 70, '…')) ?></div><?php endif; ?></td>
      <td><?= (int) $job['attempts'] ?></td>
      <td class="muted"><?= View::e($job['finished_at'] ?? '—') ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/layout/footer.php'; ?>
