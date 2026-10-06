<?php
declare(strict_types=1);
/**
* VELORA · postal code lookup
*
* Wraps the s.api.ir postal-code endpoint. The token is read from .env through
* config.php and never leaves the server: this file returns a parsed
* province/city/district/line, and api.php's postal_lookup action passes
* exactly that subset to the client. The raw response body is not returned,
* not logged, and not stored — only the parsed fields go into the cache.
*
* ── Cache ───────────────────────────────────────────────────────────────
* Postal codes are stable for months and the upstream is billed per call, so
* the result is cached on disk under storage/cache/postal/. The key is the
* sha256 of the normalized code, and the file holds {"data": {...}, "ts": unix}.
* A seven-day TTL is the balance point: long enough to absorb the repeat
* lookups one popular building generates, short enough that a correction at the
* post office lands inside a working week.
*
* ── Failure modes ───────────────────────────────────────────────────────
* Every failure returns ['ok' => false, 'error' => CODE] and never throws. The
* form on the other side degrades to "not verified" rather than to "cannot
* save", which is the correct behaviour: an outage at a third party must not
* become an outage in the address book.
*
* ── Why `success` is not the verdict ────────────────────────────────────
* The upstream's own documentation shows a response with success:false and a
* fully populated data object. Trusting `success` would therefore reject
* perfectly good answers, and trusting `data` alone would accept an error
* payload that happens to carry a message. The verdict here is
* code === 0 AND data.address is non-empty — the two conditions that together
* mean "this is a real address for a real code".
*/

if (!defined('POSTAL_ENABLED')) {
    /* Loaded outside the app (a CLI probe, a test) rather than through
       config.php. Failing loudly here is better than silently reporting every
       lookup as unconfigured, which would look like a working feature. */
    fwrite(STDERR, "includes/postal.php requires config.php to be loaded first.\n");
    exit(1);
}

/** Latin digits, 10 of them, or nothing. */
function velora_postal_normalize(string $raw): string {
    $d = preg_replace('/\D/', '', normalize_digits($raw)) ?? '';
    return strlen($d) === 10 ? $d : '';
}

/**
 * Grouped for reading, in the digits the storefront displays.
 *
 * Deliberately NOT defined here. velora_postal_format() already exists in
 * includes/geo.php and already does exactly this — both files are always
 * loaded, so a second definition of the same name is a fatal redeclare rather
 * than a convenience. The form, the composed address line, the order snapshot
 * and the API response all have to write a code the same way, and the copy
 * that already has a home is geo.php's.
 *
 * Likewise velora_postal_valid_checksum() below is a named alias rather than a
 * second algorithm: the weighting (32,26,22,18,14,10,6,2) and the mod-11
 * remainder live in velora_postal_valid(), and a copy of them here would be a
 * second answer to "is this code real" that could disagree with the one the
 * server validates on save.
 */
function velora_postal_valid_checksum(string $postal): bool {
    return velora_postal_valid($postal);
}

function velora_postal_cache_dir(): string {
    $dir = STORAGE_DIR . '/cache/postal';
    if (!is_dir($dir)) {
        /* 0750: only the web user needs to read it, and it holds the results of
           a billable API. Not 0777, and never a world-readable dump. */
        @mkdir($dir, 0750, true);
    }
    return $dir;
}

function velora_postal_cache_path(string $code): string {
    return velora_postal_cache_dir() . '/' . hash('sha256', $code) . '.json';
}

/** @return array{data: array, ts: int}|null */
function velora_postal_cache_get(string $code): ?array {
    $path = velora_postal_cache_path($code);
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return null;
    $row = json_decode($raw, true);
    /* A truncated or hand-edited file is a cache miss, not an error. The whole
       point of a cache is that failing to read it changes nothing. */
    if (!is_array($row) || !isset($row['ts'], $row['data']) || !is_array($row['data'])) {
        @unlink($path);
        return null;
    }
    if ((time() - (int) $row['ts']) > POSTAL_CACHE_TTL) {
        @unlink($path);
        return null;
    }
    return ['data' => $row['data'], 'ts' => (int) $row['ts']];
}

