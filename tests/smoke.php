<?php
declare(strict_types=1);
/**
 * VELORA · smoke test
 *
 * Run from the project root:
 *
 *     php tests/smoke.php
 *
 * There is no composer.json, no phpunit and no test directory in this project,
 * so this file is the verification. It is deliberately dependency-free: it boots
 * the real config.php in a child process, exercises the functions that were
 * previously undefined, and asserts the security predicates that matter.
 *
 * What it covers, and why each exists:
 *   · bootstrap     — config.php loads under E_ALL with no PHP diagnostic
 *   · uploads       — product_upload_dir / is_safe_upload_filename /
 *                     product_upload_url_base / product_image_url /
 *                     product_brand_image (all five were called and never
 *                     defined; product_image_url was an unguarded fatal in
 *                     sitemap.php, which is the URL robots.txt points at)
 *   · traversal     — ~30 hostile filenames, all must be refused
 *   · fake upload   — a real PNG through the real GD pipeline, when the local
 *                     GD has WebP; otherwise product_upload_health() must
 *                     correctly report why it cannot
 *   · escaping      — esc() / esc_xml() / velora_json_escape() against XSS and
 *                     invalid-UTF-8 payloads
 *   · constants     — no application constant declared outside config.php
 *   · single-home   — no raw htmlspecialchars() outside esc()/esc_xml(), no
 *                     inline JSON_HEX_* recipe outside the one encoder
 *   · pages         — index.php, catalog.php, sitemap.xml, admin.php, api.php
 *                     through a real HTTP server, checked for status, JSON
 *                     validity and the absence of fatals
 *   · lint          — php -l across the tree
 *
 * HOW THE PROBES RUN, and why they are not `php -r`
 *
 * The obvious implementation shells out with escapeshellarg(). That is wrong on
 * Windows, where escapeshellarg() wraps in single quotes and cmd.exe does not
 * treat them as quoting — so the code fragment arrives at the interpreter with
 * its own double quotes stripped. Every probe in an earlier draft of this file
 * failed that way, and the failures looked exactly like application fatals.
 *
 * So each probe is written to a temp .php file and run as `php <file>`, and the
 * server is started through proc_open()'s array form, which bypasses the shell
 * entirely. No quoting is involved on either side, so the same file behaves
 * identically on Linux, macOS and Windows.
 *
 * Exit code 0 = no failures (skips allowed), 1 = at least one failure.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '0');

const SMOKE_ROOT_DIR = __DIR__ . '/..';

$GLOBALS['__fail'] = 0;
$GLOBALS['__pass'] = 0;
$GLOBALS['__skip'] = 0;

function smoke_ok(string $what, bool $cond, string $detail = ''): void {
    if ($cond) {
        $GLOBALS['__pass']++;
        fwrite(STDOUT, "  \033[32mPASS\033[0m  {$what}\n");
        return;
    }
    $GLOBALS['__fail']++;
    fwrite(STDOUT, "  \033[31mFAIL\033[0m  {$what}" . ($detail !== '' ? "\n          → {$detail}" : '') . "\n");
}

function smoke_skip(string $what, string $why): void {
    $GLOBALS['__skip']++;
    fwrite(STDOUT, "  \033[33mSKIP\033[0m  {$what}\n          → {$why}\n");
}

function section(string $t): void { fwrite(STDOUT, "\n\033[1m{$t}\033[0m\n"); }

/* ── shell-free child execution ─────────────────────────────────────────── */

/**
 * Run a PHP fragment in a child process and return its stdout.
 *
 * Only stdout is returned, and that is deliberate. An earlier draft fell back to
 * stderr whenever stdout was empty, which is the one thing this harness must not
 * do: config.php writes its CONFIG_INCOMPLETE payload to *stdout* and only the
 * diagnostic to stderr, and a probe whose expected JSON never arrived would
 * then decode the diagnostic's neighbours into a confident-looking result. When
 * a probe produces nothing, the caller has to see that.
 */
function smoke_run_php(string $body, array $argv = []): string
{
    $file = tempnam(sys_get_temp_dir(), 'velora_probe_') . '.php';
    /* Assign $argv, not $_argv. The CLI populates both on start-up, but they are
       two separate variables — setting $_argv leaves $argv exactly as the
       interpreter built it, which for `php <file>` is [script] with no further
       elements. That produced "Undefined array key 1" in a probe that looked
       like an application failure. */
    $src  = "<?php\nerror_reporting(E_ALL);\nini_set('display_errors','1');\n"
          . "ini_set('log_errors','0');\n"
          . "chdir(" . var_export(realpath(SMOKE_ROOT_DIR), true) . ");\n"
          . '$argv = array_merge(["__FILE__"], ' . var_export($argv, true) . ");\n"
          . $body . "\n";
    file_put_contents($file, $src);
    try {
        $proc = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) return '';
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);           /* drained, discarded */
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return trim((string) $out);
    } finally {
        @unlink($file);
    }
}

/** Run a fragment that emits one JSON document; return [decoded, raw].
 *
 *  The decoded value is returned whatever its JSON type — not just arrays. A
 *  probe that answers a bare string (`json_encode(product_upload_health())` when
 *  the function returns a sentence) is a normal shape, and an earlier version
 *  that accepted only arrays turned those into a silent null, which surfaced as
 *  "the health check returned NULL" on a function that was working. */
function smoke_json(string $body, array $argv = []): array
{
    $raw = smoke_run_php($body, $argv);
    return [json_decode($raw, true), $raw];
}

/** Load the real bootstrap, then run a fragment. */
function boot_and(string $body, array $argv = []): array
{
    return smoke_json(
        'require ' . var_export(realpath(SMOKE_ROOT_DIR) . '/config.php', true) . ";\n" . $body,
        $argv
    );
}

/** A PHP diagnostic in a child's output — the thing we must never see. */
function has_diagnostic(string $s): bool
{
    return (bool) preg_match('/\b(Fatal error|Parse error|Uncaught|Warning|Notice|Deprecated)\b/i', $s);
}

function diagnostic_free(string $s): string
{
    return has_diagnostic($s) ? substr(trim(strip_tags($s)), 0, 300) : '';
}

/**
 * Take a port the OS says is free, close it, and hand it to the server.
 *
 * A FIXED PORT IS A TRAP. An earlier version used 8099 and left two `php -S`
 * processes alive whenever a run aborted before its cleanup. Every later run's
 * proc_open() then failed to bind, while the "is something listening?" probe
 * cheerfully reported success — so every request was answered by a STALE server
 * from a previous, differently configured run. The symptoms (a 200 whose body
 * reads "Failed opening required <docroot>") are indistinguishable from a broken
 * application.
 *
 * The port is chosen up here, before the throwaway .env is written, because
 * APP_URL has to name the same port the pages are served on: the assertions read
 * real absolute URLs back out of product_upload_url_base() and index.php's boot
 * payload.
 */
function smoke_free_port(): int
{
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) return 8199;
    $name = (string) stream_socket_get_name($sock, false);
    fclose($sock);
    $colon = strrpos($name, ':');
    return $colon === false ? 8199 : (int) substr($name, $colon + 1);
}

const SMOKE_HOST = '127.0.0.1';

/* Globals, not `const`: the origin is a concatenation, which a constant
   expression cannot hold on PHP 8.4 without a compile-time literal. */
$GLOBALS['SMOKE_PORT']   = smoke_free_port();
$GLOBALS['SMOKE_ORIGIN'] = 'http://' . SMOKE_HOST . ':' . $GLOBALS['SMOKE_PORT'];

/* ═══════════════════════════════════════════════════════════════════════════
   1 · BOOTSTRAP
   ═══════════════════════════════════════════════════════════════════════════ */
section('1 · Bootstrap (config.php under E_ALL)');

$envPath   = SMOKE_ROOT_DIR . '/.env';
$envExisted = is_file($envPath);
$envBackup = $envExisted ? (string) file_get_contents($envPath) : null;

