<?php
/* ═══════════════════════════════════════════════════════════════════════
   MAISON AURELLE — api/admin.php
   پنل مدیریت — تنها دروازهٔ نوشتن روی کاتالوگ، رسانه و تنظیمات
   ─────────────────────────────────────────────────────────────────────
   پیش از هر اکشنی ae_require_admin() اجرا می‌شود: نشست باید با همان
   شماره‌ای تأیید شده باشد که در config.php آمده است. شماره هرگز به
   مرورگر برنمی‌گردد.

   ورودی (JSON یا multipart): { "action": … }
     state          → وضعیت کامل پنل (کاتالوگ، رسانه، سفارش، آمار)
     product_save   → { id?, product:{…} }        محصول را می‌سازد/به‌روز می‌کند
     product_delete → { id }                      محصول را از لایهٔ مدیریت برمی‌دارد
     product_order  → { order:[id,…] }            ترتیب نمایش
     settings_save  → { promo_code, promo_pct }   کد تخفیف
     media_upload   → multipart با فیلد file
     media_update   → { id, name, alt }
     media_delete   → { id, detach }
     orders_list    → { status? }
     order_status   → { ref, status }
   ═══════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/bootstrap.php';

ae_guard_request();
ae_gc();
ae_require_admin();

$in     = ae_input();
$action = (string) ($in['action'] ?? '');

/* بدنهٔ خراب نباید بی‌صدا به «state» بیفتد و کل وضعیت را لو بدهد */
if ($action === '') {
    ae_err('درخواست نامعتبر است — اکشن مشخص نیست.', 'bad_request', 400);
}

/* ─── کمکی‌های پنل ──────────────────────────────────────────────────────── */

/** خانواده‌های کفش: از data.js خوانده می‌شود، با فهرست ثابت به‌عنوان پشتیبان. */
function adm_families(): array
{
    static $out = null;
    if ($out !== null) return $out;
    $fallback = ['maryjane', 'loafer', 'highheel', 'lowheel', 'ballet', 'boot', 'sandal'];
    $js  = @file_get_contents(dirname(__DIR__) . '/js/data.js');
    $out = $fallback;
    if (is_string($js) && preg_match('/IDX_CATS\s*=\s*\[([^\]]+)\]/u', $js, $m)) {
        $found = [];
        foreach (explode(',', $m[1]) as $q) {
            $q = trim($q, " \t\n\r'\"");
            if (preg_match('/^[a-z]{2,20}$/', $q)) $found[] = $q;
        }
        if ($found) $out = $found;
    }
    return $out;
}

/** سقف تعداد محصول و محدودیت‌های پیکربندی — برای نمایش در UI. */
function adm_limits(): array
{
    $cfg = ae_config()['admin'] ?? [];
    return [
        'max_products'  => max(1, (int) ($cfg['max_products'] ?? 120)),
        'max_upload_mb' => max(1, (int) ($cfg['max_upload_mb'] ?? 8)),
    ];
}

/** کاتالوگ لایهٔ مدیریت، مرتب‌شده بر اساس ترتیب ذخیره‌شده. */
function adm_catalog_out(): array
{
    $store = ae_catalog_store();
    $items = $store['items'];
    $order = array_values(array_filter((array) $store['order'], static fn($id) => isset($items[(string) $id])));
    $out   = [];
    foreach ($order as $id) $out[(string) $id] = $items[(string) $id];
    foreach ($items as $id => $p) if (!isset($out[(string) $id])) $out[(string) $id] = $p;   /* تازه‌ها ته صف */
    return $out;
}