function velora_postal_cache_put(string $code, array $data): void {
    $path = velora_postal_cache_path($code);
    $tmp  = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode(['data' => $data, 'ts' => time()], JSON_UNESCAPED_UNICODE);
    if ($json === false) return;
    /* Write to a sibling temp file and rename. A reader that arrives mid-write
       sees either the old complete file or no file — never half a JSON object,
       which is what a plain fwrite would leave behind under concurrency. */
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return;
    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) @unlink($tmp);

    /* Opportunistic sweep. One lookup in fifty tidies up, which keeps the
       directory bounded without needing a cron job on a host that may not
       have one. */
    if (random_int(1, 50) !== 1) return;
    $dir = velora_postal_cache_dir();
    $cut = time() - POSTAL_CACHE_TTL;
    foreach (@scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..' || !str_ends_with($f, '.json')) continue;
        $p = $dir . '/' . $f;
        $m = @filemtime($p);
        if ($m !== false && $m < $cut) @unlink($p);
    }
}

/** Drop a code from the cache. Used after a save, where the customer has just
    told us the answer they want is not the one on file. */
function velora_postal_cache_forget(string $code): void {
    $path = velora_postal_cache_path($code);
    if (is_file($path)) @unlink($path);
}

/**
 * Look one code up. Never throws, never returns the token, never returns a
 * field the caller did not ask for.
 *
 * @return array{ok: bool, error?: string, province?: string, city?: string,
 *               district?: string, line?: string, address?: string}
 */
function velora_postal_lookup(string $rawCode): array {
    $code = velora_postal_normalize($rawCode);
    if ($code === '') {
        return ['ok' => false, 'error' => 'INVALID_FORMAT'];
    }
    if (!POSTAL_ENABLED) {
        return ['ok' => false, 'error' => 'POSTAL_NOT_CONFIGURED'];
    }

    /* The checksum is a warning, not a gate.
       It is a transcription check: it catches the digit a keypad slipped on.
       It is not proof of existence, and it is not proof of absence either — a
       real code can fail it, and the one this feature exists to serve is the
       real one. A storefront that refuses to look up a code because its own
       arithmetic is unhappy throws away the only authority that can settle the
       question.
       So a failing code is still looked up, and the verdict is reported. What
       the post office says outranks what we computed. Only the shape — ten
       digits — is enforced here, because that much is a format question and no
       amount of upstream certainty makes a nine-digit code a postal code. */
    $checksumOk = velora_postal_valid_checksum($code);

    $hit = velora_postal_cache_get($code);
    if ($hit !== null) {
        $d = $hit['data'];
        return [
            'ok' => true,
            'province' => (string) ($d['province'] ?? ''),
            'city' => (string) ($d['city'] ?? ''),
            'district' => (string) ($d['district'] ?? ''),
            'line' => (string) ($d['line'] ?? ''),
            'plaque' => (string) ($d['plaque'] ?? ''),
            'unit' => (string) ($d['unit'] ?? ''),
            'address' => (string) ($d['address'] ?? ''),
            'checksum_ok' => $checksumOk,
        ];
    }

    $res = velora_postal_http_request($code);
    if (!($res['ok'] ?? false)) {
        log_action('POSTAL_LOOKUP', ['code' => $code, 'cached' => false, 'ok' => false,
                                     'checksum' => $checksumOk,
                                     'error' => (string) ($res['error'] ?? 'UNKNOWN')]);
        return ['ok' => false, 'error' => (string) ($res['error'] ?? 'UNKNOWN')];
    }

    /* Only the fields the form can use. The upstream returns a dozen; the rest
       is its own bookkeeping and has no business in our cache or in a response
       body. */
    $data = [
        'province' => (string) ($res['province'] ?? ''),
        'city' => (string) ($res['city'] ?? ''),
        'district' => (string) ($res['district'] ?? ''),
        'line' => (string) ($res['line'] ?? ''),
        'plaque' => (string) ($res['number'] ?? ''),
        'unit' => (string) ($res['unit'] ?? ''),
        'address' => (string) ($res['address'] ?? ''),
    ];
    velora_postal_cache_put($code, $data);
    log_action('POSTAL_LOOKUP', ['code' => $code, 'cached' => false, 'ok' => true,
                                 'checksum' => $checksumOk]);
    return ['ok' => true, 'checksum_ok' => $checksumOk] + $data;
}

