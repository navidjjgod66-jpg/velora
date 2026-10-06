<?php
declare(strict_types=1);

/* This file is required by config.php in the running site, and by the test
   suite on its own — a test that has to bootstrap the whole application to
   check a list of cities is a test that stops being run. So the one helper it
   borrows is pulled from includes/digits.php, its single home: the guard there
   means this require is a no-op once formatting.php has loaded it through the
   same file, and it can never define a second version of "Persian digits are
   ASCII digits". */
require_once __DIR__ . '/digits.php';

/**
* VELORA · Maison de Chaussures
* Iran provinces and cities — the single source of truth
** One list, two consumers. api.php validates an address against it and
* app.js draws its province/city picker from it, served as JSON by the
* `geo_regions` action. They cannot disagree, because there is only one copy.
*
* Why a list at all: the checkout address used to be a single free-text field
* where anything over ten characters passed. A courier then had to guess which
* city of the twenty-three named "شهر" the parcel was meant for. Making the
* city a choice rather than a guess is the fix, and a choice has to come from
* somewhere authoritative.
*
* Scope is deliberately Iran-only. The list is a Persian-language storefront
* shipping with Iranian post and TIPAX; a country field would be a control that
* cannot do anything useful.
*
* Each entry is [name, weight]. The weight orders the city list within its
* province: 1 is the provincial capital and leads, then the large cities, then
* the rest alphabetically. The client renders the list in this order and offers
* type-ahead over the same order, so the most likely answer is always first —
* which is what makes a two-tap picker work on a phone.
*/

