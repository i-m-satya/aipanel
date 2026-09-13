<?php use AIPanel\Http\View; $title = $site['domain'] . ' — aipanel'; require __DIR__ . '/../layout/header.php'; ?>

<h1><?= View::e($site['domain']) ?></h1>
<p class="sub">
  <span class="pill <?= View::e($site['status']) ?>"><?= View::e($site['status']) ?></span>
  &nbsp;<span class="mono"><?= View::e($site['repo'] ?? 'no repo') ?></span>
  &nbsp;·&nbsp;live <span class="mono"><?= View::e(substr((string) ($site['live_commit'] ?? '—'), 0, 8)) ?></span>
</p>

<div class="grid">
  <div class="card">
    <h2 style="margin-top:0">Deploy</h2>
    <p class="muted">Whatever lands on <code><?= View::e($site['deploy_branch'] ?? 'main') ?></code> goes live here.</p>
    <form method="post" action="/sites/<?= (int) $site['id'] ?>/deploy" class="inline">
      <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">
      <button class="primary" type="submit">Deploy branch head</button>
    </form>
    <form method="post" action="/sites/<?= (int) $site['id'] ?>/rollback" class="inline">
      <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">
      <button type="submit">Roll back</button>
    </form>
  </div>

  <div class="card">
    <h2 style="margin-top:0">SSH access</h2>
    <p class="muted mono" style="font-size:12px">
      ssh <?= View::e($site['site_user'] ?? '') ?>@<?= View::e($site['domain']) ?>
    </p>
    <p class="muted" style="font-size:12px">
      Chrooted to this site's home with a restricted shell. It cannot see any other site on the node.
    </p>
    <form method="post" action="/sites/<?= (int) $site['id'] ?>/ssh-keys">
      <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">
      <label for="label">Key label</label>
      <input id="label" name="label" placeholder="laptop">
      <label for="public_key">Public key</label>
      <textarea id="public_key" name="public_key" rows="3" placeholder="ssh-ed25519 AAAA..." required></textarea>
      <p style="margin-top:12px"><button type="submit">Authorise key</button></p>
    </form>
    <?php if ($keys !== []): ?>
      <table style="margin-top:10px">
        <?php foreach ($keys as $key): ?>
          <tr><td><?= View::e($key['label']) ?></td>
              <td class="mono muted" style="font-size:11px"><?= View::e($key['fingerprint']) ?></td></tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>

<h2>Ask the AI to change this site</h2>
<div class="card">
  <form method="post" action="/sites/<?= (int) $site['id'] ?>/changes">
    <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">
    <label for="instruction">What should change?</label>
    <textarea id="instruction" name="instruction" rows="4"
              placeholder="Add a contact form to the homepage that posts to /contact and emails the site owner."></textarea>
    <p style="margin-top:12px"><button class="primary" type="submit">Request change</button></p>
    <p class="muted" style="font-size:12px">
      The agent edits the repository in a sandbox, runs its tests and opens a pull request.
      Merging to <code><?= View::e($site['deploy_branch'] ?? 'main') ?></code> is what deploys it.
    </p>
  </form>
</div>

<h2>Releases</h2>
<div class="card">
  <?php if ($releases === []): ?>
    <p class="empty">Nothing deployed yet.</p>
  <?php else: ?>
  <table>
    <tr><th>Release</th><th>Commit</th><th>Trigger</th><th>State</th><th>When</th></tr>
    <?php foreach ($releases as $release): ?>
    <tr>
      <td class="mono"><?= View::e($release['release_id'] ?? '—') ?></td>
      <td class="mono"><?= View::e(substr((string) $release['commit_sha'], 0, 8)) ?></td>
      <td><?= View::e($release['trigger_source']) ?></td>
      <td><span class="pill <?= View::e($release['state']) ?>"><?= View::e($release['state']) ?></span></td>
      <td class="muted"><?= View::e($release['created_at']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
