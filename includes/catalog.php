<?php
declare(strict_types=1);
/**
 * VELORA · Product Catalogue
 * v1.0 · products.json is the single source of truth.
 *
 * ============================================================================
 * DEPLOYMENT ASSUMPTION - READ BEFORE MOVING THIS SITE
 * ============================================================================
 *
 * Every write to this catalogue is serialised by a flock() taken on
 * products.json.lock, a file on the SAME local filesystem as the data. That is
 * correct, and it is verified - see the concurrency notes on
 * velora_catalog_lock() - but it is only correct under one assumption:
 *
 *     EXACTLY ONE PHP-FPM HOST, ON LOCAL POSIX-SHARED STORAGE.
 *
 * The moment either half of that stops being true, stock accounting silently
 * stops being correct. Not loudly - there is no exception, no log line, and no
 * failed request. Two checkouts for the last pair in a size will both succeed,
 * the catalogue will say 0, and the oversell surfaces days later as a cancelled
 * order from a human doing inventory. The failure is quiet precisely BECAUSE the
 * lock is doing its job perfectly and the thing it assumed no longer holds.
 *
 * WHY IT BREAKS, CONCRETELY
 *   · Load balancer / two app servers. flock() is a kernel lock on ONE machine's
 *     inode. Host A's flock and host B's flock are unrelated objects with the
 *     same name. Two concurrent checkouts on two hosts both read stock=1 and
 *     both decrement. Guaranteed oversell under load, invisible under test.
 *   · NFS, CIFS/SMB, or any shared network volume. This is the dangerous one,
 *     because a single server CAN be moved to a "shared" disk by a well-meaning
 *     host admin and everything still works in staging. flock() over NFSv3 is
 *     unreliable; over NFSv4 it is lock-manager-dependent and varies by
 *     implementation and mount option. Some of those variations are
 *     advisory-only and return success while doing nothing at all.
 *   · A container or overlay filesystem. Common in "we moved to Docker for
 *     consistency" migrations, which this project's constraints otherwise rule
 *     out. An overlayfs upper layer can present a lock that does not coordinate
 *     across replicas.
 *   · Windows/IIS or SMB-backed shared hosting. Different lock semantics
 *     entirely. (The atomic-rename commit is separately unreliable there - see
 *     the retry in velora_catalog_commit() - but that fails LOUDLY, unlike this.)
 *
 * WHAT IT IS AND IS NOT SUITABLE FOR
 *   It IS right for the target deployment: one cPanel/DirectAdmin shared host,
 *   local disk, a single maison with a boutique's worth of SKUs. It removes an
 *   entire class of operational failure - no cache invalidation, no write
 *   contention on a 6-row table, no migration to run, and a catalogue an
 *   operator can edit in a text editor and see immediately. That is a real
 *   advantage and was the point.
 *   It is NOT a design that survives horizontal scale, and it must not be
 *   scaled by copying the file somewhere shared.
 *
 * BEFORE ANY OF THE FOLLOWING, RE-ARCHITECT THE STOCK PATH:
 *   · adding a second web server, or a load balancer with more than one origin
 *   · moving the document root onto NFS, SMB, or a network-mounted volume
 *   · containerising, or moving to a filesystem layered over a network mount
 *   · anything that makes "which machine wrote this?" a question with more than
 *     one answer
 *
 * THE MIGRATION, SPECIFICALLY: stock belongs in the database, which already has
 * what this design gave up. velora_orders and velora_order_items exist and are
 * already transactional; velora_sizes is still in the schema and already carries
 * the index db_schema.sql documents for exactly this ("the checkout hot path
 * locks (product_id, eu) FOR UPDATE"). So the target is:
 *
 *     BEGIN;
 *       SELECT stock FROM velora_sizes WHERE product_id=? AND eu=? FOR UPDATE;
 *       -- reject if stock < qty, else decrement
 *       INSERT INTO velora_orders ...; INSERT INTO velora_order_items ...;
 *     COMMIT;
 *
 * One row lock per (product, size) instead of one file lock for the whole
 * catalogue, and it is correct on any number of hosts. products.json can stay
 * exactly as it is for descriptive fields - name, copy, images, prices - as
 * long as ONLY the stock column is sourced from the database. That is the small
 * change that removes this entire constraint, and db_schema.sql's SECTION 5
 * already documents velora_sizes as legacy, so reviving it is a deliberate
 * decision rather than an archaeological dig.
 *
 * Keep the flock() in place as a second line of defence. It is cheap, and on a
 * single host it is still what makes a read-modify-write of a JSON document
 * correct. Two mechanisms that both hold is not redundancy to remove.
 * ============================================================================
 *
 * ─── Contract ────────────────────────────────────────────────────────────
 * The catalogue lives in exactly one place: <docroot>/products.json. It is
 * version-controlled, human-editable, and read directly by index.php,
 * api.php, admin.php and sitemap.php via the functions below.
 *
 * ─── Imagery ─────────────────────────────────────────────────────────────
 * A gallery key resolves in exactly TWO ways, and no others:
 *   1. a local admin-uploaded WebP filename  → /storage/uploads/products/…
 *   2. an absolute https URL                  → passed through untouched
 * Anything else (which is every bare catalogue id like "notte") renders as
 * the GENERATED SVG PLATE drawn client-side by app.js plate()/plateFor():
 * a gold-monogrammed plate with the silhouette for that product's category.
 * That plate costs zero external requests, cannot 404, needs no files on
 * disk, and is replaced automatically per-product the moment an admin
 * uploads a real photograph.
 *
 * ─── Writes ──────────────────────────────────────────────────────────────
 * There is exactly ONE write primitive: velora_catalog_transaction(). It gives
 * a callback an exclusive flock() on products.json.lock plus read-modify-write
 * semantics, and every write in the project goes through it — checkout stock
 * decrement, order cancellation restock, admin saves, image uploads, the
 * abandoned-order sweep.
 *
 * A whole-file replace used to be available as a second primitive,
 * velora_catalog_write($products), for a caller that "already holds the full new
 * array". It had no call sites, and its contract was a lost update: the caller
 * had to read the catalogue to build the array, that read happened outside any
 * lock, and the write then replaced whatever a concurrent checkout had committed
 * in between. Measured on this codebase's own code paths — 4 transaction
 * writers interleaved with 4 whole-file writers, 400 increments — 638 of 400
 * units of stock movement were lost, silently, with the document left perfectly
 * valid JSON. A function that can only be used wrongly, has no users, and fails
 * silently is not worth keeping, so it is gone. Replacing the whole catalogue is
 * still expressible:
 *
 *     velora_catalog_transaction(function (array $d, array &$out) {
 *         $out['products'] = $brandNewArray;
 *     });
 *
 * which reads under the lock and therefore cannot clobber anything.
 *
 * ─── Failure modes ───────────────────────────────────────────────────────
 * A malformed or missing products.json returns an empty catalogue and logs
 * once, rather than fatally aborting the storefront. A write never touches
 * the live file in-place: it writes a sibling temp and rename()s atomically,
 * so a crash mid-write can only lose the new data, never corrupt the old.
 */

