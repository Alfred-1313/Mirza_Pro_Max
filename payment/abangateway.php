<?php
// AbanGateway (from the original bot): the customer's browser comes back here
// with order_id and authority; the payment is verified with AbanGateway itself
// (for THIS order, at least this amount) before it is claimed and credited.
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

$order_id = trim((string) ($_GET['order_id'] ?? $_POST['order_id'] ?? ''));
$authority = trim((string) ($_GET['authority'] ?? $_POST['authority'] ?? ''));
$Payment_report = $order_id !== '' ? select("Payment_report", "*", "id_order", $order_id, "select") : false;
if (!is_array($Payment_report) || $Payment_report['Payment_Method'] !== 'AbanGateway') {
    payment_result_page('notfound', lang_tab_texts('fa'), $order_id);
    return;
}
$lang = (string) (select("user", "lang", "id", $Payment_report['id_user'], "select")['lang'] ?? 'fa') ?: 'fa';
$textbotlang = payer_texts($Payment_report['id_user']);
$price = $Payment_report['price'];
if ($Payment_report['payment_Status'] === 'paid') {
    payment_result_page('already', $textbotlang, $order_id, $price, $lang);
    return;
}
$key = trim((string) getPaySettingValue('abangateway_api_key', ''));
$endpoint = abangateway_endpoint();
if ($key === '' || $key === '0' || $endpoint === null) {
    payment_result_page('failed', $textbotlang, $order_id, $price, $lang);
    return;
}
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $endpoint . '/verify',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $key],
    CURLOPT_POSTFIELDS => json_encode(['order_id' => $order_id, 'authority' => $authority, 'amount' => (int) round((float) $price)], JSON_UNESCAPED_UNICODE),
]);
$result = curl_exec($curl);
$httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
$response = json_decode((string) $result, true);
$accepted = $httpCode === 200 && is_array($response) && !empty($response['success'])
    && isset($response['order_id'], $response['amount'])
    && (string) $response['order_id'] === $order_id && (float) $response['amount'] >= (float) $price;
if (!$accepted) {
    payment_result_page('failed', $textbotlang, $order_id, $price, $lang);
    return;
}
// a reload, or a second request at the same moment, only ever sees «already»
if (!payment_claim($order_id)) {
    payment_result_page('already', $textbotlang, $order_id, $price, $lang);
    return;
}
$setting = select("setting", "*");
DirectPayment($order_id, "../images.jpg");
$pdo->prepare("UPDATE Payment_report SET dec_not_confirmed = ? WHERE id_order = ?")->execute([json_encode($response, JSON_UNESCAPED_UNICODE), $order_id]);
$Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
$cashback = (float) getPaySettingValue('chashbackabangateway', '0');
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
        'text' => sprintf($textbotlang['paymentGateway']['reportAbanGateway'], $Balance_id['username'] ?? '-', $Balance_id['id'], number_format($price)),
        'parse_mode' => "HTML",
    ]);
}
payment_result_page('success', $textbotlang, $order_id, $price, $lang);
