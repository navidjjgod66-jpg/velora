<?php
declare(strict_types=1);
/**
 * VELORA · API handlers — admin
 *
 * Actions handled here: admin_product_reserve_id, admin_upload_image, admin_login, admin_logout, admin_stats, admin_orders, admin_order_status, admin_products, admin_product_save, admin_product_rename, admin_product_image_delete, admin_product_delete, admin_users, admin_appointments, admin_appointment_status
 *
 * Procedural code extracted verbatim from api.php's action switch.
 * It runs in the request's global scope via velora_api_handler()
 * (includes/api-handlers.php), so $pdo, $_SESSION, req_*(), jresp()
 * and log_action() behave exactly as they did inside the switch.
 * Each case-terminating `break;` became `return;`; jresp() exits on
 * its own, so the return only matters where the original break was.
 */

/* ---- admin_product_reserve_id ---- */
if ($action === 'admin_product_reserve_id') {
if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
csrf_check();
/* An allocation per call, with no ceiling.

   velora_catalog_reserve_id() takes the catalogue's write lock,
   reads every product id, and picks a fresh 80-bit one — so each
   call is a locked read of the whole file to produce a string an
   operator is going to use once. Loop it and you have turned an
   admin-only endpoint into the most expensive read on the site, and
   the ids it burns are permanently consumed from the namespace.

   30 per hour is far more than one operator needs: the panel
   reserves an id when a product is created, and an operator
   creating thirty products an hour is not a pattern worth
   supporting. Scoped per admin session, so one tab cannot exhaust
   another's allowance. */
if (!rate_limit('admin_reserve_id', 30, 3600, 'a' . session_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
try {
    jresp(['ok' => true, 'id' => velora_catalog_reserve_id()]);
} catch (Throwable $e) {
    jresp(['ok' => false, 'error' => 'ID_ALLOC_FAILED'], 500);
}
    return;
}

/* ---- admin_upload_image ---- */
if ($action === 'admin_upload_image') {
if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
csrf_check();
if (!rate_limit('admin_upload', 30, 300, 'a' . session_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT',
           'message' => 'تعداد آپلودها زیاد است — کمی بعد دوباره تلاش کنید'], 429);
}

if (!extension_loaded('gd')) {
    jresp(['ok' => false, 'error' => 'GD_MISSING',
           'message' => 'افزونه GD روی سرور فعال نیست'], 500);
}
if (!function_exists('imagewebp')) {
    jresp(['ok' => false, 'error' => 'WEBP_UNSUPPORTED',
           'message' => 'پشتیبانی WebP در GD فعال نیست'], 500);
}

$productId = req_str('product_id');
if ($productId === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $productId)) {
    jresp(['ok' => false, 'error' => 'INVALID_PRODUCT_ID'], 400);
}

if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    jresp(['ok' => false, 'error' => 'NO_FILE'], 400);
}
    $file = $_FILES['image'];
    /* UPLOAD_ERR_INI_SIZE is the failure this branch exists to explain.

       Under mod_php the ceiling is PHP's own upload_max_filesize /
       post_max_size, and exceeding it does NOT reliably arrive as
       UPLOAD_ERR_INI_SIZE: with no file in the request at all, PHP can discard
       the body and populate nothing, so $_FILES['image'] is simply absent and
       the honest answer is NO_FILE. Under PHP-FPM the same overflow is
       reported as UPLOAD_ERR_INI_SIZE with an empty tmp_name.

       Both look identical in the browser — "the upload did not happen" — and
       they have opposite causes, one of them fixed in .htaccess and the other
       not. So the code is passed back verbatim rather than collapsed to a
       generic failure, and the panel's ceiling constant is checked against the
       live ini values at runtime (see ADMIN_UPLOAD_MAX_BYTES) so the operator
       is told which limit they actually hit. Collapsing this to "upload
       failed" is what made an ini/htaccess mismatch look like a broken
       endpoint. */
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jresp(['ok' => false, 'error' => 'UPLOAD_ERROR',
'code' => (int) ($file['error'] ?? 0)], 400);
    }
if (!is_uploaded_file($file['tmp_name'])) {
    jresp(['ok' => false, 'error' => 'INVALID_UPLOAD'], 400);
}
$maxBytes = ADMIN_UPLOAD_MAX_BYTES;
if (($file['size'] ?? 0) > $maxBytes) {
    jresp(['ok' => false, 'error' => 'FILE_TOO_LARGE',
           'max' => $maxBytes, 'message' => 'حداکثر ' . ADMIN_UPLOAD_MAX_LABEL], 413);
}
$iniLimit = static function (string $key): int {
    $v = trim((string) ini_get($key));
    if ($v === '') return 0;
    $unit = strtolower(substr($v, -1));
    $n = (int) $v;
    return $unit === 'm' ? $n * 1048576
         : ($unit === 'g' ? $n * 1073741824
         : ($unit === 'k' ? $n * 1024 : $n));
};
$iniCaps = array_values(array_filter([
    $iniLimit('upload_max_filesize'),
    $iniLimit('post_max_size'),
]));
$iniMax = $iniCaps ? min($iniCaps) : PHP_INT_MAX;
if ($iniMax !== PHP_INT_MAX && ($file['size'] ?? 0) > $iniMax) {
    jresp(['ok' => false, 'error' => 'FILE_TOO_LARGE',
           'max' => $iniMax, 'php_ini_limit' => true,
           'message' => 'حجم فایل از سقف تنظیم‌شده روی سرور بیشتر است. '
                      . 'مقدار upload_max_filesize و post_max_size در php.ini '
                      . 'باید حداقل ' . ADMIN_UPLOAD_MAX_LABEL . ' باشد.'], 413);
}