/** @return array<string, array<int, array{0:string,1:int}>> province => [ [city, weight], ... ] */
function velora_geo_regions(): array {
    static $map = null;
    if ($map !== null) return $map;

    $map = [
        'تهران' => [
            ['تهران', 1], ['شهریار', 2], ['اسلامشهر', 2], ['بهارستان', 2], ['شهر قدس', 2],
            ['پاکدشت', 3], ['پردیس', 3], ['دماوند', 3], ['ری', 3], ['شمیرانات', 3],
            ['ورامین', 4], ['پیشوا', 4], ['قرمک', 4], ['بومهن', 4], ['نسیم‌شهر', 4],
            ['شهر جدید پرند', 3], ['گلدستان', 4], ['صفادشت', 5], ['لواسان', 5],
            ['فیروزکوه', 5], ['باقرشهر', 5]
        ],
        'البرز' => [
            ['کرج', 1], ['فردیس', 2], ['نظرآباد', 2], ['ساوجبلاغ', 3], ['هشتگرد', 3],
            ['مهدشت', 3], ['ماهدشت', 4], ['چهارباغ', 4], ['شهر جدید هشتگرد', 4], ['کمال‌شهر', 4],
            ['گرمدره', 5], ['طالقان', 5], ['اشتهارد', 5]
        ],
        'اصفهان' => [
            ['اصفهان', 1], ['کاشان', 2], ['خمینی‌شهر', 2], ['نجف‌آباد', 2], ['شاهین‌شهر', 3], ['مبارکه', 3], ['گلپایگان', 4], ['فولادشهر', 4], ['خوانسار', 4],
            ['اردستان', 5], ['نطنز', 5], ['سمیرم', 5], ['دهاقان', 5]
        ],
        'خراسان رضوی' => [
            ['مشهد', 1], ['نیشابور', 2], ['سبزوار', 2], ['تربت حیدریه', 2], ['قوچان', 3],
            ['کاشمر', 3], ['چناران', 3], ['گناباد', 3], ['تایباد', 3], ['سرخس', 4],
            ['تربت جام', 4], ['درگز', 4], ['خواف', 4], ['فریمان', 4], ['باخرز', 5],
            ['ششتمد', 5], ['میان‌جلگه', 5], ['جوین', 5], ['گلبهار', 5], ['زاهدخان', 5],
            ['بجستان', 5], ['خلیل‌آباد', 5], ['شیروان', 5]
        ],
        'فارس' => [
            ['شیراز', 1], ['مرودشت', 2], ['جهرم', 2], ['فسا', 2], ['کازرون', 3],
            ['لار', 3], ['آباده', 3], ['داراب', 3], ['نی‌ریز', 3], ['استهبان', 3],
            ['سپیدان', 4], ['اقلید', 4], ['اردکان', 4], ['مهر', 4],
            ['نیک‌آباد', 5], ['ششک', 5], ['دنا', 5], ['سروستان', 5], ['جهرمستان', 5]
        ],
        'آذربایجان شرقی' => [
            ['تبریز', 1], ['مراغه', 2], ['مرند', 2], ['میانه', 3], ['بناب', 3], ['اهر', 3], ['ملکان', 4], ['بستان‌آباد', 4],
            ['جلفا', 4], ['آذرشهر', 4], ['نیشاباد', 5], ['کلیسکان', 5], ['تسویه‌نو', 5],
            ['گرمی', 5], ['شبستر', 5], ['سلماس', 5], ['سراب', 5], ['عجب‌شیر', 5],
            ['بیجار', 5], ['ویسیه', 5], ['هشترود', 5]
        ],
        'آذربایجان غربی' => [
            ['ارومیه', 1], ['خوی', 2], ['بوکان', 2], ['میاندوآب', 3], ['سلماس', 3],
            ['نقده', 3], ['ماکو', 3], ['سیاه‌پوش', 4], ['پیران‌شهر', 4], ['چالوس', 4],
            ['شاهین‌دژ', 4], ['تکاب', 5], ['نازکان', 5], ['پوزدقان', 5] ],
        'خوزستان' => [
            ['اهواز', 1], ['دزفول', 2], ['آبادان', 2], ['خرمشهر', 2], ['بندر ماهشهر', 3],
            ['بهبهان', 3], ['شوشتر', 3], ['اندیمشک', 3], ['مسجدسلیمان', 3], ['شوش', 3],
            ['امیدیه', 4], ['رامهرمیز', 4], ['هویزه', 4], ['قصرشیرین', 5], ['پاکدشت', 5],
            ['الوند', 5], ['باغبهادر', 5], ['ایذه', 5],
            ['جناح', 5], ['خوزین', 5], ['حمیدیه', 5], ['دشت‌آزادگان', 5]
        ],
        'گیلان' => [
            ['رشت', 1], ['انزلی', 2], ['لاهیجان', 2], ['لنگرود', 3], ['رودسر', 3],
            ['آستارا', 3], ['تالش', 3], ['فومن', 4], ['صومعه‌سر', 4], ['آستانه اشرفیه', 4],
            ['شفت', 4], ['ماسال', 4], ['رضوان', 5], ['سیاهکل', 5], ['کلمند', 5],
            ['جنوبی', 5]
        ],
        'گلستان' => [
            ['گرگان', 1], ['گنبد', 2], ['آق‌قلا', 2], ['علی‌آباد', 3], ['کردکوی', 3],
            ['کلاله', 3],             ['آزادشهر', 4], ['مینودشت', 4], ['بندر ترکمن', 4], ['جلوده', 4],
            ['سیاه‌رود', 5], ['مروارید', 5], ['انباری', 5]
        ],
        'مازندران' => [
            ['ساری', 1], ['بابل', 2], ['آمل', 2], ['قائم‌شهر', 3], ['نوشهر', 3],
            ['چالوس', 3], ['تنکابن', 3], ['رودسر', 3], ['محمودآباد', 4], ['نکا', 4],
            ['فریدونکنار', 4], ['نور', 4], ['عباس‌آباد', 5], ['کلاردشت', 5],
            ['میان‌آباد', 5] ],
        'قم' => [
            ['قم', 1], ['سلفچگان', 2], ['کهک', 3], ['دستجرد', 3], ['محلات', 4]
        ],
        'کرمان' => [
            ['کرمان', 1], ['رفسنجان', 2], ['سیرجان', 2], ['بم', 3], ['جیرفت', 3],
            ['زرند', 3], ['راور', 3], ['بافت', 3], ['شهر بابک', 3], ['کهنوج', 4],
            ['مشهدیر', 5], ['انار', 5], ['ریگان', 5], ['ساردشت', 5], ['نیشاباد', 5]
        ],
        'یزد' => [
            ['یزد', 1], ['میبد', 2], ['اردکان', 2], ['بافق', 3], ['مهریز', 3],
            ['ابرکوه', 3], ['اشکذر', 4], ['تفت', 4], ['بخشاوه', 5], ['قصه‌گو', 5]
        ],
        'کهگیلویه و بویراحمد' => [
            ['یاسوج', 1], ['گچساران', 2], ['دهدشت', 2], ['سی‌سخت', 3], ['دیشتر', 3],
            ['چهارپایه', 3], ['لیکک', 3], ['پاتوه', 3], ['سی‌چشان', 5],
            ['حدیج', 9]
        ],
        'چهارمحال و بختیاری' => [
            ['شهرکرد', 1], ['بروجن', 2], ['فارسان', 3], ['لردگان', 3], ['سامان', 3],
            ['فلردان', 5], ['کیون', 5], ['منوجان', 5]
        ],
        'لرستان' => [
            ['خرم‌آباد', 1], ['بوجران', 2], ['دورود', 2], ['کوهدشت', 3], ['الیگودرز', 3],
            ['ازنا', 3], ['نورآباد', 3], ['پلدختر', 3], ['شوشتر', 4], ['معمولان', 4],
            ['کژه', 4], ['سیلو', 5], ['پانه', 4]
        ],
        'ایلام' => [
            ['ایلام', 1], ['دهلران', 2], ['دره‌شهر', 2], ['آبدانان', 3], ['ایوان', 3],
            ['مهران', 3], ['شیروان چرداول', 4]
        ],
        'کرمانشاه' => [
            ['کرمانشاه', 1], ['اسلام‌آباد غرب', 2], ['کنگاور', 2], ['هرسین', 2], ['سنقر', 3],
            ['جوانران', 3], ['قصر شیرین', 3], ['پاوه', 4], ['گیلان غرب', 4] ],
        'کردستان' => [
            ['سنندج', 1], ['سقز', 2], ['مریوان', 2], ['بانه', 2], ['قروه', 3],
            ['کامیاران', 3], ['دیواندره', 3], ['دهگلان', 4], ['سوران', 4], ['بیجار', 4]
        ],
        'همدان' => [
            ['همدان', 1], ['ملایر', 2], ['نهاوند', 2], ['تویسرکان', 3], ['اسدآباد', 3],
            ['بهار', 3], ['کبودرآهنگ', 3], ['رزن', 4], ['فامنین', 4], ['گلزار', 4],
            ['سردشت', 4]
        ],
        'مرکزی' => [
            ['اراک', 1], ['سالماس', 2], ['خمین', 2], ['دلیجان', 3], ['شازند', 3],
            ['محلات', 3], ['تومان‌باغ', 5], ['نراق', 5], ['آشتیان', 5]
        ],
        'بوشهر' => [
            ['بوشهر', 1], ['برازجان', 2], ['خرمج', 2], ['کنگان', 3], ['بندر دیلم', 3],
            ['اهرم', 3], ['تنگستان', 4], ['دشتستان', 4], ['عسلویه', 5] ],
        'سیستان و بلوچستان' => [
            ['زاهدان', 1], ['ایرانشهر', 2], ['چابهار', 2], ['خاش', 3],
            ['بمپور', 3], ['زابل', 3], ['سرباز', 4], ['میرجاوه', 4], ['نیک‌شهر', 4],
            ['کنارک', 4], ['باهوت', 5], ['تیمورگاه', 5], ['قصرم', 5]
        ],
        'خراسان جنوبی' => [
            ['بیرجند', 1], ['قائن', 2], ['نهبندان', 2], ['سربیشه', 3], ['فردوس', 3],
            ['بشرویه', 3], ['طبس', 4], ['درمزن', 4], ['گناباد', 4]
        ],
        'خراسان شمالی' => [
            ['بجنورد', 1], ['شیروان', 2], ['اسفراین', 2], ['آشخانه', 3], ['گرمه', 3],
            ['جاجرم', 4], ['فکه', 4], ['ماندوان', 5], ['سرشک', 5]
        ],
        'اردبیل' => [
            ['اردبیل', 1], ['پارس‌آباد', 2], ['مشگین‌شهر', 2], ['خلخال', 3], ['گرمی', 3],
            ['نیر', 4], ['بیله‌سوار', 4], ['جلفا', 4], ['نوک', 5], ['بید', 5],
            ['هیر', 5], ['شهر تازه', 5], ['لرد', 5]
        ],
        'البرز شهرستان' => []
    ];

    /* Drop empties: a few keys above are placeholders from the source list and
       must never reach the UI as a province with no cities. */
    foreach ($map as $province => $cities) {
        if ($cities === []) unset($map[$province]);
    }

    /* Sort each province by weight, then name, so the list is deterministic
       regardless of how it was typed above. usort is not stable on all PHP
       builds, so the name is the explicit tiebreaker. */
    foreach ($map as &$cities) {
        usort($cities, static function (array $a, array $b): int {
            return [$a[1], $a[0]] <=> [$b[1], $b[0]];
        });
    }
    unset($cities);

    return $map;
}

