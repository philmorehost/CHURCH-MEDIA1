-- Church Media Management System — full schema
-- Applied once by the installer (Stage 2). Safe to re-run (IF NOT EXISTS everywhere).

SET NAMES utf8mb4;

-- SaaS tenants. A tenant is one church organisation; the installer seeds a
-- single default tenant, so a single-church install behaves exactly as before.
-- Tables added from the SaaS work onward carry `tenant_id`; the older content
-- tables are still single-tenant and are migrated in the Phase 7 rollout.
CREATE TABLE IF NOT EXISTS `tenants` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(80) NOT NULL,
  `domain` VARCHAR(190) NULL COMMENT 'Full host match, e.g. yaya.example.org',
  `subdomain` VARCHAR(80) NULL COMMENT 'Leading label match, e.g. yaya',
  `logo_path` VARCHAR(255) NULL,
  `primary_colour` VARCHAR(20) NULL,
  `plan` VARCHAR(40) NOT NULL DEFAULT 'standard',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_tenant_slug` (`slug`),
  UNIQUE KEY `uniq_tenant_domain` (`domain`),
  UNIQUE KEY `uniq_tenant_subdomain` (`subdomain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `tenants` (`id`, `name`, `slug`, `is_default`)
VALUES (1, 'Default Church', 'default', 1);

CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tenant_id` INT NULL COMMENT 'NULL = shared defaults; otherwise this row overrides them for one tenant',
  `site_title` VARCHAR(255) NOT NULL DEFAULT 'Grace & Life Church',
  `site_tagline` VARCHAR(255) NULL,
  `logo_path` VARCHAR(255) NULL,
  `favicon_path` VARCHAR(255) NULL,
  `hero_tagline` VARCHAR(255) NULL,
  `hero_scripture` VARCHAR(255) NULL,
  `hero_eyebrow` VARCHAR(120) NULL,
  `hero_image_path` VARCHAR(255) NULL,
  `hero_type` VARCHAR(20) NOT NULL DEFAULT 'gradient' COMMENT 'gradient | image | video_upload | youtube',
  `hero_video_path` VARCHAR(255) NULL,
  `hero_youtube_url` VARCHAR(500) NULL,
  `hero_cta_primary_label` VARCHAR(60) NULL,
  `hero_cta_primary_url` VARCHAR(500) NULL,
  `hero_cta_secondary_label` VARCHAR(60) NULL,
  `hero_cta_secondary_url` VARCHAR(500) NULL,
  `contact_email` VARCHAR(150) NULL,
  `contact_phone` VARCHAR(50) NULL,
  `address` VARCHAR(255) NULL,
  `service_times` TEXT NULL COMMENT 'JSON array of {label, time}',
  `facebook_url` VARCHAR(255) NULL,
  `instagram_url` VARCHAR(255) NULL,
  `youtube_url` VARCHAR(255) NULL,
  `tiktok_url` VARCHAR(255) NULL,
  `twitter_url` VARCHAR(255) NULL,
  `go_declaration_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `go_declaration_title` VARCHAR(150) NULL DEFAULT 'G.O. Declaration',
  `go_declaration_text` TEXT NULL,
  `go_declaration_mode` VARCHAR(20) NOT NULL DEFAULT 'marquee' COMMENT 'marquee | static',
  `livestream_embed_url` VARCHAR(500) NULL,
  `livestream_is_live` TINYINT(1) NOT NULL DEFAULT 0,
  `giving_url` VARCHAR(500) NULL,
  `app_download_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `app_download_url` VARCHAR(500) NULL,
  `app_download_pages` TEXT NULL COMMENT "'all' or comma-separated page paths",
  `app_redirect_mode` VARCHAR(12) NOT NULL DEFAULT 'off' COMMENT 'off | interstitial | force',
  `footer_about_text` TEXT NULL,
  `meta_description` VARCHAR(255) NULL,
  `bible_source` VARCHAR(20) NOT NULL DEFAULT 'keyless' COMMENT 'keyless or api_bible',
  `bible_api_key` VARCHAR(255) NULL COMMENT 'scripture.api.bible access token',
  `smtp_host` VARCHAR(255) NULL,
  `smtp_port` INT NULL,
  `smtp_secure` VARCHAR(10) NULL DEFAULT 'tls',
  `smtp_username` VARCHAR(255) NULL,
  `smtp_password` VARCHAR(255) NULL,
  `smtp_from` VARCHAR(255) NULL,
  `email_cpanel_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `email_cpanel_host` VARCHAR(255) NULL,
  `email_cpanel_user` VARCHAR(100) NULL,
  `email_cpanel_token` VARCHAR(255) NULL,
  `email_domain` VARCHAR(190) NULL COMMENT 'Domain used for auto-created church admin emails',
  `email_default_quota` INT NOT NULL DEFAULT 500 COMMENT 'MB',
  `license_key` VARCHAR(120) NULL,
  `comments_moderation` VARCHAR(20) NOT NULL DEFAULT 'off' COMMENT 'off|all|links|words — how much is held for review',
  `comments_flag_threshold` INT NOT NULL DEFAULT 3 COMMENT 'Reader reports before a comment is auto-flagged',
  `analytics_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Collect anonymous traffic analytics',
  `analytics_retention_days` INT NOT NULL DEFAULT 180 COMMENT 'Days of raw events kept; daily roll-ups are kept forever',
  `backup_retention_days` INT NOT NULL DEFAULT 14 COMMENT 'Newest N backups kept; 0 turns pruning off',
  `backup_offsite_path` VARCHAR(255) NULL COMMENT 'Directory copied to after each backup; empty = none',
  `sms_token` TEXT NULL COMMENT 'PhilmoreSMS API token, encrypted at rest - never logged or rendered in full',
  `sms_default_country` VARCHAR(4) NOT NULL DEFAULT '234' COMMENT 'Dial code used when a number is written in local form',
  `sms_default_sender_id` VARCHAR(11) NULL COMMENT 'Must be approved at the gateway before it will send',
  `sms_sender_display_name` VARCHAR(60) NULL COMMENT 'Shown as the sender name on the handset, where the network supports it',
  `sms_quiet_start` TINYINT NOT NULL DEFAULT 7 COMMENT 'Hour sending may begin, site time',
  `sms_quiet_end` TINYINT NOT NULL DEFAULT 20 COMMENT 'Hour sending must stop, site time',
  `sms_daily_unit_cap` INT NOT NULL DEFAULT 0 COMMENT '0 = no cap',
  `sms_sender_cap` INT NOT NULL DEFAULT 0 COMMENT 'Max sender IDs one church may register; 0 = no cap',
  `sms_batch_size` INT NOT NULL DEFAULT 100 COMMENT 'Recipients per gateway call',
  `sms_allow_unit_sending` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Whether a scoped church may send at all',
  `sms_optout_footer` VARCHAR(160) NOT NULL DEFAULT 'Reply STOP to opt out.',
  `sms_log_retention_days` INT NOT NULL DEFAULT 30,
  `timezone` VARCHAR(64) NOT NULL DEFAULT 'Africa/Lagos',
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_settings_tenant` (`tenant_id`),
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Configurable hierarchy levels. `type` is the stable key stored in
-- org_units.type; label/plural are shown to admins and sort_order is the depth
-- (1 = top level). The super admin can rename, reorder, add or remove levels.
CREATE TABLE IF NOT EXISTS `unit_levels` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `type` VARCHAR(40) NOT NULL,
  `label` VARCHAR(60) NOT NULL,
  `plural` VARCHAR(60) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_unit_level_type` (`type`),
  UNIQUE KEY `uniq_unit_level_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `unit_levels` (`type`, `label`, `plural`, `sort_order`) VALUES
  ('province', 'Province', 'Provinces', 1),
  ('zone', 'Zone', 'Zones', 2),
  ('area', 'Area', 'Areas', 3),
  ('parish', 'Parish', 'Parishes', 4);

