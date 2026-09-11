<?php
declare(strict_types=1);

/** GET /api/settings — public branding/config so the app can self-theme without hardcoding it. */

$s = settings();

jsonResponse(['status' => 'success', 'data' => [
    'site_title' => $s['site_title'] ?? null,
    'site_tagline' => $s['site_tagline'] ?? null,
    'hero_tagline' => $s['hero_tagline'] ?? null,
    'hero_scripture' => $s['hero_scripture'] ?? null,
    'logo_url' => uploadUrl($s['logo_path'] ?? null),
    'favicon_url' => uploadUrl($s['favicon_path'] ?? null),
    'contact_email' => $s['contact_email'] ?? null,
    'contact_phone' => $s['contact_phone'] ?? null,
    'address' => $s['address'] ?? null,
    'service_times' => $s['service_times'] ? (json_decode((string) $s['service_times'], true) ?: []) : [],
    'social' => [
        'facebook' => $s['facebook_url'] ?? null,
        'instagram' => $s['instagram_url'] ?? null,
        'youtube' => $s['youtube_url'] ?? null,
        'tiktok' => $s['tiktok_url'] ?? null,
        'twitter' => $s['twitter_url'] ?? null,
    ],
    'go_declaration' => [
        'enabled' => (bool) ($s['go_declaration_enabled'] ?? false),
        'title' => $s['go_declaration_title'] ?? "G.O. Declaration",
        'text' => $s['go_declaration_text'] ?? null,
        'mode' => $s['go_declaration_mode'] ?? 'marquee',
    ],
    'livestream' => [
        'embed_url' => $s['livestream_embed_url'] ?? null,
        'is_live' => (bool) ($s['livestream_is_live'] ?? false),
    ],
    'giving_url' => $s['giving_url'] ?? null,
]]);
