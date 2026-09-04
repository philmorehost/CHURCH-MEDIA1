<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Install — Church Media Management System</title>
<style>
  :root{
    --bg-0:#0b0a14; --bg-1:#141227; --card:#181632cc; --border:#2c2850;
    --gold:#e8b95f; --gold-soft:#f3d38f; --ink:#f1eefc; --ink-dim:#a9a4c9;
    --danger:#ff6b6b; --success:#5fe0a4;
  }
  *{box-sizing:border-box;}
  body{
    margin:0; min-height:100vh; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    color:var(--ink);
    background:
      radial-gradient(ellipse 80% 60% at 20% -10%, #3a2a6b55, transparent),
      radial-gradient(ellipse 60% 50% at 100% 0%, #6b3a5a44, transparent),
      linear-gradient(180deg,var(--bg-0),var(--bg-1));
    display:flex; align-items:center; justify-content:center; padding:32px 16px;
  }
  .wrap{width:100%; max-width:640px;}
  .brand{text-align:center; margin-bottom:28px;}
  .brand .mark{
    width:56px; height:56px; margin:0 auto 12px; border-radius:16px;
    background:linear-gradient(135deg,var(--gold),#c98a3d);
    display:flex; align-items:center; justify-content:center;
    font-family:Georgia,serif; font-weight:700; font-size:26px; color:#1a1530;
    box-shadow:0 8px 30px #e8b95f33;
  }
  .brand h1{font-size:20px; margin:0; font-weight:600; letter-spacing:.02em;}
  .brand p{color:var(--ink-dim); font-size:13px; margin:4px 0 0;}
  .steps{display:flex; gap:8px; margin-bottom:24px;}
  .steps .dot{flex:1; text-align:center;}
  .steps .bar{height:4px; border-radius:4px; background:#2c2850; margin-bottom:8px; overflow:hidden;}
  .steps .bar.done .fill, .steps .bar.active .fill{height:100%; background:linear-gradient(90deg,var(--gold-soft),var(--gold)); width:100%;}
  .steps .bar .fill{width:0;}
  .steps .label{font-size:11px; color:var(--ink-dim);}
  .steps .active .label{color:var(--gold-soft); font-weight:600;}
  .card{
    background:var(--card); border:1px solid var(--border); border-radius:20px;
    padding:32px; backdrop-filter:blur(20px); box-shadow:0 20px 60px #00000055;
  }
  .card h2{margin:0 0 6px; font-size:22px; font-weight:600;}
  .card .sub{color:var(--ink-dim); font-size:14px; margin:0 0 24px;}
  label{display:block; font-size:13px; color:var(--ink-dim); margin:0 0 6px; font-weight:500;}
  input[type=text],input[type=password],input[type=email],input[type=number],select,textarea{
    width:100%; padding:11px 14px; border-radius:10px; border:1px solid var(--border);
    background:#0f0d1f; color:var(--ink); font-size:14px; margin-bottom:16px; font-family:inherit;
  }
  input:focus,select:focus,textarea:focus{outline:none; border-color:var(--gold);}
  .row{display:grid; grid-template-columns:1fr 1fr; gap:14px;}
  .btn{
    display:inline-flex; align-items:center; justify-content:center; gap:8px;
    background:linear-gradient(135deg,var(--gold-soft),var(--gold)); color:#1a1530;
    border:none; padding:13px 22px; border-radius:12px; font-weight:700; font-size:14px;
    cursor:pointer; width:100%; margin-top:4px; text-decoration:none;
  }
  .btn:hover{filter:brightness(1.05);}
  .btn.secondary{background:transparent; border:1px solid var(--border); color:var(--ink);}
  .check-list{list-style:none; padding:0; margin:0 0 20px;}
  .check-list li{
    display:flex; align-items:center; justify-content:space-between; padding:10px 14px;
    background:#0f0d1f; border:1px solid var(--border); border-radius:10px; margin-bottom:8px; font-size:13px;
  }
  .badge{font-size:11px; font-weight:700; padding:3px 9px; border-radius:20px;}
  .badge.ok{background:#5fe0a422; color:var(--success);}
  .badge.fail{background:#ff6b6b22; color:var(--danger);}
  .alert{padding:12px 14px; border-radius:10px; font-size:13px; margin-bottom:18px;}
  .alert.error{background:#ff6b6b18; border:1px solid #ff6b6b44; color:#ffb3b3;}
  .alert.success{background:#5fe0a418; border:1px solid #5fe0a444; color:#b6f5d8;}
  .hint{font-size:12px; color:var(--ink-dim); margin-top:-10px; margin-bottom:16px;}
  a{color:var(--gold-soft);}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <div class="mark">C</div>
    <h1>Church Media Management System</h1>
    <p>Installation Wizard</p>
  </div>
  <div class="steps">
    <?php foreach ($steps as $num => $meta): ?>
      <div class="dot">
        <div class="bar <?= $num < $step ? 'done' : ($num === $step ? 'active' : '') ?>"><div class="fill"></div></div>
        <div class="label <?= $num === $step ? 'active' : '' ?>"><?= e((string) $num) ?>. <?= e($meta['title']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="card">
