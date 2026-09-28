<?php
// CubePay (from the original bot): CubePay calls this when a payment is paid -
// either signed (order_id|status|amount, HMAC-SHA256 with the API token) or
// with an authority this file verifies with CubePay itself. A browser landing
// here gets the result page; CubePay's own server gets {"status": true|false}.
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../Marzban.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../panels.php';
require __DIR__ . '/../vendor/autoload.php';

$ManagePanel = new ManagePanel();

$wantsPage = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && strpos(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'text/html') !== false;
$answer = function ($state, $texts = null, $orderId = null, $price = null, $lang = 'fa') use ($wantsPage) {
    if ($wantsPage) {
        payment_result_page($state, $texts ?? lang_tab_texts('fa'), $orderId, $price, $lang);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['status' => in_array($state, ['success', 'already'], true)]);
    }
};

$raw = file_get_contents('php://input');
$json = json_decode((string) $raw, true);
$json = is_array($json) ? $json : [];
$order_id = (string) ($json['order_id'] ?? ($_REQUEST['order_id'] ?? ''));
$sig = (string) ($json['sig'] ?? ($_REQUEST['sig'] ?? ''));
$status = (string) ($json['status'] ?? ($_REQUEST['status'] ?? ''));
$amount = (string) ($json['amount'] ?? $json['amount_toman'] ?? ($_REQUEST['amount'] ?? ($_REQUEST['amount_toman'] ?? '')));
$authority = (string) ($json['authority'] ?? ($_REQUEST['authority'] ?? ''));

$Payment_report = $order_id !== '' ? select("Payment_report", "*", "id_order", $order_id, "select") : false;
$token = trim((string) getPaySettingValue('cubepay_api_token', ''));
if (!is_array($Payment_report) || $Payment_report['Payment_Method'] !== 'cubepay' || $token === '' || $token === '0') {
    $answer('notfound', null, $order_id);
    return;
}
$lang = (string) (select("user", "lang", "id", $Payment_report['id_user'], "select")['lang'] ?? 'fa') ?: 'fa';
$textbotlang = payer_texts($Payment_report['id_user']);
$price = $Payment_report['price'];
if ($Payment_report['payment_Status'] === 'paid') {
    $answer('already', $textbotlang, $order_id, $price, $lang);
    return;
}

$accepted = false;
$response = null;
if ($sig !== '') {
    // signed by CubePay: nothing in it is trusted before the signature is
    $valid = hash_equals(hash_hmac('sha256', $order_id . '|' . $status . '|' . $amount, $token), $sig);
    if (!$valid) {
        error_log("CubePay: invalid callback signature for order {$order_id}");
    }
    $accepted = $valid && $status === 'paid' && (float) $amount >= (float) $price;
    $response = ['order_id' => $order_id, 'status' => $status, 'amount_toman' => $amount, 'verified_by' => 'signature'];
} elseif ($authority !== '') {
    $ch = curl_init('https://cubevps.ir/smspay/api/verify-payment.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => json_encode(['authority' => $authority]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
    ]);
    $result = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $response = json_decode((string) $result, true);
    // its answer has to be about THIS order, for at least this amount (in rial)
    $forThisOrder = is_array($response) && isset($response['order_id'], $response['amount'])
        && (string) $response['order_id'] === $order_id && (float) $response['amount'] >= (float) $price * 10;
    $accepted = (($httpCode === 200 && !empty($response['success'])) || $httpCode === 409) && $forThisOrder;
}
if (!$accepted) {
    $answer('failed', $textbotlang, $order_id, $price, $lang);
    return;
}
$answer('success', $textbotlang, $order_id, $price, $lang);
// the one request that marks it paid credits it - a second callback, or the
// browser landing at the same moment, stops here
if (!payment_claim($order_id)) {
    return;
}
$setting = select("setting", "*");
DirectPayment($order_id, "../images.jpg");
$pdo->prepare("UPDATE Payment_report SET dec_not_confirmed = ? WHERE id_order = ?")->execute([json_encode($response, JSON_UNESCAPED_UNICODE), $order_id]);
$Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
$cashback = (float) getPaySettingValue('chashbackcubepay', '0');
if ($cashback > 0) {
    $reward = round($price * $cashback / 100, 2);
    // into the wallet the payment was made in
    wallet_credit($Balance_id['id'], $reward, payment_currency($Payment_report));
    sendmessage($Balance_id['id'], sprintf($textbotlang['paymentGateway']['giftReport'], $reward), null, 'HTML');
}
if (strlen((string) ($setting['Channel_Report'] ?? '')) > 0) {
    telegram('sendmessage', [
        'chat_id' => $setting['Channel_Report'],
        'message_thread_id' => select("topicid", "idreport", "report", "paymentreport", "select")['idreport'],
        'text' => sprintf($textbotlang['paymentGateway']['reportCubePay'], $Balance_id['username'] ?? '-', $Balance_id['id'], number_format($price)),
        'parse_mode' => "HTML",
    ]);
}
