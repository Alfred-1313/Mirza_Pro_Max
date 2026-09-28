<?php
// Where the customer lands after «I paid» on Variza (from the original bot).
// Variza confirms a card-to-card by SMS, often minutes later, so this page
// settles nothing: the signed push to variza_webhook.php does. It only says
// what the bot already knows - paid, or still waiting.
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';

$order_id = trim((string) ($_GET['order'] ?? $_GET['order_id'] ?? ''));
$Payment_report = $order_id !== '' ? select("Payment_report", "*", "id_order", $order_id, "select") : false;
if (!is_array($Payment_report) || $Payment_report['Payment_Method'] !== 'variza') {
    payment_result_page('notfound', lang_tab_texts('fa'), $order_id);
    return;
}
$lang = (string) (select("user", "lang", "id", $Payment_report['id_user'], "select")['lang'] ?? 'fa') ?: 'fa';
payment_result_page($Payment_report['payment_Status'] === 'paid' ? 'success' : 'waiting', payer_texts($Payment_report['id_user']), $order_id, $Payment_report['price'], $lang);
