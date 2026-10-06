<?php
declare(strict_types=1);
/**
 * VELORA · Digit normalisation — the single home of normalize_digits()
 *
 * Persian (U+06F0..U+06F9) and Arabic-Indic (U+0660..U+0669) digits to ASCII,
 * leaving everything else alone. Extracted from includes/formatting.php so the
 * leaf helpers that need it (includes/geo.php, and the test suite bootstrapping
 * a helper on its own) can require exactly this file instead of carrying a
 * second copy of the table.
 *
 * Why it exists at all: preg_replace('/\D/', '', $s) operates on bytes, and a
 * Persian digit is two of them, neither of which is in [0-9]. Without this,
 * a postal code typed in the digits the form itself displays came out the
 * other side as the empty string — not an error, a silence.
 *
 * This file has no dependency on config.php: it defines one pure function and
 * guards with function_exists so it composes with any load order. The canonical
 * body lives here and nowhere else.
 */

if (!function_exists('normalize_digits')) {
    function normalize_digits(string $raw): string {
        return strtr($raw, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);
    }
}
