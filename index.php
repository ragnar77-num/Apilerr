<?php
/* ============================================================
 *  CC CHECKER API — Single File (No .htaccess) — v2.0
 *  URL    : /?maxipuan?cc=...
 *           /?stripeauth?cc=...
 *           /?bonuspuan?cc=...
 *           /?1charge?cc=...
 *           /?random?cc=...      (rastgele gateway)
 *           /?list                 (gateway listesi)
 *           /?health               (sağlık kontrolü)
 *  Format : kart|ay|yil|cvv | Declined/Approved | Response
 *  Yıl    : 2 hane (28) VEYA 4 hane (2028) kabul → 2 haneye normalize
 *  Oran   : %15 Approved / %85 Declined
 *  Tutar  : Sadece maxipuan & bonuspuan (0.00 – 3560.00)
 *  Prefix : Sadece 4, 5, 6, 9 geçerli
 *  Luhn   : Zorunlu doğrulama
 *  Expiry : Geçmiş tarih kontrolü
 * ============================================================ */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// ------------------------------------------------------------
// 1) GATEWAY CONFIG
// ------------------------------------------------------------
$GATEWAYS = [
    'maxipuan' => [
        'name'       => 'MaxiPuan',
        'approved'   => 'Puan: {AMOUNT} Tl',
        'declined'   => 'Yetersiz bakiye.',
        'has_amount' => true,
        'amount_min' => 0.00,
        'amount_max' => 3560.00,
    ],
    'stripeauth' => [
        'name'       => 'Stripe Auth',
        'approved'   => 'Your card was Authed!',
        'declined'   => 'Your card was declined.',
        'has_amount' => false,
    ],
    'bonuspuan' => [
        'name'       => 'Bonus Puan',
        'approved'   => 'Bonus Puan: {AMOUNT} Tl',
        'declined'   => 'Bonus puan kullanilamaz.',
        'has_amount' => true,
        'amount_min' => 0.00,
        'amount_max' => 3560.00,
    ],
    '1charge' => [
        'name'       => '1$ Charge',
        'approved'   => 'You have $1 on your card.',
        'declined'   => 'Charge declined by issuer.',
        'has_amount' => false,
    ],
];

// ------------------------------------------------------------
// 2) HELPERS
// ------------------------------------------------------------