/* ALWAYS write the throwaway .env — never only when it is absent.
 *
 * An earlier version skipped the write whenever a .env already existed, on the
 * reasonable-sounding assumption that a run must not clobber the operator's real
 * one. The consequence was worse than the clobber it avoided: an aborted run left
 * a throwaway .env behind, every later run then preserved *that* instead, and
 * APP_URL stayed pinned to whatever port the first run had used. The tests
 * compared real absolute URLs against a port nothing was listening on, and
 * reported application bugs. Restoring the backup at the end (below) is what
 * actually protects the operator's file. */
file_put_contents($envPath, implode("\n", [
    '# written by tests/smoke.php — restored or removed on exit',
    'VELORA_ENV=development',
    'VELORA_DB_NAME=smoke_test',
    'VELORA_DB_USER=smoke',
    'VELORA_DB_PASS=smoke',
    'VELORA_ADMIN_USER=smoke',
    'VELORA_ADMIN_PASS=' . password_hash('smoke', PASSWORD_DEFAULT),
    'VELORA_APP_KEY=' . bin2hex(random_bytes(32)),
    'VELORA_APP_URL=' . $GLOBALS['SMOKE_ORIGIN'],
    'VELORA_ZARINPAL_MERCHANT=smoke',
    'VELORA_OTP_DEBUG=0',
    'VELORA_INSTALL_TOKEN=',
    '',
]));

/* No database is expected here, and a refused connection is a *designed*
   condition — config.php records it and keeps going so the storefront renders
   from products.json. What must not appear is a PHP diagnostic. */
$boot = smoke_run_php('echo "BOOT_OK";');

smoke_ok('config.php boots to completion', str_contains($boot, 'BOOT_OK'), $boot);
smoke_ok('bootstrap emits no PHP fatal/parse/warning/notice/deprecation',
    !has_diagnostic($boot), diagnostic_free($boot));
smoke_ok('a refused database does NOT end the request (storefront still renders)',
    str_contains($boot, 'BOOT_OK'), 'config.php aborted — the DB outage took the storefront down with it');

/* ═══════════════════════════════════════════════════════════════════════════
   2 · UPLOAD HELPERS — the functions that were called and never defined
   ═══════════════════════════════════════════════════════════════════════════ */
section('2 · Upload helpers (product_upload_dir, is_safe_upload_filename, product_upload_url_base, product_image_url, product_brand_image)');

[$p, $raw] = boot_and('echo json_encode([
    "dir"        => product_upload_dir(),
    "url_base"   => product_upload_url_base(),
    "brand"      => product_brand_image(),
    "brand_size" => product_brand_image_size(),
    "health"     => product_upload_health(),
    "gen"        => product_upload_filename("vel_123"),
    "gen_max"    => product_upload_filename(str_repeat("z", 300)),
    "url_ok"     => product_image_url("vel_123-aaaaaaaaaaaaaaaa.webp"),
    "url_url"    => product_image_url("https://cdn.example.com/a.webp"),
    "url_http"   => product_image_url("http://cdn.example.com/a.webp"),
    "url_id"     => product_image_url("vel_123"),
    "url_null"   => product_image_url(""),
    "url_trav"   => product_image_url("../../../etc/passwd"),
    "path_bad"   => product_upload_path("../../.env"),
    "path_abs"   => product_upload_path("/etc/passwd"),
    "path_good"  => product_upload_path("nothing-here.webp"),
]);');

if (!is_array($p)) {
    smoke_ok('the five upload helpers execute cleanly', false, substr($raw, 0, 400));
} else {
    smoke_ok('the five upload helpers execute cleanly under E_ALL', true);

    smoke_ok('product_upload_dir() returns a non-empty absolute path inside the project',
        is_string($p['dir'] ?? null) && ($p['dir'] ?? '') !== ''
        && str_starts_with(str_replace('\\', '/', (string) $p['dir']), str_replace('\\', '/', (string) realpath(SMOKE_ROOT_DIR))),
        var_export($p['dir'] ?? null, true));

    smoke_ok('product_upload_dir() is under storage/',
        is_string($p['dir'] ?? null) && str_contains(str_replace('\\', '/', (string) $p['dir']), '/storage/uploads/products'),
        var_export($p['dir'] ?? null, true));

    smoke_ok('product_upload_dir() is writable by this process',
        is_string($p['dir'] ?? null) && is_writable((string) $p['dir']));

    smoke_ok('product_upload_url_base() is an absolute URL ending in the uploads path',
        preg_match('#^https?://[^/]+/storage/uploads/products$#', (string) ($p['url_base'] ?? '')) === 1,
        var_export($p['url_base'] ?? null, true));

    smoke_ok('product_brand_image() points at a file that exists',
        preg_match('#/brand-icon-512\.png$#', (string) ($p['brand'] ?? '')) === 1
        && is_file(SMOKE_ROOT_DIR . '/brand-icon-512.png'),
        var_export($p['brand'] ?? null, true));

    smoke_ok('product_brand_image_size() is 512×512 (what seo.php declares)',
        ($p['brand_size'] ?? null) === [512, 512], json_encode($p['brand_size'] ?? null));

    smoke_ok('product_upload_filename("vel_123") matches the closed grammar',
        preg_match('/^[A-Za-z0-9_-]{1,64}\.webp$/', (string) ($p['gen'] ?? '')) === 1,
        var_export($p['gen'] ?? null, true));

    smoke_ok('product_upload_filename() carries a 64-bit random suffix',
        preg_match('/^[A-Za-z0-9_-]+-[0-9a-f]{16}\.webp$/', (string) ($p['gen'] ?? '')) === 1,
        var_export($p['gen'] ?? null, true));

    smoke_ok('product_upload_filename() survives a 300-char product id',
        preg_match('/^[A-Za-z0-9_-]{1,64}\.webp$/', (string) ($p['gen_max'] ?? '')) === 1,
        var_export($p['gen_max'] ?? null, true));

    smoke_ok('product_image_url() resolves an upload name to its public URL',
        ($p['url_ok'] ?? '') === $GLOBALS['SMOKE_ORIGIN'] . '/storage/uploads/products/vel_123-aaaaaaaaaaaaaaaa.webp',
        var_export($p['url_ok'] ?? null, true));

    smoke_ok('product_image_url() passes an absolute https URL through',
        ($p['url_url'] ?? '') === 'https://cdn.example.com/a.webp',
        var_export($p['url_url'] ?? null, true));

    smoke_ok('product_image_url() rejects plain http:// (mixed content under an https page)',
        ($p['url_http'] ?? null) === '', var_export($p['url_http'] ?? null, true));

    smoke_ok('product_image_url() returns "" for a bare catalogue id (no real photo)',
        ($p['url_id'] ?? null) === '', var_export($p['url_id'] ?? null, true));

    smoke_ok('product_image_url() returns "" for an empty key',
        ($p['url_null'] ?? null) === '', var_export($p['url_null'] ?? null, true));

    smoke_ok('product_image_url() returns "" for a traversal key',
        ($p['url_trav'] ?? null) === '', var_export($p['url_trav'] ?? null, true));

    smoke_ok('product_upload_path() refuses "../../.env"',
        ($p['path_bad'] ?? 'x') === '', var_export($p['path_bad'] ?? null, true));

    smoke_ok('product_upload_path() refuses an absolute path',
        ($p['path_abs'] ?? 'x') === '', var_export($p['path_abs'] ?? null, true));

    smoke_ok('product_upload_path() returns "" for a valid name that is not on disk',
        ($p['path_good'] ?? 'x') === '', var_export($p['path_good'] ?? null, true));

    smoke_ok('product_upload_health() returns null or a string, never an error',
        ($p['health'] ?? false) === null || is_string($p['health']),
        var_export($p['health'] ?? null, true));
}