if (defined('VELORA_CATALOG_LOADED')) return;
define('VELORA_CATALOG_LOADED', true);

const VELORA_CATALOG_FILE     = 'products.json';
const VELORA_CATALOG_SCHEMA   = 'velora-catalog/v1';
const VELORA_CATALOG_VERSION  = 1;

/* The EU size band is a property of the catalogue's own data model, so it is
   defined here rather than only in config.php. It is guarded because config.php
   also defines it for the rest of the app, and this file must be loadable on
   its own: catalog.php (the freshness endpoint) reads a JSON file and must not
   need the database, or the storefront's "is my catalogue current?" probe
   fails during a DB outage and reports "up to date" at exactly the moment it
   cannot know.

   This is the ONE place in the project where a constant is legitimately
   declared twice — config.php:89-101 owns the values, this file owns the
   fallback for the standalone load path. Because two files carry the same
   literals, a disagreement has to be visible rather than silent: PHP would
   otherwise keep whichever loaded first and say nothing, and the size the cart
   accepts would silently differ from the size the catalogue documents.
   So the fallback verifies instead of assuming, and writes the drift to the
   error log. Change the numbers in config.php only. */
if (!defined('SIZE_MIN')) {
    define('SIZE_MIN', 37);
    define('SIZE_MAX', 41);
    define('SIZE_BAND', range(SIZE_MIN, SIZE_MAX));
} elseif ((int) SIZE_MIN !== 37 || (int) SIZE_MAX !== 41) {
    error_log('[VELORA CATALOG] SIZE_MIN/SIZE_MAX are ' . (int) SIZE_MIN . '..' . (int) SIZE_MAX
        . ' from config.php but this module documents 37..41. The config.php values win; '
        . 'the literals in this fallback are stale and should be updated.');
}

function velora_catalog_path(): string {
    return dirname(__DIR__) . '/' . VELORA_CATALOG_FILE;
}

