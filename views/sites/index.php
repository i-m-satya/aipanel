<?php use AIPanel\Http\View; $title = 'Sites — aipanel'; require __DIR__ . '/../layout/header.php'; ?>

<h1>Sites</h1>
<p class="sub">One website, one tenant: its own Linux user, its own jail, its own repository.</p>

<div class="card">
  <?php if ($sites === []): ?>
    <p class="empty">No sites yet.</p>
  <?php else: ?>
  <table>
    <tr><th>Domain</th><th>Tenant user</th><th>Repository</th><th>PHP</th><th>Status</th></tr>
    <?php foreach ($sites as $site): ?>
    <tr>
      <td><a href="/sites/<?= (int) $site['id'] ?>"><?= View::e($site['domain']) ?></a></td>
      <td class="mono"><?= View::e($site['site_user'] ?? '') ?></td>
      <td class="mono"><?= View::e($site['repo'] ?? '—') ?></td>
      <td class="mono"><?= View::e($site['php_version']) ?></td>
      <td><span class="pill <?= View::e($site['status']) ?>"><?= View::e($site['status']) ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<h2>Add a website</h2>
<div class="card">
  <form method="post" action="/sites">
    <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">
    <div class="grid">
      <div>
        <label for="domain">Domain</label>
        <input id="domain" name="domain" placeholder="shop.example.com" required>
      </div>
      <div>
        <label for="repo">GitHub repository</label>
        <input id="repo" name="repo" placeholder="acme-org/shop-example-com" required>
      </div>
      <div>
        <label for="node_id">Node</label>
        <select id="node_id" name="node_id" required>
          <?php foreach ($nodes as $node): ?>
            <option value="<?= (int) $node['id'] ?>"><?= View::e($node['hostname']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="php_version">PHP version</label>
        <select id="php_version" name="php_version">
          <?php foreach (['8.4', '8.3', '8.2', '8.1'] as $version): ?>
            <option<?= $version === '8.3' ? ' selected' : '' ?>><?= View::e($version) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="document_root">Web root (inside the repo)</label>
        <input id="document_root" name="document_root" value="public">
      </div>
    </div>
    <p style="margin-top:16px"><button class="primary" type="submit">Provision tenant</button></p>
    <p class="muted" style="font-size:12px">
      Creates the jailed Linux user, PHP-FPM pool, vhost and release layout, and registers this
      node's read-only deploy key on the repository. Pushing to <code>main</code> deploys.
    </p>
  </form>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