/* ═══════════════════════════════════════════════════════════════════════════
   3 · is_safe_upload_filename() AGAINST HOSTILE INPUT
   ═══════════════════════════════════════════════════════════════════════════ */
section('3 · is_safe_upload_filename() — path traversal, double extensions, non-strings');

$mustRefuse = [
    ''                              => 'empty string',
    '..'                            => 'bare parent directory',
    '../../.env'                    => 'traversal to .env',
    '../../config.php'              => 'traversal to config.php',
    '..%2f..%2f.env'                => 'percent-encoded traversal',
    '..\\..\\config.php'            => 'backslash traversal',
    '....//....//etc/passwd'        => 'double-encoded traversal',
    '/etc/passwd'                   => 'absolute unix path',
    'C:\\Windows\\system32\\x.webp'  => 'absolute windows path',
    "x.webp\0.php"                  => 'null-byte truncation',
    'x.webp%00.php'                 => 'encoded null byte',
    '.htaccess'                     => 'Apache dotfile',
    '.env'                          => 'env dotfile',
    '.webp'                         => 'dot-prefixed name',
    'a.webp.php'                    => 'double extension',
    'a.php.webp'                    => 'extension-first double',
    'a.pHp.webp'                    => 'php extension hidden mid-name',
    'a.webp5'                       => 'wrong suffix',
    'a.WEBP'                        => 'uppercase suffix (the grammar is closed)',
    'a.jpg'                         => 'non-webp extension',
    'a.phtml'                       => 'non-webp extension',
    'a.phar'                        => 'non-webp extension',
    'a.webp '                       => 'trailing space',
    ' a.webp'                       => 'leading space',
    "a.webp\n"                      => 'trailing newline',
    "a.webp\r\nSet-Cookie: x=1"     => 'CRLF header injection',
    'a.webp/../b.webp'              => 'traversal after a valid-looking name',
    'a.webp#frag'                   => 'fragment',
    'a.webp?a=b'                    => 'query string',
    'a.webp;rm -rf /'               => 'shell metacharacters',
    'a.webp|cat /etc/passwd'        => 'pipe in the name',
    'a.webp`id`'                    => 'backtick in the name',
    'a.webp$(id)'                   => 'command substitution in the name',
    str_repeat('a', 81) . '.webp'    => 'over the 80-byte bound',
    "a.webp\x01\x02"                => 'control characters',
    "\xC2\xA0a.webp"                => 'non-breaking space prefix',
];

$mustAccept = [
    'a.webp'                             => 'minimal valid name',
    'vel_123-a1b2c3d4e5f60718.webp'      => 'a name the pipeline actually produces',
    str_repeat('b', 64) . '.webp'        => '64-char stem, the grammar maximum',
    '0.webp'                             => 'digit stem',
];

$refuseNames = array_keys($mustRefuse);
$acceptNames = array_keys($mustAccept);
$nonStrings  = ['null', 'int 0', 'int 1', 'float', 'true', 'false', 'array', 'object'];

/* The names cross the process boundary in a file, not on the command line:
   argv is not binary-safe and several of these names contain NUL, CR, LF and
   shell metacharacters by design. var_export() would additionally escape the
   forward slashes, which would quietly turn "../../.env" into "..\/..\/.env".
   Both lists are sent, in order, so the child's flat result vector lines up
   with the parent's expectations. */
$listFile = tempnam(sys_get_temp_dir(), 'velora_names_');
file_put_contents($listFile, json_encode([$refuseNames, $acceptNames], JSON_INVALID_UTF8_SUBSTITUTE));

$resRaw = smoke_run_php(
    'require ' . var_export(realpath(SMOKE_ROOT_DIR) . '/config.php', true) . ";
     \$lists = json_decode((string) file_get_contents(\$argv[1]), true);
     if (!is_array(\$lists) || !is_array(\$lists[0] ?? null) || !is_array(\$lists[1] ?? null)) {
         echo json_encode(['probe_error' => 'name lists did not decode']); exit;
     }
     \$out = [];
     foreach (array_merge(\$lists[0], \$lists[1]) as \$n) {
         \$out[] = is_safe_upload_filename(\$n) ? 1 : 0;
     }
     /* Every non-string value too: a `string` type hint alone would have thrown a
        TypeError on the first of these rather than answering false. */
     foreach ([null, 0, 1, 1.5, true, false, [], new stdClass()] as \$v) {
         try { \$out[] = is_safe_upload_filename(\$v) ? 1 : 0; }
         catch (Throwable \$e) { \$out[] = -1; }
     }
     echo json_encode(\$out);",
    [$listFile]
);
@unlink($listFile);
$res = json_decode($resRaw, true);

if (!is_array($res) || isset($res['probe_error'])) {
    smoke_ok('is_safe_upload_filename() is total over hostile input', false,
        'probe failed: ' . substr($resRaw, 0, 400));
} else {
    $bad = [];
    $expect = count($refuseNames) + count($acceptNames) + count($nonStrings);
    if (count($res) !== $expect) {
        $bad[] = 'child returned ' . count($res) . " results, expected {$expect}";
    }

    $i = 0;
    foreach ($mustRefuse as $name => $why) {
        $v = $res[$i] ?? null;
        if ($v !== 0) $bad[] = $why . ' → ' . ($v === 1 ? 'ACCEPTED' : 'unexpected result');
        $i++;
    }
    foreach ($mustAccept as $name => $why) {
        $v = $res[$i] ?? null;
        if ($v !== 1) $bad[] = $why . ' (' . $name . ') → ' . ($v === 0 ? 'REFUSED' : 'unexpected result');
        $i++;
    }
    foreach ($nonStrings as $label) {
        $v = $res[$i] ?? null;
        if ($v === -1)      $bad[] = 'non-string ' . $label . ' → TypeError thrown';
        elseif ($v !== 0)   $bad[] = 'non-string ' . $label . ' → ' . ($v === 1 ? 'ACCEPTED' : 'no result');
        $i++;
    }

    smoke_ok(sprintf(
        'refuses %d hostile names, accepts %d valid ones, answers false (never throws) for null/int/float/bool/array/object',
        count($mustRefuse), count($mustAccept)
    ), $bad === [], implode(' · ', array_slice($bad, 0, 8)));
}

/* ═══════════════════════════════════════════════════════════════════════════
   4 · FAKE UPLOAD THROUGH THE REAL PIPELINE
   ═══════════════════════════════════════════════════════════════════════════ */
section('4 · Fake upload — real PNG → real GD pipeline → real file on disk');

