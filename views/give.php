<?php
declare(strict_types=1);
$metaTitle = 'Give';
$s = settings();
?>

<section class="hero" style="min-height:70vh;">
  <div class="hero-content" style="padding-top:110px;">
    <span class="eyebrow">Generosity</span>
    <h1>Give Online</h1>
    <p class="scripture">"Each of you should give what you have decided in your heart to give, not reluctantly or under compulsion, for God loves a cheerful giver." — 2 Corinthians 9:7</p>
    <div class="hero-actions">
      <?php if ($s['giving_url'] ?? null): ?>
        <a href="<?= e($s['giving_url']) ?>" target="_blank" rel="noopener" class="btn btn-gold">Give Now ↗</a>
      <?php else: ?>
        <a href="/contact" class="btn btn-gold">Contact Us to Give</a>
      <?php endif; ?>
      <a href="/about" class="btn btn-ghost">Learn About Us</a>
    </div>
  </div>
</section>

<section class="section reveal">
  <div class="container">
    <div class="grid grid-3">
      <div class="glass-card" style="padding:26px;">
        <h3 style="font-size:16px;">Tithes &amp; Offerings</h3>
        <p style="color:var(--ink-dim); font-size:13.5px;">Support the ongoing life and ministry of our church family.</p>
      </div>
      <div class="glass-card" style="padding:26px;">
        <h3 style="font-size:16px;">Missions</h3>
        <p style="color:var(--ink-dim); font-size:13.5px;">Help take the message beyond our walls, near and far.</p>
      </div>
      <div class="glass-card" style="padding:26px;">
        <h3 style="font-size:16px;">Building Fund</h3>
        <p style="color:var(--ink-dim); font-size:13.5px;">Invest in spaces where lives are changed for generations.</p>
      </div>
    </div>
  </div>
</section>
