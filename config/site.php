<?php
declare(strict_types=1);

/**
 * Static fallback defaults. Once installed, the authoritative copy of these
 * values lives in the `settings` DB table (editable at /admin/settings) —
 * this file only matters before install and as a fallback if the DB is down.
 */
return [
    'site_title'           => 'Grace & Life Church',
    'site_tagline'         => 'A place to belong, believe, and become',
    'logo_path'            => null,
    'favicon_path'         => null,
    'hero_tagline'         => 'Where Faith Comes Alive',
    'hero_scripture'       => '"For where two or three gather in my name, there am I with them." — Matthew 18:20',
    'hero_eyebrow'         => 'Welcome Home',
    'hero_image_path'      => null,
    'hero_cta_primary_label'   => 'Plan Your Visit',
    'hero_cta_primary_url'     => '/about',
    'hero_cta_secondary_label' => 'Watch the Feed',
    'hero_cta_secondary_url'   => '/feed',
    'contact_email'        => 'contact@example.org', // replace at /admin/settings after install
    'contact_phone'        => null,
    'address'              => null,
    'service_times'        => '[{"label":"Sunday Worship","time":"9:00 AM & 11:00 AM"},{"label":"Bible Study","time":"Wednesday 6:30 PM"}]',
    'facebook_url'         => null,
    'instagram_url'        => null,
    'youtube_url'          => null,
    'tiktok_url'           => null,
    'livestream_embed_url' => null,
    'livestream_is_live'   => 0,
    'giving_url'           => null,
    'footer_about_text'    => 'A place to belong, believe, and become — join us in person or online every week.',
    'meta_description'     => 'Grace & Life Church — sermons, events, and media from our community.',
    'bible_source'           => 'keyless', // 'keyless' or 'api_bible'
    'bible_api_key'         => null,
    'license_key'          => null,
    'ffmpeg_path'          => null, // e.g. ROOT_PATH . '/bin/ffmpeg/ffmpeg.exe' once a static build is downloaded
    'timezone'             => 'Africa/Lagos',
    'app_env'              => 'local',
];