/** فهرست سفارش‌ها، تازه‌ترین اول، با آمار تجمیعی. */
function adm_orders_out(string $filter = ''): array
{
    $list = ae_read_json(ae_orders_file());
    if (!is_array($list)) $list = [];

    $rows = [];
    $sum  = ['orders' => 0, 'paid' => 0, 'reserved' => 0, 'canceled' => 0, 'failed' => 0,
             'revenue' => 0, 'items' => 0];
    foreach ($list as $o) {
        if (!is_array($o)) continue;
        $st = (string) ($o['status'] ?? 'paid');
        $sum['orders']++;
        if (isset($sum[$st])) $sum[$st]++;
        if ($st === 'paid') $sum['revenue'] += (int) ($o['total'] ?? 0);
        $sum['items'] += (int) ($o['count'] ?? 0);

        if ($filter !== '' && $st !== $filter) continue;
        $rows[] = [
            'ref'       => (string) ($o['ref'] ?? ''),
            'date'      => (string) ($o['date'] ?? ''),
            'ts'        => (int) (strtotime((string) ($o['date'] ?? '')) ?: 0),
            'status'    => $st,
            'total'     => (int) ($o['total'] ?? 0),
            'subtotal'  => (int) ($o['subtotal'] ?? 0),
            'discount'  => (int) ($o['discount'] ?? 0),
            'count'     => (int) ($o['count'] ?? 0),
            'phone'     => (string) ($o['phone'] ?? ''),
            'email'     => (string) ($o['email'] ?? ''),
            'name'      => (string) ($o['name'] ?? ''),
            'address'   => (string) ($o['address'] ?? ''),
            'zip'       => (string) ($o['zip'] ?? ''),
            'country'   => (string) ($o['country'] ?? ''),
            'gateway'   => (string) ($o['pay']['gateway'] ?? ''),
            'card'      => (string) ($o['pay']['card_mask'] ?? ''),
            'authority' => (string) ($o['pay']['authority'] ?? ''),
            'items'     => array_slice((array) ($o['items'] ?? []), 0, 40),
        ];
    }
    usort($rows, static fn($a, $b) => ($b['ts'] <=> $a['ts']) ?: strcmp($b['ref'], $a['ref']));
    return ['rows' => array_slice($rows, 0, 200), 'sum' => $sum];
}

/** آمار کاتالوگ و رسانه برای داشبورد. */
function adm_stats(): array
{
    $items   = ae_catalog_store()['items'];
    $vis = $hid = $oos = $low = 0;
    foreach ($items as $p) {
        if (!is_array($p)) continue;
        if (!empty($p['hidden'])) $hid++; else $vis++;
        $s = (int) ($p['stock'] ?? 0);
        if ($s <= 0) $oos++;
        elseif ($s <= 3) $low++;
    }
    $index = ae_media_index();
    $bytes = 0;
    foreach ($index as $m) $bytes += (int) ($m['size'] ?? 0);

    $orders = adm_orders_out();
    return [
        'products' => [
            'total' => count($items), 'visible' => $vis, 'hidden' => $hid,
            'out_of_stock' => $oos, 'low_stock' => $low,
        ],
        'media'    => ['count' => count($index), 'bytes' => $bytes],
        'orders'   => $orders['sum'],
        'catalog_updated' => (int) ae_catalog_store()['updated'],
    ];
}

/** نوشتن کاتالوگ با قفل. */
function adm_catalog_save(array $store): bool
{
    return ae_store_write('catalog', $store);
}

/* ─── اکشن‌ها ───────────────────────────────────────────────────────────── */

if ($action === 'state') {
    $store  = ae_catalog_store();
    $merged = adm_catalog_out();
    ae_ok([
        'catalog'   => [
            'items'    => $merged,
            'order'    => array_keys($merged),
            'settings' => ae_settings(),
            'seeded'   => !empty($store['seeded']),
        ],
        'media'     => ae_media_index(),
        'orders'    => adm_orders_out((string) ($in['status'] ?? ''))['rows'],
        'stats'     => adm_stats(),
        'limits'    => adm_limits(),
        'families'  => adm_families(),
    ]);
}

/* یک‌بار در عمر انباره: کاتالوگ پایهٔ data.js را از مرورگر می‌گیریم تا پنل
   همهٔ فرم‌های سایت را ببیند، نه فقط آن‌هایی که قبلاً دست‌خورده‌اند. */
