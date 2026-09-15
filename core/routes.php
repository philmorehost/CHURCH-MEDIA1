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

// A permalink for one reel or post, which exists because of the share sheet.
//
// WhatsApp fetches whatever URL it is given and reads the og: tags off that page. The app used to
// share /feed, which has no idea which post was meant, so every reel previewed with the church
// logo. A post needs an address of its own before it can have a preview of its own.
$router->get('/post/{id}', function (array $params) {
    $id = (int) ($params['id'] ?? 0);
    $pdo = Database::getInstance()->getConnection();

    $post = null;
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT p.*, u.name AS author_name FROM media_posts p JOIN users u ON u.id = p.user_id WHERE p.id = ? AND p.is_published = 1 LIMIT 1');
        $stmt->execute([$id]);
        $post = $stmt->fetch() ?: null;
    }

    // A deleted or unpublished post is a 404 rather than a redirect to the feed: a link somebody
    // shared should say plainly that it is gone instead of quietly showing something else.
    if ($post === null) {
        http_response_code(404);
        render('404');
        return;
    }

    $items = $pdo->prepare('SELECT type, file_path, thumbnail_path, alt_text FROM media_post_items WHERE media_post_id = ? ORDER BY sort_order ASC');
    $items->execute([$post['id']]);

    $caption = trim((string) ($post['caption'] ?? ''));
    $isReel = (string) ($post['post_type'] ?? '') === 'vertical_reel';

    render('post', [
        'metaTitle' => $caption !== '' ? mb_strimwidth($caption, 0, 70, '…') : ($isReel ? 'Reel' : 'Post') . ' — ' . setting('site_title'),
        'metaDescription' => $caption !== '' ? mb_strimwidth($caption, 0, 155, '…') : 'Watch it on ' . setting('site_title') . '.',
        // Absolute, because the crawler fetching this has to be able to reach it from outside.
        'metaImage' => baseUrl(ShareCard::urlFor('post', (int) $post['id'], (string) ($post['slug'] ?? ''))),
        'post' => $post,
        'media' => $items->fetchAll(),
    ]);
});

$router->get('/unit/{slug}', function (array $params) {
    render('unit', ['slug' => $params['slug']]);
});

$router->get('/units', function () {
    render('units');
});

// Home cell finder — the midweek gatherings, with the filter in the query string so a
// filtered list can be shared or bookmarked ("here is the cell near me").
$router->get('/find-a-cell', function () {
    render('find-a-cell');
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
        // Scoped to the church being served. Without this, typing an address into one church's site
        // emailed another church's publisher a portal link — and that link carries the token that opens
        // their Ad Manager.
        [$tenantClause, $tenantParams] = tenantScope();
        $stmt = $pdo->prepare('SELECT * FROM ad_publishers WHERE email = ? AND ' . $tenantClause . ' LIMIT 1');
        $stmt->execute(array_merge([$email], $tenantParams));
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

    // The create form has always emitted a CSRF token and nothing ever checked it — `Csrf::field()` was
    // there, so the protection looked present. It is checked now.
    Csrf::requireValid();

    $token = trim((string) ($_GET['token'] ?? ''));
    if ($token === '') {
        http_response_code(403);
        render('ad-manager');
        return;
    }

    $pdo = Database::getInstance()->getConnection();
    // The token is the credential, so this is deliberately not church-scoped: an advert created here
    // belongs to the publisher's own church, whichever host the portal was opened on, so a publisher's
    // adverts can never end up attributed to a church they do not advertise for.
    $stmt = $pdo->prepare('SELECT id, tenant_id FROM ad_publishers WHERE token = ? LIMIT 1');
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

    $stmt = $pdo->prepare('INSERT INTO ads (publisher_id, tenant_id, title, media_type, file_path, thumbnail_path, destination_url, target_platform, duration_days, price, is_free, display_frequency, payment_status, payment_method, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")');
    $stmt->execute([(int) $pub['id'], (int) $pub['tenant_id'], $title, $mediaType, $filePath, $thumbPath, $destUrl ?: null, $targetPlatform, $durationDays, $price, $isFree, $displayFreq, $isFree ? 'paid' : 'unpaid', $isFree ? 'free' : 'online']);
    $adId = (int) $pdo->lastInsertId();

    /*
     * A PAID package chosen here must take the advertiser to the payment, exactly as the public /advertise
     * form does.
     *
     * It did not, and that was a hole in the middle of the product. This handler inserted the advert with
     * `payment_status = "unpaid"` and `payment_method = "online"` and then redirected straight to the
     * dashboard saying "submitted and pending admin approval" — so an advertiser who picked a premium
     * package from their own portal was told their advert was in, was never shown a payment page, and no
     * payment attempt was ever opened. The advert then sat in the admin queue unpaid, and since 7h-5 an
     * unsettled advert cannot be approved either: it could never go live, for a reason nothing on screen
     * explained.
     *
     * Two create paths for the same product, and only one of them collected the money.
     */
    if ($isFree) {
        flash('pub_success', 'Your free advertisement has been submitted and is pending admin approval.');
        redirect('/ad-manager?token=' . rawurlencode($token));
    }

    // Reload the row so the attempt is opened against what was actually written rather than what we meant
    // to write, then hand the advertiser the same checkout the public form hands out.
    $stmt = $pdo->prepare('SELECT * FROM ads WHERE id = ? LIMIT 1');
    $stmt->execute([$adId]);
    $newAd = $stmt->fetch();

    if ($newAd) {
        $attempt = AdPayments::startAttempt($newAd);
        $_SESSION['ad_checkout_ref'] = $attempt['reference'];
        redirect('/advertise/checkout?ref=' . urlencode($attempt['reference']));
    }

    // Nothing written means nothing to pay for; the dashboard is the honest place to land.
    flash('pub_error', 'Your advertisement could not be created. Please try again.');
    redirect('/ad-manager?token=' . rawurlencode($token));
});

