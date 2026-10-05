<?php
declare(strict_types=1);
/**
 * VELORA · Address book & verified-phone helpers
 *
 * Extracted verbatim from api.php during the monolith split — same code, same
 * behaviour, one home per concern.
 */

if (!defined('VELORA_CONFIG_LOADED')) {
    http_response_code(500);
    exit('includes/addresses.php requires config.php to be loaded first.');
}

/* The validators below check a province/city pair against the geo list and
   compose a display line with velora_postal_format(), both of which live in
   includes/geo.php. Requiring it here makes this file self-sufficient: api.php
   already loads geo.php before dispatching, so this is a no-op in the running
   request — but a test or CLI probe that boots only config.php plus this file
   gets the same function table as the live site instead of a missing-symbol
   error. require_once keeps it safe if a future entry point loads both. */
require_once __DIR__ . '/geo.php';

/* ── Address book helpers ──────────────────────────────────────────────────── */

/**
* Validate and bound an address coming off the wire.
*
* Every field is truncated to its column width *before* it is compared, so a
* 4 KB "label" is shortened to 40 bytes and then rejected for length rather
* than being silently stored as a 4 KB label in a VARCHAR(40) — which MySQL
* would either truncate without a warning or reject with a rollback, depending
* on sql_mode. Neither is a behaviour worth depending on.
*
* Province and city are checked as a pair against includes/geo.php. Validating
* each independently is how you get "استان تهران، شهر اصفهان" past a form that
* believes it is validating both fields.
*/
function velora_address_in(): array {
    $receiver  = mb_substr(req_str('receiver'), 0, 120);
    $province  = mb_substr(req_str('province'), 0, 64);
    $city      = mb_substr(req_str('city'), 0, 64);
    $district  = mb_substr(req_str('district'), 0, 120);
    $line      = mb_substr(req_str('line'), 0, 400);
    /* normalize_digits first, then strip. The other way round is the bug this
       comment exists for: /[^\d]/ on Persian digits removes every byte of
       every digit, so the postal code arrives as '' and is stored as absent. */
    $plaque    = mb_substr(normalize_digits(req_str('plaque')), 0, 40);
    $unit      = mb_substr(normalize_digits(req_str('unit')), 0, 80);
    $note      = mb_substr(req_str('note'), 0, 400);
    $label     = mb_substr(normalize_digits(req_str('label')), 0, 40);
    $postal    = preg_replace('/\D/', '', normalize_digits(req_str('postal_code'))) ?? '';

    if ($label === '') $label = 'خانه';
    /* Every rejection names the field it is about.
       A validation error that says only "نشانی را کامل‌تر بنویسید" leaves the
       customer hunting through nine inputs for the one that is wrong, and the
       temptation is to retype the whole form — which is how a typo becomes a
       lost address. The client uses `field` to put the error on the input
       itself; it is a hint, not a contract, so a client that ignores it still
       gets a correct save. */
    $bad = static function (string $code, string $field, string $message, int $status = 400): void {
        jresp(['ok' => false, 'error' => $code, 'field' => $field, 'message' => $message], $status);
    };

    if (mb_strlen($label) > 40)     $bad('LABEL_INVALID',   'adLabel',    'عنوان نشانی بیش از حد بلند است');
    if (mb_strlen($receiver) < 2)   $bad('RECEIVER_INVALID','adReceiver', 'نام گیرنده را وارد کنید');
    if (mb_strlen($receiver) > 120) $bad('RECEIVER_INVALID','adReceiver', 'نام گیرنده بیش از حد بلند است');

    if (!velora_geo_valid($province, $city)) {
        $bad('GEO_INVALID', 'adGeo', 'استان و شهر باید از فهرست انتخاب شوند');
    }
    /* The street is the part a human actually reads, and the part that a
       courier needs to find the door. 400 characters is roughly three lines of
       a Persian address; anything longer is a paste of something else. */
    if (mb_strlen($line) < 5) {
        $bad('LINE_INVALID', 'adLine', 'نشانی را کامل‌تر بنویسید (خیابان، کوچه، پلاک)');
    }
    /* The district and the plaque are what turn a street into an address a
       courier can act on, so they are required here as well as in the form.
       Requiring them only in the client meant the server accepted addresses
       the form would not let anyone write. */
    if (mb_strlen($district) < 2) {
        $bad('DISTRICT_INVALID', 'adDistrict', 'محله یا منطقه را وارد کنید');
    }
    if (mb_strlen($plaque) < 1) {
        $bad('PLAQUE_INVALID', 'adPlaque', 'شمارهٔ پلاک را وارد کنید');
    }
    /* The postal code is optional, and when given it must be ten digits. The
       checksum is NOT enforced here.

       That is a change, and it is deliberate. The checksum catches a mistyped
       digit, which is useful — but it also rejects real codes, and a customer
       whose own code fails our arithmetic cannot save their address at all.
       The form already asks the post office, and the post office's answer is
       better evidence than ours: when a lookup for this exact code has come
       back with a province and a city, the code is real whatever our weighted
       sum says. So the hard failure is the shape — ten digits — and everything
       softer is left to the form, which has better evidence to offer.

       What is lost: a code with a single wrong digit is no longer refused on
       save, only flagged in the form. What is gained: a real address is never
       refused because of our arithmetic. A returned parcel is a worse outcome
       than a code that needed confirming. */
    if ($postal !== '' && strlen($postal) !== 10) {
        $bad('POSTAL_INVALID', 'adPostal', 'کدپستی باید ۱۰ رقم باشد');
    }

    /* The delivery number is optional on the address — the courier can call the
       account number — but if one is given it must be a real Iranian mobile
       and it must already be verified for this account, or the whole feature
       would become a way to send a parcel to a number you do not control. */
    $rawPhone = req_str('phone');
$phone    = $rawPhone !== '' ? normalize_phone($rawPhone) : null;

if ($rawPhone !== '' && $phone === null) {
    $bad('PHONE_INVALID', 'adPhone', 'شمارهٔ همراه معتبر نیست');
}
if ($phone !== null && !user_phone_verified($phone)) {
    $bad('PHONE_UNVERIFIED', 'adPhone', 'این شماره هنوز تأیید نشده است');
}

    return [
        'label'       => $label,
        'receiver'    => $receiver,
        'phone'       => $phone ?? '',
        'province'    => $province,
        'city'        => $city,
        'district'    => $district,
        'line'        => $line,
        'plaque'      => $plaque,
        'unit'        => $unit,
        'postal_code' => $postal,
        'note'        => $note,
    ];
}