$info = @getimagesize($file['tmp_name']);
if (!$info) jresp(['ok' => false, 'error' => 'INVALID_IMAGE'], 400);

$typeMap = [
    IMAGETYPE_JPEG => 'imagecreatefromjpeg',
    IMAGETYPE_PNG  => 'imagecreatefrompng',
    IMAGETYPE_GIF  => 'imagecreatefromgif',
];
if (function_exists('imagecreatefromwebp')) {
    $typeMap[IMAGETYPE_WEBP] = 'imagecreatefromwebp';
}
if (!isset($typeMap[$info[2]])) {
    jresp(['ok' => false, 'error' => 'UNSUPPORTED_FORMAT'], 400);
}

$srcW = (int) $info[0];
$srcH = (int) $info[1];
if ($srcW < 1 || $srcH < 1) {
    jresp(['ok' => false, 'error' => 'ZERO_DIM'], 400);
}
$maxEdge   = 2400;
$maxPixels = 4_000_000;
if ($srcW > $maxEdge || $srcH > $maxEdge) {
    jresp(['ok' => false, 'error' => 'IMAGE_TOO_LARGE',
           'max' => $maxEdge, 'message' => 'ابعاد تصویر بیش از حد بزرگ است'], 400);
}
if (($srcW * $srcH) > $maxPixels) {
    jresp(['ok' => false, 'error' => 'IMAGE_TOO_LARGE',
           'max_pixels' => $maxPixels, 'message' => 'ابعاد تصویر بیش از حد بزرگ است'], 400);
}

$src = @$typeMap[$info[2]]($file['tmp_name']);
if (!$src) jresp(['ok' => false, 'error' => 'DECODE_FAILED'], 500);

$side  = min($srcW, $srcH);
$cropX = (int) (($srcW - $side) / 2);
$cropY = (int) (($srcH - $side) / 2);

$dst = imagecreatetruecolor(1024, 1024);
if (!$dst) { imagedestroy($src); jresp(['ok' => false, 'error' => 'CANVAS_FAILED'], 500); }

imagealphablending($dst, false);
imagesavealpha($dst, true);
$transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
imagefilledrectangle($dst, 0, 0, 1023, 1023, $transparent);
imagealphablending($dst, true);

if (!imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, 1024, 1024, $side, $side)) {
    imagedestroy($src); imagedestroy($dst);
    jresp(['ok' => false, 'error' => 'RESIZE_FAILED'], 500);
}
imagedestroy($src);

$dir = product_upload_dir();
if ($dir === '') {
    /* product_upload_dir() returns '' rather than a path it could not prove is
       inside STORAGE_DIR. The old code called is_dir() on it, got false for
       the empty string, and answered DIR_NOT_WRITABLE — an accurate-sounding
       error about a directory that was never really identified. */
    imagedestroy($dst);
    jresp(['ok' => false, 'error' => 'DIR_NOT_WRITABLE',
           'message' => 'پوشهٔ آپلود روی سرور قابل ایجاد نیست — دسترسی نوشتن را بررسی کنید'], 500);
}
if (!is_dir($dir) || !is_writable($dir)) {
    imagedestroy($dst);
    jresp(['ok' => false, 'error' => 'DIR_NOT_WRITABLE'], 500);
}

/* The filename is generated here, from the validated product id and 64 bits of
   randomness, and then validated again by the same predicate every other read
   of that name goes through. Two independent checks on one value is the point:
   the generator cannot produce something the validator rejects, and if it ever
   did, the write below still would not happen. */
$filename = product_upload_filename($productId);
$filepath = $dir . '/' . $filename;

if (!is_safe_upload_filename($filename)) {
    imagedestroy($dst);
    jresp(['ok' => false, 'error' => 'FILENAME_TOO_LONG',
           'message' => 'نام شناسهٔ محصول برای ساخت نام فایل معتبر بسیار طولانی است'], 400);
}

if (!imagewebp($dst, $filepath, 86)) {
    imagedestroy($dst);
    jresp(['ok' => false, 'error' => 'WEBP_SAVE_FAILED'], 500);
}
imagedestroy($dst);
@chmod($filepath, 0644);

$replaced = false;
$replaceName = req_str('replace_filename');
if ($replaceName !== '' && is_safe_upload_filename($replaceName)) {
    $oldPath = $dir . '/' . $replaceName;
    if (is_file($oldPath)) {
        @unlink($oldPath);
        $replaced = true;
    }
}

$fileBytes = (int) @filesize($filepath);

try {
    $stale = ($replaced ? $replaceName : null);
    velora_catalog_transaction(function(array $data, array &$out) use ($productId, $filename, $stale): bool {
        foreach ($out['products'] as &$p) {
            if (($p['id'] ?? '') !== $productId) continue;
            $gal = is_array($p['gallery'] ?? null) ? $p['gallery'] : [];
            if ($stale !== null && $stale !== '') {
                $gal = array_values(array_filter(
                    $gal, static fn($k): bool => $k !== $stale
                ));
            }
            if (!in_array($filename, $gal, true)) {
                $gal[] = $filename;
            }
            $p['gallery'] = $gal;
            break;
        }
        unset($p);
        return true;
    });
} catch (Throwable $e) {
    log_action('ADMIN_UPLOAD_CATALOG_WRITE_FAIL', ['error' => $e->getMessage(), 'product_id' => $productId]);
}