if ($action === 'catalog_seed') {
    $store = ae_catalog_store();
    if (!empty($store['seeded'])) {
        ae_ok(['seeded' => true, 'skipped' => true, 'count' => count($store['items'])]);
    }
    $raw = is_array($in['items'] ?? null) ? $in['items'] : [];
    $max = adm_limits()['max_products'];
    $n   = 0;
    foreach ($raw as $rec) {
        if (!is_array($rec) || $n >= $max) continue;
        $p = ae_clean_product($rec);
        if (isset($store['items'][$p['id']])) continue;
        $store['items'][$p['id']] = $p;
        $store['order'][] = $p['id'];
        $n++;
    }
    $store['seeded'] = true;
    $store['removed'] = [];
    if (!adm_catalog_save($store)) ae_err('ذخیرهٔ کاتالوگ پایه ممکن نشد.', 'storage', 500);
    ae_ok(['seeded' => true, 'count' => count($store['items'])]);
}

if ($action === 'product_save') {
    $incoming = is_array($in['product'] ?? null) ? $in['product'] : $in;
    $store    = ae_catalog_store();
    $id       = (string) preg_replace('/[^a-z0-9\-]/i', '', (string) ($incoming['id'] ?? $in['id'] ?? ''));

    $base  = isset($store['items'][$id]) && is_array($store['items'][$id]) ? $store['items'][$id] : [];
    $clean = ae_clean_product($incoming, $base);
    $id    = $clean['id'];

    $isNew = !isset($store['items'][$id]);
    if ($isNew && count($store['items']) >= adm_limits()['max_products']) {
        ae_err('سقف تعداد محصولات پر شده است.', 'too_many_products', 422);
    }
    if (isset($store['items'][$id]) && (string) ($base['id'] ?? '') !== '' && $id !== (string) $base['id']) {
        ae_err('شناسهٔ محصول معتبر نیست.', 'bad_id', 422);
    }

    $store['items'][$id] = $clean;
    $store['removed'] = array_values(array_filter((array) ($store['removed'] ?? []), static fn($x) => (string) $x !== $id));
    if ($isNew) {
        $store['order'][] = $id;
    } elseif (!in_array($id, (array) $store['order'], true)) {
        $store['order'][] = $id;
    }
    if (!adm_catalog_save($store)) ae_err('ذخیرهٔ محصول روی سرور ممکن نشد.', 'storage', 500);

    ae_ok(['product' => $clean, 'new' => $isNew, 'stats' => adm_stats()]);
}

if ($action === 'product_delete') {
    $id = (string) preg_replace('/[^a-z0-9\-]/i', '', (string) ($in['id'] ?? ''));
    $store = ae_catalog_store();
    if ($id === '' || !isset($store['items'][$id])) ae_err('محصول یافت نشد.', 'not_found', 404);

    unset($store['items'][$id]);
    $store['order'] = array_values(array_filter((array) $store['order'], static fn($x) => (string) $x !== $id));
    /* سنگ قبر: ویترین باید این شناسه را از data.js هم پاک کند */
    $store['removed'] = array_values(array_unique(array_merge(
        array_map('strval', (array) ($store['removed'] ?? [])),
        [$id]
    )));
    if (!adm_catalog_save($store)) ae_err('حذف محصول روی سرور ممکن نشد.', 'storage', 500);
    ae_ok(['id' => $id, 'stats' => adm_stats()]);
}

if ($action === 'product_order') {
    $store = ae_catalog_store();
    $raw   = is_array($in['order'] ?? null) ? $in['order'] : [];
    $seen  = [];
    $order = [];
    foreach ($raw as $id) {
        $id = (string) preg_replace('/[^a-z0-9\-]/i', '', (string) $id);
        if ($id === '' || isset($seen[$id]) || !isset($store['items'][$id])) continue;
        $seen[$id] = true;
        $order[]   = $id;
    }
    foreach (array_keys($store['items']) as $id) {           /* محصولات جاافتاده ته بمانند */
        $id = (string) $id;
        if (!isset($seen[$id])) $order[] = $id;
    }
    $store['order'] = $order;
    if (!adm_catalog_save($store)) ae_err('ذخیرهٔ ترتیب ممکن نشد.', 'storage', 500);
    ae_ok(['order' => $order]);
}

