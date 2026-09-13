<?php use AIPanel\Http\View; $title = $site['domain'] . ' — aipanel'; require __DIR__ . '/../layout/header.php'; ?>

<h1><?= View::e($site['domain']) ?></h1>
<p class="sub">
  <span class="pill <?= View::e($site['status']) ?>"><?= View::e($site['status']) ?></span>
  &nbsp;<span class="mono"><?= View::e($site['repo'] ?? 'no repo') ?></span>
</p>

<h2>Environments</h2>
<div class="grid">
  <div class="card">
    <strong>Sandbox</strong>
    <span class="pill <?= View::e($sandbox['status'] ?? 'provisioning') ?>" style="float:right">
      <?= View::e($sandbox['status'] ?? 'none') ?>
    </span>
    <p class="mono" style="margin:6px 0">
      <a href="http://<?= View::e($sandbox['domain'] ?? '') ?>" rel="noopener">
        <?= View::e($sandbox['domain'] ?? 'not provisioned') ?>
      </a>
    </p>
    <p class="muted" style="font-size:12px">
      tracks <code><?= View::e($sandbox['deploy_branch'] ?? 'sandbox') ?></code> ·
      live <span class="mono"><?= View::e(substr((string) ($sandbox['live_commit'] ?? '—'), 0, 8)) ?></span>
    </p>
    <form method="post" action="/sites/<?= (int) ($sandbox['id'] ?? 0) ?>/deploy" class="inline">
      <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">
      <button type="submit"<?= $sandbox === null ? ' disabled' : '' ?>>Deploy sandbox</button>
    </form>
  </div>

  <div class="card">
    <strong>Production</strong>
    <span class="pill <?= View::e($site['status']) ?>" style="float:right"><?= View::e($site['status']) ?></span>
    <p class="mono" style="margin:6px 0">
      <a href="http://<?= View::e($site['domain']) ?>" rel="noopener"><?= View::e($site['domain']) ?></a>
    </p>
    <p class="muted" style="font-size:12px">
      tracks <code><?= View::e($site['deploy_branch'] ?? 'main') ?></code> ·
      live <span class="mono"><?= View::e(substr((string) ($site['live_commit'] ?? '—'), 0, 8)) ?></span>
    </p>
    <form method="post" action="/sites/<?= (int) $site['id'] ?>/deploy" class="inline">
      <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">
      <button type="submit">Redeploy</button>
    </form>
    <form method="post" action="/sites/<?= (int) $site['id'] ?>/rollback" class="inline">
      <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">
      <button type="submit">Roll back</button>
    </form>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0">Make it live</h2>
  <?php if (!empty($pending['ahead'])): ?>
    <p>
      The sandbox is running
      <span class="mono"><?= View::e(substr((string) $pending['sandbox_commit'], 0, 8)) ?></span>,
      production is on
      <span class="mono"><?= View::e(substr((string) ($pending['production_commit'] ?? 'nothing'), 0, 8)) ?></span>.
    </p>
    <p class="muted" style="font-size:12px">
      Promoting merges <code><?= View::e($sandbox['deploy_branch'] ?? 'sandbox') ?></code> into
      <code><?= View::e($site['deploy_branch'] ?? 'main') ?></code>. Production then deploys
      through the same webhook as any other push.
    </p>
    <form method="post" action="/sites/<?= (int) $site['id'] ?>/promote">
      <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">
      <button class="primary" type="submit">Make it live</button>
    </form>
  <?php else: ?>
    <p class="muted">Production matches the sandbox — there is nothing to promote.</p>
  <?php endif; ?>
</div>

<div class="grid">

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

<div class="card">
  <h2 style="margin-top:0">Changing this website</h2>
  <p class="muted">
    aipanel does not edit your code. Connect the repository to Claude Code (or
    whatever you work in), push to
    <code><?= View::e($sandbox['deploy_branch'] ?? 'sandbox') ?></code>, and the
    sandbox rebuilds on its own. When it looks right, press
    <em>Make it live</em> above.
  </p>
  <p class="mono muted" style="font-size:12px">git clone <?= View::e('git@github.com:' . ($site['repo'] ?? '')) ?>.git</p>
</div>

<h2>Releases</h2>
<div class="card">
  <?php if ($releases === []): ?>
    <p class="empty">Nothing deployed yet.</p>
  <?php else: ?>
  <table>
    <tr><th>Environment</th><th>Release</th><th>Commit</th><th>Trigger</th><th>State</th><th>When</th></tr>
    <?php foreach ($releases as $release): ?>
    <tr>
      <td><?= View::e($release['environment'] ?? 'production') ?></td>
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