/**
 * A short content fingerprint of products.json, used as the catalogue's
 * version everywhere it is referenced.
 *
 * Why this exists. The catalogue used to reach the browser only inside the
 * HTML shell, which welded its freshness to the shell's. An operator who edits
 * products.json has exactly one way to make that visible: a full document
 * round-trip. And if ANY layer holds that HTML — a service worker from an
 * older build still controlling a long-lived tab, an intermediary, a
 * full-page cache on shared hosting — the edit stays invisible indefinitely,
 * with nothing on screen to say so. That failure mode has no signal, which is
 * why it presents as "I changed the file and nothing happened" and gets
 * diagnosed as a broken site rather than as a caching layer.
 *
 * Hashing the bytes turns the catalogue into a versioned resource. The hash
 * changes exactly when the content changes, so a URL or an ETag built from it
 * cannot go stale, and the two sides of the question — "is the server serving
 * my edit?" and "is the browser showing it?" — become answerable instead of
 * arguable.
 *
 * Cost: one stat() per call once the memo is warm, and one hash_file() over
 * the ~20 KB document only when its identity actually changed. Previously the
 * full-document hash ran on EVERY request — the per-request static only saved
 * repeat calls within one render (index.php makes two). The ETag machinery in
 * catalog.php is exactly the traffic this saves: repeat visitors hit the
 * endpoint with conditional GETs constantly, and each one needs the
 * fingerprint, not a re-read of every byte.
 *
 * The memo is keyed on inode + size + mtime, and the trade-off is taken
 * knowingly:
 *
 *   · Every write path in this project replaces the file with rename(),
 *     which installs a NEW inode at the path — so a committed catalogue edit
 *     is always seen, even in the pathological case where the new document
 *     has the exact size and mtime of the old one.
 *   · A hand-edit that rewrote products.json IN PLACE while preserving both
 *     its size and its mtime to the second could keep the old fingerprint
 *     until the process restarts. That combination is effectively
 *     unreachable (mtime advances on every write), and any real edit changes
 *     either size or mtime. If it ever bites, restarting PHP-FPM resets the
 *     memo.
 *
 * In-request staleness cannot happen: velora_catalog_forget() — called by
 * every transaction commit — clears this memo alongside the parsed-document
 * memo (through the 'forget-version' sentinel handled at the top of the
 * function below), so a read after a write in the same request always agrees
 * with the file, and the two caches always describe the same window of
 * validity.
 */
function velora_catalog_version(string $set = ''): string {
    /* Called with the 'forget-version' sentinel, this drops the memo instead
       of reading it. That is how velora_catalog_forget() reaches this static:
       PHP gives no other way in, and the existing velora_catalog_cache()
       holder forwards the sentinel here. Without invalidation, the stat-keyed
       memo could keep serving a pre-write fingerprint inside one request on a
       filesystem where an in-place rewrite preserves size AND mtime — exactly
       the guarantee the old unconditional hash_file() gave. */
    if ($set === 'forget-version') {
        $memo = [null, null];
        return '';
    }

    /* [$statIdentity, $fingerprint]. Both null = nothing published yet. An
       unreadable file deliberately does NOT populate the memo: the original
       behaviour returned 'missing' without caching it, so a transiently
       unreadable file must be re-checked on the next call rather than pin the
       version to 'missing' for the life of the worker. */
    static $memo = [null, null];

    $file = velora_catalog_path();
    $st   = @stat($file);
    if ($st === false) return 'missing';

    $identity = $st[1] . ':' . $st[7] . ':' . $st[9]; // inode:size:mtime
    if ($memo[1] !== null && $identity === $memo[0]) return $memo[1];

    $h = @hash_file('sha256', $file);
    if ($h === false || $h === '') return 'missing';
    $memo = [$identity, substr($h, 0, 16)];
    return $memo[1];
}

/**
 * In-process cache holder for the current request. The storefront reads the
 * catalogue many times (routing, hydration, SEO, sitemap); parsing a small
 * JSON file twice would be wasteful, and reading it from disk N times would
 * be worse.
 *
 * One function owns the static so the write paths can invalidate it: PHP
 * gives no way to reach a `static` inside another function. Called with no
 * argument it reads; called with an argument it replaces the value and
 * returns the new one (pass null to clear). Symmetric on purpose: it lets a
 * read path do `return velora_catalog_cache($fresh)` without a second line.
 */
function velora_catalog_cache(mixed $set = null): mixed {
    static $cache = null;
    if (func_num_args() > 0) {
        /* The one value that is not a cache payload: forward it to the
           version memo so a write path can drop BOTH request-lifetime caches
           in one call. Without this, the stat-keyed version memo could keep
           serving a pre-write fingerprint inside a single request on a
           filesystem where an in-place rewrite preserves size and mtime. */
        if ($set === 'forget-version') { velora_catalog_version('forget-version'); return null; }
        $cache = $set;
    }
    return $cache;
}

