<?php
declare(strict_types=1);
/**
 * VELORA · Digit mapping — the single home of normalize_digits() and
 * to_persian_digits()
 *
 * normalize_digits(): Persian (U+06F0..U+06F9) and Arabic-Indic (U+0660..U+0669)
 * digits to ASCII, leaving everything else alone. Extracted from
 * includes/formatting.php so the leaf helpers that need it (includes/geo.php,
 * and the test suite bootstrapping a helper on its own) can require exactly
 * this file instead of carrying a second copy of the table.
 *
 * to_persian_digits(): the mirror direction, ASCII digits to Persian glyphs.
 * It used to be spelled out four times across three files; both halves of the
 * mapping now live here once.
 *
 * Why normalize_digits exists at all: preg_replace('/\D/', '', $s) operates on
 * bytes, and a Persian digit is two of them, neither of which is in [0-9].
 * Without this, a postal code typed in the digits the form itself displays came
 * out the other side as the empty string — not an error, a silence.
 *
 * This file has no dependency on config.php: it defines pure functions and
 * guards with function_exists so it composes with any load order. The canonical
 * bodies live here and nowhere else.
 */

if (!function_exists('normalize_digits')) {
    function normalize_digits(string $raw): string {
        return strtr($raw, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);
    }
}

/* ═══ ASCII digits → Persian digits ═══
 * The mirror of normalize_digits(), and now the single home of that mapping.
 * It used to be spelled out four times across three files — twice inside
 * includes/formatting.php (once in fa_num(), once in fa_pad()) and once in
 * includes/geo.php (velora_fa_digits()). Four copies of "which glyph a digit
 * is drawn with" is exactly the drift this file was extracted to prevent, so
 * every caller now routes through this one table.
 *
 * Array form of strtr, not the two-string form: the two-string form maps
 * bytes to bytes, and a Persian digit is two bytes — handing it ten 1-byte
 * "from" characters against twenty 1-byte "to" characters truncates the table
 * and shreds the output.
 */
if (!function_exists('to_persian_digits')) {
    function to_persian_digits(string $s): string {
        static $map = [
            '0' => "\u{06F0}", '1' => "\u{06F1}", '2' => "\u{06F2}", '3' => "\u{06F3}",
            '4' => "\u{06F4}", '5' => "\u{06F5}", '6' => "\u{06F6}", '7' => "\u{06F7}",
            '8' => "\u{06F8}", '9' => "\u{06F9}",
        ];
        return strtr($s, $map);
    }
}
