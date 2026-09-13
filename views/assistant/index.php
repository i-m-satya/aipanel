<?php use AIPanel\Http\View; $title = 'Assistant — aipanel'; require __DIR__ . '/../layout/header.php'; ?>

<h1>Operations assistant</h1>
<p class="sub">Describe what you want. The assistant proposes a plan; nothing runs until you approve it.</p>

<div class="card">
  <label for="message">What do you need?</label>
  <textarea id="message" rows="3" placeholder="Move shop.example.com to PHP 8.4 and give it a database."></textarea>
  <p style="margin-top:12px"><button class="primary" id="send" type="button">Ask</button></p>
</div>

<div class="card" id="reply" hidden></div>

<?php if ($pending !== []): ?>
<h2>Plans awaiting approval</h2>
<?php foreach ($pending as $plan): ?>
  <div class="card">
    <p><?= View::e($plan['summary']) ?></p>
    <pre class="mono muted"><?= View::e(json_encode(json_decode((string) $plan['steps']), JSON_PRETTY_PRINT)) ?></pre>
    <form method="post" action="/assistant/plans/<?= View::e($plan['id']) ?>/approve" class="inline">
      <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">
      <button class="primary" type="submit">Approve and run</button>
    </form>
    <form method="post" action="/assistant/plans/<?= View::e($plan['id']) ?>/reject" class="inline">
      <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">
      <button type="submit">Discard</button>
    </form>
  </div>
<?php endforeach; ?>
<?php endif; ?>

<script>
document.getElementById('send').addEventListener('click', async () => {
    const box = document.getElementById('message');
    const out = document.getElementById('reply');
    if (!box.value.trim()) return;

    out.hidden = false;
    out.textContent = 'Thinking…';

    const response = await fetch('/assistant/chat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': '<?= View::e($csrf) ?>' },
        body: JSON.stringify({ message: box.value }),
    });

    const data = await response.json();
    out.textContent = data.reply || data.error || 'No response.';

    // A plan means there is something to approve: reload so it renders with
    // its approve/discard controls.
    if (data.plan_id) window.location.reload();
});
</script>

<?php require __DIR__ . '/../layout/footer.php'; ?>
