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
    // Every row needs the payer's language, not just card and plisio. The other
    // gateways word their own expiry too, and topup_expire_notify() hands this
    // straight to languagechange(), whose $lang parameter is a typed string -
    // so leaving it null threw a TypeError that killed the whole loop before it
    // reached the UPDATE at the bottom. That is why TON, TRX, Star Telegram and
    // NowPayment invoices stayed Unpaid for ever while card and plisio expired
    // normally: those two were the only methods that filled this in.
    $payer = select('user', 'lang', 'id', $result['id_user'], 'select');
    $payer_lang = (is_array($payer) && !empty($payer['lang'])) ? $payer['lang'] : 'fa';
    if ($result['Payment_Method'] === 'cart to cart' || $result['Payment_Method'] === 'plisio') {
        $expireField = $result['Payment_Method'] === 'cart to cart' ? 'cardInvoiceExpireMinutes' : 'plisioInvoiceExpireMinutes';
        $expire_minutes = (int) pay_value($expireField, $payer_lang, $default_expire_minutes);
        if ($expire_minutes < 1) {
            $expire_minutes = $default_expire_minutes;
        }
    }
    // a gateway that words its own expiry also sets its own cutoff, or the
    // caption promises one number while the cron enforces another
    $gw_key_for_expiry = topup_gateway_key_by_method($result['Payment_Method']);
    if ($gw_key_for_expiry !== null) {
        $expire_minutes = topup_expire_minutes($payer_lang, $gw_key_for_expiry);
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
        'nowpayment' => $textbotlang['textbot']['cryptoPayment'],
        'TON' => $textbotlang['textbot']['tonPayment'],
        'TRX' => $textbotlang['textbot']['trxPayment'],
        // ?? '' because a method with no entry here used to raise an undefined-key
        // warning on every run of the cron - the value only feeds $textexpire,
        // which is currently unused, so a blank name is harmless where a warning
        // in the log was not
    ][$result['Payment_Method']] ?? '';
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
} elseif (isset(topup_expire_gateway_keys()[$result['Payment_Method']])) {
    // every online gateway now words its own expiry and offers a fresh
    // invoice, instead of the message simply vanishing
    topup_expire_notify(topup_expire_gateway_keys()[$result['Payment_Method']], $result['id_user'], $result['id_order'], $result['price'], $result['message_id'], $payer_lang);
} else {
    deletemessage($result['id_user'], $result['message_id']);
}
update("Payment_report","payment_Status","expire","id_order",$result['id_order']);
}