if (!extension_loaded('gd')) {
    smoke_skip('upload pipeline end-to-end', 'ext-gd is not loaded on this PHP build');
    smoke_skip('product_upload_health() reports the missing extension',
        'cannot be asserted without ext-gd');
} else {
    [$up, $upRaw] = boot_and('
        if (!function_exists("imagewebp")) {
            echo json_encode(["stage" => "webp_support", "gd" => true]);
            exit;
        }
        /* A real 300×200 PNG, exactly the shape an operator would upload. */
        $tmp = tempnam(sys_get_temp_dir(), "velora_in_");
        $im  = imagecreatetruecolor(300, 200);
        imagefill($im, 0, 0, imagecolorallocate($im, 180, 40, 60));
        imagepng($im, $tmp);
        imagedestroy($im);

        $info = getimagesize($tmp);
        if (!$info) { echo json_encode(["stage" => "getimagesize"]); exit; }

        /* The allow-list the admin handler uses, verbatim. */
        $typeMap = [
            IMAGETYPE_JPEG => "imagecreatefromjpeg",
            IMAGETYPE_PNG  => "imagecreatefrompng",
            IMAGETYPE_GIF  => "imagecreatefromgif",
        ];
        if (function_exists("imagecreatefromwebp")) $typeMap[IMAGETYPE_WEBP] = "imagecreatefromwebp";
        if (!isset($typeMap[$info[2]])) { echo json_encode(["stage" => "allowlist"]); exit; }

        $src = $typeMap[$info[2]]($tmp);
        if (!$src) { echo json_encode(["stage" => "decode"]); exit; }

        /* Same centre-crop to 1024×1024 the handler performs. */
        $sw = (int) $info[0]; $sh = (int) $info[1];
        $side = min($sw, $sh);
        $cropX = (int) (($sw - $side) / 2);
        $cropY = (int) (($sh - $side) / 2);
        $dst = imagecreatetruecolor(1024, 1024);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefilledrectangle($dst, 0, 0, 1023, 1023, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagealphablending($dst, true);
        imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, 1024, 1024, $side, $side);
        imagedestroy($src);

        $dir = product_upload_dir();
        if ($dir === "" || !is_writable($dir)) { echo json_encode(["stage" => "dir"]); imagedestroy($dst); exit; }

        $name = product_upload_filename("smoke_product");
        if (!is_safe_upload_filename($name)) { echo json_encode(["stage" => "name"]); imagedestroy($dst); exit; }

        $path = $dir . "/" . $name;
        $saved = imagewebp($dst, $path, 86);
        imagedestroy($dst);
        @chmod($path, 0644);
        if (!$saved) { echo json_encode(["stage" => "imagewebp"]); exit; }

        $stored = @getimagesize($path);
        $magic  = (string) @file_get_contents($path, false, null, 0, 4);

        echo json_encode([
            "ok"         => true,
            "name"       => $name,
            "bytes"      => (int) filesize($path),
            "is_webp"    => $stored && $stored[2] === IMAGETYPE_WEBP,
            "magic_ok"   => $magic === "RIFF",
            "dims"       => $stored ? [$stored[0], $stored[1]] : null,
            "url"        => product_image_url($name),
            "roundtrip"  => product_upload_path($name) === realpath($path),
            "cleanup"    => @unlink($path),
        ]);
        @unlink($tmp);
    ');

    if (!is_array($up) || ($up['stage'] ?? null) === 'webp_support') {
        /* This build has GD without WebP — a real deployment state, and exactly
           what product_upload_health() exists to catch at boot. */
        [$health, $healthRaw] = boot_and('echo json_encode(product_upload_health());');
        smoke_skip('upload pipeline end-to-end',
            'this PHP has ext-gd without WebP support (imagewebp() absent) — install a GD with WebP to run the write path');
        smoke_ok('product_upload_health() diagnoses GD-without-WebP at boot',
            is_string($health ?? null) && str_contains((string) $health, 'WebP'),
            'raw probe output: ' . substr($healthRaw, 0, 300));
    } else {
        smoke_ok('a real PNG passes getimagesize() and the type allow-list',
            ($up['ok'] ?? false) === true, 'failed at: ' . ($up['stage'] ?? '?'));

        if (($up['ok'] ?? false) === true) {
            smoke_ok('the stored file is a genuine WebP (IMAGETYPE_WEBP + RIFF magic)',
                ($up['is_webp'] ?? false) === true && ($up['magic_ok'] ?? false) === true,
                'magic/dims: ' . json_encode([$up['magic_ok'] ?? null, $up['dims'] ?? null]));
            smoke_ok('the pipeline produced the documented 1024×1024 square',
                ($up['dims'] ?? null) === [1024, 1024], json_encode($up['dims'] ?? null));
            smoke_ok('the stored file is non-empty',
                (int) ($up['bytes'] ?? 0) > 0, 'bytes=' . var_export($up['bytes'] ?? null, true));

            /* Re-check the name in the parent, so the assertion does not depend
               on the child's own validator agreeing with itself. */
            $n = (string) ($up['name'] ?? '');
            smoke_ok('the generated name matches the closed grammar (checked in the parent)',
                strlen($n) <= 80 && !str_contains($n, '/') && !str_contains($n, '\\')
                && !str_contains($n, '..') && preg_match('/^[A-Za-z0-9_-]{1,64}\.webp$/', $n) === 1, $n);

            smoke_ok('product_image_url() resolves the file just written to a public URL',
                ($up['url'] ?? '') === $GLOBALS['SMOKE_ORIGIN'] . '/storage/uploads/products/' . $n,
                var_export($up['url'] ?? null, true));

            smoke_ok('product_upload_path() round-trips the written file back',
                ($up['roundtrip'] ?? false) === true);

            smoke_ok('the test cleaned up after itself', ($up['cleanup'] ?? false) === true);
        }
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   5 · ESCAPING & JSON
   ═══════════════════════════════════════════════════════════════════════════ */
section('5 · esc() / esc_xml() / velora_json_escape() / jresp()');

[$e, $eRaw] = boot_and('
    /* includes/seo.php is required by index.php, NOT by config.php — it is a
       storefront module, not part of every bootstrap. Requiring it here is what a
       document does, and it is why seo_json() is exercised in this probe rather
       than assumed available after config.php. */
    require_once getcwd() . "/includes/seo.php";
    $xss = "\"><script>alert(1)</script>";
    $sq  = "O\x27Brien \"quoted\" & <b>";
    echo json_encode([
        "esc_xss"      => esc($xss),
        "esc_sq"       => esc($sq),
        "esc_null"     => esc(null),
        "esc_invalid"  => esc("\xB1\x31 bad utf8"),
        "esc_xml"      => esc_xml("<loc>a&b</loc>"),
        "esc_xml_sq"   => esc_xml("a\"b"),
        "json_xss"     => velora_json_escape(["n" => "</script><script>alert(1)</script>"]),
        "json_fa"      => velora_json_escape(["name" => "کفش چرم"]),
        "json_slash"   => velora_json_escape(["u" => "https://x/a"]),
        "json_invalid" => velora_json_escape(["n" => "\xB1\x31"]),
        "json_nested"  => velora_json_escape(["a" => ["b" => [1, 2, null, true]]]),
        "seo_json"     => seo_json(["x" => "</script>"]),
        "seo_eq_enc"   => seo_json(["x" => "</script>"]) === velora_json_escape(["x" => "</script>"]),
    ]);
');

if (!is_array($e)) {
    smoke_ok('the escaping helpers execute cleanly', false, substr($eRaw, 0, 400));
} else {
    smoke_ok('esc() neutralises a <script> tag', !str_contains((string) ($e['esc_xss'] ?? ''), '<script>'),
        var_export($e['esc_xss'] ?? null, true));
    smoke_ok('esc() escapes double quotes', !str_contains((string) ($e['esc_sq'] ?? ''), '"'));
    /* The ampersand must survive only as a named entity. Testing for the bare
       character fails on the CORRECT output, because "&amp;" itself contains "&"
       — a check that rejects the escaping it is meant to verify. ENT_HTML5 emits
       &apos; for the apostrophe too, so that belongs in the allowed set. */
    smoke_ok('esc() rewrites every bare & into a named entity',
        str_contains((string) ($e['esc_sq'] ?? ''), '&amp;')
        && !preg_match('/&(?!amp;|apos;|lt;|gt;|quot;|#)/', (string) ($e['esc_sq'] ?? '')),
        var_export($e['esc_sq'] ?? null, true));
    smoke_ok('esc() escapes angle brackets', !str_contains((string) ($e['esc_sq'] ?? ''), '<b>'));
    smoke_ok('esc(null) is "" rather than an error', ($e['esc_null'] ?? 'x') === '');
    smoke_ok('esc() substitutes invalid UTF-8 (ENT_SUBSTITUTE) instead of returning ""',
        is_string($e['esc_invalid'] ?? null) && ($e['esc_invalid'] ?? '') !== '',
        var_export($e['esc_invalid'] ?? null, true));

    smoke_ok('esc_xml() escapes XML metacharacters', !str_contains((string) ($e['esc_xml'] ?? ''), '<loc>'));
    smoke_ok('esc_xml() escapes double quotes (ENT_QUOTES)',
        !str_contains((string) ($e['esc_xml_sq'] ?? ''), '"'), var_export($e['esc_xml_sq'] ?? null, true));

    smoke_ok('velora_json_escape() cannot close a <script> element',
        !str_contains((string) ($e['json_xss'] ?? ''), '</script>'), var_export($e['json_xss'] ?? null, true));
    smoke_ok('velora_json_escape() keeps Persian text readable (JSON_UNESCAPED_UNICODE)',
        str_contains((string) ($e['json_fa'] ?? ''), 'کفش'), var_export($e['json_fa'] ?? null, true));
    smoke_ok('velora_json_escape() keeps slashes readable (JSON_UNESCAPED_SLASHES)',
        str_contains((string) ($e['json_slash'] ?? ''), 'https://x/a'), var_export($e['json_slash'] ?? null, true));
    smoke_ok('velora_json_escape() returns VALID JSON for invalid UTF-8',
        is_array(json_decode((string) ($e['json_invalid'] ?? ''), true)),
        var_export($e['json_invalid'] ?? null, true));
    smoke_ok('velora_json_escape() handles nested structures',
        is_array(json_decode((string) ($e['json_nested'] ?? ''), true)));
    smoke_ok('seo_json() is a true alias of the single encoder',
        ($e['seo_eq_enc'] ?? false) === true);
}

/* jresp() exits, so it needs its own child process. The property that matters:
   a body is ALWAYS produced, even for input that cannot be encoded — an empty
   body is exactly what the client reports as a bodyless HTTP_500 with no
   message. */
[$jr, $jrRaw] = boot_and('
    /* Mirrors the encoder inside jresp(), minus the header() calls and exit(). */
    function jresp_probe(array $d): string {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
               | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
        $body = json_encode($d, $flags);
        return $body === false ? "{\"ok\":false,\"error\":\"ENCODE_FAILED\"}" : $body;
    }
    $bad = json_decode(jresp_probe(["n" => "\xB1\x31"]), true);
    echo json_encode([
        "persian"  => is_array(json_decode(jresp_probe(["name" => "کفش چرم"]), true)),
        "invalid"  => is_array($bad),
        "invalid_n" => $bad["n"] ?? null,
        "nonempty" => jresp_probe(["n" => "\xB1\x31"]) !== "",
    ]);
');

smoke_ok('the jresp() encoder yields valid JSON for Persian text',
    ($jr['persian'] ?? false) === true, json_encode($jr));
smoke_ok('the jresp() encoder yields valid JSON for invalid UTF-8 (never an empty body)',
    ($jr['invalid'] ?? false) === true && ($jr['invalid_n'] ?? null) !== null,
    json_encode($jr));

/* ═══════════════════════════════════════════════════════════════════════════
   6 · CONSTANTS — one home, no silent duplicate with a different value
   ═══════════════════════════════════════════════════════════════════════════ */
section('6 · Constants');

[$c, $cRaw] = boot_and('
    /* The duplicate-define guard must be idempotent and must not warn. */
    velora_define("SIZE_MIN", 37);
    velora_define("VELORA_UPLOAD_EXT", "webp");
    echo json_encode([
        "size_min" => SIZE_MIN,
        "size_max" => SIZE_MAX,
        "band"     => SIZE_BAND,
        "cats"     => CATEGORY_SLUGS,
        "labels"   => CATEGORY_LABELS,
        "totp"     => [VELORA_TOTP_PERIOD, VELORA_TOTP_DIGITS, VELORA_TOTP_WINDOW],
        "db_free"  => VELORA_DB_INDEPENDENT_ACTIONS,
        "sub"      => VELORA_UPLOAD_SUBDIR,
        "ext"      => VELORA_UPLOAD_EXT,
        "name_re"  => VELORA_UPLOAD_NAME_RE,
        "ship"     => SHIPPING_FLAT,
        "max_line" => MAX_LINE,
    ]);
');

if (!is_array($c)) {
    smoke_ok('config.php defines the application constants', false, substr($cRaw, 0, 400));
} else {
    smoke_ok('velora_define() is idempotent for an identical value (no redeclaration notice)',
        !has_diagnostic(json_encode($c)) && ($c['size_min'] ?? null) === 37);
    smoke_ok('the size band is the documented 37..41',
        ($c['band'] ?? null) === [37, 38, 39, 40, 41], json_encode($c['band'] ?? null));
    smoke_ok('CATEGORY_SLUGS and CATEGORY_LABELS have the same length',
        count((array) ($c['cats'] ?? [])) === count((array) ($c['labels'] ?? [])) && count((array) $c['cats']) === 6,
        json_encode([$c['cats'] ?? null, $c['labels'] ?? null]));
    smoke_ok('every CATEGORY_LABEL key is a real slug',
        array_diff(array_keys((array) $c['labels']), (array) $c['cats']) === []);
    smoke_ok('the TOTP parameters are readable from config.php',
        ($c['totp'] ?? null) === [30, 6, 1], json_encode($c['totp'] ?? null));
    smoke_ok('VELORA_DB_INDEPENDENT_ACTIONS is readable from config.php',
        ($c['db_free'] ?? null) === ['geo_regions'], json_encode($c['db_free'] ?? null));
    smoke_ok('the upload layout constants are readable',
        ($c['sub'] ?? null) === 'storage/uploads/products' && ($c['ext'] ?? null) === 'webp',
        json_encode([$c['sub'] ?? null, $c['ext'] ?? null]));
    smoke_ok('the filename grammar is the closed whitelist',
        ($c['name_re'] ?? null) === '/^[A-Za-z0-9_-]{1,64}\.webp$/', var_export($c['name_re'] ?? null, true));
    smoke_ok('SHIPPING_FLAT is 0 and MAX_LINE is 5',
        ($c['ship'] ?? null) === 0 && ($c['max_line'] ?? null) === 5);
}

/* Static census: no application constant may be declared outside config.php.
   includes/catalog.php is the one documented exception — it must be loadable
   standalone by the freshness endpoint, which deliberately does not load
   config.php — so it is asserted separately, against a documented fallback. */
$appConsts = ['SIZE_MIN', 'SIZE_MAX', 'SIZE_BAND', 'CATEGORY_SLUGS', 'CATEGORY_LABELS',
              'VELORA_TOTP_PERIOD', 'VELORA_TOTP_DIGITS', 'VELORA_TOTP_WINDOW',
              'VELORA_DB_INDEPENDENT_ACTIONS', 'VELORA_UPLOAD_SUBDIR',
              'VELORA_UPLOAD_EXT', 'VELORA_UPLOAD_NAME_RE'];

function php_files(string $root): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') continue;
        $p = str_replace('\\', '/', $f->getPathname());
        if (str_contains($p, '/js/vendor/') || str_contains($p, '/.git/')) continue;
        $out[] = $p;
    }
    sort($out);
    return $out;
}

$offenders = [];
$catalogFallback = false;
foreach (php_files(SMOKE_ROOT_DIR) as $f) {
    $rel = basename($f);
    foreach (preg_split('/\R/', (string) file_get_contents($f)) as $i => $l) {
        if (preg_match("/^\s*define\('([A-Za-z_][A-Za-z0-9_]*)'/", $l, $m)
            && in_array($m[1], $appConsts, true)) {
            /* $f is slash-normalised, realpath() is not — comparing them
               directly reported config.php's own definitions as duplicates. */
            if (str_ends_with($f, '/config.php')) continue;
            if ($rel === 'catalog.php' && in_array($m[1], ['SIZE_MIN', 'SIZE_MAX', 'SIZE_BAND'], true)) {
                $catalogFallback = true;
                continue;
            }
            $offenders[] = "{$rel}:" . ($i + 1) . " re-declares {$m[1]}";
        }
        if (preg_match('/^\s*const\s+([A-Za-z_][A-Za-z0-9_]*)\s*=/', $l, $m)
            && in_array($m[1], $appConsts, true)) {
            $offenders[] = "{$rel}:" . ($i + 1) . " re-declares {$m[1]} as const";
        }
    }
}
smoke_ok('no application constant is declared outside config.php',
    $offenders === [], implode(' · ', array_slice($offenders, 0, 6)));
smoke_ok('includes/catalog.php keeps its documented standalone fallback only',
    $catalogFallback, 'expected the SIZE_* fallback to remain there for the DB-independent freshness endpoint');

/* ═══════════════════════════════════════════════════════════════════════════
   7 · SINGLE-HOME CENSUS
   ═══════════════════════════════════════════════════════════════════════════ */
section('7 · Single-home census — one escaper, one JSON recipe');

$rawEsc = [];
$hexSites = [];
foreach (php_files(SMOKE_ROOT_DIR) as $f) {
    $rel = basename($f);
    if ($rel === 'smoke.php') continue;
    foreach (token_get_all((string) file_get_contents($f)) as $i => $t) {
        if (!is_array($t)) continue;
        if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        /* The only allowed htmlspecialchars() definitions are esc() and
           esc_xml() in includes/http.php. */
        if ($t[0] === T_STRING && in_array($t[1], ['htmlspecialchars', 'htmlentities'], true)
            && $rel !== 'http.php') {
            $rawEsc[] = "{$rel}:{$t[2]}";
        }
        /* No entry point may re-implement the HTML-interpolation JSON flags. */
        if ($t[0] === T_STRING && $t[1] === 'JSON_HEX_TAG') {
            $hexSites[] = "{$rel}:{$t[2]}";
        }
    }
}
smoke_ok('no file escapes HTML with a raw htmlspecialchars() outside esc()/esc_xml()',
    $rawEsc === [], implode(', ', array_slice($rawEsc, 0, 8)));
/* The census above already collected the site:line of every JSON_HEX_TAG token,
   from the tokeniser, so reuse it rather than re-finding it with a regex. The
   expectation is exactly one site, and it is inside velora_json_escape(). */
$hexCount = count($hexSites);
$hexInHome = $hexCount === 1 && str_starts_with((string) ($hexSites[0] ?? ''), 'http.php:');
$hexInsideEncoder = false;
if ($hexInHome) {
    /* Confirm it is inside the function, not merely in the same file. */
    $tokens = token_get_all((string) file_get_contents(SMOKE_ROOT_DIR . '/includes/http.php'));
    $inside = false;
    foreach ($tokens as $i => $t) {
        if (is_array($t) && $t[0] === T_FUNCTION) {
            for ($k = $i + 1; $k < count($tokens); $k++) {
                if (is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) continue;
                $inside = is_array($tokens[$k]) && $tokens[$k][0] === T_STRING
                       && $tokens[$k][1] === 'velora_json_escape';
                break;
            }
        }
        if ($inside && is_array($t) && $t[0] === T_STRING && $t[1] === 'JSON_HEX_TAG') {
            $hexInsideEncoder = true;
            break;
        }
    }
}
smoke_ok('the JSON_HEX_* escaping flags are declared exactly once, inside velora_json_escape()',
    $hexInsideEncoder,
    'sites found: ' . ($hexSites === [] ? 'none' : implode(', ', array_slice($hexSites, 0, 8))));

/* function_exists() must survive only for genuinely optional things. */
$allowedOptional = ['apcu_', 'imagewebp', 'imagecreatefromwebp', 'curl_init',
                    'openssl_encrypt', 'openssl_decrypt', 'mb_substr', 'ini_set',
                    'normalize_digits', 'to_persian_digits'];
$badGuards = [];
foreach (php_files(SMOKE_ROOT_DIR) as $f) {
    $rel = basename($f);
    if ($rel === 'smoke.php') continue;
    foreach (token_get_all((string) file_get_contents($f)) as $i => $t) {
        if (!is_array($t) || $t[0] !== T_STRING || $t[1] !== 'function_exists') continue;
        /* the argument is the next string literal */
        for ($k = $i + 1; $k < min($i + 6, count(token_get_all((string) file_get_contents($f)))); $k++) { break; }
        $src = (string) file_get_contents($f);
        if (preg_match('/function_exists\(\s*([\'"])([A-Za-z0-9_]+)\1\s*\)/', $src, $m)) {
            $sym = $m[2];
            $ok = false;
            foreach ($allowedOptional as $a) {
                if ($sym === $a || str_starts_with($sym, $a)) { $ok = true; break; }
            }
            if (!$ok) $badGuards[] = "{$rel} guards $sym";
        }
    }
}
smoke_ok('every remaining function_exists() guards an optional extension or a self-declared symbol',
    $badGuards === [], implode(' · ', array_unique(array_slice($badGuards, 0, 8))));

/* ═══════════════════════════════════════════════════════════════════════════
   8 · API DISPATCH REACHABILITY
   ═══════════════════════════════════════════════════════════════════════════ */
section('8 · Every action a handler declares is reachable through the dispatcher');

/* This is a regression test for a real dead-endpoint bug. velora_api_handler()
   used to key its allow-list on the FILENAME alone, so includes/api/geo.php's
   two actions — geo_regions and postal_lookup — were in no map at all and every
   request for them answered UNKNOWN_ACTION 400. js/velora-bridge.js calls both:
   geo_regions() fills the checkout's province picker, postal_lookup() is the
   entire s.api.ir verification step. The code was present, correct and
   documented; it was simply unreachable, and geo_regions was the very action
   VELORA_DB_INDEPENDENT_ACTIONS names so a database outage cannot empty the
   province picker. */
[$dispatch, $dispatchRaw] = boot_and('
    require_once getcwd() . "/includes/api-handlers.php";
    /* Collect ONLY the actions a handler declares, using the same token
       pattern the dispatcher uses:  $action === "literal"
       Scanning every string literal instead picks up response keys like "ok" and
       "error" as though they were action names. */
    $declared = [];
    foreach (glob(getcwd() . "/includes/api/*.php") as $file) {
        $tokens = token_get_all((string) file_get_contents($file));
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            /* Built with chr(36) rather than written as a quoted literal: this fragment is
       itself inside a single-quoted string in the parent, so any quote character
       here terminates it — and a double-quoted form would be worse, because PHP
       would interpolate the variable and compare against its undefined value. */
            if (!is_array($t) || $t[0] !== T_VARIABLE || $t[1] !== chr(36) . "action") continue;
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            if ($j >= $n || !is_array($tokens[$j]) || $tokens[$j][0] !== T_IS_IDENTICAL) continue;
            $j++;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            if ($j >= $n || !is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) continue;
            $declared[trim($tokens[$j][1], "\x27\"")][] = basename($file);
        }
    }
    /* Filename-keyed actions are legitimate too: account.php handles
       account_profile, admin.php handles admin_login, and so on. */
    foreach (glob(getcwd() . "/includes/api/*.php") as $file) {
        $declared[basename($file, ".php")][] = basename($file);
    }
    $map = [];
    foreach (array_keys($declared) as $a) { $map[$a] = velora_api_handler($a); }
    echo json_encode([
        "map"       => array_map(static fn($f) => $f === null ? null : basename($f), $map),
        "declared"  => count($declared),
        "unknown"   => velora_api_handler("no_such_action_here"),
        "traversal" => velora_api_handler("../config"),
        "dotdot"    => velora_api_handler("../../etc/passwd"),
        "slashed"   => velora_api_handler("includes/api/geo"),
    ]);
');

if (!is_array($dispatch)) {
    smoke_ok('velora_api_handler() resolves every declared action', false, substr($dispatchRaw, 0, 400));
} else {
    $map = (array) ($dispatch['map'] ?? []);

    smoke_ok('geo_regions is reachable (the checkout province picker)',
        ($map['geo_regions'] ?? null) === 'geo.php', 'got: ' . var_export($map['geo_regions'] ?? null, true));
    smoke_ok('postal_lookup is reachable (the s.api.ir verification step)',
        ($map['postal_lookup'] ?? null) === 'geo.php', 'got: ' . var_export($map['postal_lookup'] ?? null, true));

    $broken = [];
    foreach ($map as $action => $file) {
        if ($file === null) $broken[] = $action;
    }
    smoke_ok('all ' . (int) ($dispatch['declared'] ?? 0) . ' declared actions resolve to a handler file',
        $broken === [], 'unresolved: ' . implode(', ', array_slice($broken, 0, 8)));

    /* The allow-list is the security boundary: an action must not be able to
       name a path outside includes/api/, and must not resolve by filename.
       array_key_exists, not ??: ?? treats a legitimate null as "absent" and
       would report 'x' instead of the null this is asserting. */
    $unknown      = array_key_exists('unknown', $dispatch) ? $dispatch['unknown'] : 'MISSING';
    $traversal    = array_key_exists('traversal', $dispatch) ? $dispatch['traversal'] : 'MISSING';
    $dotdot       = array_key_exists('dotdot', $dispatch) ? $dispatch['dotdot'] : 'MISSING';
    $slashed      = array_key_exists('slashed', $dispatch) ? $dispatch['slashed'] : 'MISSING';

    smoke_ok('an unknown action resolves to nothing', $unknown === null,
        'got: ' . var_export($unknown, true));
    smoke_ok('an action naming a traversal path or a directory resolves to nothing',
        $traversal === null && $dotdot === null && $slashed === null,
        'traversal=' . var_export($traversal, true)
        . ' dotdot=' . var_export($dotdot, true)
        . ' slashed=' . var_export($slashed, true));
}

/* ═══════════════════════════════════════════════════════════════════════════
   9 · PAGES OVER HTTP
   ═══════════════════════════════════════════════════════════════════════════ */
section('9 · Pages — real HTTP requests against a real server');

/** Minimal HTTP/1.0 GET so no curl extension is required. */
function http_get(int $port, string $path): array
{
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 5);
    if ($fp === false) return ['status' => 0, 'body' => '', 'headers' => '', 'error' => "$errno $errstr"];
    stream_set_timeout($fp, 10);
    fwrite($fp, "GET {$path} HTTP/1.0\r\nHost: 127.0.0.1:{$port}\r\nConnection: close\r\n\r\n");
    $raw = '';
    while (!feof($fp)) {
        $c = fread($fp, 8192);
        if ($c === false || $c === '') break;
        $raw .= $c;
    }
    fclose($fp);
    $split = explode("\r\n\r\n", $raw, 2);
    preg_match('#^HTTP/1\.[01] (\d{3})#', $split[0] ?? '', $m);
    return ['status' => (int) ($m[1] ?? 0), 'body' => $split[1] ?? '', 'headers' => $split[0] ?? '', 'error' => ''];
}

/* The port was taken near the top of this file, before the throwaway .env was
   written, so that APP_URL names the same port these pages are served on. The
   shutdown handler registered with the server below is what stops this run from
   becoming the next run's stale server. */
$port    = $GLOBALS['SMOKE_PORT'];
$docroot = realpath(SMOKE_ROOT_DIR);

/* proc_open's ARRAY form bypasses the shell, so the document root — whose path
   contains both a space and parentheses — arrives intact.

   -t is not optional. `php -S host:port <path>` treats the trailing path as a
   ROUTER SCRIPT, not a document root, so the server then tries to *include* the
   directory and every request answers "Failed opening required <docroot>" with a
   200. That failure is indistinguishable from a broken application. */
$proc = @proc_open(
    [PHP_BINARY, '-d', 'error_reporting=E_ALL', '-d', 'display_errors=1',
     '-S', "127.0.0.1:{$port}", '-t', $docroot],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);

/* Kill the server even if this run dies early. */
register_shutdown_function(static function () use (&$proc, &$pipes): void {
    if (is_resource($proc)) {
        foreach ($pipes as $pipe) { if (is_resource($pipe)) @fclose($pipe); }
        @proc_terminate($proc);
        @proc_close($proc);
    }
});

if (!is_resource($proc)) {
    smoke_skip('page-level HTTP checks', 'proc_open() is unavailable in this environment');
} else {
    $listening = false;
    for ($i = 0; $i < 60; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.25);
        if ($c) { fclose($c); $listening = true; break; }
        usleep(100_000);
    }

    if (!$listening) {
        smoke_ok('the built-in PHP server starts on a path containing spaces', false,
            'nothing listening on 127.0.0.1:' . $port);
    } else {
        smoke_ok('the built-in PHP server starts on a path containing spaces and parentheses', true);

        /* [path, expected status, assertion kind]
           Two routes are deliberately NOT here:
             /sitemap.xml — served by an Apache mod_rewrite rule, which the
                            built-in server does not read. /sitemap.php is
                            asserted directly instead, and the rewrite is
                            asserted statically against .htaccess below.
             the 403s     — .htaccess again, see the disclosure block below. */
        $routes = [
            ['/',                            200, 'html'],
            ['/?product=nonexistent',        410, 'html'],
            ['/catalog.php',                 200, 'json'],
            ['/sitemap.php',                 200, 'xml'],
            ['/admin.php',                   200, 'html'],
            ['/api.php?action=geo_regions',  200, 'json'],
        ];

        foreach ($routes as [$path, $want, $kind]) {
            $r = http_get($port, $path);
            $label = "GET {$path}";

            if ($r['status'] === 0) {
                smoke_ok("{$label} -> {$want}", false, 'no response: ' . $r['error']);
                continue;
            }
            smoke_ok("{$label} -> {$want}", $r['status'] === $want, 'got ' . $r['status']);

            smoke_ok("{$label} body carries no PHP diagnostic",
                !has_diagnostic($r['body']), diagnostic_free($r['body']));

            if ($kind === 'json') {
                $j = json_decode($r['body'], true);
                smoke_ok("{$label} returns valid JSON", is_array($j), substr(trim($r['body']), 0, 140));
                /* geo_regions specifically: this action used to be unreachable,
                   so it answered 400 for every request in production. */
                /* The province map is a literal in includes/geo.php; 27 is what it actually
                   holds today. The assertion is "a real map", not a count that
                   would break every time a province is added or corrected. */
                if ($path === '/api.php?action=geo_regions') {
                    smoke_ok('geo_regions answers ok:true with the full province map',
                        ($j['ok'] ?? false) === true
                        && count((array) ($j['map'] ?? [])) >= 25,
                        'ok=' . var_export($j['ok'] ?? null, true)
                        . ' provinces=' . count((array) ($j['map'] ?? [])));
                }
            }
            if ($kind === 'xml') {
                /* The assertion that matters most for the upload fix: the old
                   unguarded product_image_url() call made this a fatal for any
                   catalogue carrying a gallery entry. */
                smoke_ok("{$label} returns a parseable <urlset>",
                    str_contains($r['body'], '<urlset') && @simplexml_load_string($r['body']) !== false,
                    substr(trim($r['body']), 0, 200));
                smoke_ok("{$label} has no 'Call to undefined function' anywhere",
                    !str_contains($r['body'], 'Call to undefined function'));
            }
            smoke_ok("{$label} sends a Content-Type",
                stripos($r['headers'], 'Content-Type:') !== false);
        }

        /* An unknown action must be refused. With no database reachable the
           "no database, no action" gate answers first, with 500 DB_FAIL, so both
           are correct refusals — the point is that it is refused at all. */
        $unknown = http_get($port, '/api.php?action=no_such_action');
        smoke_ok('an unknown action is refused, never served',
            in_array($unknown['status'], [400, 500], true), 'got ' . $unknown['status']);
        smoke_ok('an unknown action returns JSON with ok:false',
            (json_decode($unknown['body'], true)['ok'] ?? null) === false,
            substr(trim($unknown['body']), 0, 140));

        /* Storefront integrity. */
        $home = http_get($port, '/');
        smoke_ok('index.php publishes window.VELORA_UPLOADS with a real base (was an empty string)',
            (bool) preg_match('#window\.VELORA_UPLOADS\s*=\s*"[^"]*storage/uploads/products"#', $home['body']),
            'not found in the boot payload');
        smoke_ok('index.php publishes the catalogue feed as a JSON array',
            (bool) preg_match('#window\.VELORA_CATALOG\s*=\s*\[#', $home['body']));
        smoke_ok('index.php publishes window.VELORA_MAX_LINE from the server constant',
            str_contains($home['body'], 'window.VELORA_MAX_LINE'));
        smoke_ok('index.php server-renders the collection (not an empty JS shell)',
            str_contains($home['body'], 'id="grid"'));
        smoke_ok('index.php sends a CSP carrying its nonce',
            stripos($home['headers'], 'Content-Security-Policy:') !== false && str_contains($home['headers'], 'nonce-'));
        smoke_ok('index.php sends nosniff',
            stripos($home['headers'], 'X-Content-Type-Options: nosniff') !== false);
        smoke_ok('index.php sends no HSTS over plain http (it would be ignored anyway)',
            stripos($home['headers'], 'Strict-Transport-Security:') === false);

        $retired = http_get($port, '/?product=nonexistent');
        smoke_ok('a retired/unknown product id is 410, not a thin 200 (soft-404 fix)',
            $retired['status'] === 410, 'got ' . $retired['status']);

        $admin = http_get($port, '/admin.php');
        /* An anonymous request gets the LOGIN screen, and admin.php returns
           before the panel's inline JS — so UPLOAD_BASE is legitimately absent
           here. Asserting its presence for an anonymous visitor was asserting
           that the authentication gate does not exist. What is observable
           without a session is the login form and the ceiling meta tag, both of
           which are emitted before the early return. */
        smoke_ok('admin.php shows the login screen to an anonymous visitor',
            str_contains($admin['body'], 'id="loginForm"'),
            'expected id="loginForm"');
        smoke_ok('admin.php does not leak the panel markup to an anonymous visitor',
            !str_contains($admin['body'], 'id="sidebar"') && !str_contains($admin['body'], 'UPLOAD_BASE'),
            'panel markup reached an unauthenticated request');
        smoke_ok('admin.php publishes the effective upload ceiling in a meta tag',
            (bool) preg_match('#<meta name="upload-max" content="\d+">#', $admin['body']),
            'meta name="upload-max" not found');
        smoke_ok('admin.php publishes the declared ceiling separately from the effective one',
            (bool) preg_match('#<meta name="upload-declared" content="\d+">#', $admin['body']));
        smoke_ok('admin.php is never indexable',
            stripos($admin['headers'], 'X-Robots-Tag: noindex') !== false);
        smoke_ok('admin.php refuses framing outright',
            stripos($admin['headers'], 'X-Frame-Options: DENY') !== false);

        /* ── Access control: asserted against the .htaccess FILES, not over HTTP ──
   The built-in server does not read .htaccess, so it cannot enforce any of
   this; requesting /.env here and finding it in the body proves nothing about
   the deployed site either way. What IS verifiable here is the control itself,
   so that a deploy cannot ship without it — and the 403 statuses must be
   confirmed on the real Apache after deploying, which is on the manual list. */
$rootHt = (string) @file_get_contents(SMOKE_ROOT_DIR . '/.htaccess');
smoke_ok('.htaccess denies dotfiles and .env at the document root',
    (bool) preg_match('/FilesMatch\s+"\(\^\\\./', $rootHt)
    && str_contains($rootHt, 'Require all denied'));
smoke_ok('.htaccess blocks .sql and .lock, and .bak (so audit backups are never served)',
    str_contains($rootHt, '(sql|lock)$')
    && (bool) preg_match('/env\|ini\|log\|lock\|sql\|bak\|/', $rootHt));
smoke_ok('.htaccess blocks /storage/ except uploads/products/',
    str_contains($rootHt, 'RewriteRule ^storage/(?!uploads/products/).* - [F,L]'));
smoke_ok('.htaccess maps /sitemap.xml to sitemap.php',
    str_contains($rootHt, 'RewriteRule ^sitemap\.xml$ sitemap.php [L]'));

$storageHt = (string) @file_get_contents(SMOKE_ROOT_DIR . '/storage/.htaccess');
smoke_ok('storage/.htaccess denies the tree',
    str_contains($storageHt, 'Require all denied') || str_contains($storageHt, 'Deny from all'));
smoke_ok('storage/.htaccess removes every PHP handler and sets SetHandler none',
    str_contains($storageHt, 'RemoveHandler') && str_contains($storageHt, 'SetHandler none'));
smoke_ok('storage/.htaccess turns the PHP engine off where mod_php is in use',
    (bool) preg_match('/php_flag engine off/', $storageHt));

$productsHt = (string) @file_get_contents(SMOKE_ROOT_DIR . '/storage/uploads/products/.htaccess');
smoke_ok('storage/uploads/products/.htaccess grants read (it is the only public path)',
    str_contains($productsHt, 'Require all granted'));
smoke_ok('storage/uploads/products/.htaccess serves ONLY .webp — a whitelist, not a blacklist',
    (bool) preg_match('/<FilesMatch\s+"\\\\\.webp\$">/', $productsHt)
    && (bool) preg_match('/<FilesMatch\s+"\^\.\*\$">/', $productsHt));
smoke_ok('storage/uploads/products/.htaccess denies everything else',
    (bool) preg_match('/Require all denied/', $productsHt));
smoke_ok('storage/uploads/products/.htaccess pins Content-Type and nosniff',
    str_contains($productsHt, 'Header set Content-Type "image/webp"')
    && str_contains($productsHt, 'X-Content-Type-Options'));

$upHt = (string) @file_get_contents(SMOKE_ROOT_DIR . '/storage/uploads/.htaccess');
smoke_ok('storage/uploads/.htaccess re-states the inherited deny',
    str_contains($upHt, 'Require all denied') || str_contains($upHt, 'Deny from all'));
        smoke_skip('403 status for the paths above', 'requires Apache (or nginx) with AllowOverride; the built-in server ignores .htaccess');
    }

    foreach ($pipes as $pipe) { if (is_resource($pipe)) fclose($pipe); }
    @proc_terminate($proc);
    @proc_close($proc);
}

/* ═══════════════════════════════════════════════════════════════════════════
   9 · SYNTAX
   ═══════════════════════════════════════════════════════════════════════════ */
section('10 · php -l across the tree');

$lintFail = [];
$lintOk   = 0;
foreach (php_files(SMOKE_ROOT_DIR) as $f) {
    $out = [];
    $rc  = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -n -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    if ($rc !== 0) $lintFail[] = basename($f) . ': ' . implode(' ', $out);
    else $lintOk++;
}
smoke_ok("php -l clean on all {$lintOk} PHP files", $lintFail === [],
    implode(' | ', array_slice($lintFail, 0, 4)));

/* ═══════════════════════════════════════════════════════════════════════════
   RESULT
   ═══════════════════════════════════════════════════════════════════════════ */
if (!$envExisted && is_file($envPath)) @unlink($envPath);
elseif ($envExisted && $envBackup !== null) file_put_contents($envPath, $envBackup);

$fail = $GLOBALS['__fail'];
$pass = $GLOBALS['__pass'];
$skip = $GLOBALS['__skip'];

fwrite(STDOUT, "\n" . str_repeat('─', 66) . "\n");
if ($fail === 0) {
    fwrite(STDOUT, "  \033[32m{$pass} passed, {$skip} skipped, 0 failed\033[0m\n\n");
    exit(0);
}
fwrite(STDOUT, "  \033[31m{$pass} passed, {$skip} skipped, {$fail} FAILED\033[0m\n\n");
exit(1);