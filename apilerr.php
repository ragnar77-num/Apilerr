<?php
/* ============================================================
 *  CC CHECKER API — Single File
 *  Format : kart|ay|yil|cvv | Declined/Approved | Response
 *  Yıl    : 2 hane (28) veya 4 hane (2028) kabul → 2 haneye normalize
 *  Oran   : %15 Approved / %85 Declined
 *  Tutar  : Sadece maxipuan & bonuspuan (0.00 – 3560.00)
 *  Prefix : Sadece 4, 5, 6, 9 geçerli
 *  Platform: InfinityFree / PHP 7.4+
 * ============================================================ */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

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
// 2) ROUTES
// ------------------------------------------------------------
$ROUTES = [
    '/api/v1/maxipuan'   => 'maxipuan',
    '/api/v1/stripeauth' => 'stripeauth',
    '/api/v1/bonuspuan'  => 'bonuspuan',
    '/api/v1/1charge'    => '1charge',
];

// ------------------------------------------------------------
// 3) HELPERS
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

function parseCard(string $cc): array {
    $cc = urldecode(trim($cc));
    $parts = explode('|', $cc);
    if (count($parts) !== 4) {
        return ['ok' => false, 'reason' => 'Invalid format'];
    }

    [$card, $month, $year, $cvv] = array_map('trim', $parts);

    // 1) 16 hane rakam
    if (!preg_match('/^\d{16}$/', $card)) {
        return ['ok' => false, 'reason' => 'Invalid format'];
    }

    // 2) Sadece 4, 5, 6, 9 ile başlayanlar geçerli
    $first = $card[0];
    if (!in_array($first, ['4', '5', '6', '9'], true)) {
        return ['ok' => false, 'reason' => 'Invalid format'];
    }

    // 3) Ay 01-12
    if (!preg_match('/^(0[1-9]|1[0-2])$/', $month)) {
        return ['ok' => false, 'reason' => 'Invalid format'];
    }

    // 4) Yıl: 2 hane (28) veya 4 hane (2028) kabul → 2 haneye normalize
    if (preg_match('/^\d{4}$/', $year)) {
        $year = substr($year, -2); // 2028 → 28
    } elseif (!preg_match('/^\d{2}$/', $year)) {
        return ['ok' => false, 'reason' => 'Invalid format'];
    }

    // 5) CVV 3-4 hane
    if (!preg_match('/^\d{3,4}$/', $cvv)) {
        return ['ok' => false, 'reason' => 'Invalid format'];
    }

    // 6) Luhn
    if (!luhnCheck($card)) {
        return ['ok' => false, 'reason' => 'Invalid card number'];
    }

    return [
        'ok'    => true,
        'card'  => $card,
        'month' => $month,
        'year'  => $year, // her zaman 2 hane
        'cvv'   => $cvv,
        'raw'   => "$card|$month|$year|$cvv",
    ];
}

function cardHash(string $card, string $gateway): int {
    return abs(crc32($card . '|' . $gateway));
}

function isApproved(string $card, string $gateway): bool {
    return (cardHash($card, $gateway) % 100) < 15; // %15
}

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

    if ($r % 100 < 10) $val = 0.00;

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

// ------------------------------------------------------------
// 4) ROUTER
// ------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    jsonOut(405, ['durum' => '405', 'message' => 'Method Not Allowed']);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = rtrim($path, '/');
if ($path === '') $path = '/';

if (!isset($ROUTES[$path])) {
    jsonOut(404, ['durum' => '404', 'message' => 'Endpoint not found']);
}

$gateway = $ROUTES[$path];

$cc = $_GET['cc'] ?? $_POST['cc'] ?? '';
if ($cc === '') {
    jsonOut(400, ['durum' => '400', 'message' => 'Missing cc parameter']);
}

$check = parseCard($cc);
if (!$check['ok']) {
    jsonOut(400, ['durum' => '400', 'message' => $check['reason']]);
}

$result = buildResponse($gateway, $check, $GATEWAYS);
jsonOut((int)$result['durum'], $result);