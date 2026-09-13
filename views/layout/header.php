<?php use AIPanel\Http\View; ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($title ?? 'aipanel') ?></title>
<style>
:root { --bg:#0f1115; --panel:#171a21; --line:#242833; --text:#e6e8ee; --muted:#9aa3b2;
        --accent:#6ea8fe; --ok:#4ade80; --warn:#fbbf24; --bad:#f87171; }
* { box-sizing:border-box; }
body { margin:0; background:var(--bg); color:var(--text);
       font:14px/1.55 ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
a { color:var(--accent); text-decoration:none; } a:hover { text-decoration:underline; }
header.top { display:flex; align-items:center; gap:20px; padding:14px 24px;
             border-bottom:1px solid var(--line); background:var(--panel); }
header.top .brand { font-weight:600; letter-spacing:.3px; }
header.top nav { display:flex; gap:16px; margin-left:auto; align-items:center; }
main { max-width:1100px; margin:0 auto; padding:28px 24px 60px; }
h1 { font-size:20px; margin:0 0 4px; } h2 { font-size:15px; margin:28px 0 10px; color:var(--muted);
     text-transform:uppercase; letter-spacing:.08em; }
.sub { color:var(--muted); margin:0 0 24px; }
.card { background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:18px; margin-bottom:16px; }
table { width:100%; border-collapse:collapse; }
th { text-align:left; color:var(--muted); font-weight:500; font-size:12px;
     text-transform:uppercase; letter-spacing:.06em; padding:8px 10px; border-bottom:1px solid var(--line); }
td { padding:10px; border-bottom:1px solid var(--line); vertical-align:top; }
tr:last-child td { border-bottom:0; }
.pill { display:inline-block; padding:2px 9px; border-radius:999px; font-size:12px; border:1px solid var(--line); }
.pill.active, .pill.live, .pill.done, .pill.online { color:var(--ok); border-color:#1c5136; background:#12251c; }
.pill.provisioning, .pill.queued, .pill.running, .pill.review { color:var(--warn); border-color:#5a4415; background:#231c0c; }
.pill.failed, .pill.unreachable, .pill.suspended { color:var(--bad); border-color:#5c2626; background:#251414; }
code, .mono { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12.5px; }
button, .btn { background:#222835; color:var(--text); border:1px solid var(--line);
               border-radius:7px; padding:8px 14px; font-size:13px; cursor:pointer; }
button:hover, .btn:hover { border-color:var(--accent); text-decoration:none; }
button.primary { background:var(--accent); border-color:var(--accent); color:#0b1220; font-weight:600; }
input, select, textarea { background:#11141b; border:1px solid var(--line); color:var(--text);
                          border-radius:7px; padding:9px 11px; font-size:13px; width:100%; }
label { display:block; margin:12px 0 4px; color:var(--muted); font-size:12px; }
.grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; }
.muted { color:var(--muted); }
.empty { color:var(--muted); padding:20px 0; text-align:center; }
form.inline { display:inline; }
@media (max-width:640px) { main { padding:20px 14px; } header.top { padding:12px 14px; } }
</style>
</head>
<body>
<header class="top">
  <span class="brand">aipanel</span>
  <?php if (!empty($github_login)): ?>
  <nav>
    <a href="/">Dashboard</a>
    <a href="/sites">Sites</a>
    <span class="muted mono">@<?= View::e($github_login) ?></span>
    <form method="post" action="/logout" class="inline">
      <input type="hidden" name="_token" value="<?= View::e($csrf) ?>">
      <button type="submit">Sign out</button>
    </form>
  </nav>
  <?php endif; ?>
</header>
<main>
