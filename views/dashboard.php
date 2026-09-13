<?php use AIPanel\Http\View; $title = 'Dashboard — aipanel'; require __DIR__ . '/layout/header.php'; ?>

<h1>Dashboard</h1>
<p class="sub">
  Push to <code>sandbox</code> to preview, promote to go live. Every domain gets
  Let's Encrypt automatically.
</p>

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

<h2>Recent promotions</h2>
<div class="card">
  <?php if ($promotions === []): ?>
    <p class="empty">Nothing promoted to production yet.</p>
  <?php else: ?>
  <table>
    <tr><th>Website</th><th>Promoted</th><th>State</th><th>When</th></tr>
    <?php foreach ($promotions as $promotion): ?>
    <tr>
      <td><?= View::e($promotion['domain']) ?></td>
      <td class="mono"><?= View::e(substr((string) $promotion['from_commit'], 0, 8)) ?>
          → <?= View::e($promotion['to_branch']) ?></td>
      <td><span class="pill <?= View::e($promotion['state'] === 'merged' ? 'live' : 'failed') ?>">
          <?= View::e($promotion['state']) ?></span></td>
      <td class="muted"><?= View::e($promotion['created_at']) ?></td>
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
