<?php use AIPanel\Http\View; $title = 'Operator — aipanel'; require __DIR__ . '/../layout/header.php'; ?>

<h1>Operator</h1>
<p class="sub">Shared instance: approve first sites, and suspend what misbehaves.</p>

<h2>Awaiting approval</h2>
<div class="card">
  <?php if ($pending === []): ?>
    <p class="empty">Nothing waiting.</p>
  <?php else: ?>
  <table>
    <tr><th>Domain</th><th>Repository</th><th>Account</th><th>Requested</th><th></th></tr>
    <?php foreach ($pending as $site): ?>
    <tr>
      <td class="mono"><?= View::e($site['domain']) ?></td>
      <td class="mono"><?= View::e($site['repo'] ?? '—') ?></td>
      <td><?= View::e($site['account_name']) ?>
          <div class="muted mono" style="font-size:11px">@<?= View::e($site['github_login'] ?? '?') ?></div></td>
      <td class="muted"><?= View::e($site['created_at']) ?></td>
      <td style="white-space:nowrap">
        <form method="post" action="/admin/sites/<?= (int) $site['id'] ?>/approve" class="inline">
          <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">
          <button class="primary" type="submit">Approve</button>
        </form>
        <form method="post" action="/admin/sites/<?= (int) $site['id'] ?>/reject" class="inline">
          <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">
          <button type="submit">Reject</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<h2>Accounts</h2>
<div class="card">
  <table>
    <tr><th>Account</th><th>Owner</th><th>Sites</th><th>Limit</th><th>Status</th><th></th></tr>
    <?php foreach ($accounts as $account): ?>
    <tr>
      <td><?= View::e($account['name']) ?>
          <?php if ((int) $account['trusted'] === 1): ?>
            <span class="pill active">trusted</span>
          <?php endif; ?></td>
      <td class="mono">@<?= View::e($account['owner_login'] ?? '?') ?></td>
      <td><?= (int) $account['sites'] ?></td>
      <td><?= (int) $account['max_sites'] ?></td>
      <td><span class="pill <?= $account['status'] === 'active' ? 'active' : 'suspended' ?>">
          <?= View::e($account['status']) ?></span>
          <?php if (!empty($account['suspended_reason'])): ?>
            <div class="muted" style="font-size:11px"><?= View::e($account['suspended_reason']) ?></div>
          <?php endif; ?></td>
      <td style="white-space:nowrap">
        <?php if ((int) $account['trusted'] === 0): ?>
        <form method="post" action="/admin/accounts/<?= (int) $account['id'] ?>/trust" class="inline">
          <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">
          <button type="submit">Trust</button>
        </form>
        <?php endif; ?>
        <?php if ($account['status'] === 'active'): ?>
        <form method="post" action="/admin/accounts/<?= (int) $account['id'] ?>/suspend" class="inline">
          <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">
          <button type="submit">Suspend</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