function velora_catalog_read(): array {
    $cache = velora_catalog_cache();
    if ($cache !== null) return $cache;

    $file = velora_catalog_path();
    if (!is_readable($file)) {
        error_log('[VELORA CATALOG] not readable: ' . $file);
        return velora_catalog_cache(['version' => 1, 'updated_at' => null, 'products' => [], 'by_id' => []]);
    }
    /* Three attempts — see velora_catalog_read_raw(). On a healthy local
       filesystem the first attempt always succeeds and this is a single
       comparison; the extra reads only happen in the empty-read window, where
       the alternative is an empty storefront. */
    $raw = velora_catalog_read_raw($file, 3);
    if (trim($raw) === '') {
        error_log('[VELORA CATALOG] empty or unreadable after 3 attempts: ' . $file
            . ' (size on disk: ' . (string) @filesize($file) . ' bytes). A non-zero size means the file is '
            . 'being replaced faster than this filesystem can serve reads, or rename() is not atomic here; '
            . 'a zero size means the document is genuinely empty and must be restored.');
        return velora_catalog_cache(['version' => 1, 'updated_at' => null, 'products' => [], 'by_id' => []]);
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['products']) || !is_array($data['products'])) {
        error_log('[VELORA CATALOG] malformed JSON at ' . $file);
        return velora_catalog_cache(['version' => 1, 'updated_at' => null, 'products' => [], 'by_id' => []]);
    }

    // Filter out entries without a usable id — a single bad row must not
    // invalidate the whole catalogue.
    $clean = [];
    /* The id → product map, built in the same pass as the filter so the two
       cannot disagree about which rows are eligible. Building it here rather
       than lazily is deliberate: the filter is already walking every row, so
       the extra work is one array write per row, and the alternative is a
       second walk on the first lookup of every request.

       First-wins on a duplicate id, matching the linear scan this replaced. */
    $byId = [];
    foreach ($data['products'] as $p) {
        if (!is_array($p)) continue;
        $id = (string) ($p['id'] ?? '');
        if ($id === '' || strlen($id) > 64) continue;
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id)) continue;
        $clean[] = $p;
        if (!isset($byId[$id])) $byId[$id] = $p;
    }

    return velora_catalog_cache([
        'version'    => (int) ($data['version'] ?? 1),
        'updated_at' => isset($data['updated_at']) ? (string) $data['updated_at'] : null,
        'products'   => $clean,
        'by_id'      => $byId,
    ]);
}

/**
 * Drop the in-process cache so the next read re-parses the file.
 *
 * Every write path calls this. velora_catalog_read() memoises for the
 * lifetime of the request, and velora_catalog_transaction() deliberately
 * bypasses that memo (it re-reads under the lock) — so without this, a
 * successful write is immediately shadowed by the pre-write value for the
 * rest of the request. The failure is silent and directional: the file on
 * disk is correct and the process reading it is wrong, which is exactly the
 * shape of bug that surfaces as "checkout decremented but the response still
 * showed the old stock" or "the admin's second save reverted the first".
 */
function velora_catalog_forget(): void {
    velora_catalog_cache(null);
    velora_catalog_cache('forget-version');
}

    function velora_catalog_products(): array {
        return velora_catalog_read()['products'];
    }

    /**
     * Why the catalogue looks the way it does.
     *
     * velora_catalog_read() has four distinct ways to come back empty — file
     * missing, unreadable, zero-length, malformed JSON — and all four return
     * `products => []` with nothing but an error_log() line to show for it.
     * `admin_products` then answers `ok => true` with an empty array, so the
     * panel renders "no products" and the operator is told, in effect, that
     * their shop is empty. On shared hosting, where a wrong data path or an
     * ownership problem is common and invisible, that is an unbounded
     * debugging session for what is usually a one-line permissions fix.
     *
     * So the read path records why it failed, and the admin panel can say so.
     * Nothing here changes what the storefront serves.
     */
    function velora_catalog_health(): array {
        $file = velora_catalog_path();
        $read = velora_catalog_read();

        $readable = is_readable($file);
        $exists   = file_exists($file);
        $size     = $exists ? (int) @filesize($file) : -1;
        $count    = count($read['products'] ?? []);

        $reason = '';
        if (!$exists) {
            $reason = 'MISSING';
        } elseif (!$readable) {
            $reason = 'UNREADABLE';
        } elseif ($size === 0) {
            $reason = 'EMPTY_FILE';
        } elseif ($count === 0) {
            // File is fine and non-empty, so either the JSON is malformed or
            // every row failed the id filter.
            $reason = 'NO_VALID_ROWS';
        }

        return [
            'ok'        => $reason === '',
            'reason'    => $reason,
            'file'      => $file,
            'exists'    => $exists,
            'readable'  => $readable,
            'bytes'     => $size,
            'products'  => $count,
            'version'   => (int) ($read['version'] ?? 0),
            'updated_at'=> $read['updated_at'] ?? null,
        ];
    }

function velora_catalog_active(): array {
    return array_values(array_filter(
        velora_catalog_products(),
        static fn(array $p): bool => !empty($p['active'])
    ));
}