-- Church hierarchy (posts tag to a leaf unit and roll up through its ancestors).
-- `type` is a VARCHAR key into unit_levels so levels stay configurable.
CREATE TABLE IF NOT EXISTS `org_units` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `parent_id` INT NULL,
  `type` VARCHAR(40) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(160) NULL UNIQUE,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`parent_id`) REFERENCES `org_units`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `alt_email` VARCHAR(190) NULL,
  `phone` VARCHAR(32) NULL COMMENT 'Normalised dial code + national number, e.g. 2348031234567. Set by the user or an admin.',
  `sms_consent` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = this person agreed to receive text messages on that number',
  `password` VARCHAR(255) NOT NULL,
  `unblock_pin_hash` VARCHAR(255) NULL,
  `reset_otp` VARCHAR(10) NULL,
  `reset_otp_expires_at` DATETIME NULL,
  `role` ENUM('admin','media_team','editor') NOT NULL DEFAULT 'media_team',
  `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0,
  `org_unit_id` INT NULL,
  `is_suspended` TINYINT(1) NOT NULL DEFAULT 0,
  `notify_on_login` TINYINT(1) NOT NULL DEFAULT 1,
  `bio` TEXT NULL,
  `avatar` VARCHAR(255) NULL,
  `last_login_at` TIMESTAMP NULL,
  `last_login_ip` VARCHAR(45) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`org_unit_id`) REFERENCES `org_units`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Public church-admin self-registrations awaiting super-admin approval.
CREATE TABLE IF NOT EXISTS `pending_registrations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(45) NULL,
  `username` VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `unblock_pin_hash` VARCHAR(255) NULL,
  `province_id` INT NULL,
  `zone_id` INT NULL,
  `area_id` INT NULL,
  `parish_name` VARCHAR(150) NULL,
  `parish_id` INT NULL,
  `unit_path` TEXT NULL COMMENT 'JSON [{level,id}] - the chosen chain at any depth',
  `role` VARCHAR(20) NOT NULL DEFAULT 'admin',
  `alt_email` VARCHAR(190) NULL COMMENT 'Optional backup inbox; used as the corporate email forwarder',
  `password_enc` TEXT NULL COMMENT 'Encrypted plaintext password, used to create the cPanel email on approval',
  `email_created` TINYINT(1) NOT NULL DEFAULT 0,
  `created_email` VARCHAR(190) NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` INT NULL,
  `reviewed_at` TIMESTAMP NULL,
  `reject_reason` VARCHAR(500) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`province_id`) REFERENCES `org_units`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`zone_id`) REFERENCES `org_units`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`area_id`) REFERENCES `org_units`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`parish_id`) REFERENCES `org_units`(`id`) ON DELETE SET NULL,
  INDEX `idx_reg_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Publicly-flagged church name corrections, reviewed by the super admin.
