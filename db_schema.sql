-- ═══════════════════════════════════════════════════════════════════════════
-- VELORA · Maison de Chaussures
-- Complete Database Schema + Seed Catalog
-- v9.1 Aetherion (Forensic Patch)
-- ---------------------------------------------------------------------------
-- Target      : MySQL 8.0+  /  MariaDB 10.6+
-- Charset     : utf8mb4  /  utf8mb4_unicode_ci
-- Engine      : InnoDB
-- Idempotent  : Yes — safe to re-run on a live database.
--               · Tables use CREATE TABLE IF NOT EXISTS.
--               · Catalog products use INSERT ... ON DUPLICATE KEY UPDATE.
--               · colors / sizes / gallery are re-seeded for the 11 demo
--                 products only (scoped DELETE ... WHERE product_id IN(...)).
--               · Orders, users, OTP, payments, appointments, reviews,
--                 restock signups, and any non-demo products are NEVER
--                 touched by this file.
-- ═══════════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET time_zone = '+03:30';
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';
SET FOREIGN_KEY_CHECKS = 0;

-- ─── Optional · uncomment if your host grants CREATE DATABASE privilege ────
-- CREATE DATABASE IF NOT EXISTS `velora`
--   DEFAULT CHARACTER SET utf8mb4
--   DEFAULT COLLATE utf8mb4_unicode_ci;
-- USE `velora`;


