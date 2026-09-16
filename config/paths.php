<?php
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
define('CONFIG_PATH', ROOT_PATH . '/config');
define('CORE_PATH', ROOT_PATH . '/core');
define('ADMIN_PATH', ROOT_PATH . '/admin');
define('API_PATH', ROOT_PATH . '/api');
define('VIEWS_PATH', ROOT_PATH . '/views');
define('INSTALLER_PATH', ROOT_PATH . '/installer');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('UPLOADS_PATH', PUBLIC_PATH . '/uploads');
define('UPLOADS_ORIGINALS_PATH', UPLOADS_PATH . '/originals');
define('UPLOADS_WEBP_PATH', UPLOADS_PATH . '/webp');
define('UPLOADS_REELS_PATH', UPLOADS_PATH . '/reels');
define('UPLOADS_THUMBS_PATH', UPLOADS_PATH . '/thumbs');
define('UPLOADS_FORM_PATH', UPLOADS_PATH . '/form-files');
define('UPLOADS_CMS_PATH', UPLOADS_PATH . '/cms');
define('INSTALL_LOCK_FILE', STORAGE_PATH . '/installed.lock');
