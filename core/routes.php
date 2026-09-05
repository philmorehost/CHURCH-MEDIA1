<?php
declare(strict_types=1);

/**
 * Explicit public-site routes (pretty slugs). /admin/* and /api/* need no
 * entries here — Router falls back to a flat-file dispatch for those.
 */

/** @var Router $router */

$router->get('/', function () {
    render('home');
});

$router->get('/app-features', function () {
    render('app-features', [
        'metaTitle' => 'App Features',
        'metaDescription' => 'Explore everything the ' . setting('site_title') . ' app offers — reels, the offline Bible, events, sermons, prayer, and push notifications.',
    ]);
});

$router->get('/app', function () {
    render('app', [
        'metaTitle' => 'Get the App',
        'metaDescription' => 'Download ' . e(setting('site_title')) . ' on Google Play.',
        'metaRobots' => 'noindex, nofollow',
    ]);
});

$router->get('/feed', function () {
    render('feed');
});

$router->get('/media', function () {
    render('media');
});

$router->get('/unit/{slug}', function (array $params) {
    render('unit', ['slug' => $params['slug']]);
});

$router->get('/units', function () {
    render('units');
});

// Publisher Ad Manager Portal
$router->get('/ad-manager', function () {
    render('ad-manager');
});

$router->post('/ad-manager', function () {
    $token = trim((string) ($_GET['token'] ?? ''));
    if ($token === '') {
        http_response_code(403);
        exit('Access denied.');
    }

    $pdo = Database::getInstance()->getConnection();
    $stmt = $pdo->prepare('SELECT id FROM ad_publishers WHERE token = ? LIMIT 1');
    $stmt->execute([$token]);
    $pub = $stmt->fetch();

    if (!$pub) {
        http_response_code(403);
        exit('Access denied.');
    }

    $title = trim((string) ($_POST['title'] ?? ''));
    $destUrl = trim((string) ($_POST['destination_url'] ?? ''));
    $targetPlatform = in_array($_POST['target_platform'] ?? '', ['web', 'app', 'both'], true) ? $_POST['target_platform'] : 'both';
    $durationDays = (int) ($_POST['duration_days'] ?? 7);
    $mediaType = in_array($_POST['media_type'] ?? '', ['image', 'video'], true) ? $_POST['media_type'] : 'image';

    if ($title === '') {
        flash('pub_error', 'Please enter an Ad title.');
        redirect('/ad-manager?token=' . rawurlencode($token));
    }

    $fileUpload = $_FILES['media_file'] ?? null;
    if (!$fileUpload || empty($fileUpload['tmp_name']) || ($fileUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('pub_error', 'Please upload a media file for your advert.');
        redirect('/ad-manager?token=' . rawurlencode($token));
    }

    $filePath = null;
    $thumbPath = null;

    if ($mediaType === 'image') {
        $processed = MediaProcessor::processAdImage($fileUpload['tmp_name'], UPLOADS_PATH . '/ads');
        if (!$processed) {
            flash('pub_error', 'Failed to process the uploaded image.');
            redirect('/ad-manager?token=' . rawurlencode($token));
        }
        $filePath = 'ads/' . $processed;
    } else {
        $res = MediaProcessor::processAdVideo($fileUpload['tmp_name'], UPLOADS_PATH . '/ads/reels', UPLOADS_PATH . '/ads/thumbs');
        if (empty($res['file'])) {
            flash('pub_error', 'Failed to process the uploaded video.');
            redirect('/ad-manager?token=' . rawurlencode($token));
        }
        $filePath = 'ads/reels/' . $res['file'];
        if (!empty($res['thumbnail'])) {
            $thumbPath = 'ads/thumbs/' . $res['thumbnail'];
        }
    }

    $stmt = $pdo->prepare('SELECT * FROM ad_durations WHERE days = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$durationDays]);
    $dur = $stmt->fetch();
    $price = $dur ? (float) $dur['price'] : 0.00;
    $isFree = $dur ? (int) $dur['is_free'] : 0;
    $displayFreq = $dur ? (string) ($dur['display_frequency'] ?? '5_min') : '5_min';
    if ($isFree) { $displayFreq = 'once_daily'; }

    $stmt = $pdo->prepare('INSERT INTO ads (publisher_id, title, media_type, file_path, thumbnail_path, destination_url, target_platform, duration_days, price, is_free, display_frequency, payment_status, payment_method, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")');
    $stmt->execute([(int) $pub['id'], $title, $mediaType, $filePath, $thumbPath, $destUrl ?: null, $targetPlatform, $durationDays, $price, $isFree, $displayFreq, $isFree ? 'paid' : 'unpaid', $isFree ? 'free' : 'online']);

    flash('pub_success', 'Your new advertisement has been submitted and is pending admin approval.');
    redirect('/ad-manager?token=' . rawurlencode($token));
});

// Payhub Callback & Webhook Verification Endpoint
$router->get('/payment/payhub/callback', function () {
    $pdo = Database::getInstance()->getConnection();
    $reference = trim((string) ($_GET['ref'] ?? ($_GET['reference'] ?? '')));

    if ($reference === '') {
        flash('advertise_error', 'Invalid payment reference.');
        redirect('/advertise');
    }

    $secKey = (string) setting('payhub_secret_key');
    if ($secKey === '') {
        flash('advertise_error', 'Payhub configuration missing.');
        redirect('/advertise');
    }

    // Verify transaction with Payhub API
    $url = 'https://merchant.payhub.com.ng/api/transaction/verify/' . urlencode($reference);
    $paid = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $secKey],
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        $data = json_decode((string) $res, true);
        if (!empty($data['paid']) || (!empty($data['data']['status']) && $data['data']['status'] === 'success')) {
            $paid = true;
        }
    }

    if ($paid) {
        $stmt = $pdo->prepare('UPDATE ads SET payment_status = "paid" WHERE payment_reference = ?');
        $stmt->execute([$reference]);

        $stmt = $pdo->prepare('UPDATE ad_payments SET status = "success" WHERE reference = ?');
        $stmt->execute([$reference]);

        flash('advertise_sent', '1');
        redirect('/advertise?sent=1');
    } else {
        flash('advertise_error', 'Payment verification failed or payment was not successful.');
        redirect('/advertise');
    }
});