/**
 * The upstream call.
 *
 * One retry, and only for the failures that a retry can plausibly fix: a
 * timeout or a 5xx. A 4xx is a decision the upstream has already made, and
 * asking again just doubles the latency of a refusal the customer has to read.
 */
function velora_postal_http_request(string $code): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'NO_CURL'];
    }

    $attempt = function (string $code) use (&$lastHttp) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => POSTAL_API_URL,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['postalCode' => $code], JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => POSTAL_HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => POSTAL_HTTP_CONNECT_TIMEOUT,
            /* Verification on. An endpoint reached with a bearer token over a
               connection that can be intercepted is an endpoint that hands the
               token to whoever is in the middle, and this code has no business
               relaxing that for convenience. */
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . POSTAL_API_TOKEN,
                'User-Agent: VELORA-Postal/1.0',
            ],
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $codeOut = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['body' => $body === false ? null : (string) $body, 'err' => $err, 'http' => $codeOut];
    };

    $r = $attempt($code);
    $retryable = in_array($r['http'], [0, 500, 502, 503, 504], true);
    if ($retryable) {
        usleep(250000);   /* 250ms: enough to be a different moment, short
                             enough that the customer has not given up. */
        $r2 = $attempt($code);
        if ($r2['body'] !== null || $r2['http'] !== 0) $r = $r2;
    }

    if ($r['body'] === null) {
        /* curl's errno distinguishes a timeout from a refused connection, and
           the message the customer sees should not say "timed out" when the
           host simply does not resolve. */
        $isTimeout = stripos($r['err'], 'timed out') !== false || stripos($r['err'], 'timeout') !== false;
        return ['ok' => false, 'error' => $isTimeout ? 'TIMEOUT' : 'NETWORK'];
    }
    if ($r['http'] === 401 || $r['http'] === 403) {
        /* Worth its own log line: this means the token in .env is wrong or has
           been revoked, and nothing else in the app would say so. */
        log_action('POSTAL_AUTH_FAILED', ['http' => $r['http'], 'at' => POSTAL_API_URL]);
        return ['ok' => false, 'error' => 'POSTAL_AUTH_FAILED'];
    }
    if ($r['http'] === 429) {
        return ['ok' => false, 'error' => 'RATE_LIMIT_UPSTREAM'];
    }
    if ($r['http'] >= 400) {
        return ['ok' => false, 'error' => 'UPSTREAM_ERROR'];
    }
    return velora_postal_parse((string) $r['body'], $r['http']);
}

/**
 * Turn an upstream body into a verdict.
 *
 * Split out from the transport so it can be tested against real payloads
 * without a socket, which is the only part of this file that genuinely needs
 * the network. Everything above it is bookkeeping; everything here is the
 * actual contract with a third party's response format, and that is where the
 * interesting mistakes live.
 *
 * @return array{ok: bool, error?: string, province?: string, city?: string,
 *               town?: string, district?: string, street?: string, street2?: string,
 *               number?: string, floor?: string, sideFloor?: string,
 *               buildingName?: string, address?: string, line?: string}
 */
