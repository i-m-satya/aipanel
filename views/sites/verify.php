<?php use AIPanel\Http\View; $title = 'Verify your domain — aipanel'; require __DIR__ . '/../layout/header.php'; ?>

<h1>Prove you control <?= View::e($challenge['domain']) ?></h1>
<p class="sub">
  We ask for this so nobody can point a hostname they do not own at this server,
  or obtain a certificate for it.
</p>

<div class="card">
  <h2 style="margin-top:0">Add this DNS record</h2>
  <table>
    <tr><th>Type</th><td class="mono">TXT</td></tr>
    <tr><th>Name</th><td class="mono"><?= View::e($challenge['record']) ?></td></tr>
    <tr><th>Value</th><td class="mono" style="word-break:break-all"><?= View::e($challenge['value']) ?></td></tr>
  </table>

  <p class="muted" style="font-size:12px;margin-top:14px">
    Most providers propagate within a few minutes. Verifying
    <code><?= View::e($challenge['domain']) ?></code> also covers
    <code>sandbox.<?= View::e($challenge['domain']) ?></code>, so you only do this once.
  </p>

  <form method="post" action="/domains/verify">
    <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">
    <input type="hidden" name="domain" value="<?= View::e($challenge['domain']) ?>">
    <button class="primary" type="submit">I've added it — check now</button>
  </form>
</div>

<p><a href="/sites">Back to sites</a></p>

<?php require __DIR__ . '/../layout/footer.php'; ?>
