<?php
declare(strict_types=1);

/**
 * Phone numbers, in the two shapes this codebase needs.
 *
 * Extracted because two unrelated features now collect a person's number — a home cell leader
 * (HomeCell) and a service assignment (ServiceRoster) — and a second copy of this logic is how the
 * two drift and start storing the same number differently. Which shape a number is in matters: the
 * SMS and WhatsApp features only reach a number that carries its dial code.
 */
final class Phone
{
    public const MAX_LENGTH = 32;

    /**
     * "0803 123 4567" → "2348031234567", or null when it cannot be a number.
     *
     * Delegates to Sms so a number typed anywhere in this app is stored exactly the way every other
     * number already is. The digit-only fallback covers the case where the Sms class is unavailable,
     * which is what the installer and CLI contexts can hit before the app is fully booted.
     */
    public static function normalise(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (class_exists('Sms') && method_exists('Sms', 'normaliseMsisdn')) {
            $normalised = Sms::normaliseMsisdn($raw);
            if (is_string($normalised) && $normalised !== '') {
                return $normalised;
            }
        }

        // Seven digits is the shortest real national number; anything less is a typo, not a number.
        $digits = (string) preg_replace('/[^0-9]/', '', $raw);
        return strlen($digits) >= 7 ? $digits : null;
    }

    /** "+234 803 123 4567" for a Nigerian number, otherwise the stored value unchanged. */
    public static function display(?string $stored): string
    {
        $stored = (string) $stored;
        if (preg_match('/^234(\d{3})(\d{3})(\d{4})$/', $stored, $m)) {
            return '+234 ' . $m[1] . ' ' . $m[2] . ' ' . $m[3];
        }
        return $stored;
    }
}
