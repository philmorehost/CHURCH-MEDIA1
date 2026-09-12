<?php
declare(strict_types=1);

/**
 * WhatsApp → Settings (super admin only).
 *
 * Credentials and the two switches. The secrets follow the same rule as the SMS token: stored
 * encrypted, rendered only through a masked accessor, and a blank field means "leave the stored
 * value alone" — because a form that redisplayed the token would be one screenshot away from a
 * leaked credential, and one that cleared it on save would break sending for no visible reason.
 */

$user = $waContext['user'];
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        settingSave([
            'wa_enabled' => isset($_POST['wa_enabled']) ? 1 : 0,
            'wa_phone_number_id' => trim((string) ($_POST['wa_phone_number_id'] ?? '')),
            'wa_business_account_id' => trim((string) ($_POST['wa_business_account_id'] ?? '')),
            'wa_display_name' => trim((string) ($_POST['wa_display_name'] ?? '')),
            'wa_default_language' => trim((string) ($_POST['wa_default_language'] ?? 'en')) ?: 'en',
            'wa_verify_token' => trim((string) ($_POST['wa_verify_token'] ?? '')),
            'wa_log_retention_days' => max(1, min(3650, (int) ($_POST['wa_log_retention_days'] ?? 60))),
            'wa_unofficial_enabled' => isset($_POST['wa_unofficial_enabled']) ? 1 : 0,
            'wa_bridge_url' => trim((string) ($_POST['wa_bridge_url'] ?? '')) ?: 'http://127.0.0.1:8787',
        ]);

        // Blanks mean "keep what is stored". Only a typed value replaces a secret.
        $secrets = [
            'wa_access_token' => 'wa_access_token',
            'wa_app_secret' => 'wa_app_secret',
            'wa_bridge_token' => 'wa_bridge_token',
        ];
        $secretValues = [];
        foreach ($secrets as $field => $settingKey) {
            $typed = trim((string) ($_POST[$field] ?? ''));
            if ($typed !== '') {
                $secretValues[$settingKey] = encryptSecret($typed);
            }
        }
        if ($secretValues) {
            settingSave($secretValues);
        }

        flash('success', 'WhatsApp settings saved.');
        redirect('/admin/whatsapp?tab=settings');
    }

    if ($action === 'clear_secret') {
        // A separate, explicit act. Clearing a secret by accidentally saving an empty field would
        // silently stop all sending.
        $which = (string) ($_POST['which'] ?? '');
        $allowed = ['wa_access_token', 'wa_app_secret', 'wa_bridge_token'];
        if (in_array($which, $allowed, true)) {
            settingSave([$which => '']);
            flash('success', 'That credential was cleared.');
        }
        redirect('/admin/whatsapp?tab=settings');
    }
}

$tokenSet = WhatsApp::accessToken() !== '';
$secretSet = WhatsApp::appSecret() !== '';
$verifySet = WhatsApp::verifyToken() !== '';
$bridgeTokenSet = WhatsApp::bridgeToken() !== '';
?>

<div class="card" style="max-width:760px;">
  <h2 style="margin-top:0;">Connection</h2>
  <p style="color:var(--ink-dim);font-size:13.5px;margin-top:-4px;">
    These come from your Meta app: <strong>Business Settings → Accounts → WhatsApp accounts</strong>
    for the IDs, and <strong>App Settings → Basic</strong> for the app secret. The access token is a
    temporary one unless you create a System User token, which is what you want for a server that
    runs unattended.
  </p>

  <form method="post" action="/admin/whatsapp?tab=settings">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">

    <div class="checkbox-row">
      <input type="checkbox" id="wa_enabled" name="wa_enabled" <?= WhatsApp::enabled() ? 'checked' : '' ?>>
      <label for="wa_enabled" style="margin:0;">WhatsApp channel enabled</label>
    </div>
    <p class="hint" style="margin-top:-6px;margin-bottom:16px;font-size:12.5px;color:var(--ink-dim);">
      Leave this off until the number is verified and the webhook is connected. Sending from a
      number that is not approved is worse than not sending.
    </p>

    <div class="row two">
      <div>
        <label for="wa_phone_number_id">Phone number ID</label>
        <input type="text" id="wa_phone_number_id" name="wa_phone_number_id" maxlength="40"
               value="<?= e(WhatsApp::phoneNumberId()) ?>" placeholder="123456789012345">
      </div>
      <div>
        <label for="wa_business_account_id">Business account ID</label>
        <input type="text" id="wa_business_account_id" name="wa_business_account_id" maxlength="40"
               value="<?= e(WhatsApp::businessAccountId()) ?>" placeholder="098765432109876">
      </div>
    </div>

    <label for="wa_access_token">
      Access token
      <?= $tokenSet ? '<span class="badge ok">configured</span>' : '<span class="badge fail">not set</span>' ?>
    </label>
    <input type="password" id="wa_access_token" name="wa_access_token" autocomplete="new-password"
           placeholder="<?= $tokenSet ? e(WhatsApp::maskedToken()) : 'Paste the token from your Meta app' ?>">
    <?php if ($tokenSet): ?>
      <p class="hint" style="margin-top:-6px;font-size:12.5px;color:var(--ink-dim);">
        Leave blank to keep the stored token.
      </p>
    <?php endif; ?>

    <label for="wa_app_secret">
      App secret
      <?= $secretSet ? '<span class="badge ok">configured</span>' : '<span class="badge fail">not set</span>' ?>
    </label>
    <input type="password" id="wa_app_secret" name="wa_app_secret" autocomplete="new-password"
           placeholder="<?= $secretSet ? e(WhatsApp::maskedAppSecret()) : 'App Settings → Basic → App Secret' ?>">
    <p class="hint" style="margin-top:-6px;font-size:12.5px;color:var(--ink-dim);">
      Meta signs every incoming webhook with this. Without it, messages are refused rather than
      accepted unverified — a webhook that accepts anything is a way for a stranger to write into
      your inbox.
    </p>

    <label for="wa_verify_token">
      Webhook verify token
      <?= $verifySet ? '<span class="badge ok">configured</span>' : '<span class="badge fail">not set</span>' ?>
    </label>
    <input type="text" id="wa_verify_token" name="wa_verify_token" maxlength="120"
           value="<?= e(WhatsApp::verifyToken()) ?>" placeholder="Any phrase you choose">
    <p class="hint" style="margin-top:-6px;font-size:12.5px;color:var(--ink-dim);">
      You invent this and type the same value into Meta. It proves the webhook address belongs to
      you when Meta first calls it.
    </p>

    <div class="row two">
      <div>
        <label for="wa_default_language">Default template language</label>
        <input type="text" id="wa_default_language" name="wa_default_language" maxlength="12"
               value="<?= e(WhatsApp::defaultLanguage()) ?>" placeholder="en">
      </div>
      <div>
        <label for="wa_display_name">Display name</label>
        <input type="text" id="wa_display_name" name="wa_display_name" maxlength="60"
               value="<?= e(WhatsApp::displayName()) ?>" placeholder="RCCG LP63 Yaya">
      </div>
    </div>

    <label for="wa_log_retention_days">Keep message history for (days)</label>
    <input type="number" id="wa_log_retention_days" name="wa_log_retention_days" min="1" max="3650"
           value="<?= (int) setting('wa_log_retention_days', 60) ?>">

    <div class="btn-row">
      <button class="btn" type="submit">Save settings</button>
    </div>
  </form>