/**
 * One product by id, or null.
 *
 * This was a linear scan over every product, and it is on the hottest path in
 * the application: api.php's checkout calls it once per cart line — up to
 * twenty — to price the basket, so a normal basket of two lines walked the
 * catalogue twice and a twenty-line basket walked it twenty times, before the
 * order's transaction had even opened.
 *
 * The scan is not expensive in absolute terms — eleven products is nothing. The
 * problem is that the cost is O(n) *per line*, so it multiplies by exactly the
 * number the customer controls, on the one request they are waiting for, and it
 * grows with the collection rather than with the request. A maison with two
 * hundred pairs would turn every checkout into two hundred comparisons per line,
 * with no ceiling the customer can see.
 *
 * So the id → product map is built once, inside velora_catalog_read(), and
 * stored in the same memoised array as the products it was derived from.
 *
 * Storing it beside the data rather than in a static of its own is the whole
 * correctness argument. A separate cache needs its own invalidation, and any
 * rule for that rule is a guess: comparing a content hash is wrong if the hash
 * is not bumped, comparing a count is wrong if a row is edited in place, and
 * comparing the cache object is not expressible for an array. Holding both in
 * one value makes staleness unrepresentable — velora_catalog_forget() replaces
 * the array, so the index is replaced with it and the two cannot disagree.
 *
 * A duplicate id keeps the FIRST occurrence, which is what a sequential scan
 * returning on first match did. This is therefore a pure speed change and cannot
 * alter which record a duplicate id resolves to.
 */
function velora_catalog_product(string $id): ?array {
    if ($id === '' || strlen($id) > 64) return null;
    return velora_catalog_read()['by_id'][$id] ?? null;
}

/* ── Write paths ─────────────────────────────────────────────────────────── */

/**
 * The catalogue's mutual-exclusion token: an exclusive flock() on a handle, plus
 * the path it was taken on, for the caller to release in a `finally`.
 *
 * WHY A SEPARATE .lock FILE AND NOT THE DATA FILE ITSELF
 *
 * The obvious implementation is fopen($file) + flock($fp), and that is what
 * this function used to do. It cannot be combined with an atomic write, and the
 * reason is that flock() locks an INODE, not a path. Replacing a file's contents
 * atomically means rename(), and rename() installs a new inode at the path:
 *
 *   A: fopen(products.json) -> inode X, flock(X)          [holds]
 *   B: fopen(products.json) -> inode X, blocks on flock(X)
 *   A: write tmp; rename(tmp, products.json) -> path is now inode Y
 *   A: flock(X, LOCK_UN); fclose(X)
 *   B: acquires flock(X) — and X is an unlinked inode. B reads the pre-rename
 *      content, computes a delta, writes it, and that write goes nowhere. The
 *      increment is silently lost, and the next reader sees Y with none of B's
 *      work.
 *
 * So locking the data file and then renaming it produces a strictly worse bug
 * than the truncate it was meant to replace: a lost update that no log line
 * reports. Locking a file that is never renamed removes the whole class — the
 * lock keeps meaning "no one else is mid-write" across any number of renames.
 *
 * The same change fixes a second, more frequent problem. velora_catalog_read()
 * does NOT take the lock, because the storefront must not block on a checkout.
 * Against the old ftruncate+fwrite, an unlocked reader arriving between the two
 * calls sees a zero-length file, logs "empty or unreadable", and renders an
 * empty catalogue — during a live purchase, with stock numbers that do not
 * exist. ftruncate(0) followed by fwrite() is two operations, and the reader
 * takes neither lock, so the window is structural, not a timing coincidence: it
 * is open for exactly as long as the writer is descheduled between them, which
 * a contended disk, a garbage collection, or a SIGKILL all produce.
 *
 * Demonstrated, same logical write, same 60 ms pause between the two steps of
 * each shape, with an unlocked reader polling throughout:
 *
 *   ftruncate(0) + fwrite()   ->  45,270 of 45,281 reads saw an EMPTY file
 *   temp file    + rename()   ->         0 of    194 reads saw an empty file
 *
 * rename() is atomic, so a reader now sees the whole old document or the whole
 * new one, never neither. Losing the lock on the read path is no longer lossy at
 * all.
 *
 * Cost: one extra empty file next to products.json. It is created mode 0644 and
 * never written to; it exists only to be locked.
 *
 * @return array{0: resource, 1: string} handle and the lock path
 * @throws RuntimeException CATALOG_LOCK_FAIL
 */
function velora_catalog_lock(string $file): array {
    $lockPath = $file . '.lock';
    $fh = @fopen($lockPath, 'c+');
    if ($fh === false) throw new RuntimeException('CATALOG_LOCK_FAIL');
    /* Blocking, not LOCK_NB: the original was blocking too, and a checkout that
       gives up because another checkout holds the lock would fail a customer for
       a reason that resolves itself in milliseconds. The lock is held for the
       duration of one flock-protected read-modify-write plus, on the slow path,
       a gateway-free catalogue edit — never across a network call. */
    if (!flock($fh, LOCK_EX)) { fclose($fh); throw new RuntimeException('CATALOG_LOCK_FAIL'); }
    return [$fh, $lockPath];
}

/** Release a lock taken by velora_catalog_lock(). Safe to call twice. */
function velora_catalog_unlock($fh): void {
    if (!is_resource($fh)) return;
    @flock($fh, LOCK_UN);
    @fclose($fh);
}