log_action('ADMIN_IMAGE_UPLOAD', [
    'product_id' => $productId,
    'filename'   => $filename,
    'replaced'   => $replaced ? $replaceName : null,
    'bytes'      => $fileBytes,
]);

jresp([
    'ok'       => true,
    'url'      => product_upload_url_base() . '/' . $filename,
    'filename' => $filename,
    'width'    => 1024,
    'height'   => 1024,
    'bytes'    => $fileBytes,
    'replaced' => $replaced,
]);
    return;
}

/* ---- admin_login ---- */
if ($action === 'admin_login') {
csrf_check();
if (!rate_limit('admin_login', 5, 300)) jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);

if (!ADMIN_HASH_CONFIGURED) {
    log_action('ADMIN_LOGIN_MISCONFIGURED', ['ip' => get_client_ip()]);
    jresp([
        'ok'      => false,
        'error'   => 'ADMIN_PASSWORD_NOT_HASHED',
        'message' => 'پیکربندی سرور ناقص است: رمز مدیر به‌صورت هش ذخیره نشده است. با مدیر سرور تماس بگیرید.',
    ], 503);
}

$user = req_str('username');
$pass = (string) req('password');

$userOk = hash_equals(ADMIN_USER, $user);
$passMode = is_password_hash(ADMIN_PASS_HASH) ? 'hash' : 'plain';

$dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
$effectiveHash = ($userOk && is_password_hash(ADMIN_PASS_HASH))
    ? ADMIN_PASS_HASH
    : $dummyHash;
$passOk = password_verify($pass, $effectiveHash) && $userOk;

if (!$userOk || !$passOk) {
    log_action('ADMIN_LOGIN_FAIL', [
        'username_sent'     => $user,
        'username_sent_len' => strlen($user),
        'username_cfg_len'  => strlen(ADMIN_USER),
        'username_ok'       => $userOk,
        'password_sent_len' => strlen($pass),
        'password_cfg_len'  => strlen(ADMIN_PASS_HASH),
        'password_mode'     => $passMode,
        'password_ok'       => $passOk,
        'ip'                => get_client_ip(),
    ]);
    jresp([
        'ok'      => false,
        'error'   => 'INVALID_CREDENTIALS',
        'message' => 'نام کاربری یا رمز عبور اشتباه است',
    ], 401);
}

if (admin_totp_enabled()) {
    if (!rate_limit('admin_totp', 5, 300, 'a' . session_id())) {
        log_action('ADMIN_TOTP_RATE_LIMIT', ['ip' => get_client_ip()]);
        jresp(['ok' => false, 'error' => 'RATE_LIMIT',
               'message' => 'تلاش‌های ناموفق زیاد بود. کمی بعد دوباره تلاش کنید.'], 429);
    }
    $secret = admin_totp_secret();
    $code   = req_str('code');
    if ($secret === '') {
        log_action('ADMIN_TOTP_SECRET_UNREADABLE', ['ip' => get_client_ip()]);
        jresp([
            'ok'      => false,
            'error'   => 'TOTP_NOT_CONFIGURED',
            'message' => 'کلید تأیید دوم‌مرحله‌ای قابل خواندن نیست. با مدیر سرور تماس بگیرید.',
        ], 503);
    }
    if (!admin_totp_verify($secret, $code)) {
        log_action('ADMIN_LOGIN_FAIL_2FA', [
            'code_len' => strlen($code),
            'ip'       => get_client_ip(),
        ]);
        jresp([
            'ok'      => false,
            'error'   => 'TOTP_INVALID',
            'message' => 'کد تأیید نامعتبر است یا منقضی شده',
        ], 401);
    }
}

session_regenerate_id(true);
$_SESSION['csrf']           = bin2hex(random_bytes(32));
$_SESSION['csrf_at']        = time();
$_SESSION['admin']          = true;
$_SESSION['admin_user']     = ADMIN_USER;
$_SESSION['admin_login_at'] = time();
log_action('ADMIN_LOGIN', [
    'username' => ADMIN_USER,
    'ip'       => get_client_ip(),
    'two_factor' => admin_totp_enabled() ? 'totp' : 'none',
]);
jresp(['ok' => true, 'message' => 'ورود موفق', 'csrf' => $_SESSION['csrf']]);
    return;
}

/* ---- admin_logout ---- */
if ($action === 'admin_logout') {
if (!is_admin()) jresp(['ok' => false], 401);
csrf_check();
log_action('ADMIN_LOGOUT', ['username' => $_SESSION['admin_user'] ?? 'unknown']);
unset($_SESSION['admin'], $_SESSION['admin_user'], $_SESSION['admin_login_at']);
jresp(['ok' => true]);
    return;
}

