<?php
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../keyboard.php';
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../jdf.php';
$ManagePanel = new ManagePanel();

// Public endpoint: the phone's SMS-forwarder app (e.g. "SMS Forwarder" by
// Frzin Apps) hits this URL directly with a webhook POST/GET whenever a bank
// SMS arrives - no Telegram bot, no polling. The ?secret= query param IS the
// entire trust boundary here, so it's checked before anything else runs.
//
// Deliberately NOT gated on Bot_Status (unlike croncard.php): that switch is
// the customer-facing shutter - index.php already lets admins straight through
// it - and this whole path is admin-owned infrastructure (the admin's own
// phone, own bank account). Money that physically landed in the account should
// still be reconciled while the shop is closed to customers. To pause matching,
// turn SMS Forward itself off.
$setting = select("setting", "*");
if (($setting['smsForwardEnabled'] ?? '0') !== '1') {
    http_response_code(200);
    echo 'ok';
    return;
}
$configuredSecret = (string) ($setting['smsForwardSecret'] ?? '');
$suppliedSecret = (string) ($_GET['secret'] ?? $_POST['secret'] ?? '');
if ($configuredSecret === '' || !hash_equals($configuredSecret, $suppliedSecret)) {
    http_response_code(403);
    echo 'forbidden';
    return;
}

$rawBody = (string) file_get_contents('php://input');
$contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
$text = sms_forward_extract_text_from_request($rawBody, $contentType, $_POST, $_GET);
if (trim($text) === '') {
    http_response_code(200);
    echo 'ok';
    return;
}

$textbotlang = languagechange();
$paymentreports = select("topicid", "idreport", "report", "paymentreport", "select")['idreport'];
$listExceptions = select("PaySetting", "ValuePay", "NamePay", "Exception_auto_cart", "select")['ValuePay'];
$listExceptions = is_string($listExceptions) ? json_decode($listExceptions, true) : [];
if (!is_array($listExceptions)) {
    $listExceptions = [];
}

$amounts = sms_forward_extract_amounts($text);
foreach ($amounts as $rialAmount) {
    $matchResult = sms_forward_find_pending_match($rialAmount);
    if ($matchResult['status'] === 'matched') {
        $row = $matchResult['row'];
        if (in_array($row['id_user'], $listExceptions)) {
            continue;
        }
        // atomic claim - guards against croncard.php's own time-based
        // auto-confirm or a manual admin tap landing on this exact row at the
        // same instant, and against the forwarder app retrying the same SMS.
        if (!sms_forward_claim_order($row['id_order'])) {
            continue;
        }
        update("Payment_report", "dec_not_confirmed", $textbotlang['hardcoded']['autoConfirmedBySms'], "id_order", $row['id_order']);
        DirectPayment($row['id_order'], "../images.jpg");
        // the invoice ("فاکتور ایجاد شد...") is now stale - its card numbers,
        // countdown and "رسید را بفرستید" button are all meaningless once the
        // payment is confirmed, and leaving it up invites the user to pay a
        // second time. DirectPayment() has already sent its own confirmation
        // message, so the user is not left without feedback. Deliberately
        // AFTER DirectPayment: if crediting fails, the user keeps the invoice.
        // message_id is the column payment_expire.php already relies on.
        if (!empty($row['message_id'])) {
            deletemessage($row['id_user'], $row['message_id']);
        }
        // the user may have sent a receipt before the SMS landed - that receipt
        // is sitting in the admin's chat with live تایید/رد buttons on an
        // invoice that is already credited, so say so explicitly.
        payment_notify_admins_auto_confirmed($row, $textbotlang['hardcoded']['autoConfirmedBySms']);
        $priceCashback = select("PaySetting", "ValuePay", "NamePay", "chashbackcart", "select")['ValuePay'];
        $balanceRow = select("user", "*", "id", $row['id_user'], "select");
        if ($priceCashback != "0") {
            $cashbackAmount = ($row['price'] * $priceCashback) / 100;
            // into the wallet the payment was made in
            wallet_credit($balanceRow['id'], $cashbackAmount, payment_currency($row));
            $cashbackReport = sprintf($textbotlang['hardcoded']['giftDepositNotice'], $cashbackAmount);
            sendmessage($balanceRow['id'], $cashbackReport, null, 'HTML');
        }
        $reportPayment = sprintf($textbotlang['hardcoded']['newPaymentAutoConfirmSms'], $row['id_user'], number_format($row['price']), $row['Payment_Method']);
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $paymentreports,
                'text' => $reportPayment,
                'parse_mode' => "HTML",
            ]);
        }
        break; // this message is spoken for - stop scanning its other candidate numbers
    } elseif ($matchResult['status'] === 'ambiguous') {
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $paymentreports,
                'text' => sprintf($textbotlang['hardcoded']['smsForwardAmbiguousMatch'], number_format($rialAmount)),
                'parse_mode' => "HTML",
            ]);
        }
    }
}

http_response_code(200);
echo 'ok';
