<?php use AIPanel\Http\View; $title = 'Error — aipanel'; require __DIR__ . '/layout/header.php'; ?>
<div class="card" style="max-width:560px;margin:40px auto">
  <h1>That didn't work</h1>
  <p class="sub"><?= View::e($message ?? 'Unknown error.') ?></p>
  <p><a class="btn" href="/">Back to the dashboard</a></p>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
