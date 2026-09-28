<?php
// Variza's push (from the original bot): HMAC-SHA256 of the raw body with the
// webhook secret, in X-Webhook-Signature. Nothing in the body is trusted before
// the signature is; then the slug must be an order of ours for at least the
// amount billed, and the order is claimed before it is credited - Variza
// resends up to 5 times.
// Set in Variza's panel: profile → webhook → https://{domain}/payment/variza_webhook.php
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
$respond = function ($code, $msg) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => $msg]);
};

$raw = file_get_contents('php://input');
$secret = trim((string) getPaySettingValue('variza_webhook_secret', ''));
if ($secret === '' || $secret === '0') {
    error_log('variza webhook: the webhook secret is not set');
    $respond(500, 'not configured');
    return;
}
$sig = (string) ($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '');
if (strpos($sig, 'sha256=') === 0) {
    $sig = substr($sig, 7);
}
if ($raw === false || $raw === '' || $sig === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), $sig)) {
    error_log('variza webhook: signature mismatch');
    $respond(400, 'invalid signature');
    return;
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    $respond(400, 'invalid json');
    return;
}
if (($data['event'] ?? '') !== 'payment.paid') {
    $respond(200, 'ignored');
    return;
}
$slug = (string) ($data['slug'] ?? '');
$Payment_report = $slug !== '' ? select("Payment_report", "*", "dec_not_confirmed", $slug, "select") : false;
if (!is_array($Payment_report) || $Payment_report['Payment_Method'] !== 'variza') {
    error_log("variza webhook: unknown slug {$slug}");
    $respond(404, 'order not found');
    return;
}
if ($Payment_report['payment_Status'] === 'paid') {
    $respond(200, 'already paid');
    return;
}
$price = $Payment_report['price'];
if ((float) ($data['amount'] ?? 0) < (float) $price) {
    error_log("variza webhook: amount for {$slug}: billed {$price}, paid " . ($data['amount'] ?? 0));
    $respond(400, 'amount mismatch');
    return;
}
$order_id = $Payment_report['id_order'];
if (!payment_claim($order_id)) {
    $respond(200, 'already claimed');
    return;
}
$textbotlang = payer_texts($Payment_report['id_user']);
$setting = select("setting", "*");
DirectPayment($order_id, "../images.jpg");
$Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
$cashback = (float) getPaySettingValue('chashbackvariza', '0');
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
        'text' => sprintf($textbotlang['paymentGateway']['reportVariza'], $Balance_id['username'] ?? '-', $Balance_id['id'], number_format($price), $order_id, htmlspecialchars($slug)),
        'parse_mode' => "HTML",
    ]);
}
$respond(200, 'ok');