// Edit a rejected advert and send it back for review.
//
// The point of this route is what it does NOT touch. "Without paying again" is not a discount applied
// here; it is the absence of any write to `payment_status`, `payment_reference`, `payment_attempts` or
// `ad_payments`. An advertiser whose advert was rejected after paying keeps that payment, the reviewer
// sees the same settled advert come back, and no second attempt is ever opened. Anything that added a
// payment write to this handler would be charging twice for one advert.
$router->post('/ad-manager/revise', function () {
    Csrf::requireValid();

    $token = trim((string) ($_POST['token'] ?? ''));
    if ($token === '') {
        http_response_code(403);
        render('ad-manager');
        return;
    }

    $pdo = Database::getInstance()->getConnection();
    // The token is the credential — the same rule as the create handler above.
    $stmt = $pdo->prepare('SELECT id, tenant_id FROM ad_publishers WHERE token = ? LIMIT 1');
    $stmt->execute([$token]);
    $pub = $stmt->fetch();

    if (!$pub) {
        http_response_code(403);
        exit('Access denied.');
    }

    $back = '/ad-manager?token=' . rawurlencode($token);

    // The advert has to belong to THIS publisher. Without the second condition one advertiser's token
    // could rewrite another advertiser's campaign by guessing an id.
    $stmt = $pdo->prepare('SELECT * FROM ads WHERE id = ? AND publisher_id = ? LIMIT 1');
    $stmt->execute([(int) ($_POST['ad_id'] ?? 0), (int) $pub['id']]);
    $ad = $stmt->fetch();

    if (!$ad) {
        flash('pub_error', 'That advert could not be found.');
        redirect($back);
    }

    /*
     * Only a rejected advert may be resubmitted, and this is not a formality.
     *
     * An approved advert pushed back to `pending` would stop being served — and an advertiser with a
     * month of display left could use that to restart the clock on it. A pending one is already waiting
     * and needs nothing, and resubmitting it would only inflate its revision count.
     */
    if ((string) $ad['status'] !== 'rejected') {
        flash('pub_error', 'That advert is not waiting for a change, so there is nothing to resubmit.');
        redirect($back);
    }

    $title = trim((string) ($_POST['title'] ?? ''));
    $destUrl = trim((string) ($_POST['destination_url'] ?? ''));

    if ($title === '') {
        flash('pub_error', 'Please enter an Ad title.');
        redirect($back);
    }

    $filePath = (string) $ad['file_path'];
    $thumbPath = $ad['thumbnail_path'];
    $mediaType = (string) $ad['media_type'];

    // A replacement creative, if one was chosen — through the same processors the first upload went
    // through, so a resubmission cannot smuggle in a file the original path would have refused.
    $fileUpload = $_FILES['media_file'] ?? null;
    if ($fileUpload && ($fileUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $mediaType = in_array($_POST['media_type'] ?? '', ['image', 'video'], true) ? (string) $_POST['media_type'] : $mediaType;

        if ($mediaType === 'image') {
            $processed = MediaProcessor::processAdImage($fileUpload['tmp_name'], UPLOADS_PATH . '/ads');
            if (!$processed) {
                flash('pub_error', 'Failed to process the uploaded image.');
                redirect($back);
            }
            $filePath = 'ads/' . $processed;
            $thumbPath = null;
        } else {
            $res = MediaProcessor::processAdVideo($fileUpload['tmp_name'], UPLOADS_PATH . '/ads/reels', UPLOADS_PATH . '/ads/thumbs');
            if (empty($res['file'])) {
                flash('pub_error', 'Failed to process the uploaded video.');
                redirect($back);
            }
            $filePath = 'ads/reels/' . $res['file'];
            $thumbPath = !empty($res['thumbnail']) ? 'ads/thumbs/' . $res['thumbnail'] : null;
        }
    }

    /*
     * Back into the queue — and that is the entire write.
     *
     * `rejection_reason` is deliberately NOT cleared: the reviewer's list shows it beside the revision
     * count, so whoever rejected it last time can see what they objected to and whether it was addressed.
     * A reason that vanished on resubmission would make the second review blind.
     */
    $pdo->prepare('UPDATE ads SET title = ?, destination_url = ?, media_type = ?, file_path = ?, thumbnail_path = ?, status = "pending", resubmitted_at = NOW(), revision_count = revision_count + 1 WHERE id = ?')
        ->execute([$title, $destUrl !== '' ? $destUrl : null, $mediaType, $filePath, $thumbPath, (int) $ad['id']]);

    flash('pub_success', 'Your advert has been sent back for review. Your payment still stands — there is nothing more to pay.');
    redirect($back);
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

    /*
     * The gateway is asked, server-side, and its answer is the only thing that decides.
     *
     * This block used to set `$paid = true` whenever it could not reach PayHub — no curl, no secret key,
     * a timeout — so `?ref=…` on this URL marked a donation completed with no money behind it, and marked
     * an advert paid. A verification that cannot be made is not a payment, and `Payhub::verify()` says so
     * with the reason instead.
     */
    $verified = Payhub::verify($reference);
    $paid = $verified['paid'];
    $gatewayNote = $verified['error'] !== '' ? $verified['error'] : $verified['reason'];
    $gatewayPayload = $verified['raw'] === [] ? null : (string) json_encode($verified['raw']);

    if ($paid) {
        if ($isGiving) {
            $stmt = $pdo->prepare('UPDATE donations SET payment_status = "completed" WHERE payment_reference = ?');
            $stmt->execute([$reference]);

            $stmt = $pdo->prepare('SELECT * FROM donations WHERE payment_reference = ? LIMIT 1');
            $stmt->execute([$reference]);
            $don = $stmt->fetch();

            $amtStr = $don ? ' ₦' . number_format((float) $don['amount']) : '';
            flash('give_success', 'Thank you for your generosity!' . $amtStr . ' online giving has been processed successfully.');

            // Send them back to the campaign they gave to, if they gave to one, so the progress bar
            // they were looking at has moved by the time they return.
            $backTo = '/give';
            if ($don && !empty($don['campaign_id'])) {
                $campaign = GivingCampaign::find((int) $don['campaign_id']);
                if ($campaign !== null) {
                    $backTo = '/give/c/' . $campaign['slug'];
                }
            }
            redirect($backTo);
        } else {
            $stmt = $pdo->prepare('UPDATE ads SET payment_status = "paid" WHERE payment_reference = ?');
            $stmt->execute([$reference]);

            $stmt = $pdo->prepare('UPDATE ad_payments SET status = "success", gateway_response = ? WHERE reference = ?');
            $stmt->execute([$gatewayPayload ?? $gatewayNote, $reference]);

            flash('advertise_sent', '1');
            redirect('/advertise?sent=1');
        }
    } else {
        if ($isGiving) {
            // The attempt is recorded as failed rather than left pending for ever, so the record matches
            // what the giver was told.
            $pdo->prepare('UPDATE donations SET payment_status = "failed" WHERE payment_reference = ? AND payment_status <> "completed"')->execute([$reference]);

            flash('give_error', $gatewayNote !== ''
                ? 'Your payment was not completed: ' . $gatewayNote . ' Nothing has been charged.'
                : 'Your payment was not completed. Nothing has been charged.');
            redirect('/give');
        }

        // Recorded on the attempt, where the advertiser's report reads it, and never over an earlier
        // success — a replayed return URL must not be able to un-pay a paid advert.
        $pdo->prepare('UPDATE ad_payments SET status = "failed", gateway_response = ? WHERE reference = ? AND status <> "success"')
            ->execute([$gatewayPayload ?? $gatewayNote, $reference]);

        flash('advertise_error', $gatewayNote !== ''
            ? 'Payment was not completed: ' . $gatewayNote
            : 'Payment was not completed.');
        redirect('/advertise');
    }
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

        // A crafted POST asking for card payment on a site that cannot verify one is refused here, before
        // an advert exists, rather than after — the alternative is an advert on the books that nobody can
        // pay for and no admin can collect, which is worse than an error message.
        //
        // The form only offers the online option when Payhub::configured(), so this is the branch a
        // request that never saw the form takes.
        if ($paymentMethod === 'online' && !Payhub::configured()) {
            keepFormOld($_POST);
            flash('advertise_error', 'Card payment is not available on this site right now. Please choose bank transfer.');
            redirect('/advertise');
        }
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

    // Find or create publisher, within the church being served. The same advertiser buying space on two
    // churches gets an account on each rather than one account whose adverts belong to a church it does
    // not — and each account needs its own portal token anyway.
    [$tenantClause, $tenantParams] = tenantScope();
    $stmt = $pdo->prepare('SELECT id, token, tenant_id FROM ad_publishers WHERE email = ? AND ' . $tenantClause . ' LIMIT 1');
    $stmt->execute(array_merge([$pubEmail], $tenantParams));
    $pub = $stmt->fetch();
    if ($pub) {
        $publisherId = (int) $pub['id'];
        $pubToken = $pub['token'];
        $publisherTenant = (int) $pub['tenant_id'];
    } else {
        $pubToken = bin2hex(random_bytes(24));
        $publisherTenant = (int) (Tenant::id() ?? 0);
        $stmt = $pdo->prepare('INSERT INTO ad_publishers (name, email, phone, token, tenant_id) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$pubName, $pubEmail, $pubPhone ?: null, $pubToken, $publisherTenant]);
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

    $stmt = $pdo->prepare('INSERT INTO ads (publisher_id, tenant_id, title, media_type, file_path, thumbnail_path, destination_url, target_platform, duration_days, price, is_free, display_frequency, payment_status, payment_method, payment_proof_path, payment_reference, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")');
    $stmt->execute([
        $publisherId, $publisherTenant, $title, $mediaType, $filePath, $thumbPath, $destUrl ?: null, $targetPlatform,
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

    // Card payment. The advertiser pays on THIS site now — the checkout page below renders the gateway's
    // inline checkout — so this is a redirect to our own page rather than to PayHub's website, and the
    // browser is never handed off. It is a redirect and not a render because a render would re-run this
    // whole handler on a refresh, creating a second advert and a second attempt for one payment.
    if (!$isFree && $paymentMethod === 'online') {
        // Kept in the session because the checkout page is the page that takes money. The reference is
        // ours and random, but a URL that can be handed to somebody else will be, so the page checks that
        // the session asking for it is the session that created it.
        $_SESSION['ad_checkout_ref'] = $reference;
        clearFormOld();
        redirect('/advertise/checkout?ref=' . urlencode($reference));
    }

    clearFormOld();
    flash('advertise_sent', '1');
    redirect('/advertise?sent=1');
});

/**
 * Loads an advert by its payment reference, within the church being served.
 *
 * Shared by the three money routes below so they cannot disagree about which advert a reference names —
 * the same reasoning as `Payhub::amountInKobo()` being the only conversion: one answer to one question.
 *
 * @return array<string, mixed>|null
 */
$loadAdByReference = function (string $reference): ?array {
    if (trim($reference) === '') {
        return null;
    }
    $pdo = Database::getInstance()->getConnection();
    [$tenantClause, $tenantParams] = tenantScope(null, 'a.tenant_id');

    // Resolved through the ATTEMPT, not through `ads.payment_reference`.
    //
    // A retry gets its own reference — a gateway identifies a transaction by its reference, so re-offering
    // one it has already seen is asking it to answer about the previous attempt. That makes the reference an
    // identifier for the attempt, and an older attempt's return URL has to keep routing after a newer one
    // exists. `ads.payment_reference` tracks the newest attempt; `ad_payments.reference` remembers all of
    // them, and it is the one that can answer the question.
    $stmt = $pdo->prepare('SELECT a.*, p.name AS publisher_name, p.email AS publisher_email, p.token AS publisher_token, pay.reference AS attempt_reference
                           FROM ad_payments pay
                           JOIN ads a ON a.id = pay.ad_id
                           JOIN ad_publishers p ON p.id = a.publisher_id
                           WHERE pay.reference = ? AND ' . $tenantClause . ' LIMIT 1');
    $stmt->execute(array_merge([trim($reference)], $tenantParams));
    $row = $stmt->fetch();

    if ($row) {
        return $row;
    }

    // An advert whose attempt row is missing: the submission handler writes both, so this is a state it
    // cannot normally produce, and the fallback exists so that a half-written advert is still reachable
    // rather than showing the advertiser a 404 for a payment they may have made.
    $stmt = $pdo->prepare('SELECT a.*, p.name AS publisher_name, p.email AS publisher_email, p.token AS publisher_token, a.payment_reference AS attempt_reference
                           FROM ads a JOIN ad_publishers p ON p.id = a.publisher_id
                           WHERE a.payment_reference = ? AND ' . $tenantClause . ' LIMIT 1');
    $stmt->execute(array_merge([trim($reference)], $tenantParams));

    return $stmt->fetch() ?: null;
};

/** How many online attempts this advert has actually failed. The retry rule is measured on this. */

/*
 * The webhook — how PayHub tells this site that money arrived when the browser never came back.
 *
 * Per the API reference: the event is `charge.success`, the reference is at `data.reference`, and the
 * signature is `HMAC-SHA256(raw body, secret)` in `X-Payhub-Signature`. An unverifiable webhook is refused
 * rather than trusted, and an unconfigured secret key refuses every one — the safe way round.
 *
 * Two things it used to get wrong, both of which lost money:
 *
 * 1. It resolved the advert by `ads.payment_reference`, which tracks only the NEWEST attempt. A webhook for
 *    any earlier attempt therefore matched nothing, so the attempt row was marked successful while the
 *    advert stayed unpaid. It now resolves through the same attempt-reference lookup the return URL uses.
 * 2. It wrote payment state by hand instead of calling `AdPayments::recordOutcome()`. That duplicated the
 *    paid guard and the attempt-counter sync in a second place, which is the class of drift 7h-0 removed
 *    from the gateway client. Both money paths now go through the one decision.
 *
 * It is registered here, below the lookup, because a closure's `use` binds at creation — above this point
 * the variable does not exist yet.
 */
$router->post('/payment/payhub/webhook', function () use ($loadAdByReference) {
    $pdo = Database::getInstance()->getConnection();
    $body = (string) file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_PAYHUB_SIGNATURE'] ?? '';

    if (!Payhub::verifyWebhook($body, $signature)) {
        http_response_code(401);
        exit('Invalid signature');
    }

    $payload = json_decode($body, true);
    $event = (string) ($payload['event'] ?? '');
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $reference = trim((string) ($data['reference'] ?? ''));

    // Anything we have no use for is still acknowledged, or the gateway retries it for ever.
    if ($event === 'charge.success' && $reference !== '') {
        if (str_starts_with($reference, 'GIVE_') || str_starts_with($reference, 'DON_')) {
            // Giving has no attempt rows; the donation row is its own record.
            if ((string) ($data['status'] ?? '') === 'success' || !empty($data['paid'])) {
                $pdo->prepare('UPDATE donations SET payment_status = "completed" WHERE payment_reference = ?')
                    ->execute([$reference]);
            }
        } else {
            $ad = $loadAdByReference($reference);
            if ($ad !== null) {
                // The shape `Payhub::verify()` returns, built from a payload whose signature we have
                // already checked. The decision itself belongs to AdPayments, exactly as on the return URL.
                AdPayments::recordOutcome($ad, [
                    'paid' => ((string) ($data['status'] ?? '') === 'success') || !empty($data['paid']),
                    'reason' => (string) ($data['gateway_response'] ?? ''),
                    'error' => '',
                    'raw' => $data,
                ], $reference);
            }
        }
    }

    http_response_code(200);
    echo json_encode(['status' => 'success']);
    exit;
});

// The page that takes the money, on this site.
$router->get('/advertise/checkout', function () use ($loadAdByReference) {
    $ref = trim((string) ($_GET['ref'] ?? ''));

    /*
     * The REFERENCE is the credential here, not the session.
     *
     * This used to 404 unless the session that created the reference matched — and that is exactly what
     * made the page disappear for the advertiser it was built for. A revisit, a restored tab, a link opened
     * in another browser, or a session that expired between the form and the payment all produced a 404 on
     * the one screen where somebody is trying to give the church money.
     *
     * `Payhub::reference()` is 16 hex characters from `random_bytes` — the same class of unguessable bearer
     * token the publisher portal already accepts as the credential for a whole account. Holding it is the
     * authorisation. What must NOT be reachable is a reference that resolves to no advert, and that is still
     * a 404 below.
     */
    if ($ref === '') {
        http_response_code(404);
        render('404', [], true);
        return;
    }

    $ad = $loadAdByReference($ref);
    if ($ad === null) {
        http_response_code(404);
        render('404', [], true);
        return;
    }

    // Keep the session pointing at this attempt, so the hosted route and a retry agree with this page.
    $_SESSION['ad_checkout_ref'] = $ref;

    // Already settled — a refresh, a back button, a bookmarked step. Sending them to the report is the
    // only answer that cannot take a second payment for an advert that is already paid for.
    if ((string) $ad['payment_status'] === 'paid') {
        redirect('/advertise/return?ref=' . urlencode($ref));
    }

    // Bank transfer has become the only option, or the advert is not an online one. Either way this page
    // must not offer a card payment; 7h-4 owns the rule that decides when, so until then the advert simply
    // is not sent here.
    if ((string) $ad['payment_method'] !== 'online') {
        redirect('/advertise?sent=1');
    }

    render('advertise-checkout', [
        'ad' => $ad,
        'returnTo' => '/advertise/return',
        'metaTitle' => 'Complete your payment',
    ]);
});

// The hosted checkout, reached from the button on the page above. Still inside the site as far as the
// advertiser is concerned — they chose it — but it is the gateway's page, which is why it is a separate
// deliberate step rather than what happens by default.
$router->post('/advertise/hosted', function () use ($loadAdByReference) {
    Csrf::requireValid();

    $ref = trim((string) ($_POST['ref'] ?? ''));

    // Same rule as the page above: the reference is the credential. See the note there.
    if ($ref === '') {
        http_response_code(404);
        render('404', [], true);
        return;
    }

    $ad = $loadAdByReference($ref);
    if ($ad === null || (string) $ad['payment_status'] === 'paid') {
        redirect('/advertise/return?ref=' . urlencode($ref));
    }

    $_SESSION['ad_checkout_ref'] = $ref;

    $result = Payhub::initialize([
        'email' => (string) $ad['publisher_email'],
        // NAIRA, not kobo: the hosted checkout renders the figure it is given as naira. See
        // Payhub::amountInNaira() — sending kobo here charged ₦900,000 for a ₦9,000 advert.
        'amount' => Payhub::amountInNaira((float) $ad['price']),
        'reference' => $ref,
        'name' => (string) $ad['publisher_name'],
        'callback_url' => baseUrl('advertise/return?ref=' . urlencode($ref)),
        'metadata' => ['ad_id' => (int) $ad['id'], 'publisher_id' => (int) $ad['publisher_id']],
    ]);

    if ($result['ok'] && $result['authorization_url'] !== '') {
        redirect($result['authorization_url']);
    }

    // The gateway could not be reached. Nothing has been charged, and the advert is untouched — the
    // advertiser is told rather than shown a page that implies a payment happened.
    flash('advertise_error', 'The card payment could not be started (' . $result['error']
        . '). Nothing has been charged — please try again, or pay by bank transfer.');
    redirect('/advertise/checkout?ref=' . urlencode($ref));
});

// Where the payment comes back to, and where the only decision about it is made.
//
// The browser is what arrives here, and a browser can be told to ask for any reference. So the reference is
// verified with the gateway **server-side**, and what the browser says about the outcome is worth nothing.
$router->get('/advertise/return', function () use ($loadAdByReference) {
    $ref = trim((string) ($_GET['ref'] ?? ''));

    $ad = $loadAdByReference($ref);
    if ($ad === null) {
        http_response_code(404);
        render('404', [], true);
        return;
    }

    // The browser is what arrives here, and a browser can be told to ask for any reference. So the gateway
    // is asked **server-side**, and what the browser claims the outcome was counts for nothing.
    //
    // What the answer means is `core/AdPayments.php`, not this closure: a route handler ends in
    // `redirect()`, which calls `exit`, so logic living here could never be driven from a harness. Every
    // branch of the decision is tested directly on that class instead.
    if ((string) $ad['payment_status'] === 'paid') {
        $result = ['outcome' => 'paid', 'reason' => ''];
    } else {
        $result = AdPayments::recordOutcome($ad, Payhub::verify($ref), $ref);
    }

    $fresh = $loadAdByReference($ref) ?? $ad;

    /*
     * A payment that succeeded goes to the advertiser's own dashboard, not to a report.
     *
     * The report page exists to explain a failure and to offer the retry and the bank-transfer proof; a
     * successful payment has nothing to explain, and the advertiser's next question is about the advert,
     * not the transaction. So they land where the advert lives, told what happened to their money and what
     * happens next — pending review and approval — with the advert itself listed below the message.
     *
     * A failure still renders the report, because retry and proof upload are the whole point of it.
     */
    if ($result['outcome'] === 'paid' && !empty($fresh['publisher_token'])) {
        flash('pub_success', 'Payment received — ₦' . number_format((float) $fresh['price'], 2)
            . ' for "' . (string) $fresh['title'] . '".'
            . ' Transaction status: PAID (reference ' . $ref . ').'
            . ' Your advert is now pending review and approval — we will email you the moment it goes live.');
        redirect('/ad-manager?token=' . rawurlencode((string) $fresh['publisher_token']));
    }

    render('advertise-return', [
        'ad' => $fresh,
        'reference' => $ref,
        'outcome' => $result['outcome'],
        'reason' => $result['reason'],
        'attempts' => (int) $fresh['payment_attempts'],
        'remaining' => AdPayments::remainingOnlineAttempts((int) $fresh['id']),
        // The retry offer is decided here and not in the view: whether the advertiser may try a card payment
        // again is a rule about their money, and a view is the wrong place for it.
        //
        // Deliberately NOT also requiring that the inline checkout is available. Those are different
        // questions: a church with a verification key but no public key can still take and confirm a card
        // payment, it just does so on the gateway's own page — and hiding the retry from that church's
        // advertisers would strand a payment they can perfectly well make. The checkout page chooses
        // between the frame and the hosted form; the report only decides whether to offer a retry.
        'canRetry' => (string) $fresh['payment_status'] !== 'paid'
            && AdPayments::canRetryOnline((int) $fresh['id']),
        'manualEnabled' => (bool) setting('manual_payment_enabled', 1),
        'manualInstructions' => (string) setting('manual_payment_instructions', ''),
    ]);
});

// Retry: opens a new attempt and sends the advertiser back to the checkout for it.
//
// A POST rather than a link, because it creates a payment attempt and a GET that creates anything is a GET
// that a mail client will pre-fetch, or a crawler will follow, or somebody will bookmark and reload.
$router->post('/advertise/retry', function () use ($loadAdByReference) {
    Csrf::requireValid();

    $ref = trim((string) ($_POST['ref'] ?? ''));

    /*
     * The same rule as the checkout page: the reference is the credential, not the session.
     *
     * This gate used to 404 on a session mismatch, so an advertiser whose session had changed could not
     * retry a payment the report page had just offered them — on the page whose entire purpose is to let
     * them try again. What must not be reachable is a reference that resolves to no advert, checked below.
     */
    if ($ref === '') {
        http_response_code(404);
        render('404', [], true);
        return;
    }

    $ad = $loadAdByReference($ref);
    if ($ad === null) {
        http_response_code(404);
        render('404', [], true);
        return;
    }

    if ((string) $ad['payment_status'] === 'paid') {
        redirect('/advertise/return?ref=' . urlencode($ref));
    }

    // Refused in the handler, not merely hidden in the view. Two failed attempts is the advertiser's own
    // rule and a hidden button is not an enforcement of it.
    if (!AdPayments::canRetryOnline((int) $ad['id'])) {
        flash('advertise_error', 'Card payment has not worked after two attempts. Please pay by bank transfer instead.');
        redirect('/advertise/return?ref=' . urlencode($ref));
    }

    $attempt = AdPayments::startAttempt($ad);

    // The session follows the newest attempt, so the earlier attempt's checkout page stops being payable the
    // moment a new one exists — otherwise going back in the browser would offer to charge twice.
    $_SESSION['ad_checkout_ref'] = $attempt['reference'];

    redirect('/advertise/checkout?ref=' . urlencode($attempt['reference']));
});

// The bank-transfer proof. What an advertiser uploads as evidence of a transfer they have made.
$router->post('/advertise/proof', function () use ($loadAdByReference) {
    Csrf::requireValid();

    $ref = trim((string) ($_POST['ref'] ?? ''));
    $ad = $loadAdByReference($ref);

    if ($ad === null) {
        http_response_code(404);
        render('404', [], true);
        return;
    }

    if ((string) $ad['payment_status'] === 'paid') {
        redirect('/advertise/return?ref=' . urlencode($ref));
    }

    $upload = $_FILES['payment_proof'] ?? null;
    if (!$upload || empty($upload['tmp_name']) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('advertise_error', 'Please choose the receipt or screenshot of your bank transfer.');
        redirect('/advertise/return?ref=' . urlencode($ref));
    }

    // The same processor the submission form has always used for a proof, so a receipt is stored one way.
    $proofName = MediaProcessor::processImage($upload['tmp_name'], UPLOADS_PATH . '/ads/proofs');
    if (!$proofName) {
        flash('advertise_error', 'That file could not be read as an image. A photo or a screenshot of the receipt is fine.');
        redirect('/advertise/return?ref=' . urlencode($ref));
    }

    AdPayments::recordProof($ad, 'ads/proofs/' . $proofName);

    // `pending_review` is not one of the three outcomes the report page renders, so the advertiser is sent
    // to the page that says what happens next rather than to one that would have to invent a fourth state.
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
    // Every other public POST handler validates CSRF; this one did not, while the
    // small church-name-flag form further down the page did. That is the wrong way
    // round, so the check now covers both forms.
    Csrf::requireValid();

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

// ---------------------------------------------------------------------------
// News & blog — the public half of the feature whose authoring screens live in admin/news.php.
//
// Registered in this order because the router takes the first pattern that matches: an archive URL has
// two segments after `/news`, so `/news/{slug}` could never have swallowed it — but the day somebody
// adds a second archive shape, the order is the thing that keeps it working.
$router->get('/news', function () {
    render('news', ['action' => 'index']);
});

$router->get('/news/category/{slug}', function (array $params) {
    render('news', ['action' => 'category', 'slug' => (string) $params['slug']]);
});

$router->get('/news/{slug}', function (array $params) {
    // Deliberately NOT Analytics::recordEntityBySlug(), which every other detail route uses. That helper
    // loads its row by slug with no church filter, and news is the one content type here whose slugs are
    // explicitly *not* globally unique — two churches may both publish `announcement`. Its `LIMIT 1` would
    // then be free to attribute the view to the other church's post. The post carries its own counter
    // instead (News::countView), which is church-scoped and is the number an editor actually wants.
    render('news-detail', ['slug' => (string) $params['slug']]);
});

// The news feed, beside the podcast feed and shaped exactly like it: served straight to the output
// rather than through render(), because its reader is a machine and the site's layout around the XML
// would make the document invalid.
$router->get('/news.xml', function () {
    require VIEWS_PATH . '/news-feed.php';
});

$router->get('/series', function () {
    render('series');
});

$router->get('/series/{slug}', function (array $params) {
    render('series-detail', ['slug' => $params['slug']]);
});

// The podcast feed. Served directly rather than through render(), because it is a document
// for a machine: an RSS reader, Spotify, or Apple Podcasts. Wrapping it in the site layout
// would put HTML around the XML and break every one of them.
$router->get('/podcast.xml', function () {
    require VIEWS_PATH . '/podcast.php';
});

// A human-readable page explaining how to subscribe, for people who are not podcast apps.
$router->get('/podcast', function () {
    render('podcast-page');
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

// A single campaign. Renders the same giving page in campaign mode rather than a second view, so the
// payment flow, the forms and the validation cannot drift apart between "give" and "give to this".
$router->get('/give/c/{slug}', function (array $params) {
    $campaign = GivingCampaign::findBySlug((string) $params['slug']);
    if ($campaign === null) {
        http_response_code(404);
        render('404');
        return;
    }
    render('give', array('campaign' => $campaign));
});

// A pledge is taken on the campaign's own address rather than /give, because a promise has to be a
// promise *about* something. The money forms still post to /give with a campaign_id, so there is one
// payment path and only one place where a gift is recorded.
$router->post('/give/c/{slug}', function (array $params) {
    Csrf::requireValid();

    $campaign = GivingCampaign::findBySlug((string) $params['slug']);
    if ($campaign === null) {
        http_response_code(404);
        render('404');
        return;
    }

    if (empty($_POST['pledge'])) {
        redirect('/give/c/' . $campaign['slug']);
    }

    // Rate limited like the other public forms: a pledge creates a row somebody has to read, so an
    // open endpoint is an invitation to fill the church's list with rubbish.
    RateLimiter::require('pledge', 10, 600);

    $result = GivingCampaign::pledge((int) $campaign['id'], array(
        'donor_name' => (string) ($_POST['donor_name'] ?? ''),
        'donor_email' => (string) ($_POST['donor_email'] ?? ''),
        'donor_phone' => (string) ($_POST['donor_phone'] ?? ''),
        'amount' => (string) ($_POST['amount'] ?? ''),
        'promised_on' => (string) ($_POST['promised_on'] ?? ''),
        'note' => (string) ($_POST['note'] ?? ''),
    ));

    if (empty($result['ok'])) {
        flash('pledge_error', (string) ($result['errors'][0] ?? 'Please check the pledge form.'));
    } else {
        flash('pledge_ok', 'Thank you — your pledge has been recorded. It is not counted as money received; the church will see it as something promised.');
    }
    redirect('/give/c/' . $campaign['slug']);
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

    // A gift offered to a campaign is only accepted while that campaign is taking gifts. Silently
    // recording it as general giving instead would leave the donor believing they gave to a project
    // and the treasurer unable to tell them otherwise.
    $campaignId = (int) ($_POST['campaign_id'] ?? 0);
    $campaign = $campaignId > 0 ? GivingCampaign::find($campaignId) : null;
    if ($campaignId > 0 && ($campaign === null || !GivingCampaign::acceptsGifts($campaign))) {
        flash('give_error', $campaign === null
            ? 'That campaign is no longer available.'
            : 'Giving to "' . $campaign['title'] . '" has closed, so nothing was taken.');
        redirect($campaign !== null ? '/give/c/' . $campaign['slug'] : '/give');
    }

    if ($amount < 100) {
        flash('give_error', 'Giving amount must be at least ₦100.');
        redirect($campaign !== null ? '/give/c/' . $campaign['slug'] : '/give');
    }
    if ($donorEmail === '' || !filter_var($donorEmail, FILTER_VALIDATE_EMAIL)) {
        flash('give_error', 'Please provide a valid email address.');
        redirect($campaign !== null ? '/give/c/' . $campaign['slug'] : '/give');
    }

    if ($paymentMethod === 'manual_bank') {
        $fileUpload = $_FILES['receipt_file'] ?? null;
        if (!$fileUpload || empty($fileUpload['tmp_name']) || ($fileUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('give_error', 'Please upload a bank transfer receipt image or PDF proof.');
            redirect($campaign !== null ? '/give/c/' . $campaign['slug'] : '/give');
        }

        $receiptDir = UPLOADS_PATH . '/donations';
        if (!is_dir($receiptDir)) {
            @mkdir($receiptDir, 0775, true);
        }

        $ext = strtolower(pathinfo($fileUpload['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
            flash('give_error', 'Invalid file type. Upload JPG, PNG, WebP or PDF receipt.');
            redirect($campaign !== null ? '/give/c/' . $campaign['slug'] : '/give');
        }

        $fileName = 'receipt_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($fileUpload['tmp_name'], $receiptDir . '/' . $fileName)) {
            flash('give_error', 'Failed to save receipt file. Please try again.');
            redirect($campaign !== null ? '/give/c/' . $campaign['slug'] : '/give');
        }

        $ref = 'GIVE_MANUAL_' . strtoupper(bin2hex(random_bytes(6)));
        $stmt = $pdo->prepare('INSERT INTO donations (donor_name, donor_email, donor_phone, category, amount, currency, description, payment_method, payment_status, payment_reference, receipt_path, campaign_id) VALUES (?, ?, ?, ?, ?, "NGN", ?, "manual_bank", "pending", ?, ?, ?)');
        $stmt->execute([$donorName ?: 'Anonymous Giver', $donorEmail, $donorPhone ?: null, $category, $amount, $description ?: null, $ref, 'donations/' . $fileName, $campaign !== null ? (int) $campaign['id'] : null]);

        flash('give_success', 'Thank you! Your bank transfer receipt of ₦' . number_format($amount) . ' for ' . $category . ' has been submitted and is pending verification by our finance team.');
        redirect($campaign !== null ? '/give/c/' . $campaign['slug'] : '/give');
    }

    // Online Payment Gateway (Payhub)
    $ref = 'GIVE_' . strtoupper(bin2hex(random_bytes(8)));
    $stmt = $pdo->prepare('INSERT INTO donations (donor_name, donor_email, donor_phone, category, amount, currency, description, payment_method, payment_status, payment_reference, campaign_id) VALUES (?, ?, ?, ?, ?, "NGN", ?, "online", "pending", ?, ?)');
    $stmt->execute([$donorName ?: 'Anonymous Giver', $donorEmail, $donorPhone ?: null, $category, $amount, $description ?: null, $ref, $campaign !== null ? (int) $campaign['id'] : null]);

    $apiKey = Payhub::publicKey();
    $secKey = Payhub::secretKey();

    if (Payhub::configured()) {
        $callbackUrl = baseUrl('payment/payhub/callback?ref=' . urlencode($ref));

        $result = Payhub::initialize([
            // Kobo, per the gateway's documentation: `500000` is ₦5,000. This used to send naira here and
            // kobo in the advert flow, so one of the two was always going to be a hundred times out.
            // NAIRA, not kobo — the hosted route's unit. See Payhub::amountInNaira().
            'amount' => Payhub::amountInNaira($amount),
            'email' => $donorEmail,
            'reference' => $ref,
            'callback_url' => $callbackUrl,
            'description' => 'Church Giving: ' . $category . ($description ? ' - ' . substr($description, 0, 80) : ''),
            'currency' => 'NGN',
        ]);

        if ($result['ok'] && $result['authorization_url'] !== '') {
            redirect($result['authorization_url']);
        }

        /*
         * The gateway could not be reached. Say so and stop.
         *
         * What used to be here was a "Sandbox / fallback mode" that marked the donation **completed** and
         * thanked the giver for money that was never taken. On any install where a giver chose online
         * giving, the church's donation record said it had been paid. An honest dead end is the only
         * acceptable behaviour when the alternative is a lie in the accounts.
         */
        $pdo->prepare('UPDATE donations SET payment_status = "failed" WHERE payment_reference = ?')->execute([$ref]);

        flash('give_error', 'Online giving could not be started just now (' . $result['error'] . '). '
            . 'Nothing has been charged. Please use the bank transfer option below, or try again in a few minutes.');
        redirect($campaign !== null ? '/give/c/' . $campaign['slug'] : '/give');
    }

    // Reachable only with a crafted request: the page does not offer online giving while the gateway is
    // unconfigured. Refused rather than recorded as a gift.
    $pdo->prepare('UPDATE donations SET payment_status = "failed" WHERE payment_reference = ?')->execute([$ref]);

    flash('give_error', 'Online giving is not available at the moment. Nothing has been charged — '
        . 'please use the bank transfer option below.');
    redirect($campaign !== null ? '/give/c/' . $campaign['slug'] : '/give');
});

$router->get('/live', function () {
    render('live');
});

$router->get('/prayer', function () {
    render('prayer');
});

// A prayer request taken on the page itself, so it is not lost when JavaScript
// fails. The app and the page's remote form both use POST /api/prayer, which
// shares PrayerWall::submit() with this handler.
$router->post('/prayer', function () {
    Csrf::requireValid();
    RateLimiter::require('prayer', 10, 300);

    // Honeypot: a real visitor never fills the hidden field.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        redirect('/prayer');
    }

    $result = PrayerWall::submit(
        (string) ($_POST['name'] ?? ''),
        (string) ($_POST['email'] ?? ''),
        (string) ($_POST['message'] ?? ''),
        !empty($_POST['is_public']),
        !empty($_POST['is_anonymous'])
    );

    if (isset($result['errors'])) {
        keepFormOld($_POST);
        flash('prayer_error', (string) ($result['errors'][0] ?? 'Please check the form.'));
    } else {
        clearFormOld();
        flash('prayer_ok', 'Your prayer request has been received. Our team is praying with you.');
    }

    redirect('/prayer');
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

// The language switcher. A GET that records one preference and returns to the page it was clicked on, so
// it carries no CSRF token: it changes no data, and the worst a forged link can do is choose a language.
$router->get('/lang/{code}', function (array $params) {
    $code = strtolower((string) ($params['code'] ?? ''));

    // An unknown code is refused rather than turned into a 404: the visitor clicked a link on a real page,
    // so they go back to it. `remember()` checks the code against the catalogues on disk itself, so the
    // cookie can never hold a language that `Lang::current()` would then have to ignore.
    Lang::remember($code);

    // Already reduced to a same-site path by `Lang::safeNext()` — see the note there about why a switcher
    // that can be pointed anywhere is an open redirect.
    redirect(Lang::safeNext($_GET['next'] ?? '/'));
});

$router->get('/sitemap.xml', function () {
    require VIEWS_PATH . '/sitemap.php';
});

// The web app manifest and the offline page — what makes the site installable to a home screen and
// openable without a connection. Both are public on purpose: the manifest is built from this church's
// own settings, so it cannot be a static file, and the offline page is precached by public/sw.js.
$router->get('/manifest.webmanifest', function () {
    require VIEWS_PATH . '/manifest.php';
});

$router->get('/offline', function () {
    render('offline', [
        'metaTitle' => 'Offline',
        // Never indexed: it is a state, not a page. A search result pointing here is a dead end that
        // says the church's site is broken.
        'metaRobots' => 'noindex, nofollow',
    ]);
});

// ---------------------------------------------------------------------------
// Member accounts — the first visitor-facing logins in this project.
//
// Deliberately not /admin: a member session can never satisfy Auth::check(), and
// every lookup here is tenant-scoped, so a member of one church cannot sign in on
// another church's site. See core/MemberAuth.php for why the two are kept apart.
// ---------------------------------------------------------------------------

$router->get('/member/register', function () {
    if (MemberAuth::check()) {
        redirect('/member');
    }
    render('member/register', [
        'metaTitle' => 'Create your account',
        'metaRobots' => 'noindex, nofollow',
    ]);
});

$router->post('/member/register', function () {
    Csrf::requireValid();

    // Honeypot: bots fill hidden fields, humans never see them.
    if (trim((string) ($_POST['company'] ?? '')) !== '') {
        redirect('/member/register?sent=1');
    }

    if (!RateLimiter::attempt('member_register', clientIp(), 5, 900)) {
        keepFormOld($_POST);
        flash('member_error', 'Too many attempts — please wait a few minutes and try again.');
        redirect('/member/register');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $email = (string) ($_POST['email'] ?? '');
    $phone = (string) ($_POST['phone'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    $errors = Member::validateRegistration($name, $email, $password, $confirm);
    if (!$errors) {
        $result = Member::register($name, $email, $phone, $password);
        if (!empty($result['errors'])) {
            $errors = $result['errors'];
        }
    }

    if ($errors) {
        keepFormOld($_POST);
        flash('member_error', implode(' ', $errors));
        redirect('/member/register');
    }

    $mailSent = Member::sendVerification(Member::normaliseEmail($email), $name, (string) $result['token']);

    // Sign them in rather than making them wait on an email to use what they just
    // created — and because a broken SMTP setting would otherwise strand them.
    MemberAuth::login((int) $result['id']);

    flash('member_notice', $mailSent
        ? 'Welcome! We have emailed you a link to confirm your address.'
        : 'Welcome! We could not send the confirmation email — please ask an admin to check the mail settings.');
    redirect('/member');
});

$router->get('/member/login', function () {
    if (MemberAuth::check()) {
        redirect('/member');
    }
    render('member/login', [
        'metaTitle' => 'Sign in',
        'metaRobots' => 'noindex, nofollow',
    ]);
});

$router->post('/member/login', function () {
    Csrf::requireValid();

    if (!RateLimiter::attempt('member_login', clientIp(), 10, 900)) {
        flash('member_error', 'Too many attempts — please wait a few minutes and try again.');
        redirect('/member/login');
    }

    $email = (string) ($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if (!MemberAuth::attempt($email, $password)) {
        keepFormOld(['email' => $email]);
        flash('member_error', 'Those details did not match an account.');
        redirect('/member/login');
    }

    // Where they were headed, remembered by MemberAuth::requireLogin(). Only
    // /member paths are honoured, so a tampered session value cannot turn this
    // into an open redirect off-site.
    $intended = (string) ($_SESSION['member_intended'] ?? '');
    unset($_SESSION['member_intended']);
    redirect(($intended !== '' && str_starts_with($intended, '/member')) ? $intended : '/member');
});

$router->post('/member/logout', function () {
    Csrf::requireValid();
    MemberAuth::logout();
    flash('member_notice', 'You have been signed out.');
    redirect('/');
});

$router->get('/member/verify', function () {
    $memberId = Member::verify((string) ($_GET['token'] ?? ''));
    if ($memberId === null) {
        flash('member_error', 'That confirmation link is no longer valid. Sign in and we will send a fresh one.');
        redirect('/member/login');
    }
    MemberAuth::login($memberId);
    flash('member_notice', 'Your email address is confirmed. Welcome!');
    redirect('/member');
});

$router->get('/member/forgot-password', function () {
    render('member/forgot-password', [
        'metaTitle' => 'Reset your password',
        'metaRobots' => 'noindex, nofollow',
    ]);
});

$router->post('/member/forgot-password', function () {
    Csrf::requireValid();

    if (!RateLimiter::attempt('member_forgot', clientIp(), 5, 900)) {
        flash('member_error', 'Too many attempts — please wait a few minutes and try again.');
        redirect('/member/forgot-password');
    }

    $email = (string) ($_POST['email'] ?? '');
    $token = Member::issueReset($email);

    if ($token !== null) {
        $member = Member::findByEmail($email);
        if ($member !== null) {
            Member::sendReset(Member::normaliseEmail($email), (string) $member['name'], $token);
        }
    }

    // The same answer whether or not the address exists: which emails are registered
    // is not something an anonymous visitor gets to ask.
    flash('member_notice', 'If that address has an account, a reset link is on its way.');
    redirect('/member/forgot-password?sent=1');
});

$router->get('/member/reset-password', function () {
    $token = (string) ($_GET['token'] ?? '');
    render('member/reset-password', [
        'metaTitle' => 'Choose a new password',
        'metaRobots' => 'noindex, nofollow',
        'token' => $token,
        'tokenValid' => Member::tokenLooksValid($token),
    ]);
});

$router->post('/member/reset-password', function () {
    Csrf::requireValid();

    $token = (string) ($_POST['token'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if (strlen($password) < 8 || $password !== $confirm) {
        flash('member_error', 'Choose a password of at least 8 characters, and make sure both boxes match.');
        redirect('/member/reset-password?token=' . urlencode($token));
    }

    $memberId = Member::resetPassword($token, $password);
    if ($memberId === null) {
        flash('member_error', 'That reset link has expired or was already used. Please request a new one.');
        redirect('/member/forgot-password');
    }

    MemberAuth::login($memberId);
    flash('member_notice', 'Your password has been changed.');
    redirect('/member');
});

$router->get('/member', function () {
    MemberAuth::requireLogin();
    $member = MemberAuth::member();
    if ($member === null) {
        // Session outlived the row, or the tenant changed underneath it.
        MemberAuth::logout();
        redirect('/member/login');
    }
    $memberId = (int) $member['id'];
    $email = (string) $member['email'];

    // The reading plan, if they are on one. `next_day` is the first day they have not ticked,
    // which is what the dashboard offers to tick — not today's date, because a plan is a list of
    // readings rather than a calendar, and a member catching up after a week away should be
    // offered the reading they actually stopped at.
    $plan = ReadingPlan::currentFor($memberId);
    $planProgress = null;
    $planReadings = array();
    if ($plan !== null) {
        $planProgress = ReadingPlan::progressFor($memberId, (int) $plan['id']);
        if ($planProgress['next_day'] !== null) {
            $planReadings = ReadingPlan::passagesForDay((int) $plan['id'], (int) $planProgress['next_day']);
        }
    }

    render('member/dashboard', [
        'metaTitle' => 'My account',
        'metaRobots' => 'noindex, nofollow',
        'member' => $member,
        'prefs' => Member::preferences($member),
        'plan' => $plan,
        'planProgress' => $planProgress,
        'planReadings' => $planReadings,
        'planChoices' => ReadingPlan::published(),
        // The reads take the current fingerprint and email alongside the member id, so
        // activity from this browser appears straight away rather than at the next
        // sign-in when the claim runs.
        'saved' => MemberActivity::savedPosts($memberId, Fingerprint::hash(), 24),
        'giving' => MemberActivity::givingHistory($memberId, $email, 25),
        'totals' => MemberActivity::givingTotals($memberId, $email),
        'homeCell' => Member::homeCell($member),
        // Roster invitations waiting on this member. Capped, because the dashboard is a summary —
        // "what am I on next" rather than a full history of everything they have ever served.
        'serving' => ServiceRoster::upcomingForMember($memberId, 5),
        'servingPending' => ServiceRoster::pendingForMember($memberId),
    ]);
});

$router->post('/member', function () {
    Csrf::requireValid();
    MemberAuth::requireLogin();

    $member = MemberAuth::member();
    if ($member === null) {
        MemberAuth::logout();
        redirect('/member/login');
    }

    $memberId = (int) $member['id'];

    switch ((string) ($_POST['do'] ?? '')) {
        case 'profile':
            $phone = (string) ($_POST['phone'] ?? '');
            $waConsent = !empty($_POST['whatsapp_consent']);
            $previousMsisdn = (string) ($member['phone'] ?? '');

            Member::updateProfile(
                $memberId,
                (string) ($_POST['name'] ?? $member['name']),
                $phone,
                !empty($_POST['sms_consent']),
                $waConsent
            );

            // A WhatsApp broadcast only reads `wa_opt_ins`, so this tick has to reach it or
            // it is a checkbox that changes nothing. WaCampaign owns that table, so the write
            // sits with the read rather than being duplicated here.
            $msisdn = Member::normalisePhone($phone);
            if ($msisdn !== null) {
                WaCampaign::setOptIn($msisdn, $waConsent, 'member');
            }

            // Changing to a new number must not leave the old one opted in for whoever
            // inherits it.
            if ($previousMsisdn !== '' && $previousMsisdn !== $msisdn) {
                WaCampaign::setOptIn($previousMsisdn, false, 'member');
            }

            flash('member_notice', 'Your details are saved.');
            break;

        case 'prefs':
            Member::savePreferences($memberId, (array) ($_POST['prefs'] ?? []));
            flash('member_notice', 'Your notification choices are saved.');
            break;

        case 'serving':
            // A member answering their own roster invitation. The ownership check lives in
            // ServiceRoster::respondAsMember rather than here, so a guessed assignment id changes
            // nothing and no future screen can forget to make the check itself.
            $answer = ServiceRoster::respondAsMember(
                $memberId,
                (int) ($_POST['assignment_id'] ?? 0),
                (string) ($_POST['status'] ?? '')
            );

            if (empty($answer['ok'])) {
                flash('member_error', implode(' ', $answer['errors'] ?? array('We could not record that answer.')));
            } else {
                flash('member_notice', (string) ($_POST['status'] ?? '') === 'accepted'
                    ? 'Thank you — the team can see you are coming.'
                    : 'Thank you for saying — the team will ask somebody else.');
            }
            break;

        case 'resend':
            $token = Member::reissueVerification($memberId);
            $sent = $token !== null
                && Member::sendVerification((string) $member['email'], (string) $member['name'], $token);
            flash($sent ? 'member_notice' : 'member_error', $sent
                ? 'A fresh confirmation link is on its way.'
                : 'We could not send that email — please ask an admin to check the mail settings.');
            break;

        case 'homecell':
            // Typed rather than picked from a dropdown: this organisation has hundreds of
            // units, and a select listing them all is unusable on a phone. Phase 6's cell
            // finder (meeting day, address, nearest-to-me) is what replaces this.
            $typed = Unit::nameFor((string) ($_POST['unit_name'] ?? ''));

            if ($typed === '') {
                Member::setHomeCell($memberId, null);
                flash('member_notice', 'Your home church has been cleared.');
                break;
            }

            $unit = Unit::findByNameAnywhere($typed);
            if ($unit === null) {
                flash('member_error', 'We could not find a church called "' . $typed . '". Check the spelling, or ask an admin to add it.');
                break;
            }

            if (!Member::setHomeCell($memberId, (int) $unit['id'])) {
                flash('member_error', 'That name matches a group rather than one church. Please type the church itself.');
                break;
            }

            flash('member_notice', 'Your home church is saved.');
            break;
    }

    redirect('/member');
});

// The whole plan on one page. Every day is a checkbox in a single form, because the form replaces
// the member's set of read days wholesale — that is what makes unticking work — and a form that
// only covered one page of days would clear every day on the others.
$router->get('/member/plan', function () {
    MemberAuth::requireLogin();
    $member = MemberAuth::member();
    if ($member === null) {
        MemberAuth::logout();
        redirect('/member/login');
    }

    $plan = ReadingPlan::currentFor((int) $member['id']);
    if ($plan === null) {
        // The picker lives on the dashboard, so there is nothing to show here yet.
        redirect('/member');
    }

    render('member/plan', [
        'metaTitle' => $plan['name'],
        'metaRobots' => 'noindex, nofollow',
        'member' => $member,
        'plan' => $plan,
        'progress' => ReadingPlan::progressFor((int) $member['id'], (int) $plan['id']),
        'days' => ReadingPlan::days((int) $plan['id']),
        'read' => ReadingPlan::completedDays((int) $member['id'], (int) $plan['id']),
    ]);
});

$router->post('/member/plan', function () {
    Csrf::requireValid();
    MemberAuth::requireLogin();

    $member = MemberAuth::member();
    if ($member === null) {
        MemberAuth::logout();
        redirect('/member/login');
    }
    $memberId = (int) $member['id'];

    switch ((string) ($_POST['do'] ?? '')) {
        case 'join':
            // 0 means "leave", and is allowed: progress is kept, so coming back finds their place.
            $planId = (int) ($_POST['plan_id'] ?? 0);
            if ($planId > 0 && ReadingPlan::find($planId) === null) {
                flash('member_error', 'That reading plan is no longer available.');
                break;
            }
            ReadingPlan::join($memberId, $planId);
            flash('member_notice', $planId > 0
                ? 'You are now following that plan.'
                : 'You have left the plan. Your progress has been kept.');
            break;

        case 'tick':
            // Marks one day and nothing else, so the quick action on the dashboard can never
            // untick something by omission.
            $plan = ReadingPlan::currentFor($memberId);
            if ($plan === null) {
                flash('member_error', 'Choose a reading plan first.');
                break;
            }
            $day = (int) ($_POST['day'] ?? 0);
            if ($day < 1 || $day > (int) $plan['days_count']) {
                flash('member_error', 'That is not a day of this plan.');
                break;
            }
            ReadingPlan::markRead($memberId, (int) $plan['id'], $day);
            flash('member_notice', 'Day ' . $day . ' is marked as read.');
            break;

        case 'days':
            $plan = ReadingPlan::currentFor($memberId);
            if ($plan === null) {
                flash('member_error', 'Choose a reading plan first.');
                break;
            }
            $planId = (int) $plan['id'];
            $total = (int) $plan['days_count'];

            // Whatever was submitted is the whole truth. Out-of-range values are dropped rather
            // than trusted, so a hand-edited form cannot mark day 9000 or reach another plan.
            $wanted = array();
            foreach ((array) ($_POST['days'] ?? array()) as $day) {
                $day = (int) $day;
                if ($day >= 1 && $day <= $total) {
                    $wanted[$day] = $day;
                }
            }

            $already = ReadingPlan::completedDays($memberId, $planId);
            foreach ($wanted as $day) {
                if (!isset($already[$day])) {
                    ReadingPlan::markRead($memberId, $planId, $day);
                }
            }
            foreach ($already as $day) {
                if (!isset($wanted[$day])) {
                    ReadingPlan::unmarkRead($memberId, $planId, $day);
                }
            }

            flash('member_notice', count($wanted) . ' day' . (count($wanted) === 1 ? '' : 's') . ' marked as read.');
            redirect('/member/plan');

        default:
            break;
    }

    redirect('/member/plan');
});

$router->get('/devotional', function () {
    render('devotional', [
        'metaTitle' => 'Daily Devotional',
        'metaDescription' => 'A short devotional for each day from ' . setting('site_title') . '.',
        'date' => null,
    ]);
});

// A permalink per day, so a devotional can be shared on WhatsApp or read again later without
// hunting through the archive. A malformed date is a 404 rather than a page for today, which
// would quietly hand back the wrong entry under the right-looking URL.
$router->get('/devotional/{date}', function (array $params) {
    $date = (string) ($params['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
        http_response_code(404);
        render('404');
        return;
    }
    render('devotional', [
        'metaTitle' => 'Devotional for ' . date('j F Y', strtotime($date)),
        'metaRobots' => 'noindex, follow',
        'date' => $date,
    ]);
});

// The site icon. A route rather than a file on disk on purpose: a real
// public/favicon.ico is served by the web server ahead of the front controller
// (.htaccess and public/router.php both skip existing files), which is why every
// site built from this code showed the same icon no matter what was uploaded.
// Do not add that file back — deleting it is what makes this reachable.
$router->get('/favicon.ico', function () {
    $path = (string) (setting('favicon_path') ?? '');
    if ($path !== '' && is_file(UPLOADS_PATH . '/' . $path)) {
        // processImage() stores WebP, but the type comes from the extension so a
        // differently stored icon still declares itself correctly.
        $types = [
            'webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
        ];
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=86400');
        readfile(UPLOADS_PATH . '/' . $path);
        exit;
    }
    // Nothing uploaded yet: a generated letter tile from the church's initial, so a
    // new site shows its own mark instead of the browser's blank placeholder.
    MediaProcessor::renderDynamicFavicon((string) setting('site_title', 'C'));
});