CREATE TABLE IF NOT EXISTS `church_name_flags` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `org_unit_id` INT NULL,
  `current_name` VARCHAR(150) NOT NULL,
  `suggested_name` VARCHAR(150) NOT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reported_by` VARCHAR(150) NULL,
  `reviewed_by` INT NULL,
  `reviewed_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`org_unit_id`) REFERENCES `org_units`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_flag_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `security_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(45) NOT NULL,
  `username_attempted` VARCHAR(100) NULL,
  `event_type` ENUM('failed_login','successful_login','blocked_attempt') NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ip_event_time` (`ip_address`, `event_type`, `created_at`),
  INDEX `idx_user_event_time` (`username_attempted`, `event_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ip_rules` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(45) NOT NULL UNIQUE,
  `type` ENUM('whitelist','blacklist') NOT NULL,
  `is_auto_whitelisted` TINYINT(1) NOT NULL DEFAULT 0,
  `successful_session_count` INT NOT NULL DEFAULT 0,
  `reason` VARCHAR(255) NULL,
  `expires_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `country_rules` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `country_code` VARCHAR(2) NOT NULL UNIQUE,
  `country_name` VARCHAR(100) NOT NULL,
  `status` ENUM('whitelisted','not_specified','blacklisted') NOT NULL DEFAULT 'not_specified',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `media_categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(120) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `media_posts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `org_unit_id` INT NULL,
  `slug` VARCHAR(160) NULL UNIQUE,
  `caption` TEXT NULL,
  `post_type` ENUM('single_image','carousel','vertical_reel') NOT NULL,
  `likes_count` INT NOT NULL DEFAULT 0,
  `views_count` INT NOT NULL DEFAULT 0,
  `saves_count` INT NOT NULL DEFAULT 0,
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,
  `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
  `pinned_at` DATETIME NULL,
  `pinned_expires_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`org_unit_id`) REFERENCES `org_units`(`id`) ON DELETE SET NULL,
  INDEX `idx_published_created` (`is_published`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `media_post_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `media_post_id` INT NOT NULL,
  `type` ENUM('image','video') NOT NULL,
  `source` ENUM('upload','youtube') NOT NULL DEFAULT 'upload',
  `file_path` VARCHAR(500) NOT NULL,
  `thumbnail_path` VARCHAR(500) NULL,
  `alt_text` VARCHAR(255) NULL,
  `processing_status` ENUM('ready','pending','failed') NOT NULL DEFAULT 'ready',
  `converted_at` DATETIME NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`media_post_id`) REFERENCES `media_posts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `post_saves` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `media_post_id` INT NOT NULL,
  `fingerprint_hash` VARCHAR(64) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_save_post_fingerprint` (`media_post_id`, `fingerprint_hash`),
  FOREIGN KEY (`media_post_id`) REFERENCES `media_posts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `post_comments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `media_post_id` INT NOT NULL,
  `parent_id` INT NULL COMMENT 'Set for replies (threaded comments)',
  `name` VARCHAR(100) NULL,
  `message` TEXT NOT NULL,
  `image_path` VARCHAR(255) NULL COMMENT 'Auto-compressed webp attachment',
  `likes_count` INT NOT NULL DEFAULT 0,
  `fingerprint_hash` VARCHAR(64) NULL,
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,
  `status` ENUM('approved','pending','rejected','spam') NOT NULL DEFAULT 'approved' COMMENT 'Moderation state; existing rows stay visible', 
  `moderated_by` INT NULL,
  `moderated_at` DATETIME NULL,
  `moderator_note` VARCHAR(255) NULL,
  `report_count` INT NOT NULL DEFAULT 0,
  `is_flagged` TINYINT(1) NOT NULL DEFAULT 0,
  `held_reason` VARCHAR(255) NULL COMMENT 'Why the screener held it, shown in the queue',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`media_post_id`) REFERENCES `media_posts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`parent_id`) REFERENCES `post_comments`(`id`) ON DELETE CASCADE,
  INDEX `idx_comment_post` (`media_post_id`, `created_at`),
  INDEX `idx_comment_parent` (`parent_id`),
  INDEX `idx_comment_status` (`status`, `created_at`),
  INDEX `idx_comment_flagged` (`is_flagged`, `report_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `post_comment_likes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `comment_id` INT NOT NULL,
  `fingerprint_hash` VARCHAR(64) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_comment_like` (`comment_id`, `fingerprint_hash`),
  FOREIGN KEY (`comment_id`) REFERENCES `post_comments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reader reports on a comment. One report per fingerprint per comment, so a
-- single person cannot pile on and force a comment off the site.
CREATE TABLE IF NOT EXISTS `comment_reports` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `comment_id` INT NOT NULL,
  `fingerprint_hash` VARCHAR(64) NULL,
  `reason` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_comment_report` (`comment_id`, `fingerprint_hash`),
  FOREIGN KEY (`comment_id`) REFERENCES `post_comments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin-managed word lists. kind='block' holds a comment for review, kind='spam'
-- sends it straight to the spam queue. `tenant_id` keeps them per church.
CREATE TABLE IF NOT EXISTS `comment_blocklist` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tenant_id` INT NULL,
  `kind` ENUM('block','spam') NOT NULL DEFAULT 'block',
  `word` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_blocklist_word` (`tenant_id`, `kind`, `word`),
  INDEX `idx_blocklist_kind` (`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `media_post_categories` (
  `media_post_id` INT NOT NULL,
  `media_category_id` INT NOT NULL,
  PRIMARY KEY (`media_post_id`, `media_category_id`),
  FOREIGN KEY (`media_post_id`) REFERENCES `media_posts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`media_category_id`) REFERENCES `media_categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `post_likes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `media_post_id` INT NOT NULL,
  `fingerprint_hash` VARCHAR(64) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_post_fingerprint` (`media_post_id`, `fingerprint_hash`),
  FOREIGN KEY (`media_post_id`) REFERENCES `media_posts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `post_views` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `media_post_id` INT NOT NULL,
  `fingerprint_hash` VARCHAR(64) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_post_fingerprint` (`media_post_id`, `fingerprint_hash`),
  FOREIGN KEY (`media_post_id`) REFERENCES `media_posts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(200) NOT NULL,
  `slug` VARCHAR(220) NOT NULL UNIQUE,
  `description` TEXT NULL,
  `cover_image` VARCHAR(255) NULL,
  `start_at` DATETIME NOT NULL,
  `end_at` DATETIME NULL,
  `location` VARCHAR(255) NULL,
  `rsvp_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `rsvp_url` VARCHAR(500) NULL,
  `rsvp_mode` ENUM('legacy','off','external','internal') NOT NULL DEFAULT 'legacy'
    COMMENT 'legacy = keep using rsvp_enabled/rsvp_url so existing events behave unchanged',
  `max_capacity` INT NOT NULL DEFAULT 0 COMMENT '0 = unlimited',
  `allow_guests` TINYINT(1) NOT NULL DEFAULT 1,
  `waitlist_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `rsvp_closes_at` DATETIME NULL COMMENT 'Optional deadline; NULL = always open',
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `org_unit_id` INT NULL,
  INDEX `idx_published_start` (`is_published`, `start_at`),
  FOREIGN KEY (`org_unit_id`) REFERENCES `org_units`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- RSVPs taken on the site or in the app. `token` is the guest's own key, so they
-- can amend or cancel without an account. One row per email per event, and a
-- NULL email is allowed (multiple NULLs are fine in a UNIQUE key) because nobody
-- is turned away for not sharing an address.
CREATE TABLE IF NOT EXISTS `event_rsvps` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `event_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(190) NULL,
  `phone` VARCHAR(45) NULL,
  `guests` INT NOT NULL DEFAULT 0 COMMENT 'Extra people aside from the guest themselves',
  `status` ENUM('going','maybe','declined','waitlist','cancelled') NOT NULL DEFAULT 'going',
  `token` VARCHAR(64) NOT NULL,
  `note` VARCHAR(500) NULL,
  `fingerprint_hash` VARCHAR(64) NULL,
  `checked_in` TINYINT(1) NOT NULL DEFAULT 0,
  `checked_in_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_rsvp_token` (`token`),
  UNIQUE KEY `uniq_rsvp_event_email` (`event_id`, `email`),
  INDEX `idx_rsvp_event_status` (`event_id`, `status`),
  INDEX `idx_rsvp_email` (`email`),
  FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A named run of sermons. Defined before `sermons` because that table points at it.
CREATE TABLE IF NOT EXISTS `sermon_series` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tenant_id` INT NULL,
  `title` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(170) NOT NULL,
  `description` TEXT NULL,
  `cover_image` VARCHAR(255) NULL,
  `org_unit_id` INT NULL,
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_sermon_series_slug` (`slug`),
  INDEX `idx_sermon_series_unit` (`org_unit_id`, `is_published`),
  FOREIGN KEY (`org_unit_id`) REFERENCES `org_units`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sermons` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(200) NOT NULL,
  `slug` VARCHAR(220) NOT NULL UNIQUE,
  `speaker` VARCHAR(150) NULL,
  `series` VARCHAR(150) NULL COMMENT 'Legacy free text. Kept in step with series_id so older code keeps working.',
  `series_id` INT NULL,
  `series_position` INT NULL COMMENT 'Episode number within the series',
  `scripture_ref` VARCHAR(150) NULL,
  `description` TEXT NULL,
  `audio_path` VARCHAR(255) NULL COMMENT 'Uploaded audio, relative to /uploads',
  `audio_url` VARCHAR(500) NULL COMMENT 'External audio, e.g. a podcast host. Wins over audio_path when set.',
  `duration_seconds` INT NULL,
  `episode_guid` VARCHAR(190) NULL COMMENT 'Stable podcast episode id. Never change it once published.',
  `is_explicit` TINYINT(1) NOT NULL DEFAULT 0,
  `video_embed_url` VARCHAR(500) NULL,
  `cover_image` VARCHAR(255) NULL,
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,
  `published_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `org_unit_id` INT NULL,
  INDEX `idx_published_at` (`is_published`, `published_at`),
  INDEX `idx_sermon_series` (`series_id`, `series_position`),
  UNIQUE KEY `uniq_sermon_episode_guid` (`episode_guid`),
  FOREIGN KEY (`org_unit_id`) REFERENCES `org_units`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `team_members` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `role_title` VARCHAR(150) NULL,
  `photo` VARCHAR(255) NULL,
  `bio` TEXT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,
  `org_unit_id` INT NULL,
  FOREIGN KEY (`org_unit_id`) REFERENCES `org_units`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `prayer_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tenant_id` INT NULL,
  `name` VARCHAR(150) NULL,
  `email` VARCHAR(150) NULL,
  `message` TEXT NOT NULL,
  `is_public` TINYINT(1) NOT NULL DEFAULT 0,
  `increment_count` INT NOT NULL DEFAULT 0,
  `is_anonymous` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Hides the name on the public wall, never from the pastoral team',
  `is_featured` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Floats the request to the top of the wall',
  `answered_at` DATETIME NULL COMMENT 'NULL while the request is still open',
  `answer_note` TEXT NULL COMMENT 'Shown publicly on the Answered Prayers wall',
  `status` ENUM('new','prayed','archived') NOT NULL DEFAULT 'new',
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `org_unit_id` INT NULL,
  INDEX `idx_prayer_wall` (`tenant_id`, `is_public`, `status`, `created_at`),
  INDEX `idx_prayer_answered` (`tenant_id`, `answered_at`),
  FOREIGN KEY (`org_unit_id`) REFERENCES `org_units`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per person per request, so the "I prayed for this" counter can never
-- count the same visitor twice. The unique key does the de-duplication.
CREATE TABLE IF NOT EXISTS `prayer_participants` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tenant_id` INT NULL,
  `request_id` INT NOT NULL,
  `session_hash` VARCHAR(64) NOT NULL COMMENT 'Rotating device hash - never an IP',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_prayer_participant` (`request_id`, `session_hash`),
  INDEX `idx_pp_request` (`request_id`),
  FOREIGN KEY (`request_id`) REFERENCES `prayer_requests`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `subscribed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `org_unit_id` INT NULL,
  FOREIGN KEY (`org_unit_id`) REFERENCES `org_units`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `forms` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(200) NOT NULL,
  `slug` VARCHAR(220) NOT NULL UNIQUE,
  `description` TEXT NULL,
  `submit_label` VARCHAR(100) NOT NULL DEFAULT 'Submit',
    `end_at` DATETIME NULL COMMENT 'Optional validity end date (NULL = open-ended)',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `visibility` ENUM('public','private') NOT NULL DEFAULT 'public',
  `password_hash` VARCHAR(255) NULL COMMENT 'Required when visibility = private',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `org_unit_id` INT NULL,
  INDEX `idx_active_end` (`is_active`, `end_at`),
  FOREIGN KEY (`org_unit_id`) REFERENCES `org_units`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `form_fields` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `form_id` INT NOT NULL,
  `label` VARCHAR(255) NOT NULL,
  `field_type` ENUM('text','textarea','email','phone','number','date','url','select','radio','checkbox','image','cascade','church','time','datetime') NOT NULL DEFAULT 'text',
  `placeholder` VARCHAR(255) NULL,
  `options` TEXT NULL COMMENT 'One option per line (select/radio/checkbox)',
  `required` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`form_id`) REFERENCES `forms`(`id`) ON DELETE CASCADE,
  INDEX `idx_field_form_order` (`form_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `form_submissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `form_id` INT NOT NULL,
  `data` LONGTEXT NOT NULL COMMENT 'JSON map of field id -> value',
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`form_id`) REFERENCES `forms`(`id`) ON DELETE CASCADE,
  INDEX `idx_submission_form_time` (`form_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Server-hosted shareable CSV exports (Google-Forms style). Files live in
-- storage/exports; the token is unguessable and anyone with the link can view it.
CREATE TABLE IF NOT EXISTS `export_files` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `kind` VARCHAR(40) NOT NULL DEFAULT 'csv',
  `title` VARCHAR(255) NULL,
  `filename` VARCHAR(255) NOT NULL,
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `path` VARCHAR(255) NOT NULL,
  `form_id` INT NULL,
  `created_by` INT NULL,
  `downloads` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`form_id`) REFERENCES `forms`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_export_form` (`form_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CMS pages — editable site pages (About, new pages), content is a JSON array
