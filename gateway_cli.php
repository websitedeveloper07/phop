<?php

// --- FULL DEBUG MODE ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// --- SET DEFAULT TIMEZONE ---
date_default_timezone_set('UTC');

// --- RECEIVE DATA FROM CLI OR GET/POST ---
$sk = $argv[1] ?? ($_GET['sk_key'] ?? null);
$token = $argv[2] ?? ($_GET['token'] ?? null); // token instead of raw card
$amount = $argv[3] ?? ($_GET['amount'] ?? 50); // optional amount in cents

if (!$sk || !$token) {
    die("Error: Missing Stripe key or token arguments.\n");
}

// --- RANDOM USER DATA ---
$ch_user = curl_init();
curl_setopt($ch_user, CURLOPT_URL, 'https://randomuser.me/api/1.2/?nat=us');
curl_setopt($ch_user, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch_user, CURLOPT_USERAGENT, 'Mozilla/5.0');
$get = curl_exec($ch_user);
curl_close($ch_user);

$name = 'John';
$last = 'Doe';
if ($get) {
    preg_match_all('/"first":"(.*?)"/i', $get, $matches1);
    preg_match_all('/"last":"(.*?)"/i', $get, $matches2);
    $name = $matches1[1][0] ?? 'John';
    $last = $matches2[1][0] ?? 'Doe';
}

// --- STRIPE API CALLS ---
$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($ch, CURLOPT_USERPWD, $sk . ':');

// --- CREATE CUSTOMER USING TOKEN ---
curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/customers');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'description' => "$name $last",
    'source' => $token
]));
$result2 = curl_exec($ch);
$cus = json_decode($result2, true);
$customer_id = $cus['id'] ?? null;

// --- CREATE CHARGE ---
$result3 = "{}";
$charge_id = null;
if ($customer_id) {
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/charges');
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'amount' => $amount,
        'currency' => 'usd',
        'customer' => $customer_id,
        'description' => 'Charge via token'
    ]));
    $result3 = curl_exec($ch);
    $charge_data = json_decode($result3, true);
    $charge_id = $charge_data['id'] ?? null;
}

// --- CREATE REFUND ---
$result4 = "{}";
if ($charge_id) {
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/refunds');
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'charge' => $charge_id,
        'amount' => $amount,
        'reason' => 'requested_by_customer'
    ]));
    $result4 = curl_exec($ch);
}

// --- FULL DEBUG OUTPUT ---
echo "<pre>";
echo "=== DEBUG OUTPUT ===\n";
echo "Token: $token\n";
echo "Stripe Customer (Result2): $result2\n";
echo "Stripe Charge (Result3): $result3\n";
echo "Stripe Refund (Result4): $result4\n";
echo "==================\n";
echo "</pre>";

// --- RESPONSE LOGIC ---
if (strpos($result3, '"status": "succeeded"') !== false) {
    echo "<span class='badge badge-success'>#Approved</span> ◈ <span class='badge badge-success'>$token</span> ◈ <span class='badge badge-info'> 「Approved (Charge & Refund) ✅」</span>";
} elseif (isset($cus['sources']['data'][0]['cvc_check']) && $cus['sources']['data'][0]['cvc_check'] === 'pass') {
    echo "<span class='badge badge-success'>#Approved</span> ◈ <span class='badge badge-success'>$token</span> ◈ <span class='badge badge-info'> 「Approved (CVV Pass) ✔️」</span>";
} elseif (isset($cus['sources']['data'][0]['cvc_check']) && $cus['sources']['data'][0]['cvc_check'] === 'fail') {
    echo "<span class='badge badge-success'>#Approved</span> ◈ <span class='badge badge-danger'>$token</span> ◈ <span class='badge badge-warning'> 「Token Live (Incorrect CVV) 🟡」</span>";
} elseif (strpos($result3, "insufficient_funds") !== false) {
    echo "<span class='badge badge-success'>#Approved</span> ◈ <span class='badge badge-success'>$token</span> ◈ <span class='badge badge-warning'> 「Token Live (Insufficient Funds) 💰」</span>";
} else {
    echo "<span class='badge badge-danger'>#Declined</span> ◈ <span class='badge badge-danger'>$token</span> ◈ <span class='badge badge-warning'> 「An Unknown Error Occurred ❓」</span>";
}

curl_close($ch);
?>
