<?php
/* ============================================================
 *  CC CHECKER API — Single File — v2.3
 *  - Prefix: 0,1,2,3,7,8 → Invalid format
 *            4,5,6,9     → Normal işlem
 *  - Yıl: 28 veya 2028 kabul
 *  - Tutar dağılımı:
 *      %70 → 0.00  - 100.00
 *      %25 → 100.00 - 1000.00
 *      %5  → 1000.00 - 3560.00
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

function normalizeYear(string $year): ?string {
    $year = trim($year);
    if (preg_match('/^\d{4}$/', $year)) {
        $y4 = (int)$year;
        if ($y4 < 2000 || $y4 > 2099) return null;
        return substr($year, -2);
    }
    if (preg_match('/^\d{2}$/', $year)) return $year;
    if (preg_match('/^\d$/', $year)) return '0' . $year;
    return null;
}

function isExpired(string $month, string $year2): bool {
    $m = (int)$month;
    $y = 2000 + (int)$year2;
    $cY = (int)date('Y');
    $cM = (int)date('n');
    if ($y < $cY) return true;
    if ($y === $cY && $m < $cM) return true;
    return false;
}

function getBin(string $card): string {
    return substr($card, 0, 6);
}

function detectBrand(string $card): string {
    $first = $card[0];
    $firstTwo = (int)substr($card, 0, 2);
    $firstFour = (int)substr($card, 0, 4);

    if ($first === '4') return 'Visa';
    if ($firstTwo >= 51 && $firstTwo <= 55) return 'MasterCard';
    if ($firstTwo === 34 || $firstTwo === 37) return 'Amex';
    if ($firstTwo === 65) return 'Discover';
    if ($firstFour >= 2221 && $firstFour <= 2720) return 'MasterCard';
    return 'Unknown';
}

function isValidPrefix(string $card): bool {
    return in_array($card[0], ['4', '5', '6', '9'], true);
}

function parseCard(string $cc): array {
    $cc = urldecode(trim($cc));
    $cc = preg_replace('/\s+/', '', $cc);

    $parts = explode('|', $cc);
    if (count($parts) !== 4) {
        return ['ok' => false, 'reason' => 'Invalid format'];
    }

    [$card, $month, $year, $cvv] = array_map('trim', $parts);

    if (!preg_match('/^\d{16}$/', $card)) {
        return ['ok' => false, 'reason' => 'Invalid format'];
    }

    // === PREFIX KURALI ===
    if (!isValidPrefix($card)) {
        return ['ok' => false, 'reason' => 'Invalid format'];
    }

    // Ay
    if (!preg_match('/^(0[1-9]|1[0-2])$/', $month)) {
        return ['ok' => false, 'reason' => 'Invalid format'];
    }

    // Yıl
    $yearNorm = normalizeYear($year);
    if ($yearNorm === null) {
        return ['ok' => false, 'reason' => 'Invalid format'];
    }

    // CVV
    if (!preg_match('/^\d{3,4}$/', $cvv)) {
        return ['ok' => false, 'reason' => 'Invalid format'];
    }

    // Luhn
    if (!luhnCheck($card)) {
        return ['ok' => false, 'reason' => 'Invalid card number (Luhn)'];
    }

    // Expiry
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

function cardHash(string $card, string $gateway): int {
    return abs(crc32($card . '|' . $gateway));
}

function isApproved(string $card, string $gateway): bool {
    return (cardHash($card, $gateway) % 100) < 15; // %15
}

/**
 * Tutar dağılımı:
 *   %70 → 0.00  - 100.00
 *   %25 → 100.00 - 1000.00
 *   %5  → 1000.00 - 3560.00
 */
function generateAmount(string $card, string $gateway, float $min, float $max): string {
    $h = cardHash($card, $gateway . '_amt') % 10000; // 0-9999
    $roll = $h % 100; // 0-99 → yüzde dilimi

    if ($roll < 70) {
        // %70 → 0.00 - 100.00
        $rangeMin = 0.00;
        $rangeMax = 100.00;
    } elseif ($roll < 95) {
        // %25 → 100.00 - 1000.00
        $rangeMin = 100.00;
        $rangeMax = 1000.00;
    } else {
        // %5 → 1000.00 - 3560.00
        $rangeMin = 1000.00;
        $rangeMax = 3560.00;
    }

    // Aralık içi rastgele değer (deterministik)
    $subRoll = ($h * 7919) % 10000; // 0-9999
    $val = $rangeMin + (($rangeMax - $rangeMin) * $subRoll / 9999);

    // Clamp
    if ($val > $max) $val = $max;
    if ($val < $min) $val = $min;

    return number_format($val, 2, '.', '');
}

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

function parseRequest(array $GATEWAYS): array {
    $uri    = $_SERVER['REQUEST_URI'] ?? '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    $postGateway = $_POST['gateway'] ?? null;
    $postCc      = $_POST['cc'] ?? null;

    $query = parse_url($uri, PHP_URL_QUERY) ?? '';
    $gateway = null;
    $cc      = null;

    if ($query !== '') {
        if (strpos($query, '?cc=') !== false) {
            [$gwPart, $ccPart] = explode('?cc=', $query, 2);
            $gateway = trim($gwPart);
            $cc      = urldecode($ccPart);
        } elseif (strpos($query, '=') !== false) {
            parse_str($query, $q);
            $gateway = $q['gateway'] ?? null;
            $cc      = $q['cc'] ?? null;
        } else {
            $gateway = trim($query);
            $cc      = $_GET['cc'] ?? null;
        }
    }

    if ($postGateway !== null) $gateway = $postGateway;
    if ($postCc !== null)      $cc      = $postCc;

    if ($gateway === null) $gateway = $_GET['gateway'] ?? null;
    if ($cc === null)      $cc      = $_GET['cc'] ?? null;

    $gateway = $gateway !== null ? strtolower(trim($gateway)) : null;

    return [$gateway, $cc, $method];
}

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

if ($gateway === 'list') {
    $list = [];
    foreach ($GATEWAYS as $key => $gw) {
        $list[] = ['key' => $key, 'name' => $gw['name'], 'has_amount' => $gw['has_amount']];
    }
    jsonOut(200, ['durum' => '200', 'gateways' => $list]);
}

if ($gateway === 'health') {
    jsonOut(200, ['durum' => '200', 'status' => 'ok', 'time' => date('c'), 'version' => '2.3']);
}

if ($gateway === null || (!isset($GATEWAYS[$gateway]) && $gateway !== 'random')) {
    jsonOut(404, ['durum' => '404', 'message' => 'Endpoint not found']);
}

if ($cc === null || $cc === '') {
    jsonOut(400, ['durum' => '400', 'message' => 'Missing cc parameter']);
}

if ($gateway === 'random') {
    $gateway = randomGateway($GATEWAYS);
}

$check = parseCard($cc);
if (!$check['ok']) {
    jsonOut(400, ['durum' => '400', 'message' => $check['reason']]);
}

$result = buildResponse($gateway, $check, $GATEWAYS);
jsonOut((int)$result['durum'], $result);