-- of design sections (hero / text / columns / image / quote / cta).
CREATE TABLE IF NOT EXISTS `pages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `parent_id` INT NULL DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `slug` VARCHAR(220) NOT NULL UNIQUE,
  `eyebrow` VARCHAR(120) NULL,
  `content` LONGTEXT NULL COMMENT 'JSON array of content sections',
  `meta_description` VARCHAR(255) NULL,
  `in_nav` TINYINT(1) NOT NULL DEFAULT 0,
  `nav_label` VARCHAR(60) NULL,
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_page_nav` (`is_published`, `in_nav`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the About page so the existing /about link has CMS content.
INSERT INTO `pages` (`title`, `slug`, `eyebrow`, `content`, `meta_description`, `in_nav`, `nav_label`, `sort_order`)
SELECT 'About Us', 'about', 'Our Story',
  '[{"type":"text","heading":"Welcome to Grace & Life Church","body":"We are a family of believers on a journey together — growing in faith, building community, and serving our city with the love of Christ.","align":"center"},{"type":"columns","heading":"Why We Exist","columns":[{"heading":"Our Mission","body":"To lead people into a growing relationship with God, build authentic community, and serve our city with the love of Christ."},{"heading":"Our Vision","body":"A church without walls — reaching every generation, in the room and online, with hope that lasts."},{"heading":"Our Values","body":"Grace first. People over programs. Faith in action. Generosity, humility, and love in everything we do."}]},{"type":"quote","quote":"Wherever you are on your journey, you are welcome here — exactly as you are.","source":"Grace & Life Church"},{"type":"cta","title":"Come worship with us this weekend","subtitle":"Every Sunday — in the room and online.","label":"Plan a Visit","url":"/contact"}]',
  'Learn about our story, mission, vision, and values.', 1, 'About',
  (SELECT COUNT(*) FROM `pages` WHERE `slug` = 'about')
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `pages` WHERE `slug` = 'about');