function velora_postal_parse(string $body, int $http = 200): array {
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'BAD_RESPONSE'];
    }

    $code0   = $json['code'] ?? null;
    $data    = is_array($json['data'] ?? null) ? $json['data'] : [];
    $address = velora_postal_punct(trim((string) ($data['address'] ?? '')));

    /* The verdict, per the note at the top of this file: not `success`, which
       the upstream documents as false on a perfectly good payload, but the two
       conditions that together mean "real code, real address". A body with
       data but a non-zero code is an error envelope that happens to carry
       fields, and accepting it would hand the customer a confident answer
       derived from an error.

       `code` is checked first, and it is not a single answer.
         0    → a real code; the address decides whether there is one
         406  → the account has no credit left. Observed live, returned as
                 HTTP 200 with success:false, so it looks exactly like a
                 "not found" unless the code is read. It is not: it means
                 every lookup from here on will fail, including lookups of codes
                 that are perfectly valid. Reporting it as NOT_FOUND tells the
                 customer their address does not exist, which is both false and
                 the one answer they are most likely to act on by giving up.
         other→ a refusal, or a code the upstream does not recognise. Either way
                 it is not a statement about this postal code, so it is kept
                 distinct from a genuine miss. */
    $code = (int) $code0;
    if ($code === 406) {
        /* The upstream's own wording is "no credit on account | شارژ حساب
           کاربری شما کافی نمی باشد". It is an operator problem, and it is
           logged as one so it is visible in the log rather than in a
           customer's face. */
        log_action('POSTAL_NO_CREDIT', ['upstream_message' => (string) ($json['message'] ?? '')]);
        return ['ok' => false, 'error' => 'POSTAL_NO_CREDIT'];
    }
    if ($code !== 0) {
        return ['ok' => false, 'error' => 'UPSTREAM_REFUSED'];
    }
    if ($address === '') {
        return ['ok' => false, 'error' => 'NOT_FOUND'];
    }

    $g = static function (string $k) use ($data): string {
        return trim((string) ($data[$k] ?? ''));
    };

    return [
        'ok'            => true,
        'province'      => $g('province'),
        'city'          => $g('city'),
        'town'          => $g('town'),
        'district'      => $g('district'),
        'street'        => $g('street'),
        'street2'       => $g('street2'),
        'number'        => $g('number'),
        'unit'          => velora_postal_unit($data),
        'floor'         => $g('floor'),
        'sideFloor'     => $g('sideFloor'),
        'buildingName'  => $g('buildingName'),
        'address'       => $address,
        'line'          => velora_postal_line($data),
    ];
}

/**
 * Punctuation for display.
 *
 * The upstream composes its address with ASCII commas: a live response reads
 * "شهر همدان,محله شکریه,خیابان عارف,پلاک 0". That string is shown to the
 * customer in the form's own summary, directly under a field whose other half is
 * composed with Persian commas, so the same address appears on one screen with
 * two different separators. It is a small thing, and it is the kind of small
 * thing that makes a page feel translated rather than written.
 *
 * Only the separators are touched. The words are the post office's — "محله" and
 * "پلاک" are their labels, and rewriting their phrasing to match ours would mean
 * editing somebody else's data to look better in our form.
 */
function velora_postal_punct(string $s): string {
    if ($s === '') return '';
    $s = preg_replace('/\s*,\s*(?=\S)/u', '، ', $s) ?? $s;
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    /* Strip leading and trailing separators with a /u pattern, not with trim().
       trim()'s character list is a set of BYTES, and "،" is three of them
       (d8 8c) — so a charlist containing it also contains d8, and d8 is the first
       byte of ا. Trimming "همدان، …" removed that byte and left a string that
       was no longer valid UTF-8, which the browser renders as a replacement
       glyph. It looked like a console encoding problem, and it was a data
       corruption bug in the display path. */
    return preg_replace('/^[\s،,]+|[\s،,]+$/u', '', $s) ?? $s;
}

/**
 * Compose the street line from the parts.
 *
 * The upstream splits the street across street/street2/number, and returns
 * them in Persian order, so a naive concat reads "۱۲ انقلاب پلاک ۱۰" — number
 * before street. This builds the order a Persian address is written in:
 * street, then alley or boulevard, then plaque, then unit and floor.
 */