$router->post('/payment/payhub/webhook', function () {
    $pdo = Database::getInstance()->getConnection();
    $body = (string) file_get_contents('php://input');
    $sig = $_SERVER['HTTP_X_PAYHUB_SIGNATURE'] ?? '';
    $secKey = (string) setting('payhub_secret_key');

    if ($secKey !== '') {
        if ($sig === '' || !hash_equals(hash_hmac('sha256', $body, $secKey), $sig)) {
            http_response_code(401);
            exit('Invalid signature');
        }
    }

    $payload = json_decode($body, true);
    if (($payload['event'] ?? '') === 'charge.success' && !empty($payload['data']['reference'])) {
        $ref = $payload['data']['reference'];
        $pdo->prepare('UPDATE ads SET payment_status = "paid" WHERE payment_reference = ?')->execute([$ref]);
        $pdo->prepare('UPDATE ad_payments SET status = "success" WHERE reference = ?')->execute([$ref]);
    }

    http_response_code(200);
    echo json_encode(['status' => 'success']);
    exit;
});

// Public Ad placement page and submission handler
$router->get('/advertise', function () {
    render('advertise', [
        'metaTitle' => 'Place an Advert',
        'metaDescription' => 'Promote your brand, business or ministry on our website and Mobile App with targeted vertical video and image ads.',
    ]);
});