-- Donations table for online giving, tithes, offerings, and manual bank transfers
CREATE TABLE IF NOT EXISTS `donations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `donor_name` VARCHAR(150) NULL,
  `donor_email` VARCHAR(255) NULL,
  `donor_phone` VARCHAR(50) NULL,
  `category` VARCHAR(100) NOT NULL DEFAULT 'Tithe',
  `amount` DECIMAL(12,2) NOT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'NGN',
  `description` TEXT NULL,
  `payment_method` ENUM('online', 'manual_bank') NOT NULL DEFAULT 'online',
  `payment_status` ENUM('pending', 'completed', 'failed') NOT NULL DEFAULT 'pending',
  `payment_reference` VARCHAR(100) NULL,
  `receipt_path` VARCHAR(255) NULL,
  `org_unit_id` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_donation_status` (`payment_status`, `payment_method`),
  INDEX `idx_donation_category` (`category`),
  FOREIGN KEY (`org_unit_id`) REFERENCES `org_units`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the Privacy Policy page so it appears in admin/pages and renders at /page/privacy-policy
INSERT INTO `pages` (`title`, `slug`, `eyebrow`, `content`, `meta_description`, `in_nav`, `nav_label`, `sort_order`)
SELECT 'Privacy Policy', 'privacy-policy', 'Legal',
  '[{"type":"text","heading":"Privacy Policy","body":"Effective date: {{effective_date}}\\n\\n{{site_title}} (\"we\", \"us\", or \"our\") is committed to protecting your privacy and upholding the trust you place in our ministry. This Privacy Policy governs our website, our mobile application (available on Google Play Store), online giving and donation platforms, advertisement placement services, and all related services operated by {{site_title}}.","align":"center"},{"type":"text","heading":"1. Introduction & Scope","body":"This policy outlines how {{site_title}} collects, uses, protects, and discloses personal information obtained through our digital platforms, including:\\n- Our official website ({{site_url}})\\n- Our Android mobile application on the Google Play Store\\n- Account registration, member portals, and Church Unit administration\\n- Online giving, tithes, offerings, and event registrations\\n- Advertising placement orders and publisher ad management\\n- Contact forms, prayer requests, and interactive media features\\n\\nBy accessing or using our website, mobile app, or services, you agree to the collection and use of information in accordance with this policy.","align":"left"},{"type":"text","heading":"2. Information We Collect","body":"We collect several types of information from and about users of our services:\\n\\na) Account & Member Information:\\nWhen you register an account, request Church Unit membership, or update your profile, we collect your full name, primary email address, phone number, alternate email address, assigned parish/unit, security unblock PIN, and account password (stored securely as a cryptographic hash).\\n\\nb) Financial Donations & Giving Details:\\nWhen you make voluntary donations, tithes, offerings, or event payments through our platform, transaction details (such as donation amounts, payment references, date, and time) are processed through secure payment gateways. We do not store raw credit card numbers or bank credentials on our servers.\\n\\nc) Advertising Placement & Publisher Data:\\nWhen you place an order to advertise on our website and mobile app, we collect your business/publisher contact details, selected ad package, target destination link, uploaded media files (9:16 vertical images or videos), and payment proof/checkout receipt.\\n\\nd) User Content & Interactive Submissions:\\nWe collect information you voluntarily post or submit, including prayer requests, newcomer forms, comments, saved media items, and direct messages sent through our contact channels.\\n\\ne) Mobile Application & Technical Device Data:\\nWhen using our Android app on Google Play or visiting our website, we automatically collect technical details such as your device model, operating system version, unique device tokens for Firebase Cloud Messaging (FCM) push notifications, IP address, approximate geographical location, browser type, and app feature interactions.","align":"left"},{"type":"text","heading":"3. How We Use Your Information","body":"We process your personal information for the following purposes:\\n- Service Delivery: To operate our website, mobile app, live video feeds, sermon archives, and digital Bible reading features.\\n- Account & Access Management: To verify account credentials, manage member permissions, and process password resets or Security PIN account unblocks.\\n- Financial Processing: To process tithes, offerings, event registrations, and ad placement payments accurately and generate confirmation receipts.\\n- Advertisements & Media Management: To format, review, approve, and render 9:16 vertical display ads on our feed and mobile app, and send performance analytics to ad publishers.\\n- Communication & Notifications: To send requested newsletters, church updates, event reminders, and real-time push notifications via Firebase Cloud Messaging.\\n- Ministry & Community Care: To respond to prayer requests, contact form submissions, and newcomer follow-up requests.\\n- Platform Security & Optimization: To prevent fraudulent activity, mitigate security threats, analyze aggregate usage metrics, and improve user experience.","align":"left"},{"type":"text","heading":"4. Payment Processing & Gateways","body":"All financial transactions (including donations and ad purchases) are processed through accredited, encrypted payment gateways (including Payhub and direct bank transfer verification).\\n\\n- Online Payment Gateways: Payment processors operate using secure SSL/TLS encryption and HMAC-SHA256 signature verification. Payment processors handle card details directly in accordance with PCI-DSS standards.\\n- Manual Bank Transfers: When you upload proof of bank transfer receipts, the file is securely stored on our server and accessible exclusively by authorized church administrators for payment verification.","align":"left"},{"type":"text","heading":"5. Mobile App Permissions & Notifications","body":"Our mobile application on the Google Play Store offers enhanced features designed to enrich your experience:\\n- Push Notifications: With your consent, we use Firebase Cloud Messaging (FCM) to send real-time alerts for live broadcasts, daily devotionals, and church announcements. You can disable push notifications at any time in your device settings.\\n- Storage & Camera Access: If you choose to upload media for ad placements, profile pictures, or prayer requests, the app may request permission to access your device storage or camera. These permissions are strictly opt-in and can be revoked whenever desired.","align":"left"},{"type":"text","heading":"6. Cookies, Analytics & Display Advertising","body":"We use essential cookies and local browser storage to keep you securely signed in, protect against Cross-Site Request Forgery (CSRF), and save your playback preferences.\\n\\nDisplay Advertisements rendered on our feed and mobile app are pre-screened and approved by our administrators. Ad engagement metrics (views and clicks) are recorded anonymously to provide statistical reporting in the Publisher Ad Manager portal.","align":"left"},{"type":"text","heading":"7. Data Sharing & Third-Party Disclosure","body":"We respect your trust and do NOT sell, rent, or trade your personal information to third-party marketers.\\n\\nInformation is disclosed only under these strict conditions:\\n- Trusted Service Providers: Third-party infrastructure providers who assist in operating our platform (hosting providers, SMTP email servers, push notification services, and payment gateways) bound by confidentiality obligations.\\n- Church Unit Leaders: Relevant pastoral and administrative leaders within {{site_title}} for newcomer care, prayer requests, or unit administration.\\n- Legal & Safeguarding Requirements: When required by law, subpoena, court order, or to protect the safety and rights of our community members.","align":"left"},{"type":"text","heading":"8. Data Security & Cryptographic Protection","body":"We implement industry-standard technical and organizational security measures to protect your data:\\n- Cryptographic Hashing: User account passwords and Security Unblock PINs are hashed using Argon2id cryptographic algorithms.\\n- Encryption in Transit: All data exchanged between your browser or mobile app and our servers is encrypted using Transport Layer Security (TLS/HTTPS).\\n- Access Controls: Administrative access to sensitive donor records, user accounts, and ad transactions is restricted to authorized roles.","align":"left"},{"type":"text","heading":"9. Data Retention & Account Rights","body":"We retain your personal data for as long as your account remains active or as needed to fulfill ministry services, maintain financial record compliance, or satisfy legal requirements.\\n\\nYour Rights:\\nDepending on applicable law, you have the right to:\\n- Access and inspect the personal data we hold about you.\\n- Correct or update inaccurate or incomplete information.\\n- Request deletion of your account and associated personal data.\\n- Withdraw consent for push notifications or marketing communications.\\n\\nTo exercise any of these rights, please submit a request through our contact form or contact our administration directly.","align":"left"},{"type":"text","heading":"10. Children’s Privacy","body":"Our digital services are designed for general audiences and ministry engagement. We do not knowingly collect personal information from children under 13 without verified parental or guardian consent. If you believe a child has submitted personal data without consent, please contact us immediately for prompt deletion.","align":"left"},{"type":"text","heading":"11. Policy Changes & Updates","body":"We may update this Privacy Policy periodically to reflect technological advancements, service enhancements, or legal modifications. Any revisions will be published on this page with an updated effective date. We encourage you to review this page regularly.","align":"left"},{"type":"text","heading":"12. Contact Us","body":"If you have any questions, concerns, or privacy requests regarding this Privacy Policy or our data practices, please contact us:\\n\\n{{site_title}}\\n{{address}}\\nWebsite: {{site_url}}\\nEmail: {{contact_email}}\\nPhone: {{contact_phone}}","align":"left"}]',
  'How {{site_title}} collects, uses, and protects your personal information.', 0, 'Privacy Policy', 90
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `pages` WHERE `slug` = 'privacy-policy');