/** Row → client shape, with the one-line form composed and the postal grouped. */
function velora_address_out(array $row): array {
    if (!$row) return [];
    $a = [
        'id'          => (int) ($row['id'] ?? 0),
        'label'       => (string) ($row['label'] ?? ''),
        'receiver'    => (string) ($row['receiver'] ?? ''),
        'phone'       => (string) ($row['phone'] ?? ''),
        'province'    => (string) ($row['province'] ?? ''),
        'city'        => (string) ($row['city'] ?? ''),
        'district'    => (string) ($row['district'] ?? ''),
        'line'        => (string) ($row['line'] ?? ''),
        'plaque'      => (string) ($row['plaque'] ?? ''),
        'unit'        => (string) ($row['unit'] ?? ''),
        'postal_code' => (string) ($row['postal_code'] ?? ''),
        'note'        => (string) ($row['note'] ?? ''),
        'is_default'  => (int) ($row['is_default'] ?? 0) === 1,
    ];
    $a['postal_display'] = velora_postal_format($a['postal_code']);
    $a['summary'] = velora_address_line($a);
    return $a;
}

/**
* The inbound validator, run against a stored row instead of the request.
*
* When checkout is given an address_id it must apply the *same* rules to what
* is already in the book, not trust the row. Rows predate the current rules
* (the table is new, but a row can be edited by an older build, and a
* hand-edited database is not a fiction), and re-validating costs one indexed
* read. Trusting the book here would mean a bad province/city pair saved once
* ships forever.
*/
function velora_address_in_from(array $row): array {
    $postal = preg_replace('/\D/', '', (string) ($row['postal_code'] ?? '')) ?? '';
    if (!velora_geo_valid((string) $row['province'], (string) $row['city'])) {
        jresp(['ok' => false, 'error' => 'GEO_INVALID',
               'message' => 'نشانی ذخیره‌شده معتبر نیست — لطفاً آن را اصلاح کنید'], 400);
    }
    if (mb_strlen((string) ($row['line'] ?? '')) < 5) {
        jresp(['ok' => false, 'error' => 'LINE_INVALID',
               'message' => 'نشانی ذخیره‌شده ناقص است — لطفاً آن را کامل کنید'], 400);
    }
    return [
        'label'       => (string) ($row['label'] ?? 'خانه'),
        'receiver'    => (string) ($row['receiver'] ?? ''),
        'phone'       => (string) ($row['phone'] ?? ''),
        'province'    => (string) $row['province'],
        'city'        => (string) $row['city'],
        'district'    => (string) ($row['district'] ?? ''),
        'line'        => (string) $row['line'],
        'plaque'      => (string) ($row['plaque'] ?? ''),
        'unit'        => (string) ($row['unit'] ?? ''),
        'postal_code' => $postal,
        'note'        => (string) ($row['note'] ?? ''),
    ];
}

