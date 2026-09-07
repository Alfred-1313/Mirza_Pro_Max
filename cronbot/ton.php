<?php
// Watches the shop's TON wallet and settles the invoices it finds paid.
//
// This is what makes TON an online gateway rather than an offline one: nobody
// has to approve anything. The chain is public, the transfer carries the order
// id as its comment, and toncenter hands both back with no API key - so the
// bot can see a payment land and credit the wallet by itself.
//
// Runs like the other crons (cronbot/plisio.php is the closest sibling).
// The same set cronbot/plisio.php takes, and for the same reason: DirectPayment()
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

$rows = $pdo->query("SELECT * FROM Payment_report WHERE payment_Status = 'Unpaid' AND Payment_Method = 'TON'")
    ->fetchAll(PDO::FETCH_ASSOC);
if (empty($rows)) {
    return;
}

// One chain read per wallet, not per invoice: every language may point at a
// different address, but most shops use one, and this keeps it to a single
// request in that case.
$byAddress = [];
foreach ($rows as $row) {
    $payer_lang = (string) (select("user", "*", "id", $row['id_user'], "select")['lang'] ?? 'fa');
    $addr = topup_ton_address($payer_lang);
    if ($addr === '') {
        continue;
    }
    $byAddress[$addr][] = $row + ['_lang' => $payer_lang];
}

foreach ($byAddress as $addr => $addrRows) {
    $incoming = ton_incoming_transfers($addr, 100);
    if ($incoming === null) {
        // could not look, as opposed to nothing arrived - leave every invoice
        // alone rather than expiring one that may well have been paid
        continue;
    }
    foreach ($addrRows as $row) {
        if (!ton_payment_settled($row, $incoming)) {
            continue;
        }
        // Mark it paid BEFORE crediting: DirectPayment sends messages and can
        // take a while, and a second cron tick landing in the middle of it
        // would otherwise credit the same transfer twice.
        $claim = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'paid' WHERE id_order = ? AND payment_Status = 'Unpaid'");
        $claim->execute([$row['id_order']]);
        if ($claim->rowCount() !== 1) {
            continue;   // another tick got there first
        }
        DirectPayment($row['id_order'], "../images.jpg");
        $report = select("user", "*", "id", $row['id_user'], "select");
        if (strlen((string) ($setting['Channel_Report'] ?? '')) > 0) {
            $paymentreports = select("topicid", "idreport", "report", "paymentreports", "select")['idreport'];
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $paymentreports,
                'text' => sprintf(
                    $textbotlang['hardcoded']['tonNewPaymentLog'],
                    $report['username'] ?? '-',
                    $row['id_user'],
                    number_format($row['price']),
                    rtrim(rtrim(number_format($incoming[$row['id_order']] / 1000000000, 9, '.', ''), '0'), '.'),
                    $row['id_order']
                ),
                'parse_mode' => "HTML",
            ]);
        }
    }
}
