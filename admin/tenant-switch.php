<?php
declare(strict_types=1);

/**
 * Super-admin tenant switcher (SaaS).
 *
 * Only a super admin may act as, or look at, another church. This endpoint does
 * the full authorisation check once, then hands the session over to
 * Tenant::setCurrent(..., allowed: true) — which is what lets Tenant::resolve()
 * trust the session on later requests without running an authorisation query on
 * every single page load.
 */

Auth::requireRole('admin');

if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    exit('Only the super admin can switch between churches.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    $raw = (string) ($_POST['tenant_id'] ?? '');
    $target = $raw === '' ? null : (int) $raw;

    if ($target === null) {
        Tenant::setCurrent(null, true);
        flash('success', 'Switched back to the default church.');
    } else {
        $tenant = Tenant::find($target);
        if ($tenant === null) {
            flash('error', 'That church no longer exists.');
        } else {
            Tenant::setCurrent($target, true);
            flash('success', 'Now managing ' . (string) $tenant['name'] . '.');
        }
    }

    // Return the user where they were (same-origin admin paths only, so the
    // `return` field can never be used as an open redirect).
    $return = (string) ($_POST['return'] ?? '/admin/settings');
    if ($return === '' || $return[0] !== '/' || str_starts_with($return, '//')) {
        $return = '/admin/settings';
    }
    redirect($return);
}

redirect('/admin/settings');