-- Seed a starter set of media categories so the admin composer isn't empty on first login.
INSERT INTO `media_categories` (`name`, `slug`) VALUES
  ('Worship', 'worship'),
  ('Sermon Clip', 'sermon-clip'),
  ('Youth', 'youth'),
  ('Testimony', 'testimony'),
  ('Events', 'events'),
  ('Behind the Scenes', 'behind-the-scenes')
ON DUPLICATE KEY UPDATE `name` = `name`;

-- In-place migration guard for databases created before these columns/tables
-- existed. Each block checks the schema first so re-running the file is safe.
SET @has_source = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'media_post_items' AND COLUMN_NAME = 'source');
SET @mig_source = IF(@has_source = 0, 'ALTER TABLE `media_post_items` ADD COLUMN `source` ENUM(''upload'',''youtube'') NOT NULL DEFAULT ''upload'' AFTER `type`', 'DO 0');
PREPARE mig_source FROM @mig_source;
EXECUTE mig_source;
DEALLOCATE PREPARE mig_source;

SET @has_saves = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'media_posts' AND COLUMN_NAME = 'saves_count');
SET @mig_saves = IF(@has_saves = 0, 'ALTER TABLE `media_posts` ADD COLUMN `saves_count` INT NOT NULL DEFAULT 0 AFTER `views_count`', 'DO 0');
PREPARE mig_saves FROM @mig_saves;
EXECUTE mig_saves;
DEALLOCATE PREPARE mig_saves;