/* ---- admin_stats ---- */
if ($action === 'admin_stats') {
if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
if (!rate_limit('admin_read', 120, 60, 'a' . session_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$active = velora_catalog_active();
$lowStock = 0;
foreach ($active as $p) {
    $total = 0;
    foreach ((array) ($p['sizes'] ?? []) as $s) $total += (int) ($s['stock'] ?? 0);
    if ($total < 5) $lowStock++;
}
$stats = [
    'total_orders'   => (int) $pdo->query("SELECT COUNT(*) FROM velora_orders")->fetchColumn(),
    'paid_orders'    => (int) $pdo->query("SELECT COUNT(*) FROM velora_orders WHERE payment_status='paid'")->fetchColumn(),
    'revenue'        => (int) $pdo->query("SELECT COALESCE(SUM(total),0) FROM velora_orders WHERE payment_status='paid'")->fetchColumn(),
    'pending_orders' => (int) $pdo->query("SELECT COUNT(*) FROM velora_orders WHERE status='pending' AND payment_status='paid'")->fetchColumn(),
    'total_products' => count($active),
    'low_stock'      => $lowStock,
    'total_users'    => (int) $pdo->query("SELECT COUNT(*) FROM velora_users")->fetchColumn(),
    'today_orders'   => (int) $pdo->query("SELECT COUNT(*) FROM velora_orders WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY")->fetchColumn(),
];
jresp(['ok' => true, 'stats' => $stats]);
    return;
}

/* ---- admin_orders ---- */
if ($action === 'admin_orders') {
if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
if (!rate_limit('admin_read', 120, 60, 'a' . session_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$status = req_str('status');
$page   = max(1, req_int('page', 1));
$limit  = min(200, max(1, req_int('limit', 50)));
$offset = ($page - 1) * $limit;
$where = "1=1";
$params = [];
$allowed = ['pending','processing','shipped','delivered','cancelled'];
if ($status !== '' && in_array($status, $allowed, true)) {
    $where .= " AND o.status=?";
    $params[] = $status;
}
$sql = "SELECT o.*, u.name AS user_name, u.phone AS user_phone
    FROM velora_orders o
    LEFT JOIN velora_users u ON o.user_id = u.id
    WHERE $where
    ORDER BY o.created_at DESC, o.id DESC
    LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$st = $pdo->prepare($sql);
$st->execute($params);
$orders = $st->fetchAll();

$itemsByOrder = [];
if ($orders) {
    $ids = array_column($orders, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $ist = $pdo->prepare("SELECT order_id, product_id, product_name, qty, color, eu_size, unit_price
        FROM velora_order_items WHERE order_id IN ($in) ORDER BY id ASC");
    $ist->execute($ids);
    foreach ($ist->fetchAll() as $row) {
        $itemsByOrder[$row['order_id']][] = $row;
    }
}
foreach ($orders as &$o) {
    $o['items'] = $itemsByOrder[$o['id']] ?? [];
    /* address_snapshot is a JSON column, so PDO hands it over as a
       string. Decoding it here rather than in admin.php means the
       modal can read the parts directly, and an operator sees the
       province/city/plaque the customer actually typed instead of
       re-parsing a composed line by eye.
       Decoded defensively: a snapshot written before the column
       existed is NULL, and a hand-edited one must not be able to
       take the whole order list down. */
    $snap = null;
    if (isset($o['address_snapshot']) && is_string($o['address_snapshot']) && $o['address_snapshot'] !== '') {
        $decoded = json_decode($o['address_snapshot'], true);
        if (is_array($decoded)) $snap = $decoded;
    }
    unset($o['address_snapshot']);
    $o['address_snapshot'] = $snap;
    /* The postal code, formatted once, for the same reason the
       storefront formats it: one place decides how a code reads. */
    $o['postal_display'] = $snap && !empty($snap['postal_code'])
        ? velora_postal_format((string) $snap['postal_code'])
        : '';
}
unset($o);
jresp(['ok' => true, 'orders' => $orders, 'count' => count($orders), 'limit' => $limit]);
    return;
}

/* ---- admin_order_status ---- */
if ($action === 'admin_order_status') {
if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
csrf_check();
if (!rate_limit('admin_write', 120, 60, 'a' . session_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$oid    = req_str('id');
$status = req_str('status');
$allowed = ['pending','processing','shipped','delivered','cancelled'];
if (!in_array($status, $allowed, true) || $oid === '' || strlen($oid) > 32) {
    jresp(['ok' => false, 'error' => 'INVALID_STATUS'], 400);
}
$pdo->beginTransaction();
try {
    $sel = $pdo->prepare("SELECT id, status, payment_status FROM velora_orders WHERE id=? FOR UPDATE");
    $sel->execute([$oid]);
    $order = $sel->fetch();
    if (!$order) {
        $pdo->rollBack();
        jresp(['ok' => false, 'error' => 'NOT_FOUND', 'message' => 'سفارش یافت نشد'], 404);
    }
    $prev = (string) $order['status'];
    if ($prev === $status) {
        $pdo->commit();
        jresp(['ok' => true, 'message' => 'وضعیت تغییری نکرد', 'unchanged' => true]);
    }

    $FULFILMENT = ['processing', 'shipped', 'delivered'];
    $wasPaid    = ((string) $order['payment_status']) === 'paid';
    if (in_array($status, $FULFILMENT, true) && !$wasPaid) {
        $pdo->rollBack();
        log_action('ADMIN_ORDER_STATUS_BLOCKED', [
            'order_id' => $oid,
            'from'     => $prev,
            'to'       => $status,
            'payment'  => (string) $order['payment_status'],
        ]);
        jresp([
            'ok'      => false,
            'error'   => 'PAYMENT_REQUIRED',
            'message' => 'این سفارش پرداخت نشده است — ابتدا باید پرداخت آن تأیید شود',
        ], 409);
    }

    $pdo->prepare("UPDATE velora_orders SET status=?, updated_at=NOW() WHERE id=?")
        ->execute([$status, $oid]);

    $stockInfo = ['items' => 0, 'restored' => 0, 'short' => 0];
    if ($status === 'cancelled' && $prev !== 'cancelled') {
        $stockInfo = adjust_order_stock($pdo, $oid, +1, $wasPaid);
    } elseif ($prev === 'cancelled' && $status !== 'cancelled') {
        $stockInfo = adjust_order_stock($pdo, $oid, -1, $wasPaid);
        if ($stockInfo['short'] > 0) {
            throw new Exception('STOCK_SHORT:' . $stockInfo['short']);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (str_starts_with($e->getMessage(), 'STOCK_SHORT:')) {
        jresp([
            'ok'      => false,
            'error'   => 'INSUFFICIENT_STOCK',
            'message' => 'موجودی کافی نیست تا این سفارش دوباره فعال شود',
        ], 409);
    }
    log_action('ADMIN_ORDER_STATUS_FAIL', ['order_id' => $oid, 'error' => $e->getMessage()]);
    jresp(['ok' => false, 'error' => 'STATUS_FAIL', 'message' => 'خطا در به‌روزرسانی وضعیت'], 500);
}

log_action('ADMIN_ORDER_STATUS', [
    'order_id' => $oid,
    'from'     => $prev,
    'status'   => $status,
    'stock'    => $stockInfo,
]);
jresp([
    'ok'      => true,
    'message' => 'وضعیت به‌روزرسانی شد',
    'stock'   => $stockInfo,
]);
    return;
}

/* ---- admin_products ---- */
if ($action === 'admin_products') {
if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
if (!rate_limit('admin_read', 60, 60, 'a' . session_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$products = [];
foreach (velora_catalog_products() as $p) {
    $c = velora_catalog_client_shape($p);
    $total = 0;
    foreach ($c['sizes'] as $s) $total += (int) $s['stock'];
    $products[] = [
        'id'          => $c['id'],
        'name'        => $c['name'],
        'cat'         => $c['cat'],
        'sub'         => $c['sub'],
        'desc'        => $c['desc'],
        'price'       => $c['price'],
        'old_price'   => $c['old'],
        'heel'        => $c['heel'],
        'eta'         => $c['eta'],
        'is_new'      => $c['isNew'],
        'sold'        => $c['sold'],
        'active'      => !empty($p['active']) ? 1 : 0,
        'feats'       => $c['feats'],
        'specs'       => $c['specs'],
        'colors'      => array_map(static fn(array $c): array => ['key' => $c[0], 'name' => $c[1]], $c['colors']),
        'sizes'       => $c['sizes'],
        'gallery'     => implode('|', $c['gallery']),
        'total_stock' => $total,
    ];
}
jresp(['ok' => true, 'products' => $products, 'catalog' => velora_catalog_health()]);
    return;
}

/* ---- admin_product_save ---- */
if ($action === 'admin_product_save') {
if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
csrf_check();
if (!rate_limit('admin_write', 60, 60, 'a' . session_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}

$id    = req_str('id');
$name  = req_str('name');
$cat   = req_str('cat');
$sub   = req_str('sub');
$desc  = req_str('desc');
$price = req_int('price');
$old   = req_int('old_price');
$heel  = req_int('heel');
$isNew = min(1, max(0, req_int('is_new')));
$eta   = req_int('eta', 4);
$mode  = req_str('mode', 'update') === 'create' ? 'create' : 'update';

if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id)) jresp(['ok' => false, 'error' => 'ID_INVALID'], 400);

if ($mode === 'create' && !preg_match('/^p-[0-9a-f]{20}$/', $id)) {
    jresp(['ok' => false, 'error' => 'ID_NOT_SERVER_ISSUED', 'message' => 'شناسه باید توسط سرور تولید شده باشد'], 400);
}
if ($name === '' || mb_strlen($name) > 255
    || mb_strlen($sub)  > 512
    || mb_strlen($desc) > 8000
    || $price < 0 || $price > 2_000_000_000
    || $old   < 0 || $old   > 2_000_000_000
    || $heel  < 0 || $heel  > 300
    || $eta   < 0 || $eta   > 90) {
    jresp(['ok' => false, 'error' => 'INVALID_INPUT'], 400);
}

/* The category is a closed vocabulary, not a free string.

   It was validated only by length — `strlen($cat) > 64` — so any 64
   characters were accepted and written to products.json. The only
   thing stopping a typo was the admin panel's <select>, and a panel
   is a UI, not a constraint: a stale tab, a restored session, a
   hand-edited request, or a category added to the panel and not to
   the validator all put an unreachable product into the catalogue.

   Unreachable is the right word for the failure. Nothing filters by
   an unknown category, so the product renders on a card, opens on
   its own page, and can be bought — and it can never be found
   again by browsing, because no category chip matches it. A product
   that exists and cannot be located is worse than a save that is
   refused, so the vocabulary is enforced where the write happens,
   against the same CATEGORY_SLUGS the panel builds its <select>
   from.

   The rejection names the allowed values. "INVALID_INPUT" would tell
   an operator their save failed and nothing about why. */
if (!in_array($cat, CATEGORY_SLUGS, true)) {
    jresp([
        'ok'      => false,
        'error'   => 'CATEGORY_INVALID',
        'message' => 'دستهٔ نامعتبر است — یکی از این‌ها را انتخاب کنید: '
                     . implode('، ', CATEGORY_LABELS),
        'allowed' => array_values(CATEGORY_SLUGS),
    ], 400);
}

if ($old > 0 && $old <= $price) {
    jresp(['ok' => false, 'error' => 'OLD_PRICE_INVALID', 'message' => 'قیمت پیش از تخفیف باید بیشتر از قیمت فعلی باشد'], 400);
}

$hasColors  = req_has('colors');
$hasSizes   = req_has('sizes');
$hasGallery = req_has('gallery');
$hasFeats   = req_has('feats');
$hasSpecs   = req_has('specs');

$existingWidth = null;
$existingSole  = null;
$existing = velora_catalog_product($id);
if ($existing !== null) {
    $existingWidth = $existing['width'] ?? 'r';
    $existingSole  = $existing['sole']  ?? 'leather';
}
$width = req_has('width') ? req_str('width', 'r') : ($existingWidth ?? 'r');
$sole  = req_has('sole')  ? req_str('sole', 'leather') : ($existingSole ?? 'leather');
if (!in_array($width, ['n','r','w'], true)) $width = $existingWidth ?? 'r';
if (!in_array($sole,  ['leather','rubber'], true)) $sole = $existingSole ?? 'leather';

$sizesLockedBy = null;
if ($hasSizes) {
    $holdSt = $pdo->prepare(
        "SELECT o.id
           FROM velora_orders o
           JOIN velora_order_items oi ON oi.order_id = o.id
          WHERE oi.product_id = ?
            AND o.status = 'pending'
            AND o.payment_status IN ('unpaid','pending')
          ORDER BY o.created_at ASC
          LIMIT 1"
    );
    $holdSt->execute([$id]);
    $held = $holdSt->fetch();
    if ($held) $sizesLockedBy = (string) $held['id'];
}

$colorsPayload  = $hasColors  ? req_json('colors')  : null;
$sizesPayload   = $hasSizes   ? req_json('sizes')   : null;
$galleryPayload = $hasGallery ? req_json('gallery') : null;
$featsPayload   = $hasFeats   ? req_json('feats')   : null;
$specsPayload   = $hasSpecs   ? req_json('specs')   : null;

try {
    velora_catalog_transaction(function(array $data, array &$out) use (
        $mode, $id, $name, $cat, $sub, $desc, $price, $old, $heel, $width, $sole,
        $isNew, $eta, $colorsPayload, $sizesPayload, $galleryPayload,
        $featsPayload, $specsPayload,
        $sizesLockedBy, $hasColors, $hasSizes, $hasGallery, $hasFeats, $hasSpecs
    ): bool {
        $idx = null;
        foreach ($out['products'] as $i => $prod) {
            if (($prod['id'] ?? '') === $id) { $idx = $i; break; }
        }
        if ($mode === 'create' && $idx !== null) {
            throw new RuntimeException('PRODUCT_EXISTS');
        }
        if ($mode === 'update' && $idx === null) {
            throw new RuntimeException('PRODUCT_NOT_FOUND');
        }

        $existing = $idx !== null ? $out['products'][$idx] : [];
        $record = [
            'id'         => $id,
            'name'       => $name,
            'cat'        => $cat,
            'sub'        => $sub,
            'desc'       => $desc,
            'price'      => $price,
            'old_price'  => $old,
            'heel'       => $heel,
            'width'      => $width,
            'sole'       => $sole,
            'is_new'     => $isNew === 1,
            'sold'       => (int) ($existing['sold'] ?? 0),
            'eta'        => $eta,
            'bias'       => (float) ($existing['bias'] ?? 0),
            'active'     => true,
            'sort_order' => (int) ($existing['sort_order'] ?? (count($out['products']))),
            'drop_date'  => (string) ($existing['drop_date'] ?? date('Y-m-d')),
            'feats'      => $existing['feats']   ?? [],
            'specs'      => $existing['specs']   ?? [],
            'colors'     => $existing['colors']  ?? [],
            'sizes'      => $existing['sizes']   ?? [],
            'gallery'    => $existing['gallery'] ?? [],
        ];

        if ($hasFeats && is_array($featsPayload)) {
            $record['feats'] = array_values(array_filter(array_map(
                static fn($v): string => trim((string) $v), $featsPayload
            ), 'strlen'));
        }
        if ($hasSpecs && is_array($specsPayload)) {
            $spec = [];
            foreach ($specsPayload as $k => $v) {
                $k = trim((string) $k);
                if ($k === '') continue;
                if (is_scalar($v) || $v === null) $spec[$k] = (string) $v;
            }
            $record['specs'] = $spec;
        }
        if ($hasColors && is_array($colorsPayload)) {
            $clean = [];
            foreach ($colorsPayload as $c) {
                if (!is_array($c)) continue;
                $k = trim((string) ($c['key']  ?? ''));
                $n = trim((string) ($c['name'] ?? ''));
                if ($k === '' || $n === '') continue;
                $clean[] = ['key' => mb_substr($k, 0, 64), 'name' => mb_substr($n, 0, 128)];
                if (count($clean) >= 32) break;
            }
            $record['colors'] = $clean;
        }
        if ($hasSizes && is_array($sizesPayload) && $sizesLockedBy === null) {
            $clean = [];
            $seen  = [];
            foreach ($sizesPayload as $s) {
                if (!is_array($s)) continue;
                $eu = (int) ($s['eu']    ?? 0);
                $st = (int) ($s['stock'] ?? 0);
                if ($eu < SIZE_MIN || $eu > SIZE_MAX) continue;
                if ($st < 0 || $st > 100000) continue;
                if (isset($seen[$eu])) continue;
                $seen[$eu] = true;
                $clean[] = ['eu' => $eu, 'stock' => $st];
            }
            usort($clean, static fn(array $a, array $b): int => $a['eu'] <=> $b['eu']);
            $record['sizes'] = $clean;
        }
        if ($hasGallery && is_array($galleryPayload)) {
            $clean = [];
            foreach ($galleryPayload as $g) {
                if (!is_string($g)) continue;
                $key = sanitize_image_key($g);
                if ($key !== '') $clean[] = $key;
                if (count($clean) >= 32) break;
            }
            $record['gallery'] = $clean;
        }

        if ($idx !== null) $out['products'][$idx] = $record;
        else               $out['products'][]       = $record;
        return true;
    });

    log_action('ADMIN_PRODUCT_SAVE', [
        'product_id'     => $id,
        'mode'           => $mode,
        'sizes_skipped'  => $sizesLockedBy,
        'colors_replaced'=> $hasColors,
        'gallery_replaced'=> $hasGallery,
    ]);
    $saved = ['ok' => true, 'message' => 'محصول ذخیره شد'];
    if ($sizesLockedBy !== null) {
        $saved['sizes_skipped']    = true;
        $saved['blocked_by_order'] = $sizesLockedBy;
        $saved['message']          = 'محصول ذخیره شد — اما موجودی سایزها تغییر نکرد';
        $saved['warning']          = 'سفارش ' . $sizesLockedBy . ' هنوز در انتظار پرداخت این محصول است. '
                           . 'موجودی سایزها برای جلوگیری از فروش مضاعف تغییر داده نشد؛ چند دقیقه دیگر دوباره تلاش کنید.';
    }
    jresp($saved);
} catch (Throwable $e) {
    if ($e->getMessage() === 'PRODUCT_EXISTS') {
        jresp(['ok' => false, 'error' => 'PRODUCT_EXISTS', 'message' => 'این شناسه قبلاً استفاده شده است'], 409);
    }
    if ($e->getMessage() === 'PRODUCT_NOT_FOUND') {
        jresp(['ok' => false, 'error' => 'PRODUCT_NOT_FOUND', 'message' => 'محصول یافت نشد؛ ممکن است حذف شده باشد'], 404);
    }
    log_action('ADMIN_PRODUCT_SAVE_FAIL', ['error' => $e->getMessage(), 'product_id' => $id]);
    jresp(['ok' => false, 'error' => 'SAVE_FAILED', 'message' => APP_DEBUG ? $e->getMessage() : 'خطای ذخیره‌سازی'], 500);
}
    return;
}

/* ---- admin_product_rename ---- */
if ($action === 'admin_product_rename') {
if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
csrf_check();
if (!rate_limit('admin_write', 30, 60, 'a' . session_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$from = req_str('from');
$to   = req_str('to');

if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $from)) {
    jresp(['ok' => false, 'error' => 'ID_INVALID'], 400);
}
if (!preg_match('/^[a-z0-9](?:[a-z0-9_-]{0,62}[a-z0-9])?$/i', $to)) {
    jresp(['ok' => false, 'error' => 'ID_INVALID', 'message' => 'شناسه باید با حرف یا رقم شروع و تمام شود و فقط شامل a-z 0-9 - _ باشد'], 400);
}
if ($from === $to) {
    jresp(['ok' => true, 'id' => $to, 'message' => 'شناسه تغییری نکرد']);
}

try {
    $probe = $pdo->prepare('SELECT 1 FROM velora_order_items WHERE product_id = ? LIMIT 1');
    $probe->execute([$from]);
    if ($probe->fetchColumn() !== false) {
        jresp(['ok' => false, 'error' => 'ID_IN_HISTORY', 'message' => 'این شناسه در سفارش‌های ثبت‌شده استفاده شده و قابل تغییر نیست — محصول جدید بسازید و قبلی را غیرفعال کنید'], 409);
    }
} catch (Throwable $e) {
    log_action('ADMIN_RENAME_PROBE_FAIL', ['product_id' => $from, 'error' => $e->getMessage()]);
    jresp(['ok' => false, 'error' => 'HISTORY_UNAVAILABLE', 'message' => 'بررسی تاریخچهٔ سفارش‌ها ممکن نشد؛ شناسه تغییر نکرد'], 503);
}

$renamed = false;
$taken   = false;
velora_catalog_transaction(function (array $data, array &$out) use ($from, $to, &$renamed, &$taken): bool {
    $found = false;
    foreach ($out['products'] as $p) {
        $pid = (string) ($p['id'] ?? '');
        if ($pid === $to)   { $taken = true; }
        if ($pid === $from) { $found = true; }
    }
    if (!$found || $taken) { return true; }
    foreach ($out['products'] as &$p) {
        if ((string) ($p['id'] ?? '') === $from) { $p['id'] = $to; $renamed = true; break; }
    }
    unset($p);
    return true;
});

if ($taken) {
    jresp(['ok' => false, 'error' => 'ID_TAKEN', 'message' => 'شناسهٔ جدید قبلاً به محصول دیگری اختصاص دارد'], 409);
}
if (!$renamed) {
    jresp(['ok' => false, 'error' => 'NOT_FOUND', 'message' => 'محصول با این شناسه پیدا نشد'], 404);
}

log_action('ADMIN_PRODUCT_RENAME', ['from' => $from, 'to' => $to]);
jresp(['ok' => true, 'id' => $to, 'message' => 'شناسهٔ محصول تغییر کرد']);
    return;
}

/* ---- admin_product_image_delete ---- */
if ($action === 'admin_product_image_delete') {
if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
csrf_check();
if (!rate_limit('admin_write', 120, 60, 'a' . session_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$productId = req_str('product_id');
$filename  = req_str('filename');
if ($productId === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $productId)) {
    jresp(['ok' => false, 'error' => 'ID_INVALID'], 400);
}
if (!is_safe_upload_filename($filename)) {
    jresp(['ok' => false, 'error' => 'FILENAME_INVALID', 'message' => 'فقط عکس‌های آپلودشدهٔ همین سایت قابل حذف هستند'], 400);
}

$entryGone = false;
$shared    = false;
velora_catalog_transaction(function (array $data, array &$out) use ($productId, $filename, &$entryGone, &$shared): bool {
    foreach ($out['products'] as &$p) {
        if ((string) ($p['id'] ?? '') !== $productId) { continue; }
        $gal = is_array($p['gallery'] ?? null) ? $p['gallery'] : [];
        if (in_array($filename, $gal, true)) {
            $p['gallery'] = array_values(array_filter(
                $gal, static fn($k): bool => $k !== $filename
            ));
            $entryGone = true;
        }
        break;
    }
    unset($p);
    foreach ($out['products'] as $p) {
        foreach ((array) ($p['gallery'] ?? []) as $k) {
            if ($k === $filename) { $shared = true; break 2; }
        }
    }
    return true;
});

if ($shared) {
    jresp(['ok' => false, 'error' => 'IMAGE_SHARED', 'message' => 'این عکس در گالری محصول دیگری هم استفاده شده — فقط ارجاع آن حذف شد'], 409);
}

$fileGone = false;
/* product_upload_path() re-validates the grammar *and* re-resolves the path
   with realpath(), so a symlink planted inside the uploads directory cannot
   turn this unlink into a delete somewhere else on the filesystem. The old
   `$dir . '/' . $filename` trusted the earlier validation and nothing else. */
$path = product_upload_path($filename);
if ($path !== '') {
    $fileGone = @unlink($path);
}

log_action('ADMIN_IMAGE_DELETE', [
    'product_id'   => $productId,
    'filename'     => $filename,
    'entry_removed'=> $entryGone ? 1 : 0,
    'file_deleted' => $fileGone ? 1 : 0,
]);
jresp([
    'ok'            => true,
    'entry_removed' => $entryGone,
    'file_deleted'  => $fileGone,
    'message'       => $fileGone ? 'عکس حذف شد' : 'ارجاع عکس حذف شد',
]);
    return;
}

/* ---- admin_product_delete ---- */
if ($action === 'admin_product_delete') {
if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
csrf_check();
if (!rate_limit('admin_write', 120, 60, 'a' . session_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$id = req_str('id');
if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id)) jresp(['ok' => false, 'error' => 'ID_REQUIRED'], 400);
velora_catalog_transaction(function(array $data, array &$out) use ($id): bool {
    foreach ($out['products'] as &$p) {
        if (($p['id'] ?? '') === $id) { $p['active'] = false; break; }
    }
    unset($p);
    return true;
});
log_action('ADMIN_PRODUCT_DELETE', ['product_id' => $id]);
jresp(['ok' => true, 'message' => 'محصول غیرفعال شد']);
    return;
}

/* ---- admin_users ---- */
if ($action === 'admin_users') {
if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
if (!rate_limit('admin_read', 60, 60, 'a' . session_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$page   = max(1, req_int('page', 1));
$limit  = 100;
$offset = ($page - 1) * $limit;
$st = $pdo->prepare("SELECT u.id, u.phone, u.name, u.email, u.created_at,
    COUNT(o.id) AS order_count,
    COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) AS total_spent
    FROM velora_users u
    LEFT JOIN velora_orders o ON u.id = o.user_id
    GROUP BY u.id, u.phone, u.name, u.email, u.created_at
    ORDER BY u.created_at DESC
    LIMIT ? OFFSET ?");
$st->execute([$limit, $offset]);
jresp(['ok' => true, 'users' => $st->fetchAll()]);
    return;
}

/* ---- admin_appointments ---- */
if ($action === 'admin_appointments') {
if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
if (!rate_limit('admin_read', 60, 60, 'a' . session_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$st = $pdo->query("SELECT * FROM velora_appointments ORDER BY date DESC, time ASC LIMIT 100");
jresp(['ok' => true, 'appointments' => $st->fetchAll()]);
    return;
}

/* ---- admin_appointment_status ---- */
if ($action === 'admin_appointment_status') {
if (!is_admin()) jresp(['ok' => false, 'error' => 'UNAUTHORIZED'], 403);
csrf_check();
if (!rate_limit('admin_write', 120, 60, 'a' . session_id())) {
    jresp(['ok' => false, 'error' => 'RATE_LIMIT'], 429);
}
$aid    = req_int('id');
$status = req_str('status');
$allowed = ['pending','confirmed','cancelled'];
if (!in_array($status, $allowed, true) || $aid <= 0) {
    jresp(['ok' => false, 'error' => 'INVALID_STATUS'], 400);
}
$pdo->prepare("UPDATE velora_appointments SET status=? WHERE id=?")
    ->execute([$status, $aid]);
log_action('ADMIN_APPOINTMENT_STATUS', ['appointment_id' => $aid, 'status' => $status]);
jresp(['ok' => true, 'message' => 'وضعیت به‌روزرسانی شد']);
    return;
}