/** Provinces in display order, i.e. with at least one city. @return string[] */
function velora_geo_provinces(): array {
    return array_keys(velora_geo_regions());
}

/** @return string[] the cities of one province, ordered */
function velora_geo_cities(string $province): array {
    $map = velora_geo_regions();
    if (!isset($map[$province])) return [];
    return array_column($map[$province], 0);
}

/**
* Is this city actually in this province?
*
* The pair is validated together rather than separately on purpose. Checking
* "تهران" against the province list and "اصفهان" against the city list would
* both pass and produce an address that names two different provinces — the
* single most common way a hand-written Iranian address goes wrong.
*/
function velora_geo_valid(string $province, string $city): bool {
    return in_array($city, velora_geo_cities($province), true);
}

/** The client's shape: { "تهران": ["تهران", "شهریار", ...], ... } */
function velora_geo_json(): string {
    $out = [];
    foreach (velora_geo_regions() as $province => $cities) {
        $out[$province] = array_column($cities, 0);
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Is this a valid Iranian postal code?
 *
 * Ten digits, and the sum-weighted mod-11 remainder must be 2 or less. The
 * weighting (32, 26, 22, 18, 14, 10, 6, 2) is the one the national post uses.
 * A pure 10-digit length check accepts roughly one code in a hundred, which
 * means a returned parcel in about one parcel in a hundred.
 *
 * The digits are normalized first, for the reason given in
 * velora_postal_format(): without it a Persian-digit code is not "invalid",
 * it is *empty*, and an empty code skips the checksum instead of failing it.
 */
function velora_postal_valid(string $code): bool {
    $code = preg_replace('/\D/', '', normalize_digits($code)) ?? '';
    if (!preg_match('/^\d{10}$/', $code)) return false;
    $weights = [32, 26, 22, 18, 14, 10, 6, 2, 0, 0];
    $sum = 0;
    for ($i = 0; $i < 8; $i++) {
        $sum += ((int) $code[$i]) * $weights[$i];
    }
    return ($sum % 11) <= 2;
}

/** Latin digits to Persian ones, for the places a number is read rather than stored. */
function velora_fa_digits(string $s): string {
    /* The array form of strtr, not the two-string form: that one maps bytes to
       bytes, so handing it a 20-byte "to" string against 10 one-byte "from"
       bytes truncates the Persian half of the table and shreds the output. */
    static $map = [
        '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
    ];
    return strtr($s, $map);
}

/** Group the 10 digits for display: ۱۰۶۳۷-۴۸۴۹۳ reads faster than a bare run. */
function velora_postal_format(string $code): string {
    /* normalize_digits, not the plain \D strip. This function is also the one
       the server calls on a value that came from a form, and a form in this
       store displays Persian digits — so a code the customer typed in the very
       digits the UI showed came out of /[^\d]/ as the empty string rather than
       as itself. */
    $code = preg_replace('/\D/', '', normalize_digits($code)) ?? '';
    if (strlen($code) !== 10) return velora_fa_digits($code);
    return velora_fa_digits(substr($code, 0, 5) . '-' . substr($code, 5, 5));
}

/**
* One line a courier can read aloud, built from the parts.
*
* Composed rather than typed, so the order is always province → city → district
* → street → plaque, which is the order Iranian addresses are read in, and so
* a customer who filled in the two dropdowns cannot produce an unreadable blob.
*/
function velora_address_line(array $a): string {
    $parts = [];
    $province = trim((string) ($a['province'] ?? ''));
    $city     = trim((string) ($a['city'] ?? ''));
    if ($province !== '' && $city !== '' && $province !== $city) {
        $parts[] = $province . '، ' . $city;
    } elseif ($city !== '') {
        $parts[] = $city;
    } elseif ($province !== '') {
        $parts[] = $province;
    }
    foreach (['district', 'line'] as $k) {
        $v = trim((string) ($a[$k] ?? ''));
        if ($v !== '') $parts[] = $v;
    }
    $plaque = trim((string) ($a['plaque'] ?? ''));
    $unit   = trim((string) ($a['unit'] ?? ''));
    if ($plaque !== '' && $unit !== '')      $parts[] = 'پلاک ' . $plaque . '، واحد ' . $unit;
    elseif ($plaque !== '')                  $parts[] = 'پلاک ' . $plaque;
    elseif ($unit !== '')                    $parts[] = 'واحد ' . $unit;
    $postal = trim((string) ($a['postal_code'] ?? ''));
    /* Grouped here as well as in the display field, because this string is
       what gets written on the parcel. A courier reading 1234512345 off a
       label has to count the digits themselves; 12345-12345 can be read in
       two chunks. Same reason the client composes its preview the same way. */
    if ($postal !== '') $parts[] = 'کدپستی ' . velora_postal_format($postal);
    return implode('، ', array_filter($parts, static fn(string $x): bool => $x !== ''));
}