SET @has_converted_at = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'media_post_items' AND COLUMN_NAME = 'converted_at');
SET @mig_converted_at = IF(@has_converted_at = 0, 'ALTER TABLE `media_post_items` ADD COLUMN `converted_at` DATETIME NULL AFTER `processing_status`', 'DO 0');
PREPARE mig_converted_at FROM @mig_converted_at;
EXECUTE mig_converted_at;
DEALLOCATE PREPARE mig_converted_at;

-- Provincial announcements: province admin broadcasts to all/selected churches.
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `sender_id` INT NULL,
  `title` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `target_unit_id` INT NULL COMMENT 'The unit picked when sending - any level. NULL = every unit in the sender scope',
  `target_level` VARCHAR(40) NULL COMMENT 'Type of the target unit at send time, since level names are configurable',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_notifications_target` (`target_unit_id`),
  FOREIGN KEY (`sender_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `notification_recipients` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `notification_id` INT NOT NULL,
  `org_unit_id` INT NOT NULL,
  `read_at` DATETIME NULL,
  `delivered_at` DATETIME NULL,
  FOREIGN KEY (`notification_id`) REFERENCES `notifications`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`org_unit_id`) REFERENCES `org_units`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uq_notif_recipient` (`notification_id`, `org_unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Push device tokens registered by the mobile app (FCM, anonymous).
CREATE TABLE IF NOT EXISTS `device_tokens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `token` VARCHAR(512) NOT NULL,
  `platform` VARCHAR(30) NULL,
  `org_unit_id` INT NULL,
  `user_agent` VARCHAR(255) NULL,
  `last_seen_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_device_token` (`token`(255)),
  FOREIGN KEY (`org_unit_id`) REFERENCES `org_units`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Church growth tracking: per-service attendance + newcomer follow-up.
CREATE TABLE IF NOT EXISTS `attendance_records` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `org_unit_id` INT NULL,
  `service_date` DATE NOT NULL,
  `service_name` VARCHAR(120) NOT NULL,
  `topic` VARCHAR(255) NULL,
  `bible_text` VARCHAR(255) NULL,
  `male_count` INT NOT NULL DEFAULT 0,
  `female_count` INT NOT NULL DEFAULT 0,
  `notes` TEXT NULL,
  `created_by` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_attendance_unit_date` (`org_unit_id`, `service_date`),
  FOREIGN KEY (`org_unit_id`) REFERENCES `org_units`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `newcomers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `org_unit_id` INT NULL,
  `name` VARCHAR(150) NOT NULL,
  `whatsapp_phone` VARCHAR(40) NULL,
  `address` VARCHAR(255) NULL,
  `gender` ENUM('male','female','other') NULL,
  `age_group` ENUM('adult','children','youth') NOT NULL DEFAULT 'adult',
  `attendance_id` INT NULL,
  `visit_date` DATE NULL,
  `follow_up_status` ENUM('new','contacted','followed_up','returned','inactive') NOT NULL DEFAULT 'new',
  `notes` TEXT NULL,
  `created_by` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_newcomer_unit_status` (`org_unit_id`, `follow_up_status`),
  FOREIGN KEY (`org_unit_id`) REFERENCES `org_units`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`attendance_id`) REFERENCES `attendance_records`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Advertising System Tables