-- ═══════════════════════════════════════════════════════════════════════════
-- SECTION 1 · SCHEMA
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS velora_products (
    id          VARCHAR(64)  NOT NULL,
    name        VARCHAR(255) NOT NULL,
    cat         VARCHAR(64)  NOT NULL,
    sub         VARCHAR(512) NOT NULL DEFAULT '',
    `desc`      TEXT,
    price       INT          NOT NULL DEFAULT 0,
    old_price   INT          NOT NULL DEFAULT 0,
    heel        INT          NOT NULL DEFAULT 0,
    width       CHAR(1)      NOT NULL DEFAULT 'r',
    sole        VARCHAR(32)  NOT NULL DEFAULT 'leather',
    is_new      TINYINT(1)   NOT NULL DEFAULT 0,
    sold        INT          NOT NULL DEFAULT 0,
    eta         INT          NOT NULL DEFAULT 4,
    bias        DECIMAL(5,2) NOT NULL DEFAULT 0,
    feats       JSON,
    specs       JSON,
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  INT          NOT NULL DEFAULT 0,
    drop_date   DATE         DEFAULT NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cat_active (cat, active),
    KEY idx_price (price),
    KEY idx_sold (sold),
    CONSTRAINT chk_price_positive CHECK (price >= 0),
    CONSTRAINT chk_heel_range CHECK (heel >= 0 AND heel <= 300)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS velora_gallery (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id   VARCHAR(64)  NOT NULL,
    unsplash_key VARCHAR(255) NOT NULL,
    sort_order   INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_product (product_id),
    CONSTRAINT fk_gallery_product
        FOREIGN KEY (product_id) REFERENCES velora_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS velora_colors (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id VARCHAR(64)  NOT NULL,
    color_key  VARCHAR(64)  NOT NULL,
    color_name VARCHAR(128) NOT NULL,
    sort_order INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_product (product_id),
    CONSTRAINT fk_colors_product
        FOREIGN KEY (product_id) REFERENCES velora_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS velora_sizes (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id VARCHAR(64)  NOT NULL,
    eu         INT          NOT NULL,
    stock      INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_product_size (product_id, eu),
    KEY idx_sizes_product_eu_stock (product_id, eu, stock),
    -- One size standard, 37..41. `eu` is the historical column name and a
    -- frozen contract (velora_order_items.eu_size too); it is never rendered.
    CONSTRAINT chk_sizes_band CHECK (eu BETWEEN 37 AND 41),
    CONSTRAINT fk_sizes_product
        FOREIGN KEY (product_id) REFERENCES velora_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS velora_users (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    phone       VARCHAR(20)  NOT NULL,
    name        VARCHAR(255) DEFAULT NULL,
    email       VARCHAR(255) DEFAULT NULL,
    fit_profile JSON,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    last_seen   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_users_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS velora_otp (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    phone      VARCHAR(20)  NOT NULL,
    code       VARCHAR(255) NOT NULL,
    ip         VARCHAR(45)  DEFAULT NULL,
    used       TINYINT(1)   NOT NULL DEFAULT 0,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    expires_at INT          NOT NULL,
    PRIMARY KEY (id),
    KEY idx_otp_phone_used (phone, used, expires_at),
    KEY idx_otp_expires (expires_at),
    KEY idx_otp_phone_created (phone, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS velora_orders (
    id                VARCHAR(32)  NOT NULL,
    user_id           INT UNSIGNED DEFAULT NULL,
    guest_phone       VARCHAR(20)  NOT NULL,
    guest_name        VARCHAR(255) NOT NULL,
    address           TEXT         NOT NULL,
    note              TEXT         DEFAULT NULL,
    subtotal          INT          NOT NULL DEFAULT 0,
    discount          INT          NOT NULL DEFAULT 0,
    shipping          INT          NOT NULL DEFAULT 0,
    gift_wrap         TINYINT(1)   NOT NULL DEFAULT 0,
    total             INT          NOT NULL DEFAULT 0,
    voucher           VARCHAR(64)  DEFAULT NULL,
    status            ENUM('pending','processing','shipped','delivered','cancelled')
                                   NOT NULL DEFAULT 'pending',
    payment_status    ENUM('unpaid','pending','paid','failed','refunded')
                                   NOT NULL DEFAULT 'unpaid',
    payment_authority VARCHAR(128) DEFAULT NULL,
    payment_ref       VARCHAR(128) DEFAULT NULL,
    payment_gateway   VARCHAR(32)  DEFAULT 'zarinpal',
    payment_amount    BIGINT       DEFAULT NULL,
    ip_address        VARCHAR(45)  DEFAULT NULL,
    user_agent        TEXT,
    created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_orders_user_created (user_id, created_at),
    KEY idx_orders_payment (payment_status),
    KEY idx_orders_phone_created (guest_phone, created_at),
    KEY idx_orders_sweep_full (payment_status, status, created_at),
    CONSTRAINT chk_total_positive CHECK (total >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS velora_order_items (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id     VARCHAR(32)  NOT NULL,
    product_id   VARCHAR(64)  NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    color        VARCHAR(128) DEFAULT '',
    eu_size      INT          DEFAULT 0,
    qty          INT          NOT NULL DEFAULT 1,
    unit_price   INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_items_order (order_id),
    KEY idx_items_product (product_id),
    CONSTRAINT fk_items_order
        FOREIGN KEY (order_id) REFERENCES velora_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS velora_reviews (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id VARCHAR(64)  NOT NULL,
    user_name  VARCHAR(128) NOT NULL,
    rating     TINYINT      NOT NULL,
    text       TEXT,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reviews_product (product_id),
    KEY idx_reviews_product_created (product_id, created_at),
    CONSTRAINT fk_reviews_product
        FOREIGN KEY (product_id) REFERENCES velora_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS velora_appointments (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    date       DATE         NOT NULL,
    time       VARCHAR(10)  NOT NULL,
    name       VARCHAR(255) NOT NULL,
    phone      VARCHAR(20)  NOT NULL,
    email      VARCHAR(255) DEFAULT NULL,
    status     ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_appt_status_date (status, date),
    /* A slot must be unique only while it is actually held. The old
       UNIQUE KEY (date, time) also covered cancelled rows, so once the admin
       cancelled a slot it could never be booked again — the rebooking attempt
       died on error 1062 and the customer was told the time was "already
       taken". A generated column that is NULL for cancelled rows restores
       rebooking while keeping the concurrency guarantee for live ones, and
       MySQL/MariaDB both treat NULLs in a UNIQUE index as non-conflicting. */
    active_slot VARCHAR(24)
        GENERATED ALWAYS AS (IF(status = 'cancelled', NULL, CONCAT(date, ' ', time))) VIRTUAL,
    UNIQUE KEY uk_appt_active_slot (active_slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS velora_restock (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id VARCHAR(64)  NOT NULL,
    eu_size    INT          NOT NULL,
    email      VARCHAR(255) NOT NULL,
    notified   TINYINT(1)   NOT NULL DEFAULT 0,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_restock (product_id, eu_size, email),
    KEY idx_restock_product (product_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS velora_payment_logs (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id   VARCHAR(32)  NOT NULL,
    gateway    VARCHAR(32)  NOT NULL,
    authority  VARCHAR(128) DEFAULT NULL,
    ref_id     VARCHAR(128) DEFAULT NULL,
    amount     BIGINT       NOT NULL,
    status     VARCHAR(32)  NOT NULL,
    response   TEXT,
    ip_address VARCHAR(45)  DEFAULT NULL,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_payment_logs_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── velora_checkout_attempts · checkout idempotency ────────────────────────
-- The fix for the duplicate-order race. `checkout` used to record the last
-- successful attempt in $_SESSION['_cko_last'] and read it back to collapse a
-- double submit. A session is a poor authority for that: the same customer on
-- a phone and a laptop submits the identical basket within the same second and
-- both requests pass the check, so two orders exist, stock is decremented
-- twice, and two gateway sessions are opened for one purchase. The session
-- fast path is kept (it is free and it handles the common case), but it is no
-- longer the only authority — this table is, and the UNIQUE KEY below is what
-- makes the check atomic rather than advisory.
--
-- WHY minute_bucket AND NOT created_at IN THE UNIQUE KEY
-- This is the part that is easy to get wrong and that makes the table inert.
-- The obvious schema is
--     UNIQUE KEY uk (user_id, fingerprint, created_at)
-- on the strength that created_at is part of the identity. It is not:
-- created_at is `TIMESTAMP DEFAULT CURRENT_TIMESTAMP`, so it takes a new value
-- on every single INSERT. Two rows with the same (user_id, fingerprint) get
-- different created_at values and the constraint never fires — the table grows
-- a row per attempt and nothing is ever deduplicated. The unique key has to be
-- built on a value that is *deliberately* coarse, so that "the same payload,
-- within the same window" collapses onto one row.
--
-- The bucket is computed in SQL from the server clock, never in PHP:
--     minute_bucket = FLOOR(UNIX_TIMESTAMP(NOW()) / 60)
-- so the row cannot disagree with created_at (which the engine sets from the
-- same clock), and there is no PHP-side value to get wrong. The session
-- time_zone is pinned to +03:30 by the PDO init command in config.php, which
-- is what makes UNIX_TIMESTAMP(NOW()) and the TIMESTAMP column agree; a
-- connection that changed time_zone mid-request would shift the bucket, and
-- the consequence would be a failed dedupe rather than a wrong order.
--
-- 60 s is the bucket width, not the replay window. The window is enforced
-- separately, in seconds, by the WHERE clauses in api.php — a bucket is only a
-- candidate set to look inside, never the decision. That separation is what
-- makes the 90 s window exact despite straddling bucket boundaries: a retry at
-- 12:00:59 and one at 12:01:01 land in different buckets, are both found by
-- the widened bucket range, and both then pass the same second-granularity
-- freshness test.
--
-- state is 'pending' from the claim until the gateway has answered. A 'pending'
-- row younger than 300 s means another request for this exact payload is
-- genuinely in flight — the gateway call is a network round trip and can be
-- slow — so a concurrent duplicate is told to retry rather than being handed a
-- second order. A 'pending' row older than 300 s is the residue of a process
-- that was killed mid-checkout, and is taken over.
--
-- claim_token is how a request knows the slot is *its own*. Without it, "the
-- row I just wrote is pending" is ambiguous between "I claimed it" and "someone
-- else claimed it and I lost the race". The loser is detectable only because
-- the token it generated differs from the one on the row.
--
-- On the identity of the key: user_id is 0 for a guest, so guest duplicates
-- collapse on (phone, address, items, voucher, gift) instead. That is
-- deliberate — an order for an identical basket, to an identical phone number
-- and an identical address, inside one minute, is one purchase, and splitting
-- it is the exact defect this table exists to remove. It does mean such a pair
-- shares one order and one pay_url, which is the intended outcome: one basket,
-- one payment.
--
-- Retention: api.php deletes rows older than 24 h from the 1-in-20 sweep. That
-- is deliberately much longer than the 300 s abandon window, so it can never be
-- the reason a live row disappears.
CREATE TABLE IF NOT EXISTS velora_checkout_attempts (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    fingerprint   CHAR(64)     NOT NULL,
    minute_bucket INT UNSIGNED NOT NULL,
    state         VARCHAR(16)  NOT NULL DEFAULT 'pending',
    claim_token   CHAR(32)     NOT NULL,
    order_id      VARCHAR(32)  NOT NULL DEFAULT '',
    pay_url       VARCHAR(1024) NOT NULL DEFAULT '',
    total         BIGINT       NOT NULL DEFAULT 0,
    gateway       VARCHAR(32)  NOT NULL DEFAULT '',
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    settled_at    TIMESTAMP    NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cko_fp (user_id, fingerprint, minute_bucket),
    KEY idx_cko_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ───────────────────────────────────────────────────────────────────────────
-- velora_addresses · the address book
-- ───────────────────────────────────────────────────────────────────────────
-- Until now the only place an address existed was velora_orders.address, one
-- free-text blob typed into a single textarea. That cannot be edited, cannot be
-- reused, and cannot be validated: "تهران خیابان ولیعصر" and "从一个" both pass
-- the 10-character check, and a mistyped city name is found by the courier, in
-- the customer's face.
--
-- So the address is now structured. province and city are separate columns and
-- both are validated against the Iranian list in includes/geo.php — the same
-- list the client draws its two-step picker from, which means a city that
-- cannot be picked cannot be typed either.
--
-- The postal code is 10 digits with the Iranian checksum (mod 11, remainder
-- must be 2 or less) rather than a 10-digit length check. A bad postal code is
-- the single most common cause of a returned parcel in Iran, and the checksum
-- is free to compute.
--
-- A customer may hold several addresses but exactly one default: the partial
-- UNIQUE index is not portable across MySQL, so is_default is enforced in the
-- write path (api.php clears the others inside the same transaction) and
-- checked here as a non-unique index.
--
-- No FOREIGN KEY on user_id, and that is a decision rather than an omission.
-- A cascade would delete a customer's saved addresses, while velora_orders —
-- which has no cascade and no key — keeps pointing at address_id. Deleting an
-- account would then leave orders referencing rows that are gone. A user is
-- removed by anonymising them, not by cascading; the write path decides that,
-- and the database is not asked to make the call. migrate-addressbook.php and
-- db.sql omit the key for the same reason, so a fresh install and a migrated
-- one end up with the same shape.
CREATE TABLE IF NOT EXISTS velora_addresses (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    label       VARCHAR(40)  NOT NULL DEFAULT 'خانه',
    receiver    VARCHAR(120) NOT NULL DEFAULT '',
    phone       VARCHAR(20)  NOT NULL DEFAULT '',
    province    VARCHAR(64)  NOT NULL,
    city        VARCHAR(64)  NOT NULL,
    district    VARCHAR(120) NOT NULL DEFAULT '',
    line        VARCHAR(400) NOT NULL DEFAULT '',
    plaque      VARCHAR(40)  NOT NULL DEFAULT '',
    unit        VARCHAR(80)  NOT NULL DEFAULT '',
    postal_code CHAR(10)     NOT NULL DEFAULT '',
    note        VARCHAR(400) NOT NULL DEFAULT '',
    is_default  TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_addr_user (user_id, is_default, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ───────────────────────────────────────────────────────────────────────────
-- velora_user_phones · which numbers a customer has proven they own
-- ───────────────────────────────────────────────────────────────────────────
-- The checkout form used to accept any well-formed number: a signed-in
-- customer could send an order to 09120000000 while the admin, who reads
-- guest_phone and not the account, called a number nobody had verified. The
-- address book needs the same guarantee for delivery calls.
--
-- velora_users.phone stays the *login* identity and remains UNIQUE — one
-- account, one login number. This table is the broader set: every number this
-- customer has completed an OTP challenge for, including the login number.
-- Checkout accepts a phone only if it appears here, so changing the delivery
-- number to one the customer has never verified is impossible rather than
-- merely discouraged.
--
-- is_primary marks the number used for login. It is denormalised from
-- velora_users.phone and exists so "can this number receive order updates?" is
-- a single indexed read instead of a join.
--
-- No FOREIGN KEY on user_id here either, for the same reason as
-- velora_addresses: the rows are receipts for an OTP that happened, and the
-- account they belong to is removed by the write path rather than by a
-- cascade. The unique key below is the constraint that actually matters — one
-- row per (account, number), so "already verified" is a lookup and not a guess.
CREATE TABLE IF NOT EXISTS velora_user_phones (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    phone       VARCHAR(20)  NOT NULL,
    is_primary  TINYINT(1)   NOT NULL DEFAULT 0,
    verified_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP   NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_user_phone (user_id, phone),
    KEY idx_phone_lookup (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══════════════════════════════════════════════════════════════════════════
-- SECTION 2 · SEED CATALOG
-- 11 demo products · mirrors app.js RAW array
-- ═══════════════════════════════════════════════════════════════════════════

-- ─── 2.1 · Products ────────────────────────────────────────────────────────
INSERT INTO velora_products
    (id, name, cat, sub, `desc`, price, old_price, heel, width, sole,
     is_new, sold, eta, bias, feats, specs, active, sort_order, drop_date)
VALUES
('notte', 'نوته', 'heel', 'پامپ لاکی · پاشنه ۱۰۰ میلیمتر',
 'پامپ لاکی با پاشنه ۱۰۰ میلیمتر و قوس استاندارد؛ مناسب مجالس و استفادهٔ رسمی.',
 31000000, 0, 100, 'n', 'leather', 1, 186, 4, 0.00,
 '["رویهٔ لاکی","قوس استاندارد","پاشنه ۱۰۰ میلیمتر"]',
 '{"رویه":"چرم لاکی","زیره":"چرمی","پاشنه":"۱۰۰ میلیمتر","قالب":"باریک","وزن":"۳۱۰ گرم"}',
 1, 0, '2026-03-02'),

('aurelia', 'اورلیا', 'heel', 'پامپ شامپاینی · پاشنه ۱۰۰ میلیمتر',
 'پامپ شامپاینی با رویهٔ متالیک و پاشنه ۱۰۰ میلیمتر؛ مناسب مراسم و مهمانی.',
 28500000, 0, 100, 'n', 'leather', 0, 164, 4, 0.00,
 '["رویهٔ متالیک","قوس استاندارد","آستر چرمی"]',
 '{"رویه":"چرم متالیک","زیره":"چرمی","پاشنه":"۱۰۰ میلیمتر","قالب":"باریک","وزن":"۳۰۵ گرم"}',
 1, 1, '2026-01-22'),

('notturno', 'نوتورنو', 'heel', 'اسلینگ‌بک مخمل · پاشنه ۹۰ میلیمتر',
 'اسلینگ‌بک مخمل با سگک قابل تنظیم و پاشنه ۹۰ میلیمتر.',
 22800000, 0, 90, 'r', 'leather', 1, 74, 4, 0.00,
 '["رویهٔ مخمل","سگک قابل تنظیم","بند پشت پا"]',
 '{"رویه":"مخمل","زیره":"چرمی","پاشنه":"۹۰ میلیمتر","قالب":"استاندارد","وزن":"۲۹۵ گرم"}',
 1, 2, '2026-05-11'),

('alba', 'آلبا', 'flat', 'بالهٔ راحت · پاشنه ۱۰ میلیمتر',
 'بالهٔ راحت با پاپیون و کفی نرم؛ مناسب استفادهٔ روزانه.',
 15600000, 0, 10, 'w', 'leather', 0, 142, 3, -0.20,
 '["کفی نرم","پاپیون","پاشنه کوتاه"]',
 '{"رویه":"پارچه‌ای","زیره":"چرمی","پاشنه":"۱۰ میلیمتر","قالب":"عریض","وزن":"۱۸۰ گرم"}',
 1, 3, '2026-01-18'),

('velluto', 'ولوتو', 'boot', 'نیم‌بوت · پاشنه ۷۵ میلیمتر',
 'نیم‌بوت با زیپ جانبی و یقهٔ پددار؛ پاشنه ۷۵ میلیمتر.',
 34200000, 0, 75, 'r', 'rubber', 0, 118, 5, 0.20,
 '["زیپ جانبی","یقهٔ پددار","زیرهٔ مقاوم"]',
 '{"رویه":"چرم","زیره":"چرم و لاستیک","پاشنه":"۷۵ میلیمتر","قالب":"استاندارد","وزن":"۴۸۰ گرم"}',
 1, 4, '2025-11-04'),

('costa', 'کاستا', 'sandal', 'صندل مجلسی · پاشنه ۹۵ میلیمتر',
 'صندل مجلسی با بندهای ظریف و پاشنه ۹۵ میلیمتر؛ مناسب مهمانی.',
 19800000, 28900000, 95, 'n', 'leather', 0, 96, 4, 0.00,
 '["بندهای ظریف","سگک قابل تنظیم","پد پنجه"]',
 '{"رویه":"چرم متالیک","زیره":"چرمی","پاشنه":"۹۵ میلیمتر","قالب":"باریک","وزن":"۲۷۰ گرم"}',
 1, 5, '2025-10-12'),

('contessa', 'کنتسا', 'loafer', 'لوفر · پاشنه ۲۵ میلیمتر',
 'لوفر چرم با منگوله و کفی راحت؛ مناسب استفادهٔ روزمره و اداری.',
 19200000, 0, 25, 'w', 'rubber', 0, 134, 4, -0.10,
 '["منگوله","کفی راحت","پاشنه کوتاه"]',
 '{"رویه":"چرم","زیره":"چرمی","پاشنه":"۲۵ میلیمتر","قالب":"عریض","وزن":"۳۴۰ گرم"}',
 1, 6, '2026-02-08'),

('serafina', 'سرافینا', 'bridal', 'کفش عروس · پاشنه ۸۵ میلیمتر',
 'کفش عروس با رویهٔ ساتن و تزئین مروارید؛ پاشنه ۸۵ میلیمتر و کفی پددار.',
 38700000, 0, 85, 'n', 'leather', 0, 39, 7, 0.00,
 '["تزئین مروارید","کفی پددار","ساتن"]',
 '{"رویه":"ساتن","زیره":"چرمی","پاشنه":"۸۵ میلیمتر","قالب":"باریک","وزن":"۳۰۰ گرم"}',
 1, 7, '2025-12-20'),

('regina', 'رجینا', 'heel', 'پامپ مجلسی · پاشنه ۱۱۰ میلیمتر',
 'بلندترین پاشنهٔ مزون؛ ۱۱۰ میلیمتر با قوس تقویت‌شده، مخصوص مجالس.',
 44600000, 0, 110, 'n', 'leather', 1, 14, 7, 0.00,
 '["پاشنه ۱۱۰ میلیمتر","قوس تقویت‌شده","طراحی مجلسی"]',
 '{"رویه":"چرم لاکی","زیره":"چرمی","پاشنه":"۱۱۰ میلیمتر","قالب":"باریک","وزن":"۳۳۰ گرم"}',
 1, 8, '2026-06-08'),

('ombra', 'امبرا', 'boot', 'بوت ساق‌بلند · پاشنه ۵۵ میلیمتر',
 'بوت ساق‌بلند با زیپ دوطرفه و ساق قابل تنظیم؛ پاشنه ۵۵ میلیمتر.',
 41500000, 0, 55, 'r', 'rubber', 0, 47, 6, 0.30,
 '["زیپ دوطرفه","ساق قابل تنظیم","زیرهٔ مقاوم"]',
 '{"رویه":"چرم مات","زیره":"لاستیک و چرم","پاشنه":"۵۵ میلیمتر","قالب":"استاندارد","وزن":"۶۲۰ گرم"}',
 1, 9, '2025-10-02'),

('rosa', 'رزا', 'heel', 'مری جین · پاشنه ۴۵ میلیمتر',
 'مری جین کلاسیک با سگک و کفی فوم؛ پاشنه ۴۵ میلیمتر، مناسب استفادهٔ شهری.',
 11400000, 14900000, 45, 'w', 'rubber', 0, 203, 3, -0.15,
 '["سگک","کفی فوم","بند قابل تنظیم"]',
 '{"رویه":"چرم لاکی","زیره":"چرمی","پاشنه":"۴۵ میلیمتر","قالب":"عریض","وزن":"۲۹۰ گرم"}',
 1, 10, '2026-01-29')

ON DUPLICATE KEY UPDATE
    name       = VALUES(name),
    cat        = VALUES(cat),
    sub        = VALUES(sub),
    `desc`     = VALUES(`desc`),
    price      = VALUES(price),
    old_price  = VALUES(old_price),
    heel       = VALUES(heel),
    width      = VALUES(width),
    sole       = VALUES(sole),
    is_new     = VALUES(is_new),
    sold       = VALUES(sold),
    eta        = VALUES(eta),
    bias       = VALUES(bias),
    feats      = VALUES(feats),
    specs      = VALUES(specs),
    active     = VALUES(active),
    sort_order = VALUES(sort_order),
    drop_date  = VALUES(drop_date),
    updated_at = CURRENT_TIMESTAMP;


-- ─── 2.2 · Gallery (scoped re-seed) ────────────────────────────────────────
DELETE FROM velora_gallery WHERE product_id IN (
    'notte','aurelia','notturno','alba','velluto','costa',
    'contessa','serafina','regina','ombra','rosa'
);

INSERT INTO velora_gallery (product_id, unsplash_key, sort_order) VALUES
('notte',    'notte',    0), ('notte',    'regina',   1), ('notte',    'aurelia',  2), ('notte',    'notturno', 3),
('aurelia',  'aurelia',  0), ('aurelia',  'costa',    1), ('aurelia',  'regina',   2), ('aurelia',  'notte',    3),
('notturno', 'notturno', 0), ('notturno', 'aurelia',  1), ('notturno', 'notte',    2), ('notturno', 'rosa',     3),
('alba',     'alba',     0), ('alba',     'rosa',     1), ('alba',     'aurelia',  2), ('alba',     'notturno', 3),
('velluto',  'velluto',  0), ('velluto',  'ombra',    1), ('velluto',  'contessa', 2), ('velluto',  'aurelia',  3),
('costa',    'costa',    0), ('costa',    'aurelia',  1), ('costa',    'regina',   2), ('costa',    'alba',     3),
('contessa', 'contessa', 0), ('contessa', 'velluto',  1), ('contessa', 'ombra',    2), ('contessa', 'alba',     3),
('serafina', 'serafina', 0), ('serafina', 'alba',     1), ('serafina', 'aurelia',  2), ('serafina', 'costa',    3),
('regina',   'regina',   0), ('regina',   'serafina', 1), ('regina',   'costa',    2), ('regina',   'notte',    3),
('ombra',    'ombra',    0), ('ombra',    'velluto',  1), ('ombra',    'contessa', 2), ('ombra',    'notte',    3),
('rosa',     'rosa',     0), ('rosa',     'alba',     1), ('rosa',     'notturno', 2), ('rosa',     'notte',    3);


-- ─── 2.3 · Colors (scoped re-seed) ─────────────────────────────────────────
DELETE FROM velora_colors WHERE product_id IN (
    'notte','aurelia','notturno','alba','velluto','costa',
    'contessa','serafina','regina','ombra','rosa'
);

INSERT INTO velora_colors (product_id, color_key, color_name, sort_order) VALUES
('notte',    'patent',   'لاکی مشکی',        0),
('notte',    'noir',     'مشکی مات',         1),
('aurelia',  'champ',    'شامپاینی',         0),
('aurelia',  'ivory',    'عاجی',             1),
('notturno', 'satin',    'مخمل مشکی',        0),
('notturno', 'oxblood',  'زرشکی تیره',       1),
('alba',     'ivory',    'عاجی',             0),
('alba',     'suede',    'جیر روشن',         1),
('velluto',  'cognac',   'قهوه‌ای عسلی',     0),
('velluto',  'noir',     'مشکی',             1),
('costa',    'gold',     'طلایی',            0),
('costa',    'ivory',    'عاجی',             1),
('contessa', 'cognac',   'قهوه‌ای عسلی',     0),
('contessa', 'noir',     'مشکی',             1),
('serafina', 'ivory',    'ساتن عاجی',        0),
('serafina', 'pearl',    'مرواریدی',         1),
('regina',   'gold',     'طلایی',            0),
('regina',   'patent',   'لاکی مشکی',        1),
('ombra',    'noir',     'مشکی مات',         0),
('ombra',    'cognac',   'قهوه‌ای تیره',     1),
('rosa',     'patent',   'لاکی مشکی',        0),
('rosa',     'cognac',   'قهوه‌ای عسلی',     1),
('rosa',     'oxblood',  'زرشکی تیره',       2);


-- ─── 2.4 · Sizes (scoped re-seed) ──────────────────────────────────────────
DELETE FROM velora_sizes WHERE product_id IN (
    'notte','aurelia','notturno','alba','velluto','costa',
    'contessa','serafina','regina','ombra','rosa'
);

INSERT INTO velora_sizes (product_id, eu, stock) VALUES
-- notte · 37:8,38:4,39:5,40:3,41:1  (21 pairs)
('notte', 37, 8), ('notte', 38, 4), ('notte', 39, 5), ('notte', 40, 3), ('notte', 41, 1),
-- aurelia · 37:8,38:5,39:4,40:3,41:1  (21 pairs)
('aurelia', 37, 8), ('aurelia', 38, 5), ('aurelia', 39, 4), ('aurelia', 40, 3), ('aurelia', 41, 1),
-- notturno · 37:7,38:6,39:4,40:2,41:1  (20 pairs)
('notturno', 37, 7), ('notturno', 38, 6), ('notturno', 39, 4), ('notturno', 40, 2), ('notturno', 41, 1),
-- alba · 37:10,38:6,39:7,40:4,41:2  (29 pairs)
('alba', 37, 10), ('alba', 38, 6), ('alba', 39, 7), ('alba', 40, 4), ('alba', 41, 2),
-- velluto · 37:5,38:6,39:4,40:3,41:2  (20 pairs)
('velluto', 37, 5), ('velluto', 38, 6), ('velluto', 39, 4), ('velluto', 40, 3), ('velluto', 41, 2),
-- costa · 37:4,38:6,39:3,40:2,41:0  (15 pairs)
('costa', 37, 4), ('costa', 38, 6), ('costa', 39, 3), ('costa', 40, 2), ('costa', 41, 0),
-- contessa · 37:9,38:6,39:8,40:5,41:3  (31 pairs)
('contessa', 37, 9), ('contessa', 38, 6), ('contessa', 39, 8), ('contessa', 40, 5), ('contessa', 41, 3),
-- serafina · 37:3,38:2,39:1,40:1,41:0  (7 pairs)
('serafina', 37, 3), ('serafina', 38, 2), ('serafina', 39, 1), ('serafina', 40, 1), ('serafina', 41, 0),
-- regina · 37:2,38:2,39:1,40:1,41:0  (6 pairs)
('regina', 37, 2), ('regina', 38, 2), ('regina', 39, 1), ('regina', 40, 1), ('regina', 41, 0),
-- ombra · 37:3,38:4,39:3,40:2,41:1  (13 pairs)
('ombra', 37, 3), ('ombra', 38, 4), ('ombra', 39, 3), ('ombra', 40, 2), ('ombra', 41, 1),
-- rosa · 37:6,38:3,39:5,40:2,41:0  (16 pairs)
('rosa', 37, 6), ('rosa', 38, 3), ('rosa', 39, 5), ('rosa', 40, 2), ('rosa', 41, 0);


-- ═══════════════════════════════════════════════════════════════════════════
-- SECTION 3 · OPTIONAL · SCHEMA MIGRATION FOR PRE-v10 INSTALLS
-- Only needed if the database was provisioned by an older install.
--
-- PORTABILITY NOTE: the previous version of this file used
--     ALTER TABLE ... ADD COLUMN IF NOT EXISTS
-- and the header claimed it was "safe on MySQL 8.0.29+ / MariaDB 10.6.11+".
-- That was false. MariaDB does not support `ADD COLUMN IF NOT EXISTS` at all
-- (it only learned it for indexes/constraints), so on MariaDB this statement
-- is a hard syntax error and aborts the whole import. The information_schema
-- guard below is portable and idempotent on both engines.
-- ═══════════════════════════════════════════════════════════════════════════

-- velora_products.bias
SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_products ADD COLUMN bias DECIMAL(5,2) NOT NULL DEFAULT 0',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'velora_products'
      AND COLUMN_NAME  = 'bias'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

-- velora_orders.gift_wrap  (gift-wrap fee is now charged server-side)
SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_orders ADD COLUMN gift_wrap TINYINT(1) NOT NULL DEFAULT 0',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'velora_orders'
      AND COLUMN_NAME  = 'gift_wrap'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

-- velora_orders.address_snapshot · the address as it stood at the moment of
-- the order. The order keeps its own copy of province, city and the rest,
-- because velora_addresses is the *live* book: a customer who corrects a typo
-- in their saved address must not silently rewrite where a parcel sent three
-- weeks ago was addressed. `address` (the composed one-line string) stays as
-- the field admin.php and the courier label already read.
SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_orders ADD COLUMN address_snapshot JSON NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'velora_orders'
      AND COLUMN_NAME  = 'address_snapshot'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

-- velora_orders.address_id · which book entry was used, so "ship again" can
-- offer it back. NULL when the customer typed a one-off address instead.
SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_orders ADD COLUMN address_id INT UNSIGNED NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'velora_orders'
      AND COLUMN_NAME  = 'address_id'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

-- velora_orders.shipping is now always 0 (SHIPPING_FLAT). The column is
-- intentionally NOT dropped and existing rows are NOT back-filled: it is the
-- record of what was actually charged, and a 2024 order that says 680,000 is
-- telling the truth about 2024. Only new inserts change.
--
-- velora_orders.gift_wrap likewise stays, and is written as 0 from now on.

-- ── Supporting indexes ───────────────────────────────────────────────────
-- Every one of these is created with the information_schema guard as well, so
-- re-running the migration is a no-op instead of a duplicate-key error.

-- velora_sizes: the checkout hot path locks (product_id, eu) FOR UPDATE.
SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_sizes ADD KEY idx_sizes_product_eu_stock (product_id, eu, stock)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'velora_sizes'
      AND INDEX_NAME   = 'idx_sizes_product_eu_stock'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

-- velora_orders: admin order search / phone lookup.
SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_orders ADD KEY idx_orders_phone_created (guest_phone, created_at)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'velora_orders'
      AND INDEX_NAME   = 'idx_orders_phone_created'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

-- ═══════════════════════════════════════════════════════════════════════════
-- 2.5 · Size standard 37..41 — purge retired bands + enforce the range
-- ═══════════════════════════════════════════════════════════════════════════
-- The maison now runs ONE sizing system, 37..41. The 35/36 rows and anything
-- else outside the band are retired. Destructive by design and irreversible:
-- the stock held against a retired size is discarded with the row. Snapshot
-- first if the units matter —
--   CREATE TABLE velora_sizes_backup AS SELECT * FROM velora_sizes;
--
-- velora_order_items.eu_size is deliberately NOT touched: historical orders
-- must keep the size they were placed with, and the column is a frozen
-- contract for the order and refund paths.
DELETE FROM velora_sizes WHERE eu < 37 OR eu > 41;

-- Enforce the band at the storage layer so an out-of-range size can never be
-- reintroduced by a hand-edited row, a bad import or a future code path.
-- velora_sizes is dropped and recreated rather than ALTERed because MySQL
-- does not support adding a CHECK to an existing table.
SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_sizes
            ADD CONSTRAINT chk_sizes_band CHECK (eu BETWEEN 37 AND 41)',
        'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'velora_sizes'
      AND CONSTRAINT_NAME = 'chk_sizes_band'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

-- velora_orders: abandoned-order sweep filters on (status, payment_status, created_at).
SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_orders ADD KEY idx_orders_sweep (payment_status, created_at)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'velora_orders'
      AND INDEX_NAME   = 'idx_orders_sweep'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

-- velora_otp: the send path discards expired rows by expiry.
SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_otp ADD KEY idx_otp_expires (expires_at)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'velora_otp'
      AND INDEX_NAME   = 'idx_otp_expires'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

-- velora_otp: verification counts recent attempts per phone.
SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_otp ADD KEY idx_otp_phone_created (phone, created_at)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'velora_otp'
      AND INDEX_NAME   = 'idx_otp_phone_created'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

-- velora_reviews: product page listing, newest first.
SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_reviews ADD KEY idx_reviews_product_created (product_id, created_at)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'velora_reviews'
      AND INDEX_NAME   = 'idx_reviews_product_created'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

-- velora_restock: restock history per product.
SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_restock ADD KEY idx_restock_product (product_id, created_at)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'velora_restock'
      AND INDEX_NAME   = 'idx_restock_product'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

-- velora_order_items: the `sold` counter JOINs on product_id alone.
SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_order_items ADD KEY idx_items_product (product_id)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'velora_order_items'
      AND INDEX_NAME   = 'idx_items_product'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

-- velora_appointments: admin list filtered by status/date. Note the column is
-- `date`, not `appt_date`.
SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_appointments ADD KEY idx_appt_status_date (status, date)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'velora_appointments'
      AND INDEX_NAME   = 'idx_appt_status_date'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

-- velora_appointments: replace the blanket UNIQUE(date,time) with a generated
-- column that excludes cancelled slots, so a cancelled time becomes bookable
-- again while live slots stay race-free. The booking endpoint already treats
-- error 1062 as TIME_TAKEN, so the guarantee is still enforced by the index.
--
-- This migration has to decide on TWO independent things, and it was asking one
-- question of one guard. It now asks each separately, because each has its own
-- failure mode and they are opposites of each other:
--
--   1. DROP INDEX uk_appt_date_time  — only if that index EXISTS. Guarded by
--      COUNT(*) > 0. This was the import-breaking bug: the condition was
--      `COUNT(*) = 0`, which fired the DROP precisely when the index was ABSENT
--      — so a fresh database (where the index does not exist) got
--      "ERROR 1091 (42000): Can't DROP INDEX `uk_appt_date_time`; check that it
--      exists", and the import aborted at line 853 before any later section
--      ran. Every ADD-style guard in this file is correctly `= 0` because
--      adding is the action when the thing is absent; a DROP is the action when
--      the thing is PRESENT, so copying that idiom here is what inverted it.
--
--   2. ADD COLUMN active_slot / ADD UNIQUE KEY uk_appt_active_slot — only if
--      those do NOT exist. Guarded by COUNT(*) = 0, the correct polarity, and
--      now checked separately so that a database which already has the
--      generated column but still has the old blanket index (an interrupted
--      earlier import) gets the DROP it needs instead of being skipped whole.
--
-- ORDER IS LOAD-BEARING: the DROP is evaluated first, then the ADDs. MySQL
-- evaluates and prepares in statement order, and the two are independent, so
-- either order would work today — but dropping after adding would mean a
-- re-run finds the new column, skips, and never removes the old constraint.
SET @ddl := (
    SELECT IF(COUNT(*) > 0,
        'ALTER TABLE velora_appointments DROP INDEX uk_appt_date_time',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'velora_appointments'
      AND INDEX_NAME   = 'uk_appt_date_time'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_appointments
            ADD COLUMN active_slot VARCHAR(24)
                GENERATED ALWAYS AS (IF(status = ''cancelled'', NULL, CONCAT(date, '' '', time))) VIRTUAL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'velora_appointments'
      AND COLUMN_NAME  = 'active_slot'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_appointments ADD UNIQUE KEY uk_appt_active_slot (active_slot)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'velora_appointments'
      AND INDEX_NAME   = 'uk_appt_active_slot'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;


-- ═══════════════════════════════════════════════════════════════════════════
-- SECTION 3b · MONEY OVERFLOW + DATA-INTEGRITY CONSTRAINTS
-- Safe to run against an existing installation, and safe to run twice: every
-- statement below is guarded so it becomes a no-op once applied.
-- ═══════════════════════════════════════════════════════════════════════════

-- ── Why BIGINT, and on exactly these two columns ────────────────────────────
-- Unit convention in this schema, which the widening depends on:
--   velora_orders.total / subtotal / shipping / discount  → TOMAN
--   velora_orders.payment_amount                          → RIAL
--   velora_payment_logs.amount                            → RIAL
--
-- Both Rial columns are written as `total * 10` at checkout (see the gateway
-- request in api.php), so they are 10x the Toman value and were the first to
-- overflow. INT tops out at 2_147_483_647, which a Rial amount crosses once
-- total exceeds 214_748_364 Toman = 2.147 billion Rial. Whether that is a
-- routine basket or an edge case depends on your pricing, but the two columns
-- were one order of magnitude away from the INT ceiling relative to the
-- largest price the admin API accepts, so they are widened to BIGINT.
--
-- The Toman columns are deliberately left as INT. Their ceiling is 2.147
-- billion Toman, and the admin API caps a product price at 2_000_000_000, so
-- they still fit — see the note at the end of this section about carts whose
-- combined total exceeds that, which is NOT covered by this migration.
--
-- Guarded on DATA_TYPE rather than run unconditionally: MODIFY COLUMN rebuilds
-- the table (ALGORITHM=COPY) even when the type already matches, so an
-- unguarded re-run would lock and rewrite a live orders table for nothing.

SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_orders MODIFY COLUMN payment_amount BIGINT DEFAULT NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'velora_orders'
      AND COLUMN_NAME  = 'payment_amount'
      AND DATA_TYPE    = 'bigint'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_payment_logs MODIFY COLUMN amount BIGINT NOT NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'velora_payment_logs'
      AND COLUMN_NAME  = 'amount'
      AND DATA_TYPE    = 'bigint'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;


-- ── Pre-flight: rows that would violate the constraints below ───────────────
-- Run these first and confirm all three return 0. A non-zero count means the
-- ALTER that follows will abort with a "check constraint is violated" error
-- and, if the client is not running with --force, nothing after it in this
-- file will execute either — so it is worth seeing the offending rows before
-- the migration rather than after.

SELECT 'velora_products.price < 0'   AS check_name, COUNT(*) AS violations FROM velora_products WHERE price < 0
UNION ALL SELECT 'velora_products.heel out of 0..300', COUNT(*) FROM velora_products WHERE heel < 0 OR heel > 300
UNION ALL SELECT 'velora_orders.total < 0',             COUNT(*) FROM velora_orders   WHERE total < 0;

-- Also worth eyeballing before widening: any order whose Rial amount was
-- already clamped or wrapped by a non-strict SQL mode would now be stored
-- wrong rather than rejected. Non-zero here is a data bug to fix, not a
-- migration failure.
SELECT id, total, payment_amount FROM velora_orders
WHERE payment_amount IS NOT NULL AND payment_amount <> total * 10
LIMIT 20;


-- ── CHECK constraints ───────────────────────────────────────────────────────
-- MySQL parses and silently ignores CHECK before 8.0.16, and MariaDB has
-- supported it since 10.2. On an older server these become no-ops rather than
-- errors, but they are also then not enforced — verify with the SELECT in
-- SECTION 4 that reads back information_schema.
--
-- These duplicate validation the PHP layer already performs. That is the
-- point: they are the last line of defence for rows written by a script, a
-- migration, or an admin whose deployed JS is older than the server.

SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_products ADD CONSTRAINT chk_price_positive CHECK (price >= 0)',
        'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME        = 'velora_products'
      AND CONSTRAINT_NAME   = 'chk_price_positive'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_products ADD CONSTRAINT chk_heel_range CHECK (heel >= 0 AND heel <= 300)',
        'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME        = 'velora_products'
      AND CONSTRAINT_NAME   = 'chk_heel_range'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_orders ADD CONSTRAINT chk_total_positive CHECK (total >= 0)',
        'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME        = 'velora_orders'
      AND CONSTRAINT_NAME   = 'chk_total_positive'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;


-- ── Sweep index ─────────────────────────────────────────────────────────────
-- sweep_abandoned_orders() runs:
--     WHERE payment_status IN ('unpaid','pending')
--       AND status = 'pending'
--       AND created_at < NOW() - INTERVAL 30 MINUTE
--     ORDER BY id ASC LIMIT 25 FOR UPDATE
--
-- The previous idx_orders_sweep was (payment_status, created_at) — it omitted
-- `status`, so it could only be used as a prefix and the optimizer had to
-- filter `status` from the row set. Adding `status` in the middle lets all
-- three columns be used, with the range column (created_at) last, which is the
-- only column order that works: put the range first and the equality columns
-- after it can no longer narrow the scan.
--
-- NOTE: idx_orders_sweep is now a strict prefix of this index and is therefore
-- redundant. It is left in place rather than dropped here, because dropping an
-- index on a live orders table is not something to do implicitly — see the
-- optional DROP at the end of this section.

SET @ddl := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE velora_orders ADD KEY idx_orders_sweep_full (payment_status, status, created_at)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'velora_orders'
      AND INDEX_NAME   = 'idx_orders_sweep_full'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

-- Optional cleanup, left commented so it is a deliberate decision:
-- every query that could use idx_orders_sweep can equally use
-- idx_orders_sweep_full, so this only reclaims write throughput and space.
--
-- SET @ddl := (
--     SELECT IF(COUNT(*) > 0,
--         'ALTER TABLE velora_orders DROP INDEX idx_orders_sweep',
--         'DO 0')
--     FROM information_schema.STATISTICS
--     WHERE TABLE_SCHEMA = DATABASE()
--       AND TABLE_NAME   = 'velora_orders'
--       AND INDEX_NAME   = 'idx_orders_sweep'
-- );
-- PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;


-- ── NOT covered by this migration: cart totals above the INT ceiling ────────
-- The two Rial columns are now BIGINT, but the Toman columns on velora_orders
-- (subtotal, total, shipping, discount) are still INT, ceiling 2_147_483_647
-- Toman. Per-product price is capped at 2_000_000_000 by admin_product_save,
-- which is what keeps a single line inside INT — but checkout accumulates
-- across line items with no cap on the basket:
--
--     $subtotal += (int) $prod['price'] * $qty;   // api.php, order create
--     $total     = $after + $ship + $giftFee;
--
-- Two units of a 2e9-priced product reach 4e9 and overflow subtotal/total
-- before payment_amount is ever computed. Under strict mode that surfaces as
-- errno 1264 "Out of range value" and the order is rejected; under a
-- non-strict sql_mode the value is silently clamped to 2_147_483_647, which
-- chk_total_positive does NOT catch, because a clamped value is still >= 0.
--
-- This is a real gap, but closing it is an application decision rather than a
-- schema one, and it is out of scope for the statements above. Either:
--   (a) widen subtotal/total/shipping/discount to BIGINT as well, or
--   (b) reject baskets over a ceiling in order create, which also stops a
--       customer from being quoted an amount the gateway will not accept.
-- Confirm which before running anything further.


-- ═══════════════════════════════════════════════════════════════════════════
-- SECTION 4 · VERIFICATION
-- ═══════════════════════════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'products'     AS tbl, COUNT(*) AS n FROM velora_products
UNION ALL SELECT 'gallery',     COUNT(*) FROM velora_gallery
UNION ALL SELECT 'colors',      COUNT(*) FROM velora_colors
UNION ALL SELECT 'sizes',       COUNT(*) FROM velora_sizes
UNION ALL SELECT 'users',       COUNT(*) FROM velora_users
UNION ALL SELECT 'orders',      COUNT(*) FROM velora_orders
UNION ALL SELECT 'order_items', COUNT(*) FROM velora_order_items
UNION ALL SELECT 'otp',         COUNT(*) FROM velora_otp
UNION ALL SELECT 'reviews',     COUNT(*) FROM velora_reviews
UNION ALL SELECT 'appointments',COUNT(*) FROM velora_appointments
UNION ALL SELECT 'restock',     COUNT(*) FROM velora_restock
UNION ALL SELECT 'payment_logs',COUNT(*) FROM velora_payment_logs;

-- Read back the structures this migration is responsible for. If a row here is
-- missing, the corresponding ALTER did not apply — most often because the
-- server is older than MySQL 8.0.16, where CHECK is parsed and then ignored.
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND ((TABLE_NAME = 'velora_orders'      AND COLUMN_NAME = 'payment_amount')
     OR (TABLE_NAME = 'velora_payment_logs' AND COLUMN_NAME = 'amount'))
 ORDER BY TABLE_NAME;

SELECT TABLE_NAME, CONSTRAINT_NAME
  FROM information_schema.TABLE_CONSTRAINTS
 WHERE CONSTRAINT_SCHEMA = DATABASE()
   AND CONSTRAINT_TYPE   = 'CHECK'
   AND CONSTRAINT_NAME IN ('chk_price_positive','chk_heel_range','chk_total_positive')
 ORDER BY TABLE_NAME, CONSTRAINT_NAME;

SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'velora_orders'
   AND INDEX_NAME IN ('idx_orders_sweep','idx_orders_sweep_full')
 ORDER BY INDEX_NAME, SEQ_IN_INDEX;
-- ═══════════════════════════════════════════════════════════════════════════
-- SECTION 5 · PRODUCTS MOVED TO products.json
-- ═══════════════════════════════════════════════════════════════════════════
-- The product catalogue is no longer a set of tables. It is a single JSON
-- document at the document root: products.json, version-controlled and
-- read directly by index.php, api.php, admin.php and sitemap.php via the
-- helpers in includes/catalog.php.
--
-- velora_products, velora_colors, velora_sizes and velora_gallery are
-- LEGACY. They are left in place so an existing install can still see its
-- old rows, but nothing reads or writes them any more.
--
-- The FK constraints on velora_order_items, velora_reviews and
-- velora_restock that referenced velora_products must be dropped, otherwise
-- an INSERT into any of those tables fails whenever the product id is not
-- also present in velora_products — which is now the normal case, because
-- products.json is authoritative and velora_products is never written to.
--
-- To export an existing install's catalogue to products.json before
-- dropping the FKs:
--
--   SELECT CONCAT(
--     JSON_OBJECT(
--       'id', p.id, 'name', p.name, 'cat', p.cat, 'sub', p.sub,
--       'desc', p.`desc`, 'price', p.price, 'old_price', p.old_price,
--       'heel', p.heel, 'width', p.width, 'sole', p.sole,
--       'is_new', p.is_new, 'sold', p.sold, 'eta', p.eta, 'bias', p.bias,
--       'active', p.active, 'sort_order', p.sort_order, 'drop_date', p.drop_date,
--       'feats', CAST(p.feats AS JSON), 'specs', CAST(p.specs AS JSON)
--     )
--   )
--   FROM velora_products p;
--
-- (Then merge in colors, sizes and gallery per product. See the fixture at
-- the project root for the target shape.)

-- ── Drop legacy FKs (guarded by information_schema) ──────────────────────

SET @ddl := (
    SELECT IF(COUNT(*) > 0,
        'ALTER TABLE velora_order_items DROP FOREIGN KEY fk_items_product',
        'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME        = 'velora_order_items'
      AND CONSTRAINT_NAME   = 'fk_items_product'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

SET @ddl := (
    SELECT IF(COUNT(*) > 0,
        'ALTER TABLE velora_reviews DROP FOREIGN KEY fk_reviews_product',
        'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME        = 'velora_reviews'
      AND CONSTRAINT_NAME   = 'fk_reviews_product'
);
PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;

-- velora_restock has no FK in the current schema; kept as a no-op for a
-- database provisioned by an older install.

-- ── Replace the product_id FK on order_items with a plain index ─────────
-- The column stays VARCHAR(64) and continues to be indexed for the JOINs
-- in admin.php and my_orders; only the referential constraint goes.

-- The index already exists as idx_items_product from SECTION 3b. No DDL
-- change is needed there.

-- ── Optional: drop the legacy tables ─────────────────────────────────────
-- Left commented so it is a deliberate decision. Confirm products.json is
-- complete and correct first, and take a dump:
--
--   mysqldump velora velora_products velora_colors velora_sizes velora_gallery > legacy_catalog.sql
--
-- SET @ddl := (
--     SELECT IF(COUNT(*) > 0, 'DROP TABLE velora_gallery', 'DO 0')
--     FROM information_schema.TABLES
--     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'velora_gallery'
-- );
-- PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;
--
-- SET @ddl := (
--     SELECT IF(COUNT(*) > 0, 'DROP TABLE velora_colors', 'DO 0')
--     FROM information_schema.TABLES
--     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'velora_colors'
-- );
-- PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;
--
-- SET @ddl := (
--     SELECT IF(COUNT(*) > 0, 'DROP TABLE velora_sizes', 'DO 0')
--     FROM information_schema.TABLES
--     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'velora_sizes'
-- );
-- PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;
--
-- SET @ddl := (
--     SELECT IF(COUNT(*) > 0, 'DROP TABLE velora_products', 'DO 0')
--     FROM information_schema.TABLES
--     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'velora_products'
-- );
-- PREPARE st FROM @ddl; EXECUTE st; DEALLOCATE PREPARE st;