</div>

<div class="card" style="max-width:760px;">
  <h2 style="margin-top:0;">Webhook</h2>
  <table>
    <tr>
      <th style="width:34%;">Callback URL</th>
      <td><code><?= e(baseUrl('/api/wa-webhook')) ?></code></td>
    </tr>
    <tr>
      <th>Verify token</th>
      <td>
        <?= $verifySet
            ? 'the token set above'
            : '<span class="badge fail">set one first</span>' ?>
      </td>
    </tr>
    <tr>
      <th>Subscribe to</th>
      <td><code>messages</code></td>
    </tr>
  </table>
  <p class="hint" style="font-size:12.5px;color:var(--ink-dim);">
    Meta → WhatsApp → Configuration → Webhook → Edit. It will call the URL immediately with the
    verify token; if the token does not match, the subscription is refused and nothing will arrive.
  </p>
</div>

<div class="card" style="max-width:760px;">
  <h2 style="margin-top:0;">Unofficial bridge</h2>
  <div class="alert error" style="font-size:13px;">
    This connects a separate, disposable number through an unofficial library. It is for reading
    WhatsApp group participants, which the official API cannot do at all. It <strong>violates
    WhatsApp's terms</strong>, the number can be banned without appeal, and it must never be used
    for member messaging. Leave it off unless you have read the guide.
  </div>

  <form method="post" action="/admin/whatsapp?tab=settings">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">

    <div class="checkbox-row">
      <input type="checkbox" id="wa_unofficial_enabled" name="wa_unofficial_enabled"
             <?= WhatsApp::bridgeEnabled() ? 'checked' : '' ?>>
      <label for="wa_unofficial_enabled" style="margin:0;">Bridge enabled</label>
    </div>

    <div class="row two">
      <div>
        <label for="wa_bridge_url">Bridge address</label>
        <input type="text" id="wa_bridge_url" name="wa_bridge_url" maxlength="255"
               value="<?= e(WhatsApp::bridgeUrl()) ?>">
        <p class="hint" style="margin-top:6px;font-size:12px;color:var(--ink-dim);">
          Keep it on <code>127.0.0.1</code>. It must never be reachable from the internet.
        </p>
      </div>
      <div>
        <label for="wa_bridge_token">Bridge token <?= $bridgeTokenSet ? '<span class="badge ok">configured</span>' : '' ?></label>
        <input type="password" id="wa_bridge_token" name="wa_bridge_token" autocomplete="new-password"
               placeholder="<?= $bridgeTokenSet ? 'stored — leave blank to keep' : 'shared secret for the sidecar' ?>">
      </div>
    </div>

    <div class="btn-row">
      <button class="btn" type="submit">Save</button>
    </div>
  </form>
</div>

<?php if ($tokenSet || $secretSet || $bridgeTokenSet): ?>
  <div class="card" style="max-width:760px;">
    <h2 style="margin-top:0;">Clear a credential</h2>
    <p style="color:var(--ink-dim);font-size:13.5px;margin-top:-4px;">
      Clearing is separate from saving so that an empty field cannot wipe a working credential by
      accident. Nothing is sent until it is replaced.
    </p>
    <div class="btn-row" style="flex-wrap:wrap;">
      <?php
        $clearable = [
            'wa_access_token' => ['label' => 'access token', 'set' => $tokenSet],
            'wa_app_secret' => ['label' => 'app secret', 'set' => $secretSet],
            'wa_bridge_token' => ['label' => 'bridge token', 'set' => $bridgeTokenSet],
        ];
      ?>
      <?php foreach ($clearable as $key => $meta): ?>
        <?php if (!$meta['set']) { continue; } ?>
        <form method="post" action="/admin/whatsapp?tab=settings" style="display:inline;"
              onsubmit="return confirm('Clear the stored <?= e($meta['label']) ?>? Sending will stop until a new one is saved.');">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="clear_secret">
          <input type="hidden" name="which" value="<?= e($key) ?>">
          <button class="btn danger sm" type="submit">Clear <?= e($meta['label']) ?></button>
        </form>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