if ($action === 'settings_save') {
    $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($in['promo_code'] ?? '')));
    $pct  = (int) ($in['promo_pct'] ?? 0);
    if ($code === '')        ae_err('کد تخفیف را وارد کنید.', 'bad_code', 422);
    if ($pct < 1 || $pct > 90) ae_err('درصد تخفیف باید بین ۱ تا ۹۰ باشد.', 'bad_pct', 422);

    $store = ae_catalog_store();
    $store['settings'] = ['promo_code' => substr($code, 0, 24), 'promo_pct' => $pct];
    if (!adm_catalog_save($store)) ae_err('ذخیرهٔ تنظیمات ممکن نشد.', 'storage', 500);
    ae_ok(['settings' => ae_settings()]);
}

/* ─── رسانه ─────────────────────────────────────────────────────────────── */

if ($action === 'media_upload') {
    if (!isset($_FILES['file'])) ae_err('فایلی ارسال نشد.', 'no_file', 422);
    $rec = ae_media_store_upload($_FILES['file']);
    ae_ok(['media' => $rec, 'stats' => adm_stats()]);
}

if ($action === 'media_update') {
    $id = (string) preg_replace('/[^A-Za-z0-9_]/', '', (string) ($in['id'] ?? ''));
    $store = ae_media_store();
    if ($id === '' || !isset($store['items'][$id])) ae_err('رسانه یافت نشد.', 'not_found', 404);
    if (array_key_exists('name', $in)) $store['items'][$id]['name'] = ae_clean($in['name'], 80);
    if (array_key_exists('alt',  $in)) $store['items'][$id]['alt']  = ae_clean($in['alt'], 140);
    ae_store_write('media', $store);
    $rec = $store['items'][$id];
    $rec['used'] = [];
    ae_ok(['media' => $rec]);
}

if ($action === 'media_delete') {
    $id     = (string) preg_replace('/[^A-Za-z0-9_]/', '', (string) ($in['id'] ?? ''));
    $detach = !empty($in['detach']);
    $index  = ae_media_index();
    $used   = null;
    foreach ($index as $m) if ((string) $m['id'] === $id) $used = $m;
    if ($used === null) ae_err('رسانه یافت نشد.', 'not_found', 404);
    if (!$detach && !empty($used['used'])) {
        ae_err('این تصویر در ' . count($used['used']) . ' جا به کار رفته — اول آزادش کنید.', 'in_use', 409);
    }
    ae_media_delete($id, $detach);
    ae_ok(['id' => $id, 'stats' => adm_stats()]);
}

/* ─── سفارش‌ها ──────────────────────────────────────────────────────────── */

if ($action === 'orders_list') {
    $r = adm_orders_out((string) ($in['status'] ?? ''));
    ae_ok(['orders' => $r['rows'], 'sum' => $r['sum']]);
}

if ($action === 'order_status') {
    $ref = strtoupper((string) preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($in['ref'] ?? '')));
    $st  = (string) ($in['status'] ?? '');
    if (!in_array($st, ['paid', 'reserved', 'canceled', 'failed', 'shipped', 'delivered'], true)) {
        ae_err('وضعیت نامعتبر است.', 'bad_status', 422);
    }
    $fp   = ae_lock('orders');
    $list = ae_read_json(ae_orders_file());
    if (!is_array($list)) $list = [];
    $hit = false;
    foreach ($list as $i => $o) {
        if (strtoupper((string) ($o['ref'] ?? '')) === $ref) {
            $list[$i]['status']     = $st;
            $list[$i]['status_note']= ae_clean($in['note'] ?? '', 200);
            $list[$i]['status_at']  = date('c');
            $hit = true;
        }
    }
    if ($hit) ae_write_json(ae_orders_file(), $list);
    ae_unlock($fp);
    if (!$hit) ae_err('سفارش یافت نشد.', 'not_found', 404);
    ae_ok(['ref' => $ref, 'status' => $st]);
}

ae_err('اکشن نامعتبر است.', 'bad_action', 422);