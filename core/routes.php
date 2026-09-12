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

// Forgot Password / OTP Reset route
$router->get('/forgot-password', function () {
    render('forgot-password', [], false);
});
$router->post('/forgot-password', function () {
    render('forgot-password', [], false);
});

// Unblock Security Access route
$router->get('/unblock', function () {
    render('unblock', [], false);
});
$router->post('/unblock', function () {
    render('unblock', [], false);
});

// Publisher Ad Manager Portal
$router->get('/ad-manager', function () {
    render('ad-manager');
});

$router->post('/ad-manager', function () {
    // Publisher token request via email
    if (($_GET['action'] ?? '') === 'request_token') {
        Csrf::requireValid();
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('pub_req_error', 'Please enter a valid email address.');
            redirect('/ad-manager');
        }

        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare('SELECT * FROM ad_publishers WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $pub = $stmt->fetch();

        if ($pub && !empty($pub['token'])) {
            $link = baseUrl('ad-manager?token=' . rawurlencode($pub['token']));
            $body = "Hello " . ($pub['name'] ?: 'Publisher') . ",\n\n" .
                "You requested access to your Publisher Ad Manager portal on " . setting('site_title') . ".\n\n" .
                "Click the link below to access your portal, view live ad performance, and create new advertisements:\n" .
                $link . "\n\n" .
                "If you did not request this link, you can safely ignore this email.\n\n" .
                "Best regards,\n" . setting('site_title');

            try {
                Mailer::send($pub['email'], 'Your Publisher Access Link · ' . setting('site_title'), $body);
                flash('pub_req_success', 'An access link has been sent to ' . $email . '. Please check your email inbox (and spam folder) to open your Ad Manager portal.');
            } catch (Throwable $e) {
                flash('pub_req_error', 'Failed to send access email. Please try again or contact support.');
            }
        } else {
            flash('pub_req_info', 'No publisher account was found for "' . $email . '". If you have not submitted an advertisement yet, please place an advert first.');
        }
        redirect('/ad-manager');
    }

    $token = trim((string) ($_GET['token'] ?? ''));
    if ($token === '') {
        http_response_code(403);
        render('ad-manager');
        return;
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
    $isGiving = str_starts_with($reference, 'GIVE_') || str_starts_with($reference, 'DON_');

    if ($reference === '') {
        if ($isGiving) {
            flash('give_error', 'Invalid payment reference.');
            redirect('/give');
        }
        flash('advertise_error', 'Invalid payment reference.');
        redirect('/advertise');
    }

    $secKey = (string) setting('payhub_secret_key');

    // Verify transaction with Payhub API
    $url = 'https://merchant.payhub.com.ng/api/transaction/verify/' . urlencode($reference);
    $paid = false;

    if ($secKey !== '' && function_exists('curl_init')) {
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
    } else {
        $paid = true; // Sandbox fallback
    }

    if ($paid) {
        if ($isGiving) {
            $stmt = $pdo->prepare('UPDATE donations SET payment_status = "completed" WHERE payment_reference = ?');
            $stmt->execute([$reference]);

            $stmt = $pdo->prepare('SELECT * FROM donations WHERE payment_reference = ? LIMIT 1');
            $stmt->execute([$reference]);
            $don = $stmt->fetch();

            $amtStr = $don ? ' ₦' . number_format((float) $don['amount']) : '';
            flash('give_success', 'Thank you for your generosity!' . $amtStr . ' online giving has been processed successfully.');
            redirect('/give');
        } else {
            $stmt = $pdo->prepare('UPDATE ads SET payment_status = "paid" WHERE payment_reference = ?');
            $stmt->execute([$reference]);

            $stmt = $pdo->prepare('UPDATE ad_payments SET status = "success" WHERE reference = ?');
            $stmt->execute([$reference]);

            flash('advertise_sent', '1');
            redirect('/advertise?sent=1');
        }
    } else {
        if ($isGiving) {
            flash('give_error', 'Online giving payment verification was not successful.');
            redirect('/give');
        }
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
        if (str_starts_with($ref, 'GIVE_') || str_starts_with($ref, 'DON_')) {
            $pdo->prepare('UPDATE donations SET payment_status = "completed" WHERE payment_reference = ?')->execute([$ref]);
        } else {
            $pdo->prepare('UPDATE ads SET payment_status = "paid" WHERE payment_reference = ?')->execute([$ref]);
            $pdo->prepare('UPDATE ad_payments SET status = "success" WHERE reference = ?')->execute([$ref]);
        }
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
    $unblockPin = trim((string) ($_POST['unblock_pin'] ?? ''));
    $role = in_array($_POST['role'] ?? '', ['admin', 'editor', 'media_team'], true) ? $_POST['role'] : 'admin';
    $altEmail = trim($_POST['alt_email'] ?? '');
    // The chosen branch is posted as an ordered id path covering every level
    // above the church itself; the church name is typed in at the deepest level.
    $legacyAreaId = (int) ($_POST['area_id'] ?? 0);
    $unitPath = Unit::decodePath($_POST['unit_path'] ?? '', $legacyAreaId > 0 ? $legacyAreaId : null);
    $chain = Unit::validateChain($unitPath);
    $parentId = $chain ? (int) $chain[count($chain) - 1]['id'] : 0;
    $parishId = (int) ($_POST['parish_id'] ?? 0);
    $parishName = Unit::nameFor((string) ($_POST['parish_name'] ?? $_POST['leaf_name'] ?? ''));

    // Labels drive every message so they always match the configured levels.
    $leafLabel = Unit::labelFor(Unit::leafType());
    $parentLabels = array_slice(array_map(static fn (array $l): string => $l['label'], Unit::levels()), 0, max(0, Unit::levelCount() - 1));

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
    if (!preg_match('/^[0-9]{4,6}$/', $unblockPin)) {
        $errors[] = 'Security Unblock PIN must be 4 to 6 digits.';
    }
    if ($altEmail !== '' && !filter_var($altEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'The alternative email address is not valid.';
    }
    if (count($chain) !== count($parentLabels)) {
        $errors[] = 'Please select your ' . implode(', ', $parentLabels) . '.';
    }
    if ($parishName === '') {
        $errors[] = 'Please enter your ' . $leafLabel . ' name.';
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

    // Link to an existing church if one matches; otherwise it is created on
    // approval (its name is saved here, in CAPS).
    $parish = $parishId > 0 ? Unit::find($parishId) : null;
    if ($parish && (int) ($parish['parent_id'] ?? 0) !== $parentId) {
        $parish = null;
    }
    if (!$parish && $parentId > 0) {
        $parish = Unit::findByName(Unit::leafType(), $parishName, $parentId);
    }

    // province_id/zone_id/area_id are kept populated for the older admin views;
    // unit_path is the authoritative branch at any depth.
    $stmt = $pdo->prepare('INSERT INTO pending_registrations (name, email, phone, username, password_hash, unblock_pin_hash, password_enc, role, alt_email, province_id, zone_id, area_id, parish_name, parish_id, unit_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")');
    $stmt->execute([
        mb_substr($name, 0, 150),
        mb_substr($email, 0, 150),
        mb_substr($phone, 0, 45) ?: null,
        mb_substr($username, 0, 100),
        password_hash($password, PASSWORD_ARGON2ID),
        password_hash($unblockPin, PASSWORD_DEFAULT),
        encryptSecret($password),
        $role,
        $altEmail !== '' ? mb_substr($altEmail, 0, 190) : null,
        $chain ? (int) $chain[0]['id'] : null,
        isset($chain[1]) ? (int) $chain[1]['id'] : null,
        $parentId > 0 ? $parentId : null,
        mb_substr($parishName, 0, 150),
        $parish ? (int) $parish['id'] : null,
        $chain ? json_encode(array_map(static fn (array $u): array => ['type' => $u['type'], 'id' => (int) $u['id']], $chain), JSON_UNESCAPED_SLASHES) : null,
    ]);
    clearFormOld();
    flash('register_sent', '1');
    redirect('/register?sent=1');
});

$router->get('/events', function () {
    render('events');
});

$router->get('/events/{slug}', function (array $params) {
    Analytics::recordEntityBySlug('event', 'events', (string) $params['slug']);
    render('event-detail', ['slug' => $params['slug']]);
});

// RSVP taken on the page itself. A plain form POST, so it works with JavaScript
// off — the app uses POST /api/rsvp with the same underlying logic.
$router->post('/events/{slug}', function (array $params) {
    Csrf::requireValid();
    RateLimiter::require('rsvp', 10, 300);

    $slug = (string) $params['slug'];
    $pdo = Database::getInstance()->getConnection();
    $stmt = $pdo->prepare('SELECT * FROM events WHERE slug = ? AND is_published = 1 LIMIT 1');
    $stmt->execute([$slug]);
    $event = $stmt->fetch();

    if (!$event || !Rsvp::takesRsvps($event)) {
        flash('rsvp_error', 'That event is not taking RSVPs here.');
        redirect('/events/' . rawurlencode($slug));
    }

    $result = Rsvp::submit($event, $_POST);
    if (!$result['ok']) {
        keepFormOld($_POST);
        flash('rsvp_error', $result['message']);
    } else {
        // A smaller party may have freed seats for whoever is waiting.
        Rsvp::promoteWaitlist((int) $event['id']);
        clearFormOld();
        flash('rsvp_ok', $result['message']);
    }

    redirect('/events/' . rawurlencode($slug));
});

$router->get('/sermons', function () {
    render('sermons');
});

$router->get('/sermons/{slug}', function (array $params) {
    Analytics::recordEntityBySlug('sermon', 'sermons', (string) $params['slug']);
    render('sermon-detail', ['slug' => $params['slug']]);
});

$router->get('/about', function () {
    render('page', ['slug' => 'about']);
});

$router->get('/privacy-policy', function () {
    render('page', ['slug' => 'privacy-policy']);
});

$router->get('/page/privacy-policy', function () {
    render('page', ['slug' => 'privacy-policy']);
});

$router->get('/page/{slug}', function (array $params) {
    render('page', ['slug' => $params['slug']]);
});

$router->get('/contact', function () {
    render('contact');
});

$router->get('/testimonies', function () {
    render('testimonies');
});

$router->post('/testimonies', function () {
    Csrf::requireValid();
    RateLimiter::require('testimonies', 5, 300);

    $pdo = Database::getInstance()->getConnection();

    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $unitId = (int) ($_POST['unit_id'] ?? 0);
    $unitId = $unitId > 0 ? $unitId : null;
    $title = trim((string) ($_POST['title'] ?? ''));
    $content = trim((string) ($_POST['content'] ?? ''));

    if ($name === '') {
        flash('testimony_error', 'Your full name is required.');
        redirect('/testimonies#submit-testimony');
    }
    if ($title === '') {
        flash('testimony_error', 'Please provide a title for your testimony.');
        redirect('/testimonies#submit-testimony');
    }
    if ($content === '') {
        flash('testimony_error', 'Please write your testimony details.');
        redirect('/testimonies#submit-testimony');
    }

    $mediaUrl = null;
    if (!empty($_FILES['media']['tmp_name']) && is_uploaded_file($_FILES['media']['tmp_name'])) {
        $filename = MediaProcessor::processImage($_FILES['media']['tmp_name'], UPLOADS_WEBP_PATH);
        if ($filename) {
            $mediaUrl = 'webp/' . $filename;
        }
    }

    $stmt = $pdo->prepare('INSERT INTO testimonies (unit_id, name, email, phone, title, content, media_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, "pending")');
    $stmt->execute([$unitId, $name, $email ?: null, $phone ?: null, $title, $content, $mediaUrl]);

    flash('testimony_success', 'Thank you for sharing your praise report! Our ministry team will review it and publish it to the website shortly.');
    redirect('/testimonies');
});

$router->get('/give', function () {
    render('give');
});

$router->post('/give', function () {
    Csrf::requireValid();
    $pdo = Database::getInstance()->getConnection();

    $paymentMethod = in_array($_POST['payment_method'] ?? '', ['online', 'manual_bank'], true) ? $_POST['payment_method'] : 'online';
    $category = trim((string) ($_POST['category'] ?? 'Tithe'));
    $amount = (float) ($_POST['amount'] ?? 0);
    $donorName = trim((string) ($_POST['donor_name'] ?? 'Anonymous Giver'));
    $donorEmail = trim((string) ($_POST['donor_email'] ?? ''));
    $donorPhone = trim((string) ($_POST['donor_phone'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if ($amount < 100) {
        flash('give_error', 'Giving amount must be at least ₦100.');
        redirect('/give');
    }
    if ($donorEmail === '' || !filter_var($donorEmail, FILTER_VALIDATE_EMAIL)) {
        flash('give_error', 'Please provide a valid email address.');
        redirect('/give');
    }

    if ($paymentMethod === 'manual_bank') {
        $fileUpload = $_FILES['receipt_file'] ?? null;
        if (!$fileUpload || empty($fileUpload['tmp_name']) || ($fileUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('give_error', 'Please upload a bank transfer receipt image or PDF proof.');
            redirect('/give');
        }

        $receiptDir = UPLOADS_PATH . '/donations';
        if (!is_dir($receiptDir)) {
            @mkdir($receiptDir, 0775, true);
        }

        $ext = strtolower(pathinfo($fileUpload['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
            flash('give_error', 'Invalid file type. Upload JPG, PNG, WebP or PDF receipt.');
            redirect('/give');
        }

        $fileName = 'receipt_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($fileUpload['tmp_name'], $receiptDir . '/' . $fileName)) {
            flash('give_error', 'Failed to save receipt file. Please try again.');
            redirect('/give');
        }

        $ref = 'GIVE_MANUAL_' . strtoupper(bin2hex(random_bytes(6)));
        $stmt = $pdo->prepare('INSERT INTO donations (donor_name, donor_email, donor_phone, category, amount, currency, description, payment_method, payment_status, payment_reference, receipt_path) VALUES (?, ?, ?, ?, ?, "NGN", ?, "manual_bank", "pending", ?, ?)');
        $stmt->execute([$donorName ?: 'Anonymous Giver', $donorEmail, $donorPhone ?: null, $category, $amount, $description ?: null, $ref, 'donations/' . $fileName]);

        flash('give_success', 'Thank you! Your bank transfer receipt of ₦' . number_format($amount) . ' for ' . $category . ' has been submitted and is pending verification by our finance team.');
        redirect('/give');
    }

    // Online Payment Gateway (Payhub)
    $ref = 'GIVE_' . strtoupper(bin2hex(random_bytes(8)));
    $stmt = $pdo->prepare('INSERT INTO donations (donor_name, donor_email, donor_phone, category, amount, currency, description, payment_method, payment_status, payment_reference) VALUES (?, ?, ?, ?, ?, "NGN", ?, "online", "pending", ?)');
    $stmt->execute([$donorName ?: 'Anonymous Giver', $donorEmail, $donorPhone ?: null, $category, $amount, $description ?: null, $ref]);

    $apiKey = (string) setting('payhub_api_key');
    $secKey = (string) setting('payhub_secret_key');

    if ($apiKey !== '' && $secKey !== '') {
        $callbackUrl = baseUrl('payment/payhub/callback?ref=' . urlencode($ref));
        $payhubUrl = 'https://merchant.payhub.com.ng/api/v1/checkout/initialize';

        $payload = [
            'amount' => $amount,
            'email' => $donorEmail,
            'reference' => $ref,
            'callback_url' => $callbackUrl,
            'description' => 'Church Giving: ' . $category . ($description ? ' - ' . substr($description, 0, 80) : ''),
            'currency' => 'NGN',
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($payhubUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $secKey,
                ],
            ]);
            $res = curl_exec($ch);
            curl_close($ch);

            $data = json_decode((string) $res, true);
            if (!empty($data['checkout_url'])) {
                redirect($data['checkout_url']);
            } elseif (!empty($data['data']['authorization_url'])) {
                redirect($data['data']['authorization_url']);
            }
        }
    }

    // Sandbox / fallback mode
    $pdo->prepare('UPDATE donations SET payment_status = "completed" WHERE payment_reference = ?')->execute([$ref]);
    flash('give_success', 'Thank you for your cheerful giving of ₦' . number_format($amount) . ' towards ' . $category . '! Your online donation has been recorded.');
    redirect('/give');
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
