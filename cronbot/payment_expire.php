<?php
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
require __DIR__ . '/../vendor/autoload.php';
$ManagePanel = new ManagePanel();
$setting = select("setting", "*");
$textbotlang = languagechange();
// every payment method except card-to-card shares this fixed 30-minute
// cutoff; card-to-card's is admin-configurable per language (default 30,
// see gw_field_registry()'s 'cardInvoiceExpireMinutes' field), read per-row
// below since two card invoices can carry two different cutoffs. Filtering
// happens in PHP rather than the WHERE clause because of that per-row cutoff.
$default_expire_minutes = 30;
$stmt = $pdo->prepare("SELECT * FROM Payment_report WHERE payment_Status = 'Unpaid'");
$stmt->execute();

while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $expire_minutes = $default_expire_minutes;
    if ($result['Payment_Method'] === 'cart to cart') {
        $payer = select('user', 'lang', 'id', $result['id_user'], 'select');
        $payer_lang = is_array($payer) && !empty($payer['lang']) ? $payer['lang'] : 'fa';
        $expire_minutes = (int) pay_value('cardInvoiceExpireMinutes', $payer_lang, $default_expire_minutes);
        if ($expire_minutes < 1) {
            $expire_minutes = $default_expire_minutes;
        }
    }
    $row_ts = strtotime((string) $result['time']);
    if ($row_ts === false || $row_ts >= time() - ($expire_minutes * 60)) {
        continue; // not expired yet under this row's own cutoff
    }
    $status_var = [
        'cart to cart' =>  $textbotlang['textbot']['cartToCart'],
        'aqayepardakht' => $textbotlang['textbot']['aqayePardakht'],
        'zarinpal' => $textbotlang['textbot']['zarinPal'],
        'plisio' => $textbotlang['textbot']['nowPayment'],
        'arze digital offline' => $textbotlang['textbot']['nowPaymentTron'],
        'Currency Rial 1' => $textbotlang['textbot']['iranPay2'],
        'Currency Rial 2' => $textbotlang['textbot']['iranPay3'],
        'Currency Rial 3' => $textbotlang['textbot']['iranPay1'],
        'Currency Rial tow' => $textbotlang['hardcoded']['gatewayRialName1'],
        'Currency Rial gateway3' => $textbotlang['hardcoded']['gatewayRialName2'],
        'perfect' => $textbotlang['hardcoded']['gatewayPerfectMoney'],
        'paymentnotverify' => $textbotlang['textbot']['paymentNotVerify'],
        'Star Telegram' => $textbotlang['textbot']['starTelegram'],
        'nowpayment' => $textbotlang['textbot']['cryptoPayment']
        
    ][$result['Payment_Method']];
    $textexpire = sprintf($textbotlang['hardcoded']['invoiceExpiredNotice'], $status_var, $result['id_order'], $result['price']);
// sendmessage($result['id_user'], $textexpire, null, 'html');
if ($result['Payment_Method'] === 'cart to cart') {
    // edit the invoice message in place instead of deleting it: shows an
    // explanation + a "ساخت فاکتور جدید" button (cardreissue:{id_order},
    // handled in index.php) that rebuilds a fresh invoice for the same
    // amount through the same card_invoice_build() the original flow uses.
    // Every other payment method keeps the pre-existing silent-delete
    // behaviour untouched.
    $cardExpireLang = languagechange(null, $payer_lang);
    // admin-customizable per language (defaults to the translation key) -
    // same contract as every other card_invoice_caption_*-style store
    $expiredCapTemplate = card_invoice_expired_caption_for($payer_lang, $cardExpireLang['users']['Balance']['cardInvoiceExpiredCaption']);
    $expiredCaption = strtr($expiredCapTemplate, [
        '{price}' => number_format($result['price']),
    ]);
    $reissueStyle = card_invoice_btnstyle_for($payer_lang, 'reissue');
    $reissueBtn = topup_styled_button($cardExpireLang['users']['Balance']['reissueInvoiceBtn'], $reissueStyle, "cardreissue:{$result['id_order']}", 'danger');
    $reissueKb = json_encode(['inline_keyboard' => [[$reissueBtn]]]);
    Editmessagetext($result['id_user'], $result['message_id'], $expiredCaption, $reissueKb, 'HTML');
} else {
    deletemessage($result['id_user'], $result['message_id']);
}
update("Payment_report","payment_Status","expire","id_order",$result['id_order']);
}