$router->post('/advertise', function () {
    $pdo = Database::getInstance()->getConnection();

    // Honeypot check
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        flash('advertise_sent', '1');
        redirect('/advertise?sent=1');
    }

    if (!RateLimiter::attempt('advertise_submit', clientIp(), 5, 600)) {
        keepFormOld($_POST);
        flash('advertise_error', 'Too many attempts — please wait a few minutes before trying again.');
        redirect('/advertise');
    }

    $pubName = trim((string) ($_POST['publisher_name'] ?? ''));
    $pubEmail = trim((string) ($_POST['publisher_email'] ?? ''));
    $pubPhone = trim((string) ($_POST['publisher_phone'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $destUrl = trim((string) ($_POST['destination_url'] ?? ''));
    $targetPlatform = in_array($_POST['target_platform'] ?? '', ['web', 'app', 'both'], true) ? $_POST['target_platform'] : 'both';
    $durationId = (int) ($_POST['duration_id'] ?? 0);
    $mediaType = in_array($_POST['media_type'] ?? '', ['image', 'video'], true) ? $_POST['media_type'] : 'image';
    $paymentMethod = in_array($_POST['payment_method'] ?? '', ['online', 'manual', 'free'], true) ? $_POST['payment_method'] : 'free';

    $stmt = $pdo->prepare('SELECT * FROM ad_durations WHERE id = ? AND is_active = 1');
    $stmt->execute([$durationId]);
    $dur = $stmt->fetch();

    if (!$dur) {
        keepFormOld($_POST);
        flash('advertise_error', 'Please select a valid ad duration package.');
        redirect('/advertise');
    }

    $durationDays = (int) $dur['days'];
    $price = (float) $dur['price'];
    $isFree = (bool) $dur['is_free'];
    $displayFreq = (string) ($dur['display_frequency'] ?? '5_min');

    if ($isFree) {
        $paymentMethod = 'free';
        $paymentStatus = 'paid';
        $displayFreq = 'once_daily';
    } else {
        $paymentStatus = 'unpaid';
    }

    $errors = [];
    if ($pubName === '' || !filter_var($pubEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter your name and a valid email address.';
    }
    if ($title === '') {
        $errors[] = 'Please enter an Ad title.';
    }
    if ($destUrl !== '' && !filter_var($destUrl, FILTER_VALIDATE_URL)) {
        $errors[] = 'Please enter a valid website destination URL (including http:// or https://).';
    }

    $fileUpload = $_FILES['media_file'] ?? null;
    if (!$fileUpload || empty($fileUpload['tmp_name']) || ($fileUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'Please upload an image or video for your advert.';
    }

    $proofPath = null;
    if (!$isFree && $paymentMethod === 'manual') {
        $proofUpload = $_FILES['payment_proof'] ?? null;
        if (!$proofUpload || empty($proofUpload['tmp_name']) || ($proofUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Please upload your bank transfer payment receipt / proof.';
        } else {
            $proofName = MediaProcessor::processImage($proofUpload['tmp_name'], UPLOADS_PATH . '/ads/proofs');
            if ($proofName) {
                $proofPath = 'ads/proofs/' . $proofName;
                $paymentStatus = 'pending_review';
            } else {
                $errors[] = 'Failed to process payment proof image.';
            }
        }
    }

    if ($errors) {
        keepFormOld($_POST);
        flash('advertise_error', implode(' ', $errors));
        redirect('/advertise');
    }

    // Find or create publisher
    $stmt = $pdo->prepare('SELECT id, token FROM ad_publishers WHERE email = ? LIMIT 1');
    $stmt->execute([$pubEmail]);
    $pub = $stmt->fetch();
    if ($pub) {
        $publisherId = (int) $pub['id'];
        $pubToken = $pub['token'];
    } else {
        $pubToken = bin2hex(random_bytes(24));
        $stmt = $pdo->prepare('INSERT INTO ad_publishers (name, email, phone, token) VALUES (?, ?, ?, ?)');
        $stmt->execute([$pubName, $pubEmail, $pubPhone ?: null, $pubToken]);
        $publisherId = (int) $pdo->lastInsertId();
    }

    // Process media into 9:16 vertical aspect ratio
    $filePath = null;
    $thumbPath = null;

    if ($mediaType === 'image') {
        $processed = MediaProcessor::processAdImage($fileUpload['tmp_name'], UPLOADS_PATH . '/ads');
        if (!$processed) {
            flash('advertise_error', 'Failed to process the uploaded image. Please ensure it is a valid JPG, PNG, or WebP image.');
            keepFormOld($_POST);
            redirect('/advertise');
        }
        $filePath = 'ads/' . $processed;
    } else {
        $res = MediaProcessor::processAdVideo($fileUpload['tmp_name'], UPLOADS_PATH . '/ads/reels', UPLOADS_PATH . '/ads/thumbs');
        if (empty($res['file'])) {
            flash('advertise_error', 'Failed to process the uploaded video. Please ensure it is a valid MP4 or MOV video.');
            keepFormOld($_POST);
            redirect('/advertise');
        }
        $filePath = 'ads/reels/' . $res['file'];
        if (!empty($res['thumbnail'])) {
            $thumbPath = 'ads/thumbs/' . $res['thumbnail'];
        }
    }

    $reference = 'PH_AD_' . time() . '_' . mt_rand(1000, 9999);

    $stmt = $pdo->prepare('INSERT INTO ads (publisher_id, title, media_type, file_path, thumbnail_path, destination_url, target_platform, duration_days, price, is_free, display_frequency, payment_status, payment_method, payment_proof_path, payment_reference, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")');
    $stmt->execute([
        $publisherId, $title, $mediaType, $filePath, $thumbPath, $destUrl ?: null, $targetPlatform,
        $durationDays, $price, $isFree ? 1 : 0, $displayFreq, $paymentStatus, $paymentMethod, $proofPath, $reference
    ]);
    $adId = (int) $pdo->lastInsertId();

    // Log payment record if applicable
    if (!$isFree) {
        $stmt = $pdo->prepare('INSERT INTO ad_payments (ad_id, publisher_id, amount, payment_method, reference, status, proof_path) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$adId, $publisherId, $price, $paymentMethod, $reference, $paymentStatus === 'paid' ? 'success' : 'pending', $proofPath]);
    }

    // Notify Super Admin of new submission
    $adminEmail = (string) setting('contact_email');
    if ($adminEmail !== '') {
        try {
            Mailer::send($adminEmail, 'New Ad Submission: ' . $title, "Hello Admin,\n\nA new advertisement '{$title}' has been submitted by {$pubName} ({$pubEmail}).\nPackage: {$dur['title']}\nPayment Method: {$paymentMethod}\nPayment Status: {$paymentStatus}\n\nPlease review it in the Admin Ads Management panel.");
        } catch (Throwable $e) {}
    }

    // Online Payment via Payhub
    if (!$isFree && $paymentMethod === 'online' && setting('payhub_enabled') && setting('payhub_secret_key')) {
        $secKey = (string) setting('payhub_secret_key');
        $callbackUrl = baseUrl('payment/payhub/callback');
        $koboAmount = (int) round($price * 100);

        $payload = json_encode([
            'email' => $pubEmail,
            'amount' => $koboAmount,
            'reference' => $reference,
            'name' => $pubName,
            'phone' => $pubPhone,
            'callback_url' => $callbackUrl,
            'metadata' => ['ad_id' => $adId, 'publisher_id' => $publisherId]
        ]);

        if (function_exists('curl_init')) {
            $ch = curl_init('https://merchant.payhub.com.ng/api/transaction/initialize');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $secKey
                ],
            ]);
            $res = curl_exec($ch);
            curl_close($ch);

            $data = json_decode((string) $res, true);
            if (!empty($data['data']['authorization_url'])) {
                clearFormOld();
                redirect($data['data']['authorization_url']);
            }
        }
    }

    clearFormOld();
    flash('advertise_sent', '1');
    redirect('/advertise?sent=1');
});

// Public church-admin self-registration (super admin approves afterwards).
$router->get('/register', function () {
    render('register', [
        'metaTitle' => 'Register Your Church',
        'metaDescription' => 'Register your church on ' . e(setting('site_title')) . ' — church administrators can sign up for review and approval.',
    ]);
});

$router->post('/register', function () {
    $pdo = Database::getInstance()->getConnection();

    // Church name correction flag (small second form on the register page).
    if (!empty($_POST['flag_submit'])) {
        if (!RateLimiter::attempt('register_flag', clientIp(), 5, 900)) {
            flash('register_error', 'Too many attempts — please wait a few minutes and try again.');
            redirect('/register');
        }
        $current = Unit::nameFor((string) ($_POST['flag_current'] ?? ''));
        $suggested = Unit::nameFor((string) ($_POST['flag_suggested'] ?? ''));
        if ($current === '' || $suggested === '' || $current === $suggested) {
            flash('register_error', 'Please provide the current church name and the correct spelling — both are required and must be different.');
            redirect('/register');
        }
        $unit = Unit::findByNameAnywhere($current);
        $stmt = $pdo->prepare('INSERT INTO church_name_flags (org_unit_id, current_name, suggested_name, status, reported_by) VALUES (?, ?, ?, "pending", ?)');
        $stmt->execute([$unit ? (int) $unit['id'] : null, $current, $suggested, mb_substr(trim((string) ($_POST['flag_by'] ?? '')), 0, 150) ?: null]);
        flash('register_sent', '1');
        redirect('/register?sent=1');
    }

    // Honeypot: bots fill hidden fields, humans never see them.
    if (trim((string) ($_POST['company'] ?? '')) !== '') {
        flash('register_sent', '1');
        redirect('/register?sent=1');
    }

    if (!RateLimiter::attempt('register', clientIp(), 5, 900)) {
        keepFormOld($_POST);
        flash('register_error', 'Too many attempts — please wait a few minutes and try again.');
        redirect('/register');
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    $role = in_array($_POST['role'] ?? '', ['admin', 'editor', 'media_team'], true) ? $_POST['role'] : 'admin';
    $altEmail = trim($_POST['alt_email'] ?? '');
    $provinceId = (int) ($_POST['province_id'] ?? 0);
    $zoneId = (int) ($_POST['zone_id'] ?? 0);
    $areaId = (int) ($_POST['area_id'] ?? 0);
    $parishId = (int) ($_POST['parish_id'] ?? 0);
    $parishName = Unit::nameFor((string) ($_POST['parish_name'] ?? ''));

    $errors = [];
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide your name and a valid email address.';
    }
    if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username) || $username === '') {
        $errors[] = 'Username may only contain letters, numbers, dots, dashes, and underscores.';
    }
    $pwError = false;
    if (strlen($password) < 8) {
        $pwError = true;
        $errors[] = 'Password is too short — please enter at least 8 characters.';
    } elseif ($password !== $confirm) {
        $pwError = true;
        $errors[] = 'Passwords do not match — please retype both.';
    } elseif (cpanelPasswordScore($password) < 65) {
        $pwError = true;
        $errors[] = 'Password strength is below cPanel minimum (65) — add uppercase, lowercase, numbers, and a symbol. Your other details are kept; just fix the password and resubmit.';
    }
    if ($altEmail !== '' && !filter_var($altEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'The alternative email address is not valid.';
    }
    $area = $areaId > 0 ? Unit::find($areaId) : null;
    if (!$area || $area['type'] !== 'area') {
        $errors[] = 'Please select your Province, Zone, and Area.';
    }
    if ($parishName === '') {
        $errors[] = 'Please enter your Parish church name.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $errors[] = 'That username or email is already in use.';
        }
        $stmt = $pdo->prepare('SELECT id FROM pending_registrations WHERE status = "pending" AND (username = ? OR email = ?) LIMIT 1');
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $errors[] = 'You already have a pending registration — please wait for approval.';
        }
    }

    if ($errors) {
        // Flag the password section so the re-rendered page scrolls straight to
        // it (all other fields are preserved) when the only problem is the password.
        if ($pwError) {
            $_SESSION['register_pw_focus'] = true;
        }
        keepFormOld($_POST);
        flash('register_error', implode(' ', $errors));
        redirect('/register');
    }

    // Link to an existing parish if one matches; otherwise the parish is created
    // on approval (its name is saved here, in CAPS).
    $parish = $parishId > 0 ? Unit::find($parishId) : null;
    if ($parish && (int) ($parish['parent_id'] ?? 0) !== $areaId) {
        $parish = null;
    }
    if (!$parish) {
        $parish = Unit::findByName('parish', $parishName, $areaId);
    }

    $stmt = $pdo->prepare('INSERT INTO pending_registrations (name, email, phone, username, password_hash, password_enc, role, alt_email, province_id, zone_id, area_id, parish_name, parish_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")');
    $stmt->execute([
        mb_substr($name, 0, 150),
        mb_substr($email, 0, 150),
        mb_substr($phone, 0, 45) ?: null,
        mb_substr($username, 0, 100),
        password_hash($password, PASSWORD_ARGON2ID),
        encryptSecret($password),
        $role,
        $altEmail !== '' ? mb_substr($altEmail, 0, 190) : null,
        $provinceId > 0 ? $provinceId : null,
        $zoneId > 0 ? $zoneId : null,
        $areaId,
        mb_substr($parishName, 0, 150),
        $parish ? (int) $parish['id'] : null,
    ]);
    clearFormOld();
    flash('register_sent', '1');
    redirect('/register?sent=1');
});