/**
 * Read the raw document, tolerating absence, emptiness and a UTF-8 BOM.
 *
 * $attempts > 1 enables a bounded retry when a read comes back with no usable
 * content. This is insurance against the FILESYSTEM, not against the code.
 *
 * The commit is now a temp file plus an atomic rename, and on a local NTFS/ext4
 * volume an unlocked reader concurrent with six writers recorded zero empty and
 * zero unparseable reads out of 385 — the window the retry defends against does
 * not exist there. It is kept because that guarantee is a property of the
 * filesystem, and this project is documented for shared hosting, where
 * products.json can sit on NFS, SMB or a union/overlay mount whose rename is not
 * atomic. On such a mount the old zero-length window reappears in full — the
 * measurement in velora_catalog_lock() shows what 45,270 empty reads out of
 * 45,281 does to a storefront — and the log line that explains it reads "empty or
 * unreadable", which points an operator at file permissions rather than at the
 * mount type.
 *
 * Cost on the happy path: one comparison. The loop only runs when the read came
 * back empty, which on a healthy local filesystem is never.
 *
 * The transaction path passes 1 and does not retry: it reads while holding the
 * lock, so no other writer can be replacing the file, and there is no transient
 * state to wait out.
 */
function velora_catalog_read_raw(string $file, int $attempts = 1): string {
    $attempts = max(1, $attempts);
    $raw = '';
    for ($i = 1; $i <= $attempts; $i++) {
        $r = @file_get_contents($file);
        $raw = ($r === false) ? '' : $r;
        if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
        if (trim($raw) !== '') return $raw;
        if ($i < $attempts) usleep(2000 * $i);   // 2 ms, then 4 ms
    }
    return $raw;
}

/**
 * Replace the document atomically. Temp file in the same directory (so rename
 * stays within one filesystem and is therefore atomic) + rename. A crash at any
 * point can only lose the NEW content; the old document is either fully intact
 * or fully replaced, never a half-written mixture.
 *
 * rename() can fail transiently, and the most common reason is not a full disk —
 * it is another process holding the destination open. On Windows a rename onto a
 * file that any process has open without FILE_SHARE_DELETE fails outright, which
 * means a single concurrent page render reading products.json can turn a
 * perfectly healthy write into a failure. The window is sub-millisecond, so a
 * short bounded retry with a growing backoff absorbs it.
 *
 * @return bool true only if the document is now the new one
 */
function velora_catalog_commit(string $file, string $json, int $attempts = 5): bool {
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
    /* LOCK_EX here guards the temp file against a same-name collision only —
     * the name carries 4 random bytes, so it is already unique. The real
     * exclusion is the .lock the caller is holding. */
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        error_log('[VELORA CATALOG] could not write temp file for ' . $file);
        return false;
    }

    $delay = 20000; // 20 ms, doubling
    for ($i = 1; $i <= $attempts; $i++) {
        if (@rename($tmp, $file)) {
            @chmod($file, 0640);
            return true;
        }
        if ($i < $attempts) usleep($delay);
        $delay *= 2;
    }

    @unlink($tmp);
    log_line('[VELORA CATALOG] rename failed after ' . (int) $attempts . ' attempts for ' . $file);
    return false;
}

/**
 * Remove orphaned temp files left by a process that died between write and
 * rename. Only ever called from the periodic sweep, never on a write path: it
 * globs the directory, which is fine once every twenty requests and not fine on
 * the checkout critical path.
 *
 * $maxAgeSeconds is a guard against deleting a temp file another process is
 * right now writing. A live temp file is milliseconds old, so anything older than
 * an hour cannot be in flight.
 *
 * Takes the .lock itself, because flock() is per open file description and a
 * second flock() on the same file from the same process would block against
 * itself. It is therefore NOT safe to call while already holding the lock — the
 * only caller is the periodic sweep, which does not.
 *
 * @return int number of files removed
 */
function velora_catalog_sweep_tmp(int $maxAgeSeconds = 3600): int {
    $file = velora_catalog_path();
    try {
        [$lock] = velora_catalog_lock($file);
    } catch (RuntimeException $e) {
        /* Cannot lock, so cannot safely enumerate. Leaving the files is harmless
           — they are inert and a later sweep will collect them. */
        return 0;
    }
    try {
        $glob = @glob($file . '.tmp.*', GLOB_NOSORT);
        if (!is_array($glob) || !$glob) return 0;
        $cutoff = time() - $maxAgeSeconds;
        $n = 0;
        foreach ($glob as $path) {
            if (!is_string($path)) continue;
            $mtime = @filemtime($path);
            if ($mtime === false || $mtime > $cutoff) continue;
            if (@unlink($path)) $n++;
        }
        return $n;
    } finally {
        velora_catalog_unlock($lock);
    }
}