CREATE TABLE IF NOT EXISTS `ad_durations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(100) NOT NULL,
  `days` INT NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `is_free` TINYINT(1) NOT NULL DEFAULT 0,
  `display_frequency` VARCHAR(20) NOT NULL DEFAULT '5_min',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ad_publishers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(45) NULL,
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pub_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ads` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `publisher_id` INT NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `media_type` ENUM('image','video') NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `thumbnail_path` VARCHAR(255) NULL,
  `destination_url` VARCHAR(500) NULL,
  `target_platform` ENUM('web','app','both') NOT NULL DEFAULT 'both',
  `duration_days` INT NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `is_free` TINYINT(1) NOT NULL DEFAULT 0,
  `display_frequency` VARCHAR(20) NOT NULL DEFAULT '5_min',
  `payment_status` ENUM('unpaid','pending_review','paid') NOT NULL DEFAULT 'unpaid',
  `payment_method` ENUM('online','manual','free') NOT NULL DEFAULT 'free',
  `payment_proof_path` VARCHAR(255) NULL,
  `payment_reference` VARCHAR(100) NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `start_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  `views_count` INT NOT NULL DEFAULT 0,
  `clicks_count` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`publisher_id`) REFERENCES `ad_publishers`(`id`) ON DELETE CASCADE,
  INDEX `idx_ad_status_expires` (`status`, `start_at`, `expires_at`, `target_platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ad_events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ad_id` INT NOT NULL,
  `event_type` ENUM('view','click') NOT NULL,
  `platform` VARCHAR(20) NOT NULL DEFAULT 'web',
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ad_id`) REFERENCES `ads`(`id`) ON DELETE CASCADE,
  INDEX `idx_ad_event_time` (`ad_id`, `event_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ad_payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ad_id` INT NOT NULL,
  `publisher_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `payment_method` ENUM('online','manual','free') NOT NULL,
  `reference` VARCHAR(100) NOT NULL,
  `status` ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
  `proof_path` VARCHAR(255) NULL,
  `gateway_response` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ad_id`) REFERENCES `ads`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`publisher_id`) REFERENCES `ad_publishers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `testimonies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `unit_id` INT NULL DEFAULT NULL,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(190) NULL,
  `phone` VARCHAR(50) NULL,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `media_url` VARCHAR(500) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `approved_at` TIMESTAMP NULL DEFAULT NULL,
  INDEX `idx_testimony_status` (`status`, `submitted_at`),
  INDEX `idx_testimony_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Analytics: raw traffic/interaction events. Deliberately has NO foreign keys so
-- pruning old rows can never cascade into content, and so events survive even if
-- a post is later deleted. `session_hash` is a rotating device hash, never an IP.
-- Counts that already live in their own tables (giving, newcomers, attendance,
-- likes, saves, comments) are NOT duplicated here.
CREATE TABLE IF NOT EXISTS `analytics_events` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `tenant_id` INT NOT NULL DEFAULT 0 COMMENT '0 = not yet attributed',
  `occurred_at` DATETIME NOT NULL,
  `event` VARCHAR(60) NOT NULL,
  `path` VARCHAR(255) NULL,
  `org_unit_id` INT NULL,
  `entity_type` VARCHAR(30) NULL,
  `entity_id` INT NULL,
  `device` VARCHAR(10) NOT NULL DEFAULT 'web' COMMENT 'web|app',
  `session_hash` VARCHAR(64) NULL COMMENT 'Rotating device hash - never an IP',
  `referrer_host` VARCHAR(120) NULL,
  `country` VARCHAR(2) NULL,
  `meta` VARCHAR(255) NULL COMMENT 'e.g. the search term, truncated',
  INDEX `idx_ae_time` (`occurred_at`),
  INDEX `idx_ae_event_time` (`event`, `occurred_at`),
  INDEX `idx_ae_tenant_time` (`tenant_id`, `occurred_at`),
  INDEX `idx_ae_entity` (`entity_type`, `entity_id`),
  INDEX `idx_ae_session` (`session_hash`, `occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nightly roll-up, kept indefinitely. Raw events are pruned after
-- `analytics_retention_days`, so these rows are the long-term history.
-- Every key column is NOT NULL so the upsert below is deterministic.
CREATE TABLE IF NOT EXISTS `analytics_daily` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tenant_id` INT NOT NULL DEFAULT 0,
  `day` DATE NOT NULL,
  `event` VARCHAR(60) NOT NULL,
  `device` VARCHAR(10) NOT NULL DEFAULT 'web',
  `entity_type` VARCHAR(30) NOT NULL DEFAULT '',
  `entity_id` INT NOT NULL DEFAULT 0,
  `org_unit_id` INT NOT NULL DEFAULT 0,
  `hits` INT NOT NULL DEFAULT 0,
  UNIQUE KEY `uniq_analytics_day` (`tenant_id`, `day`, `event`, `device`, `entity_type`, `entity_id`, `org_unit_id`),
  INDEX `idx_ad_day` (`day`),
  INDEX `idx_ad_event_day` (`event`, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
