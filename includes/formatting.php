<?php
declare(strict_types=1);
/**
 * VELORA · Formatting helpers
 *
 * Extracted verbatim from config.php during the monolith split — same code,
 * same behaviour, one home per concern. Covers the Persian-numeral formatters
 * (fa_num/fa_pad) and the digit/phone normalizers.
 */

if (!defined('VELORA_CONFIG_LOADED')) {
    http_response_code(500);
    exit('includes/formatting.php requires config.php to be loaded first.');
}

/* ═══════════════════════════════════════════════════════════════════════════
PERSIAN NUMERALS
═══════════════════════════════════════════════════════════════════════════
   fa_num() is byte-for-byte what `(n) => Number(n).toLocaleString('fa-IR')`
   produces in core.js. That is a stricter requirement than it looks, and the
   looser version was in place.

   The server formatted numbers with number_format(), which emits ASCII digits
   and an ASCII comma. The browser formats them with toLocaleString('fa-IR'),
   which emits Persian digits (U+06F0..U+06F9) and U+066C ARABIC THOUSANDS
   SEPARATOR. A comment claimed the two were "one formatter, shared with the
   browser, so the two cannot drift" — they were two formatters and they did
   drift, on the first paint, for every number on the page: the hero read
   ۳۱٬۰۰۰٬۰۰۰ in the markup and 31,000,000 in Latin on screen. On a Persian
   maison the first thing a visitor sees was the wrong script, and it swapped
   the instant any renderer touched the DOM.

   The mapping, taken from the runtime rather than from memory:
       0      → ۰            1000   → ۱٬۰۰۰
       999    → ۹۹۹          1234567890 → ۱٬۲۳۴٬۵۶۷٬۸۹۰
       1500   → ۱٬۵۰۰       0.5     → ۰٫۵
       -1500  → ‎−۱٬۵۰۰   (U+200E LEFT-TO-RIGHT MARK, then U+2212 MINUS)
   The LRM before the minus is not decoration: in an RTL paragraph a bare
   Unicode minus binds to the number on its right and the minus jumps to the
   wrong end of the string.

   All three codepoints are written as escapes rather than as literals. An
   invisible mark is invisible in a diff, in a review, and in a copy-paste;
   \u200E survives all three. */
function fa_num(int|float|string $n): string {
    if (is_string($n)) {
        $n = is_numeric($n) ? $n + 0 : 0;
    }
    $isFloat = is_float($n) && floor($n) !== $n;
    $neg  = $n < 0;
    $abs  = $neg ? -$n : $n;

    /* Grouping is the runtime's, not number_format()'s: fa-IR groups in
       threes from the right with U+066C and takes a fractional part only when
       there is one. Rounding before formatting keeps 26949999.6 from printing
       a digit the browser would not have printed.

       Two earlier versions of this were wrong in a way worth recording,
       because both looked right:
         · strrev(implode(sep, str_split(strrev(s), 3))) — strrev() is
           byte-oriented, so reversing the *joined* string turned "310٬000٬000"
           into eight broken bytes and every digit after the first separator
           stopped matching, printing an empty price for anything over 999.
         · The same idiom without the outer strrev mis-grouped any length that
           is not a multiple of three, because str_split() pads from the left:
           "1500" reversed to "0051", split to ["005","1"], and reassembled as
           ۱٬۰۰۵.

       The separator is therefore anchored from the right by the lookahead
       itself — \B so a leading separator can never be produced, (?!\d) so a
       partial trailing group is never counted — and every byte operation in
       the function stays on the ASCII string, before any digit is mapped. */
    $intPart = (string) (int) floor($abs);
    if (strlen($intPart) > 3) {
        $intPart = preg_replace('/\B(?=(\d{3})+(?!\d))/u', "\u{066C}", $intPart) ?? $intPart;
    }
    $out = $isFloat
        ? $intPart . "\u{066B}" . rtrim(rtrim(substr(sprintf('%.3f', $abs - floor($abs)), 2), '0'), '.')
        : $intPart;

    $out = strtr($out, [
        '0' => "\u{06F0}", '1' => "\u{06F1}", '2' => "\u{06F2}", '3' => "\u{06F3}",
        '4' => "\u{06F4}", '5' => "\u{06F5}", '6' => "\u{06F6}", '7' => "\u{06F7}",
        '8' => "\u{06F8}", '9' => "\u{06F9}",
    ]);
    return $neg ? "\u{200E}\u{2212}" . $out : $out;
}

/** Zero-padded Persian numerals — the counterpart of core.js faPad(). */
function fa_pad(int|string $n, int $width = 2): string {
    $s = (string) max(0, (int) $n);
    if (strlen($s) < $width) {
        $s = str_pad($s, $width, '0', STR_PAD_LEFT);
    }
    return strtr($s, [
        '0' => "\u{06F0}", '1' => "\u{06F1}", '2' => "\u{06F2}", '3' => "\u{06F3}",
        '4' => "\u{06F4}", '5' => "\u{06F5}", '6' => "\u{06F6}", '7' => "\u{06F7}",
        '8' => "\u{06F8}", '9' => "\u{06F9}",
    ]);
}

/* ═══════════════════════════════════════════════════════════════════════════
DIGIT NORMALIZATION
═══════════════════════════════════════════════════════════════════════════ */
/**
 * Persian and Arabic-Indic digits to ASCII, leaving everything else alone.
 *
 * Every field the customer can type a number into needs this, and the reason
 * is not pedantry: preg_replace('/\D/', '', $s) operates on bytes, and a
 * Persian digit is two of them, neither of which is in [0-9]. So a postal code
 * typed in the digits the form itself displays came out the other side as the
 * empty string — not an error, a silence. The field looked accepted, the
 * checksum never ran, and the parcel went out with no postal code at all.
 *
 * normalize_phone() below does this and more, because a phone number also has
 * to be told apart from +98 and 0098. This one is for the fields where the
 * only question is which glyph the digit is drawn with.
 *
 * The function itself lives in includes/digits.php — its single home — so that
 * leaf helpers like includes/geo.php can load it without bootstrapping the
 * whole application. Requiring it here keeps every existing caller working
 * unchanged.
 */
require_once __DIR__ . '/digits.php';

/* ═══════════════════════════════════════════════════════════════════════════
PHONE NORMALIZATION
═══════════════════════════════════════════════════════════════════════════ */
function normalize_phone(string $raw): ?string {
    $raw = normalize_digits($raw);
    $p = preg_replace('/\D+/', '', $raw);
    if ($p === '' || $p === null) return null;
    if (str_starts_with($p, '0098'))      $p = '0' . substr($p, 4);
    elseif (str_starts_with($p, '98') && strlen($p) === 12) $p = '0' . substr($p, 2);
    elseif (str_starts_with($p, '9') && strlen($p) === 10)  $p = '0' . $p;
    if (!preg_match('/^09\d{9}$/', $p)) return null;
    return $p;
}