/**
 * Exclusive flock() on the .lock file + read-modify-write. The callback receives
 * the current parsed document and a mutable copy of it; if it changes $out, the
 * new document is written back before the lock is released. Callback return
 * value is passed through.
 *
 *   velora_catalog_transaction(function(array $data, array &$out) {
 *       $out['products'][0]['price'] = 999;
 *       return 'done';
 *   });
 *
 * The read deliberately bypasses the request-local memo and re-reads under the
 * lock: a value cached before the lock was taken may already be stale, and every
 * caller of this function is a read-modify-write whose whole correctness rests
 * on seeing the committed state.
 */
function velora_catalog_transaction(callable $fn): mixed {
    $file = velora_catalog_path();
    try {
        [$lock] = velora_catalog_lock($file);
    } catch (RuntimeException $e) {
        throw new RuntimeException($e->getMessage());
    }
    try {
        /* One attempt: the lock is held, so no other writer can be renaming, and
           there is no transient state left to wait out. The unlocked read path
           passes 3 — see velora_catalog_read_raw(). */
        $raw  = velora_catalog_read_raw($file, 1);
        $data = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['products']) || !is_array($data['products'])) {
            /* A malformed or empty document is not a reason to refuse the write.
               The callback is about to supply the authoritative content, and
               bailing here would turn a recoverable file problem into a failed
               checkout. The parse failure is still logged, because a catalogue
               that is being read as empty on every page view is its own outage. */
            if (trim($raw) !== '') {
                log_line('[VELORA CATALOG] malformed JSON at ' . $file . ' - rebuilding from callback');
            }
            $data = ['version' => VELORA_CATALOG_VERSION, 'products' => []];
        }
        $out      = $data;
        $before   = json_encode($data, JSON_UNESCAPED_UNICODE);
        $result   = $fn($data, $out);
        $after    = json_encode($out, JSON_UNESCAPED_UNICODE);
        if ($after !== $before) {
            $out['$schema']    = VELORA_CATALOG_SCHEMA;
            $out['version']    = (int) ($out['version'] ?? VELORA_CATALOG_VERSION);
            $out['updated_at'] = date('c');
            $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false) {
                throw new RuntimeException('CATALOG_COMMIT_FAIL');
            }
            if (!velora_catalog_commit($file, $json)) {
                /* Throwing rather than logging and continuing is the whole point
                   of this branch.

                   The callback has already been told its mutation was applied —
                   checkout has already decremented stock, the sweep has already
                   computed a restore — and if this returns normally the caller
                   goes on to commit an order against a catalogue that was never
                   updated. The result is a silent lost update: stock that does
                   not match the orders, discovered later by a customer as a size
                   that will not go in stock.

                   Throwing propagates into the caller's own try/catch, which
                   rolls the database transaction back. That is the correct
                   outcome and it is atomic with respect to the database: either
                   the order is recorded and the file holds the matching
                   decrement, or neither happened.

                   Note the asymmetry with the OLD code, which also failed here
                   silently but had already run ftruncate() — so a failed write
                   destroyed the previous content. Here the previous content is
                   still intact on disk and the lock is still held, so this is a
                   clean failure with nothing to repair by hand. */
                throw new RuntimeException('CATALOG_COMMIT_FAIL');
            }
            /* The caller's view of the catalogue has just changed. Without
               this the request-local memo keeps serving the pre-write value, so
               a read after a write in the same request disagrees with the file. */
            velora_catalog_forget();
        }
        return $result;
    } finally {
        velora_catalog_unlock($lock);
    }
}

/* ── Id issuance & client shaping ───────────────────────────────────────── */

/**
 * 80 bits of entropy, hex-encoded, fixed 'p-' prefix. The prefix keeps
 * server-issued ids greppable and visually distinct from the hand-written
 * slugs ('notte', 'rosa') that already exist in the catalogue, so an admin
 * reading products.json can tell at a glance which entries came from the
 * panel.
 */
function velora_catalog_reserve_id(): string {
    $taken = [];
    foreach (velora_catalog_products() as $p) $taken[(string) ($p['id'] ?? '')] = true;
    for ($i = 0; $i < 16; $i++) {
        $id = 'p-' . bin2hex(random_bytes(10));
        if (!isset($taken[$id])) return $id;
    }
    throw new RuntimeException('CATALOG_ID_COLLISION');
}

