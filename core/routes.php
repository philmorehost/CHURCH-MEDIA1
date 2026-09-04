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