$router->get('/events', function () {
    render('events');
});

$router->get('/events/{slug}', function (array $params) {
    render('event-detail', ['slug' => $params['slug']]);
});

$router->get('/sermons', function () {
    render('sermons');
});

$router->get('/sermons/{slug}', function (array $params) {
    render('sermon-detail', ['slug' => $params['slug']]);
});

$router->get('/about', function () {
    render('page', ['slug' => 'about']);
});

$router->get('/privacy-policy', function () {
    render('page', ['slug' => 'privacy-policy']);
});

$router->get('/page/{slug}', function (array $params) {
    render('page', ['slug' => $params['slug']]);
});

$router->get('/contact', function () {
    render('contact');
});

$router->get('/give', function () {
    render('give');
});

$router->get('/live', function () {
    render('live');
});

$router->get('/prayer', function () {
    render('prayer');
});

$router->get('/bible', function () {
    render('bible', [
        'metaTitle' => 'Holy Bible',
        'metaDescription' => 'Read the Holy Bible in your preferred version and language — KJV, NIV, NLT, NKJV with multi-language support.',
    ]);
});

$router->get('/forms/{slug}', function (array $params) {
    render('form', ['slug' => $params['slug']]);
});

// Unlock a private form with its password (link + password both required).
$router->post('/forms/{slug}/unlock', function (array $params) {
    $pdo = Database::getInstance()->getConnection();
    $slug = $params['slug'];

    $stmt = $pdo->prepare('SELECT * FROM forms WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $form = $stmt->fetch();

    if (!$form) {
        http_response_code(404);
        render('404', [], true);
        return;
    }
    if (($form['visibility'] ?? 'public') !== 'private' || formUnlocked($form)) {
        redirect('/forms/' . $slug);
    }

    if (!RateLimiter::attempt('form_unlock', $slug, 10, 300)) {
        flash('form_error', 'Too many attempts — please wait a few minutes and try again.');
        redirect('/forms/' . $slug);
    }

    $password = (string) ($_POST['password'] ?? '');
    if (!empty($form['password_hash']) && password_verify($password, (string) $form['password_hash'])) {
        $_SESSION['form_unlocked'][(int) $form['id']] = true;
        redirect('/forms/' . $slug);
    }

    flash('form_error', 'Incorrect password for this private form.');
    redirect('/forms/' . $slug);
});

$router->post('/forms/{slug}', function (array $params) {
    $pdo = Database::getInstance()->getConnection();
    $slug = $params['slug'];

    $stmt = $pdo->prepare('SELECT * FROM forms WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $form = $stmt->fetch();

    if (!$form) {
        http_response_code(404);
        render('404', [], true);
        return;
    }
    if (!formUnlocked($form)) {
        redirect('/forms/' . $slug);
    }
    if (!formsAccepting($form)) {
        redirect('/forms/' . urlencode($slug));
    }

    // Honeypot: bots fill every field, humans never see this one.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        flash('form_sent', '1');
        redirect('/forms/' . $slug . '?sent=1');
    }

    if (!RateLimiter::attempt('form_submit', $slug, 10, 300)) {
        keepFormOld($_POST);
        flash('form_error', 'Too many attempts from your browser — please wait a few minutes and try again.');
        redirect('/forms/' . $slug);
    }

    $stmt = $pdo->prepare('SELECT * FROM form_fields WHERE form_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$form['id']]);
    $fields = $stmt->fetchAll();

    $uploadedFiles = normalizeUploadedFiles($_FILES);
    $storedImages = [];
    $data = [];
    $errors = [];
    foreach ($fields as $field) {
        $key = 'field_' . $field['id'];
        $raw = $_POST[$key] ?? null;

        if (is_array($raw)) {
            $value = array_values(array_filter(array_map('trim', $raw), fn ($v) => $v !== ''));
        } else {
            $value = trim((string) $raw);
        }

        // Image uploads are handled from $_FILES, not text values.
        if ($field['field_type'] === 'image') {
            $value = [];
            foreach ($uploadedFiles[$key] ?? [] as $up) {
                if (empty($up['tmp_name']) || ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if (($up['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    $errors[] = 'The image "' . $up['name'] . '" failed to upload — please try again.';
                    continue;
                }
                if ((int) ($up['size'] ?? 0) > 8 * 1024 * 1024) {
                    $errors[] = 'Image "' . $up['name'] . '" is too large — max 8MB per file.';
                    continue;
                }
                $stored = null;
                try {
                    $stored = storeFormImageUpload($up);
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                }
                if (!$stored) {
                    $errors[] = '"' . $field['label'] . '" has an unsupported file ("' . $up['name'] . '"). Accepted: JPG, PNG, GIF, WebP, BMP, AVIF.';
                    continue;
                }
                $storedImages[] = $stored;
                $value[] = $stored;
            }
            if ($field['required'] && $value === []) {
                $errors[] = 'Please upload at least one image for "' . $field['label'] . '".';
            }
            $data[(string) $field['id']] = $value;
            continue;
        }

        if ($field['required'] && ($value === '' || (is_array($value) && $value === []))) {
            $errors[] = 'Please answer "' . $field['label'] . '".';
            continue;
        }

        switch ($field['field_type']) {
            case 'email':
                if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = '"' . $field['label'] . '" needs a valid email address.';
                }
                break;
            case 'url':
                if ($value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $errors[] = '"' . $field['label'] . '" needs a valid URL.';
                }
                break;
            case 'number':
                if ($value !== '' && !is_numeric($value)) {
                    $errors[] = '"' . $field['label'] . '" needs a number.';
                }
                break;
            case 'phone':
                if ($value !== '' && !preg_match('/^[0-9+\-(). ]{6,30}$/', (string) $value)) {
                    $errors[] = '"' . $field['label'] . '" needs a valid phone number.';
                }
                break;
            case 'date':
                if ($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
                    $errors[] = '"' . $field['label'] . '" needs a valid date.';
                }
                break;
            case 'time':
                if ($value !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string) $value)) {
                    $errors[] = '"' . $field['label'] . '" needs a valid time.';
                }
                break;
            case 'datetime':
                if ($value !== '' && strtotime((string) $value) === false) {
                    $errors[] = '"' . $field['label'] . '" needs a valid date and time.';
                }
                break;
            case 'cascade':
                // Value is the chosen full path ("A > B > C"); must be one of
                // the paths the admin defined for this cascading dropdown.
                if ($value !== '' && !in_array((string) $value, formCascadePaths($field), true)) {
                    $errors[] = '"' . $field['label'] . '" contains an invalid option.';
                }
                break;
            case 'church':
                // Auto church-list field: value must be a real parish path in
                // the current org_units hierarchy.
                if ($value !== '' && !in_array((string) $value, churchCascadePaths(), true)) {
                    $errors[] = '"' . $field['label'] . '" contains an invalid selection.';
                }
                break;
            case 'select':
            case 'radio':
            case 'checkbox':
                $selected = is_array($value) ? $value : ($value === '' ? [] : [$value]);
                $allowed = formFieldOptions($field);
                foreach ($selected as $v) {
                    if (!in_array($v, $allowed, true)) {
                        $errors[] = '"' . $field['label'] . '" contains an invalid option.';
                        break;
                    }
                }
                $value = $selected;
                break;
        }

        $data[(string) $field['id']] = $value;
    }

    if ($errors) {
        foreach ($storedImages as $path) {
            @unlink(UPLOADS_PATH . '/' . $path);
        }
        keepFormOld($_POST);
        flash('form_error', implode(' ', $errors));
        redirect('/forms/' . $slug);
    }

    $stmt = $pdo->prepare('INSERT INTO form_submissions (form_id, data, ip_address) VALUES (?, ?, ?)');
    $stmt->execute([$form['id'], json_encode($data, JSON_UNESCAPED_SLASHES), clientIp()]);
    clearFormOld();
    flash('form_sent', '1');
    redirect('/forms/' . $slug . '?sent=1');
});

// Server-hosted shareable CSV exports (Google-Forms style). Anyone with the
// unguessable token link can view/download the file; admins generate these
// from the panel (forms, newcomers, attendance).
$router->get('/export/{token}', function (array $params) {
    $pdo = Database::getInstance()->getConnection();
    $stmt = $pdo->prepare('SELECT * FROM export_files WHERE token = ? LIMIT 1');
    $stmt->execute([$params['token']]);
    $ef = $stmt->fetch();
    if (!$ef) {
        http_response_code(404);
        render('404', [], true);
        return;
    }
    $file = STORAGE_PATH . '/exports/' . basename((string) $ef['path']);
    if (!is_file($file)) {
        http_response_code(404);
        render('404', [], true);
        return;
    }
    $pdo->prepare('UPDATE export_files SET downloads = downloads + 1 WHERE id = ?')->execute([(int) $ef['id']]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: inline; filename="' . $ef['filename'] . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
});

$router->get('/search', function () {
    render('search');
});

$router->get('/sitemap.xml', function () {
    require VIEWS_PATH . '/sitemap.php';
});

$router->get('/favicon.ico', function () {
    $path = setting('favicon_path');
    if ($path && is_file(UPLOADS_PATH . '/' . $path)) {
        header('Content-Type: image/webp');
        header('Cache-Control: public, max-age=86400');
        readfile(UPLOADS_PATH . '/' . $path);
        exit;
    }
    MediaProcessor::renderDynamicFavicon(setting('site_title', 'C'));
});
