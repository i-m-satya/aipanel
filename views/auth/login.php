<?php use AIPanel\Http\View; $title = 'Sign in — aipanel'; require __DIR__ . '/../layout/header.php'; ?>

<div class="card" style="max-width:420px;margin:60px auto;text-align:center">
  <h1>aipanel</h1>
  <?php if (!empty($unclaimed)): ?>
    <p class="sub">
      This installation has no admin yet.<br>
      <strong>The first GitHub account to sign in becomes the administrator.</strong>
    </p>
  <?php else: ?>
    <p class="sub">Every site is a GitHub repo. Sign in with the account that owns them.</p>
  <?php endif; ?>

  <?php if (!empty($reason)): ?>
    <p class="pill failed" style="display:block;padding:10px">
      <?= View::e(match ($reason) {
          'revoked'  => 'Your access was revoked. Sign in again.',
          'denied'   => 'GitHub authorisation was cancelled.',
          'disabled' => 'This account is disabled. Contact your account owner.',
          'error'    => 'Sign-in failed: ' . ($detail ?? 'unknown error'),
          'not_invited' => '@' . ($detail ?? 'that account') . ' has not been invited to this panel. Ask its administrator to invite you.',
          default    => 'Please sign in to continue.',
      }) ?>
    </p>
  <?php endif; ?>

  <p style="margin-top:24px">
    <a class="btn" href="/auth/github" style="display:inline-block;padding:11px 20px">
      Continue with GitHub
    </a>
  </p>
  <p class="muted" style="font-size:12px;margin-top:20px">
    aipanel has no passwords. Access is granted and revoked entirely in GitHub.
  </p>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
