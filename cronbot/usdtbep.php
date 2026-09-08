<?php
// Watches the shop's BNB Smart Chain wallet for USDT (BEP20) and settles the
// invoices it finds paid.
//
// A BEP20 transfer carries no note, so the invoice is named by its amount:
// every open invoice gets a figure no other one has, down to the last decimal.
// An incoming transfer of exactly that much therefore names exactly one
// invoice - which is why the match is exact rather than tolerant, and why a
// transfer older than the invoice is ignored.
// The same set cronbot/trx.php takes, and for the same reason: DirectPayment()
// creates the service through the panel classes, so panels.php and the composer
// autoloader have to be here. admin.php must NOT be - it is a dispatcher, not a
// library: it reads $textbotlang/$version/$admin_ids at the top and returns
// early for non-admins, so requiring it from a cron is an instant fatal.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../jdf.php';
require __DIR__ . '/../vendor/autoload.php';

$setting = select("setting", "*", null, null, "select");
$textbotlang = languagechange(null, 'fa');
$GLOBALS['textbotlang'] = $textbotlang;

$rows = $pdo->query("SELECT * FROM Payment_report WHERE payment_Status = 'Unpaid' AND Payment_Method = 'USDT-BEP20'")
    ->fetchAll(PDO::FETCH_ASSOC);
if (empty($rows)) {
    return;
}

// one chain read per wallet, and only as far back as the oldest open invoice
$byAddress = [];
foreach ($rows as $row) {
    $payer_lang = (string) (select("user", "*", "id", $row['id_user'], "select")['lang'] ?? 'fa');
    $addr = topup_usdtbep_address($payer_lang);
    if ($addr === '') {
        continue;
    }
    $byAddress[$addr][] = $row;
}

foreach ($byAddress as $addr => $addrRows) {
    $oldest = PHP_INT_MAX;
    foreach ($addrRows as $row) {
        $ts = strtotime((string) $row['time']);
        if ($ts > 0 && $ts < $oldest) {
            $oldest = $ts;
        }
    }
    // usdtbep_incoming_transfers turns this into a block window itself, and caps
    // it - BSC makes a block every ~0.45s, so an unbounded span would be a range
    // no public node will serve
    $sinceTs = $oldest === PHP_INT_MAX ? 0 : ($oldest - 300);
    $incoming = usdtbep_incoming_transfers($addr, $sinceTs);
    if ($incoming === null) {
        // could not look, as opposed to nothing arrived - leave the invoices be
        continue;
    }
    foreach ($addrRows as $row) {
        if (!usdtbep_payment_settled($row, $incoming)) {
            continue;
        }
        // claim before crediting, so a second tick cannot pay it twice
        $claim = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'paid' WHERE id_order = ? AND payment_Status = 'Unpaid'");
        $claim->execute([$row['id_order']]);
        if ($claim->rowCount() !== 1) {
            continue;
        }
        DirectPayment($row['id_order'], "../images.jpg");
        if (strlen((string) ($setting['Channel_Report'] ?? '')) > 0) {
            $report = select("user", "*", "id", $row['id_user'], "select");
            $paymentreports = select("topicid", "idreport", "report", "paymentreports", "select")['idreport'];
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $paymentreports,
                'text' => sprintf(
                    $textbotlang['hardcoded']['usdtbepNewPaymentLog'],
                    $report['username'] ?? '-',
                    $row['id_user'],
                    number_format($row['price']),
                    usdtbep_micro_to_text((int) $row['dec_not_confirmed']),
                    $row['id_order']
                ),
                'parse_mode' => "HTML",
            ]);
        }
    }
}
