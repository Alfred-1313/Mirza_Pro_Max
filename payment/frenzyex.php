<?php
// FrenzyEx's own signed payment.completed callback (POST, raw JSON body + an
// X-Frenzy-Signature header) - unlike payment/zarinpal.php, this is NOT a
// browser redirect: it is a server-to-server notification FrenzyEx can also
// resend, so the credit is claimed atomically before DirectPayment() runs -
// the same guard function.php's own sms_forward_claim_order() already uses
// for card-to-card, so a resent notification can never double-credit.
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../Marzban.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../panels.php';
require __DIR__ . '/../vendor/autoload.php';

$ManagePanel = new ManagePanel();
$setting = select("setting", "*");
$textbotlang = languagechange(null, 'fa');

// 1) verify the signature over the EXACT raw body - never a re-encoded copy
$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_FRENZY_SIGNATURE'] ?? '';
$secret = (string) (select("PaySetting", "ValuePay", "NamePay", "frenzyex_callback_secret", "select")['ValuePay'] ?? '');

if ($secret === '' || $secret === '0' || $signature === '' || !hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}

$data = json_decode($rawBody, true);
if (!is_array($data)) {
    http_response_code(400);
    exit('Invalid body');
}

// a connectivity test - acknowledged, never fulfils a real order
if (($data['event'] ?? '') === 'payment.test' || !empty($data['test'])) {
    http_response_code(200);
    exit('OK');
}

// 2) only a completed, paid event is ever acted on ('finished' is the
// guide's own documented compatibility alias for payment_status)
$status = (string) ($data['status'] ?? '');
$paymentStatus = (string) ($data['payment_status'] ?? '');
if (($data['event'] ?? '') !== 'payment.completed' || ($status !== 'paid' && $paymentStatus !== 'finished')) {
    http_response_code(200);
    exit('OK');
}

// 3) look up the pending row this request belongs to
$requestId = (string) ($data['request_id'] ?? '');
$Payment_report = $requestId !== '' ? select("Payment_report", "*", "dec_not_confirmed", $requestId, "select") : null;
if (!is_array($Payment_report) || empty($Payment_report)) {
    // unknown here (a different merchant's request, or never created by this
    // shop) - acknowledged so FrenzyEx stops resending, nothing fulfilled
    http_response_code(200);
    exit('OK');
}

// cross-check the confirmed amount against what was actually asked for
// (per the guide: never trust the callback amount blindly)
$priceAmount = $data['price_amount'] ?? null;
$priceCurrency = (string) ($data['price_currency'] ?? '');
if ($priceCurrency !== 'TMN' || $priceAmount === null || (float) $priceAmount != (float) $Payment_report['price']) {
    $errorreport = select("topicid", "idreport", "report", "errorreport", "select")['idreport'];
    if (strlen((string) ($setting['Channel_Report'] ?? '')) > 0) {
        telegram('sendmessage', [
            'chat_id' => $setting['Channel_Report'],
            'message_thread_id' => $errorreport,
            'text' => sprintf(
                "⚠️ FrenzyEx: مبلغ فاکتور با مبلغ ثبت‌شده جور درنیومد.\nrequest_id: %s\nمبلغ ثبت‌شده: %s\nمبلغ تایید‌شده: %s",
                $requestId,
                (string) $Payment_report['price'],
                (string) $priceAmount
            ),
            'parse_mode' => "HTML",
        ]);
    }
    http_response_code(409);
    exit('Amount mismatch');
}

$invoice_id = $Payment_report['id_order'];

// 4) claim before crediting - only the request that actually flips the row
// goes on to call DirectPayment(), exactly the same idempotency shape
// sms_forward_claim_order() already uses elsewhere in this codebase
$claim = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'paid' WHERE id_order = ? AND payment_Status = 'Unpaid'");
$claim->execute([$invoice_id]);
if ($claim->rowCount() !== 1) {
    // already credited (or never Unpaid) - this is exactly what makes a
    // resent notification safe
    http_response_code(200);
    exit('OK');
}

DirectPayment($invoice_id, "../images.jpg");

// optional cashback (FR-015), same shape as payment/zarinpal.php's own block
$pricecashback = select("PaySetting", "ValuePay", "NamePay", "chashbackfrenzyex", "select")['ValuePay'];
$Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
if ($pricecashback != "0") {
    $result = ($Payment_report['price'] * $pricecashback) / 100;
    // into the wallet the payment was made in
    wallet_credit($Balance_id['id'], $result, payment_currency($Payment_report));
    $text_report = sprintf($textbotlang['paymentGateway']['giftReport'], $result);
    sendmessage($Balance_id['id'], $text_report, null, 'HTML');
}

$paymentreports = select("topicid", "idreport", "report", "paymentreport", "select")['idreport'];
$price_fmt = number_format($Payment_report['price']);
$text_report = sprintf(
    $textbotlang['hardcoded']['frenzyexPaymentLog'],
    $Payment_report['id_user'],
    $Balance_id['username'] ?? '-',
    $price_fmt,
    $Payment_report['id_order'],
    $requestId
);
if (strlen((string) ($setting['Channel_Report'] ?? '')) > 0) {
    telegram('sendmessage', [
        'chat_id' => $setting['Channel_Report'],
        'message_thread_id' => $paymentreports,
        'text' => $text_report,
        'parse_mode' => "HTML",
    ]);
}

http_response_code(200);
echo 'OK';