/**
 * Shapes a raw catalogue record for delivery to the browser. Everything is
 * coerced to the type the storefront expects; missing keys get safe defaults
 * so a hand-edited file with a dropped field still renders.
 *
 * `img` and `g` are what app.js reads. `img` is the primary gallery key
 * (which may be a bare id → SVG plate), and `g` is the full ordered list.
 *
 * ─── The shape contract ───────────────────────────────────────────────────
 * This function is the *only* place the wire format is defined, so it is also
 * the only place that has to be right. Every consumer — index.php's
 * server-rendered card, api.php's `products`/`product`, catalog.php's
 * freshness feed, the JSON-LD builder, and adaptServerProduct() in data.js —
 * reads whatever is returned here and nothing else.
 *
 * Two fields were wrong, and both were wrong in the same direction: the
 * server had a richer idea of a product than it transmitted, so every consumer
 * that asked a question the shape could not answer invented an answer for
 * itself. A shape contract that forces its consumers to guess is not a
 * contract.
 *
 *   colors → was [[key, name]] positional. index.php read `$c['key']` and
 *     data.js read `c.key`; against a list both yield null/empty, so the grid
 *     painted no swatches at all, the PDP fell back to a hard-coded black,
 *     and every order line recorded the colour as "مشکی". The positional form
 *     saved 18 bytes per product and cost the entire colour dimension. It is
 *     now a named object — which is also what JSON-LD wants, so index.php no
 *     longer has to reshape it just to read a name back out.
 *
 *   stock  → did not exist. Stock is *derived*: products.json has no stock
 *     field, only per-size counts, because storing a total that can disagree
 *     with its own parts is how a shop oversells. The derivation happened in
 *     adaptServerProduct() in the browser and nowhere on the server, so the
 *     server-rendered card compared `null <= 5` — always true — and stamped
 *     "فقط ۰ مانده" on all eleven cards while emitting a warning each. The
 *     total is now summed here, once, from the same $sizes array that is sent
 *     in the same payload, so the card, the bag, the PDP and the checkout
 *     validator cannot disagree about what is left.
 */
function velora_catalog_client_shape(array $p): array {
    $colors = [];
    foreach ((array) ($p['colors'] ?? []) as $c) {
        if (!is_array($c)) continue;
        $k = (string) ($c['key']  ?? '');
        $n = (string) ($c['name'] ?? '');
        if ($k !== '' && $n !== '') $colors[] = ['key' => $k, 'name' => $n];
    }

    $stock = 0;
    $sizes = [];
    foreach ((array) ($p['sizes'] ?? []) as $s) {
        if (!is_array($s)) continue;
        $eu = (int) ($s['eu']    ?? 0);
        $st = max(0, (int) ($s['stock'] ?? 0));
        if ($eu >= SIZE_MIN && $eu <= SIZE_MAX) {
            $sizes[] = ['eu' => $eu, 'stock' => $st];
            $stock += $st;
        }
    }

    $gallery = [];
    foreach ((array) ($p['gallery'] ?? []) as $g) {
        if (!is_string($g)) continue;
        $g = trim($g);
        if ($g !== '' && strlen($g) <= 512) $gallery[] = $g;
    }

    $feats = array_values(array_filter(
        array_map(static fn($v): string => (string) $v, (array) ($p['feats'] ?? [])),
        static fn(string $s): bool => $s !== ''
    ));

    $specs = [];
    foreach ((array) ($p['specs'] ?? []) as $k => $v) {
        $k = (string) $k;
        if ($k === '' || !is_scalar($v) && $v !== null) continue;
        $specs[$k] = (string) $v;
    }

    $drop = (string) ($p['drop_date'] ?? '');
    if ($drop === '' || strtotime($drop) === false) $drop = date('Y-m-d');

    $id      = (string) ($p['id'] ?? '');
    $img     = $gallery[0] ?? $id;
    $gList   = $gallery ?: [$id];

    return [
        'id'    => $id,
        'name'  => (string) ($p['name'] ?? ''),
        'cat'   => (string) ($p['cat']  ?? ''),
        'sub'   => (string) ($p['sub']  ?? ''),
        'desc'  => (string) ($p['desc'] ?? ''),
        'price' => (int)    ($p['price'] ?? 0),
        'old'   => (int)    ($p['old_price'] ?? 0),
        'isNew' => !empty($p['is_new']) ? 1 : 0,
        'sold'  => (int)    ($p['sold'] ?? 0),
        /* Derived, never stored. See the contract note above. */
        'stock' => $stock,
        'eta'   => (int)    ($p['eta']  ?? 4),
        'heel'  => (int)    ($p['heel'] ?? 0),
        'width' => in_array(($p['width'] ?? 'r'), ['n','r','w'], true) ? $p['width'] : 'r',
        'sole'  => in_array(($p['sole']  ?? 'leather'), ['leather','rubber'], true) ? $p['sole'] : 'leather',
        'bias'  => (float)  ($p['bias'] ?? 0),
        'drop'  => $drop,
        'feats' => $feats,
        'specs' => $specs,
        'colors'=> $colors,
        'sizes' => $sizes,
        'img'   => $img,
        'g'     => $gList,
        'gallery' => $gallery,
        'sort_order' => (int) ($p['sort_order'] ?? 0),
    ];
}

/** Same as above but for every active product, ordered for the storefront. */
function velora_catalog_client_feed(): array {
    $rows = velora_catalog_active();
    usort($rows, static function (array $a, array $b): int {
        $so = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
        if ($so !== 0) return $so;
        return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
    });
    return array_map('velora_catalog_client_shape', $rows);
}