function jsonOut(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Luhn algoritması ile kart numarası doğrulaması.
 */
function luhnCheck(string $number): bool {
    $number = preg_replace('/\D/', '', $number);
    $len = strlen($number);
    if ($len < 12 || $len > 19) return false;

    $sum = 0;
    $alt = false;
    for ($i = $len - 1; $i >= 0; $i--) {
        $n = (int)$number[$i];
        if ($alt) {
            $n *= 2;
            if ($n > 9) $n -= 9;
        }
        $sum += $n;
        $alt = !$alt;
    }
    return ($sum % 10) === 0;
}

/**
 * Yıl formatını normalize eder.
 * Kabul: "28" veya "2028" veya "8" (tek hane, 2008 kabul).
 * Çıktı: 2 haneli string ("28", "08" gibi).
 */
function normalizeYear(string $year): ?string {
    $year = trim($year);

    // 4 haneli yıl (2020-2099 arası mantıklı)
    if (preg_match('/^\d{4}$/', $year)) {
        $y4 = (int)$year;
        if ($y4 < 2000 || $y4 > 2099) {
            return null;
        }
        return substr($year, -2);
    }

    // 2 haneli yıl
    if (preg_match('/^\d{2}$/', $year)) {
        return $year;
    }

    // Tek haneli yıl (8 → 08)
    if (preg_match('/^\d$/', $year)) {
        return '0' . $year;
    }

    return null;
}

/**
 * Kartın son kullanma tarihinin geçmişte olup olmadığını kontrol eder.
 * Yıl 2 haneli (28 gibi), ay 2 haneli (01-12).
 */
function isExpired(string $month, string $year2): bool {
    $m = (int)$month;
    $y = 2000 + (int)$year2;

    $currentYear  = (int)date('Y');
    $currentMonth = (int)date('n');

    if ($y < $currentYear) return true;
    if ($y === $currentYear && $m < $currentMonth) return true;

    return false;
}

/**
 * Kart numarasından ilk 6 haneyi (BIN) döner.
 */
function getBin(string $card): string {
    return substr($card, 0, 6);
}

/**
 * Kart markasını tespit eder.
 */
function detectBrand(string $card): string {
    $first = $card[0];
    $firstTwo = (int)substr($card, 0, 2);
    $firstFour = (int)substr($card, 0, 4);

    if ($first === '4') return 'Visa';
    if ($firstTwo >= 51 && $firstTwo <= 55) return 'MasterCard';
    if ($firstTwo === 34 || $firstTwo === 37) return 'Amex';
    if ($firstTwo === 65) return 'Discover';
    if ($firstFour >= 2221 && $firstFour <= 2720) return 'MasterCard';
    if ($first === '9') return 'Domestic/Other';
    if ($first === '6') return 'Discover/Other';
    if ($first === '5') return 'MasterCard';

    return 'Unknown';
}

/**
 * Kart verisini parse eder; tüm doğrulamaları yapar.
 */
function parseCard(string $cc): array {
    $cc = urldecode(trim($cc));

    // Boşluk temizleme: "kart | ay | yil | cvv" gibi boşluklu girişleri düzelt
    $cc = preg_replace('/\s+/', '', $cc);

    $parts = explode('|', $cc);
    if (count($parts) !== 4) {
        return ['ok' => false, 'reason' => 'Invalid format'];
    }

    [$card, $month, $year, $cvv] = array_map('trim', $parts);

    // Kart numarası: 16 hane zorunlu
    if (!preg_match('/^\d{16}$/', $card)) {
        return ['ok' => false, 'reason' => 'Invalid format'];
    }

    // Prefix kontrolü: 4, 5, 6, 9
    $first = $card[0];
    if (!in_array($first, ['4', '5', '6', '9'], true)) {
        return ['ok' => false, 'reason' => 'Invalid format'];
    }

    // Ay: 01-12
    if (!preg_match('/^(0[1-9]|1[0-2])$/', $month)) {
        return ['ok' => false, 'reason' => 'Invalid format'];
    }

    // Yıl: 28, 2028, 8 → hepsi normalize edilir
    $yearNorm = normalizeYear($year);
    if ($yearNorm === null) {
        return ['ok' => false, 'reason' => 'Invalid format'];
    }

    // CVV: 3 veya 4 hane
    if (!preg_match('/^\d{3,4}$/', $cvv)) {
        return ['ok' => false, 'reason' => 'Invalid format'];
    }

    // Luhn doğrulaması
    if (!luhnCheck($card)) {
        return ['ok' => false, 'reason' => 'Invalid card number (Luhn)'];
    }

    // Son kullanma tarihi geçmiş mi?
    if (isExpired($month, $yearNorm)) {
        return ['ok' => false, 'reason' => 'Card expired'];
    }

    return [
        'ok'    => true,
        'card'  => $card,
        'month' => $month,
        'year'  => $yearNorm,
        'cvv'   => $cvv,
        'bin'   => getBin($card),
        'brand' => detectBrand($card),
        'raw'   => "$card|$month|$yearNorm|$cvv",
    ];
}

/**
 * Kart + gateway için deterministik hash döner.
 */
function cardHash(string $card, string $gateway): int {
    return abs(crc32($card . '|' . $gateway));
}

/**
 * %15 onay oranı: deterministik.
 */
function isApproved(string $card, string $gateway): bool {
    return (cardHash($card, $gateway) % 100) < 15;
}

/**
 * Tutar üretir (0.00 – 3560.00) deterministik.
 */
function generateAmount(string $card, string $gateway, float $min, float $max): string {
    $h = cardHash($card, $gateway . '_amt');
    $r = $h % 10000;

    if ($r < 3000) {
        $val = $min + ($r / 3000) * 50.00;
    } elseif ($r < 6000) {
        $val = 50.00 + (($r - 3000) / 3000) * 450.00;
    } elseif ($r < 8500) {
        $val = 500.00 + (($r - 6000) / 2500) * 1000.00;
    } else {
        $val = 1500.00 + (($r - 8500) / 1500) * 2060.00;
    }

    if ($val > $max) $val = $max;
    if ($val < $min) $val = $min;

    // %10 ihtimalle 0.00
    if ($r % 100 < 10) $val = 0.00;

    return number_format($val, 2, '.', '');
}

/**
 * Gateway + kart verisinden yanıt oluşturur.
 */
function buildResponse(string $gateway, array $check, array $GATEWAYS): array {
    $gw = $GATEWAYS[$gateway] ?? null;
    if (!$gw) return ['durum' => '400', 'message' => 'Unknown gateway'];

    $card     = $check['card'];
    $raw      = $check['raw'];
    $approved = isApproved($card, $gateway);

    if ($approved) {
        if (!empty($gw['has_amount'])) {
            $amount = generateAmount($card, $gateway, $gw['amount_min'], $gw['amount_max']);
            $resp   = str_replace('{AMOUNT}', $amount, $gw['approved']);
        } else {
            $resp   = $gw['approved'];
        }
        $status = 'Approved';
        $code   = '200';
    } else {
        $resp   = $gw['declined'];
        $status = 'Declined';
        $code   = '404';
    }

    return [
        'durum'   => $code,
        'message' => $raw . ' | ' . $status . ' | ' . $resp,
    ];
}

/**
 * URL'den gateway + cc çıkar.
 *
 * Kabul edilen formatlar:
 *   /?maxipuan?cc=KART|AY|YIL|CVV
 *   /?stripeauth?cc=...
 *   /?bonuspuan?cc=...
 *   /?1charge?cc=...
 *   /?random?cc=...
 *   /?gateway=maxipuan&cc=...   (klasik)
 *   POST: gateway=maxipuan&cc=...
 */
function parseRequest(array $GATEWAYS): array {
    $uri    = $_SERVER['REQUEST_URI'] ?? '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    $postGateway = $_POST['gateway'] ?? null;
    $postCc      = $_POST['cc'] ?? null;

    $query = parse_url($uri, PHP_URL_QUERY) ?? '';

    $gateway = null;
    $cc      = null;

    if ($query !== '') {
        // "maxipuan?cc=..." formatı
        if (strpos($query, '?cc=') !== false) {
            [$gwPart, $ccPart] = explode('?cc=', $query, 2);
            $gateway = trim($gwPart);
            $cc      = urldecode($ccPart);
        }
        // "gateway=maxipuan&cc=..." formatı
        elseif (strpos($query, '=') !== false) {
            parse_str($query, $q);
            $gateway = $q['gateway'] ?? null;
            $cc      = $q['cc'] ?? null;
        }
        // Sadece gateway adı: "?maxipuan"
        else {
            $gateway = trim($query);
            $cc      = $_GET['cc'] ?? null;
        }
    }

    // POST override
    if ($postGateway !== null) $gateway = $postGateway;
    if ($postCc !== null)      $cc      = $postCc;

    // Fallback: normal $_GET
    if ($gateway === null) $gateway = $_GET['gateway'] ?? null;
    if ($cc === null)      $cc      = $_GET['cc'] ?? null;

    $gateway = $gateway !== null ? strtolower(trim($gateway)) : null;

    return [$gateway, $cc, $method];
}

/**
 * Rastgele gateway seçer (random endpoint için).
 */
function randomGateway(array $GATEWAYS): string {
    $keys = array_keys($GATEWAYS);
    return $keys[array_rand($keys)];
}

// ------------------------------------------------------------
// 3) ROUTER
// ------------------------------------------------------------
[$gateway, $cc, $method] = parseRequest($GATEWAYS);

if (!in_array($method, ['GET', 'POST'], true)) {
    jsonOut(405, ['durum' => '405', 'message' => 'Method Not Allowed']);
}

// Özel endpoint: /?list
if ($gateway === 'list') {
    $list = [];
    foreach ($GATEWAYS as $key => $gw) {
        $list[] = [
            'key'        => $key,
            'name'       => $gw['name'],
            'has_amount' => $gw['has_amount'],
        ];
    }
    jsonOut(200, ['durum' => '200', 'gateways' => $list]);
}

// Özel endpoint: /?health
if ($gateway === 'health') {
    jsonOut(200, [
        'durum'   => '200',
        'status'  => 'ok',
        'time'    => date('c'),
        'version' => '2.0',
    ]);
}

if ($gateway === null || (!isset($GATEWAYS[$gateway]) && $gateway !== 'random')) {
    jsonOut(404, ['durum' => '404', 'message' => 'Endpoint not found']);
}

if ($cc === null || $cc === '') {
    jsonOut(400, ['durum' => '400', 'message' => 'Missing cc parameter']);
}

// Rastgele gateway seçimi
if ($gateway === 'random') {
    $gateway = randomGateway($GATEWAYS);
}

$check = parseCard($cc);
if (!$check['ok']) {
    jsonOut(400, ['durum' => '400', 'message' => $check['reason']]);
}

$result = buildResponse($gateway, $check, $GATEWAYS);
jsonOut((int)$result['durum'], $result);