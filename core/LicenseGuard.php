<?php
declare(strict_types=1);

/**
 * Validates the site's license key against the vendor's license API before
 * the installer is allowed to finish. No-ops in local dev (app_env=local)
 * so this never blocks development or testing. Result is cached briefly so
 * a slow/unreachable license server doesn't add latency to every check.
 */
class LicenseGuard
{
    private const API_URL = 'https://manager.pmhserver.name.ng/api-docs.php';
    private const CACHE_TTL = 3600;

    public static function validate(string $licenseKey, string $domain): bool
    {
        if (($GLOBALS['siteConfig']['app_env'] ?? 'local') === 'local') {
            return true;
        }
        if (trim($licenseKey) === '') {
            return false;
        }

        $cacheFile = STORAGE_PATH . '/cache/license.json';
        if (is_file($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && ($cached['key'] ?? null) === $licenseKey && time() - ($cached['checked_at'] ?? 0) < self::CACHE_TTL) {
                return (bool) ($cached['valid'] ?? false);
            }
        }

        $valid = self::callLicenseApi($licenseKey, $domain);

        if (!is_dir(dirname($cacheFile))) {
            mkdir(dirname($cacheFile), 0775, true);
        }
        file_put_contents($cacheFile, json_encode(['key' => $licenseKey, 'valid' => $valid, 'checked_at' => time()]));

        return $valid;
    }

    private static function callLicenseApi(string $licenseKey, string $domain): bool
    {
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['license_key' => $licenseKey, 'domain' => $domain, 'product' => 'church-media-system']),
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $error = curl_errno($ch);
        curl_close($ch);

        if ($error || $response === false) {
            // Vendor server unreachable — fail closed on production installs.
            return false;
        }

        $data = json_decode((string) $response, true);
        return is_array($data) && ($data['status'] ?? null) === 'valid';
    }
}
