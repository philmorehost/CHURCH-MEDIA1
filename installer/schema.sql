-- Church Media Management System — full schema
-- Applied once by the installer (Stage 2). Safe to re-run (IF NOT EXISTS everywhere).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `site_title` VARCHAR(255) NOT NULL DEFAULT 'Grace & Life Church',
  `site_tagline` VARCHAR(255) NULL,
  `logo_path` VARCHAR(255) NULL,
  `favicon_path` VARCHAR(255) NULL,
  `hero_tagline` VARCHAR(255) NULL,
  `hero_scripture` VARCHAR(255) NULL,
  `hero_eyebrow` VARCHAR(120) NULL,
  `hero_image_path` VARCHAR(255) NULL,
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
  `timezone` VARCHAR(64) NOT NULL DEFAULT 'Africa/Lagos',
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Province → Zone → Area → Parish hierarchy (posts tag to a parish and roll up).
CREATE TABLE IF NOT EXISTS `org_units` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `parent_id` INT NULL,
  `type` ENUM('province','zone','area','parish') NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(160) NULL UNIQUE,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`parent_id`) REFERENCES `org_units`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Public church-admin self-registrations awaiting super-admin approval.
CREATE TABLE IF NOT EXISTS `pending_registrations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(45) NULL,
  `username` VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `province_id` INT NULL,
  `zone_id` INT NULL,
  `area_id` INT NULL,
  `parish_name` VARCHAR(150) NULL,
  `parish_id` INT NULL,
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

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
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
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`media_post_id`) REFERENCES `media_posts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`parent_id`) REFERENCES `post_comments`(`id`) ON DELETE CASCADE,
  INDEX `idx_comment_post` (`media_post_id`, `created_at`),
  INDEX `idx_comment_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `post_comment_likes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `comment_id` INT NOT NULL,
  `fingerprint_hash` VARCHAR(64) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_comment_like` (`comment_id`, `fingerprint_hash`),
  FOREIGN KEY (`comment_id`) REFERENCES `post_comments`(`id`) ON DELETE CASCADE
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
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `org_unit_id` INT NULL,
  INDEX `idx_published_start` (`is_published`, `start_at`),
  FOREIGN KEY (`org_unit_id`) REFERENCES `org_units`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sermons` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(200) NOT NULL,
  `slug` VARCHAR(220) NOT NULL UNIQUE,
  `speaker` VARCHAR(150) NULL,
  `series` VARCHAR(150) NULL,
  `scripture_ref` VARCHAR(150) NULL,
  `description` TEXT NULL,
  `audio_path` VARCHAR(255) NULL,
  `video_embed_url` VARCHAR(500) NULL,
  `cover_image` VARCHAR(255) NULL,
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,
  `published_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `org_unit_id` INT NULL,
  INDEX `idx_published_at` (`is_published`, `published_at`),
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
  `name` VARCHAR(150) NULL,
  `email` VARCHAR(150) NULL,
  `message` TEXT NOT NULL,
  `is_public` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('new','prayed','archived') NOT NULL DEFAULT 'new',
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `org_unit_id` INT NULL,
  FOREIGN KEY (`org_unit_id`) REFERENCES `org_units`(`id`) ON DELETE SET NULL
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
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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