function velora_postal_line(array $d): string {
    /* Prefix a label only when the value does not already carry it.
       The upstream's buildingName is frequently "ساختمان نمونه" — the word is
       part of the value. A blind prefix produces "ساختمان ساختمان نمونه", which
       is the kind of thing a customer sees typed into their own address and
       then has to correct. Same for a street that already says خیابان. */
    $labeled = static function (string $value, string $label): string {
        $v = trim($value);
        if ($v === '') return '';
        return (str_starts_with($v, $label) ? '' : $label . ' ') . $v;
    };

    $bits = [];

    $street = $labeled((string) ($d['street'] ?? ''), 'خیابان');
    if ($street !== '') $bits[] = $street;

    /* street2 is the rest of the address, and it names its own road type.
       A live response for a real code returns street2 as "خیابان 18 متری
       شهیدان پورش همدانی" — the word خیابان is inside the value, because the
       post office wrote the whole thing. Prefixing it produced "کوچه خیابان 18
       متری …", which contradicts the value it is labelling. So it is appended
       as it stands, separated but not described: the payload is self-describing
       here, and anything added to it is a guess.

       A bare number is not self-describing, so it is not guessed at either: it
       becomes the plaque if the payload left the plaque empty, and is dropped
       otherwise. */
    $s2 = trim((string) ($d['street2'] ?? ''));
    if ($s2 !== '') {
        if (preg_match('/^\p{N}+$/u', $s2)) {
            if (trim((string) ($d['number'] ?? '')) === '') $bits[] = 'پلاک ' . $s2;
        } else {
            $bits[] = $s2;
        }
    }

    $building = $labeled((string) ($d['buildingName'] ?? ''), 'ساختمان');
    if ($building !== '') $bits[] = $building;

    $num = trim((string) ($d['number'] ?? ''));
    if ($num !== '') $bits[] = 'پلاک ' . $num;

    /* The upstream has no "unit" field — an apartment is not something the post
       office records for a street, only for a building. Where the payload does
       carry one (some responses put it in description), it is picked up from
       there rather than invented, and the form fills it only if it is empty. */
    $unit = velora_postal_unit($d);
    if ($unit !== '') $bits[] = 'واحد ' . $unit;
    $floor = trim((string) ($d['floor'] ?? ''));
    $side  = trim((string) ($d['sideFloor'] ?? ''));
    if ($floor !== '') $bits[] = 'طبقه ' . $floor . ($side !== '' ? ' (' . $side . ')' : '');
    return implode('، ', $bits);
}

/**
 * The apartment number, if the payload has one.
 *
 * Kept separate from the line so the form's own "واحد" field can be filled from
 * it rather than parsed out of a sentence. Returns digits only — the client
 * draws them in Persian, the database stores them Latin, and this is the last
 * point where that conversion can be made once.
 */
function velora_postal_unit(array $d): string {
    foreach (['unit', 'apartment'] as $k) {
        $v = preg_replace('/\D/', '', normalize_digits((string) ($d[$k] ?? ''))) ?? '';
        if ($v !== '') return $v;
    }
    /* Some responses spell it out in the free-text description. Only an
       explicit "واحد N" is taken; anything looser would invent a number. */
    $desc = (string) ($d['description'] ?? '');
    if ($desc !== '' && preg_match('/واحد\s*([\x{06F0}-\x{06F9}0-9]+)/u', $desc, $m)) {
        return preg_replace('/\D/', '', normalize_digits($m[1])) ?? '';
    }
    return '';
}

/**
 * Health check. A known-good code, no cache involved.
 *
 * For an operator with a shell, and for the test suite. It is deliberately not
 * an API action: a probe that costs a billed call must not be reachable from
 * the storefront, or it becomes the most expensive endpoint in the app.
 */
function velora_postal_probe(string $code = '1639613891'): array {
    if (!POSTAL_ENABLED) return ['ok' => false, 'error' => 'POSTAL_NOT_CONFIGURED'];
    $n = velora_postal_normalize($code);
    if ($n === '') return ['ok' => false, 'error' => 'INVALID_FORMAT'];
    if (!velora_postal_valid_checksum($n)) return ['ok' => false, 'error' => 'INVALID_CHECKSUM'];
    $res = velora_postal_http_request($n);
    unset($res['town'], $res['buildingName'], $res['sideFloor']);
    return $res;
}
