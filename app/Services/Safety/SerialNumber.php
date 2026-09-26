<?php

namespace App\Services\Safety;

/**
 * One serial number, however it was typed.
 */
final class SerialNumber
{
    /**
     * Shorter than this after normalising and a serial is not matched at all.
     *
     * "1234" is not an identity, it is a coincidence waiting to happen, and a
     * false match here puts a stranger's honest listing on hold.
     */
    public const MIN_MATCH_LENGTH = 5;

    /**
     * Upper case letters and digits only.
     *
     * Spaces, dashes and dots are how people copy a serial off a headstock,
     * not part of it. O and 0 are deliberately NOT merged: some makers use
     * both, and folding them would match serials that genuinely differ.
     */
    public static function normalize(?string $serial): ?string
    {
        if ($serial === null) {
            return null;
        }

        $clean = preg_replace('/[^A-Z0-9]/', '', strtoupper($serial));

        return $clean === '' ? null : $clean;
    }

    public static function isMatchable(?string $normalized): bool
    {
        return $normalized !== null && strlen($normalized) >= self::MIN_MATCH_LENGTH;
    }
}
