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
$setting = select("setting", "*");
if ($setting['Bot_Status'] == "botstatusoff")
    return;
$paymentreports = select("topicid", "idreport", "report", "paymentreport", "select")['idreport'];
$stmt = $pdo->prepare("SELECT * FROM Payment_report WHERE payment_Status = 'waiting' AND (Payment_Method = 'cart to cart' OR Payment_Method = 'arze digital offline') AND bottype IS NULL");
$stmt->execute();
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($row['at_updated'] == null)
        continue;
    $Balance_id = select("user", "*", "id", $row['id_user'], "select");
    // autoconfirmcart/timeauto_not_verify are per-language overrides now (see
    // card_legacy_settings_payload() in admin.php) - each row is checked
    // against ITS OWN payer's language instead of one bot-wide switch, same
    // per-row lang lookup payment_expire.php already uses.
    $payer_lang = is_array($Balance_id) && !empty($Balance_id['lang']) ? $Balance_id['lang'] : 'fa';
    $autoconfirm = pay_value("autoconfirmcart", $payer_lang, 'offauto');
    if ($autoconfirm != "onauto")
        continue;
    // 0 minutes is the explicit "off" sentinel set by the admin screen's
    // ⏳ زمان تایید خودکار switch. Without this guard a 0 would mean
    // "$since_start > 0", i.e. confirm absolutely everything on the next run -
    // the exact opposite of off.
    $timeauto = intval(pay_value("timeauto_not_verify", $payer_lang, '0'));
    if ($timeauto <= 0)
        continue;
    $timecheck = $timeauto * 60;
    $since_start = time() - strtotime($row['at_updated']);
    if ($since_start >= 3600)
        continue;
    if ($since_start <= $timecheck)
        continue;
    $Payment_report = $row;
    $list_Exceptions = select("PaySetting", "ValuePay", "NamePay", "Exception_auto_cart", "select")['ValuePay'];
    $list_Exceptions = is_string($list_Exceptions) ? json_decode($list_Exceptions, true) : [];
    if (in_array($Balance_id['id'], $list_Exceptions))
        continue;
    $textbotlang = languagechange();
    if ($Payment_report['payment_Status'] == "paid") {
        continue;
    }
    update("Payment_report", "payment_Status", "paid", "id_order", $Payment_report['id_order']);
    update("Payment_report", "dec_not_confirmed", $textbotlang['hardcoded']['autoConfirmedByBot'], "id_order", $Payment_report['id_order']);
    DirectPayment($Payment_report['id_order'], "../images.jpg");
    // same reasoning as the SMS webhook and the manual-approval path: once the
    // payment is confirmed the invoice message is stale (card numbers, its
    // countdown, its "رسید را بفرستید" button) and inviting a second payment.
    // Harmless when the user already tapped that button, since index.php
    // deleted it then and Telegram ignores a repeat delete.
    if (!empty($Payment_report['message_id'])) {
        deletemessage($Payment_report['id_user'], $Payment_report['message_id']);
    }
    // same as the SMS webhook: the receipt the user already sent is still
    // sitting in the admin's chat with live تایید/رد buttons, on an invoice
    // this cron just credited - tell them so nobody acts on it.
    payment_notify_admins_auto_confirmed($Payment_report, $textbotlang['hardcoded']['autoConfirmedByBot']);
    $pricecashback = select("PaySetting", "ValuePay", "NamePay", "chashbackcart", "select")['ValuePay'];
    $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
    if ($pricecashback != "0") {
        $result = ($Payment_report['price'] * $pricecashback) / 100;
        $Balance_confrim = intval($Balance_id['Balance']) + $result;
        update("user", "Balance", $Balance_confrim, "id", $Balance_id['id']);
        $pricecashback = number_format($pricecashback);
        $text_report = sprintf($textbotlang['hardcoded']['giftDepositNotice'], $result);
        sendmessage($Balance_id['id'], $text_report, null, 'HTML');
    }
    $text_reportpayment = sprintf($textbotlang['hardcoded']['newPaymentAutoConfirm'], $Balance_id['id'], $Payment_report['price'], $Payment_report['Payment_Method']);
    if (strlen($setting['Channel_Report']) > 0) {
        telegram('sendmessage', [
            'chat_id' => $setting['Channel_Report'],
            'message_thread_id' => $paymentreports,
            'text' => $text_reportpayment,
            'parse_mode' => "HTML"
        ]);
    }
}