/** The EU sizes the catalogue allows, as a list ready for in_array(). */
/**
 * The EU sizes the catalogue allows, as a list ready for in_array().
 *
 * SIZE_BAND, not a second `range()`. The function predates the constant and
 * had its own copy of the bounds; two copies of a rule is how a client and a
 * server end up disagreeing about which sizes exist.
 */
function velora_size_band(): array {
    return defined('SIZE_BAND') ? SIZE_BAND : range(SIZE_MIN, SIZE_MAX);
}

/**
 * Is the address-book schema present?
 *
 * Two tables and two columns arrived after the store was already running, and
 * the migration is applied by hand. A deployment can therefore be one release
 * behind, and when it is, every query against them throws. That is not a
 * hypothetical: it happened the first time this shipped, and it took sign-in
 * and checkout down with it — 500 on /api.php with no clue in the UI.
 *
 * So the new capability is detected once per request, and every caller that
 * needs it degrades instead of failing. Sign-in and checkout must work on a
 * database that has never heard of an address book; the address book is a
 * feature, not a prerequisite.
 *
 * The probe is a plain SHOW TABLES, once, and the answer is cached in a static
 * for the rest of the request. It costs one round trip on the first call and
 * nothing after.
 */
function velora_addressbook_ready(): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    global $pdo;
    try {
        $ready = (bool) $pdo->query("SHOW TABLES LIKE 'velora_user_phones'")->fetchColumn();
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Has this customer proved they own this number?
 *
 * velora_otp.used says a code was entered for this number at some point; it
 * does not say *this customer* did it. The join through velora_user_phones is
 * what makes the answer "yes, you may have an order delivered here" rather than
 * "someone once typed a code for this number".
 *
 * Without the table there is no record to ask, so the answer falls back to the
 * session's own number — the one the customer proved by signing in with it. The
 * lock is therefore no wider than before this feature existed, which is
 * exactly right: a missing table must loosen nothing and break nothing.
 */
function user_phone_verified(string $phone): bool {
    if (!is_user() || $phone === '') return false;
    if (!velora_addressbook_ready()) {
        return $phone === (current_user_phone() ?? '');
    }
    global $pdo;
    $st = $pdo->prepare("SELECT 1 FROM velora_user_phones WHERE user_id=? AND phone=? LIMIT 1");
    $st->execute([current_user_id(), $phone]);
    return $st->fetchColumn() !== false;
}

/**
 * Record a verified number against the account.
 *
 * Called on every successful OTP. This is what makes the row set grow: the login
 * number is inserted at first sign-in, and every later change phone adds a
 * second row. is_primary follows velora_users.phone, which stays the login
 * identity — so "change my login number" and "add a delivery number" are two
 * different operations and this function is only used for the second.
 *
 * Never throws. It runs on the sign-in path, and a bookkeeping write is not a
 * reason to refuse someone their login; the failure is logged so the gap is
 * visible rather than silent.
 */
function user_phone_remember(int $userId, string $phone): void {
    if ($userId <= 0 || $phone === '') return;
    if (!velora_addressbook_ready()) return;
    global $pdo;
    try {
        $pdo->prepare("INSERT INTO velora_user_phones (user_id, phone, is_primary, verified_at)
                       VALUES (?,?,0,NOW())
                       ON DUPLICATE KEY UPDATE verified_at = NOW()")
            ->execute([$userId, $phone]);
    } catch (Throwable $e) {
        log_action('PHONE_MEMORY_FAIL', ['user_id' => $userId, 'error' => $e->getMessage()]);
    }
}

/**
 * Refuse an address-book action on a database that has no address book.
 *
 * A 500 tells the customer nothing and the log tells the operator a stack
 * trace about a table they may not know is missing. This says exactly which
 * migration is outstanding.
 */
function require_addressbook(): void {
    if (velora_addressbook_ready()) return;
    log_action('ADDRESSBOOK_MISSING', ['ip' => get_client_ip()]);
    jresp([
        'ok'      => false,
        'error'   => 'ADDRESSBOOK_UNAVAILABLE',
        'message' => 'دفترچهٔ نشانی روی این سرور هنوز راه‌اندازی نشده است',
    ], 503);
}
