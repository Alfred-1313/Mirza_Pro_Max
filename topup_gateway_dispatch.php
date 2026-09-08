<?php
// One checkout body, shared by every way a customer can reach it: the payment
// method list, a package button, and a custom amount. It used to live inline in
// index.php's `get_step_payment` branch, reachable only from the method list -
// which is why picking an amount first had to show a "✅ مبلغ ... انتخاب شد"
// screen whose only job was to produce the callback this block is keyed on.
// Card-to-card and Plisio skipped that screen because their checkouts had been
// lifted into functions; now every gateway skips it, through this file.
//
// Included, never required_once: it runs once per update, in the caller's scope,
// and a handler's `return` only ends the include - so the caller turns that back
// into a real early exit by testing the include's value (NULL when a handler
// returned, 1 when the block ran to the end).
// Hit directly over HTTP, none of this context exists, so there is nothing to
// dispatch - bail out the same way a handler does.
if (!isset($from_id, $datain)) {
    return;
}
    if ($datain == "cart_to_offline") {
        topup_card_invoice_generate($from_id, $user, $message_id, $textbotlang, $setting);
    } elseif ($datain == "aqayepardakht") {
        if ($user['Processing_value'] < 5000) {
            sendmessage($from_id, $textbotlang['users']['Balance']['zarinpal'], null, 'HTML');
            return;
        }
        $mainbalance = pay_value("minbalanceaqayepardakht", $user['lang'] ?? null);
        $maxbalance = pay_value("maxbalanceaqayepardakht", $user['lang'] ?? null);
        if ($user['Processing_value'] < $mainbalance || $user['Processing_value'] > $maxbalance) {
            topup_range_notice($from_id, $user['lang'] ?? 'fa', 'aqayepardakht', $mainbalance, $maxbalance, $textbotlang);
            return;
        }
        deletemessage($from_id, $message_id);
        topup_linkmsg_show($from_id, $user['lang'] ?? 'fa', 'aqayepardakht', $textbotlang['users']['Balance']['linkpayments']);
        $invoice = "{$user['Processing_value_tow']}|{$user['Processing_value_one']}";
        $dateacc = date('Y/m/d H:i:s');
        $randomString = bin2hex(random_bytes(5));
        $pay = createPayaqayepardakht($user['Processing_value'], $randomString);
        if ($pay['status'] != "success") {
            $text_error = json_encode($pay);
            topup_linkmsg_drop($from_id);
            sendmessage($from_id, $textbotlang['users']['Balance']['errorLinkPayment'], $keyboard, 'HTML');
            step('home', $from_id);
            $ErrorsLinkPayment = sprintf($textbotlang['Admin']['reportgroup']['errorAqayePardakhtLink'], $text_error, $from_id, $username);
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $ErrorsLinkPayment,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        $stmt = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice) VALUES (?,?,?,?,?,?,?)");
        $payment_Status = "Unpaid";
        $Payment_Method = "aqayepardakht";
        $stmt->execute([$from_id, $randomString, $dateacc, $user['Processing_value'], $payment_Status, $Payment_Method, $invoice]);
        $paymentkeyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['Balance']['payments'], 'url' => "https://panel.aqayepardakht.ir/startpay/" . $pay['transid']],
                ]
            ]
        ]);
        $price_format = number_format($user['Processing_value'], 0);
        $textnowpayments = sprintf($textbotlang['users']['Balance']['invoiceCreated'], $randomString, $price_format);
        topup_linkmsg_help($from_id, 'helpaqayepardakht');
        topup_track_invoice_message($randomString, topup_linkmsg_finish($from_id, $textnowpayments, $paymentkeyboard));
    } elseif ($datain == "zarinpal") {
        if ($user['Processing_value'] < 5000) {
            sendmessage($from_id, $textbotlang['users']['Balance']['zarinpal'], null, 'HTML');
            return;
        }
        $mainbalance = pay_value("minbalancezarinpal", $user['lang'] ?? null);
        $maxbalance = pay_value("maxbalancezarinpal", $user['lang'] ?? null);
        if ($user['Processing_value'] < $mainbalance || $user['Processing_value'] > $maxbalance) {
            topup_range_notice($from_id, $user['lang'] ?? 'fa', 'zarinpal', $mainbalance, $maxbalance, $textbotlang);
            return;
        }
        deletemessage($from_id, $message_id);
        topup_linkmsg_show($from_id, $user['lang'] ?? 'fa', 'zarinpal', $textbotlang['users']['Balance']['linkpayments']);
        $randomString = bin2hex(random_bytes(5));
        $pay = createPayZarinpal($user['Processing_value'], $randomString);
        if ($pay['data']['code'] != 100) {
            $text_error = json_encode($pay['errors']);
            topup_linkmsg_drop($from_id);
            sendmessage($from_id, $textbotlang['users']['Balance']['errorLinkPayment'], $keyboard, 'HTML');
            step('home', $from_id);
            $ErrorsLinkPayment = sprintf($textbotlang['Admin']['reportgroup']['errorZarinpalLink'], $text_error, $from_id, $username);
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $ErrorsLinkPayment,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        $invoice = "{$user['Processing_value_tow']}|{$user['Processing_value_one']}";
        $dateacc = date('Y/m/d H:i:s');
        $stmt = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice,dec_not_confirmed) VALUES (?,?,?,?,?,?,?,?)");
        $payment_Status = "Unpaid";
        $Payment_Method = "zarinpal";
        $stmt->execute([$from_id, $randomString, $dateacc, $user['Processing_value'], $payment_Status, $Payment_Method, $invoice, $pay['data']['authority']]);
        $paymentkeyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['Balance']['payments'], 'url' => "https://www.zarinpal.com/pg/StartPay/" . $pay['data']['authority']],
                ]
            ]
        ]);
        $price_format = number_format($user['Processing_value'], 0);
        $textnowpayments = sprintf($textbotlang['users']['Balance']['invoiceCreated2'], $randomString, $price_format);
        topup_linkmsg_help($from_id, 'helpzarinpal');
        topup_track_invoice_message($randomString, topup_linkmsg_finish($from_id, $textnowpayments, $paymentkeyboard));
    } elseif ($datain == "plisio") {
        topup_plisio_invoice_generate($from_id, $user, $message_id, $textbotlang, $setting);
    } elseif ($datain == "nowpayment") {
        $mainbalance = topup_effective_gateway_min($user['lang'] ?? 'fa', 'nowpayment', pay_value("minbalancenowpayment", $user['lang'] ?? null));
        $maxbalance = topup_effective_gateway_max($user['lang'] ?? 'fa', 'nowpayment', pay_value("maxbalancenowpayment", $user['lang'] ?? null));
        if ($user['Processing_value'] < $mainbalance || $user['Processing_value'] > $maxbalance) {
            topup_range_notice($from_id, $user['lang'] ?? 'fa', 'nowpayment', $mainbalance, $maxbalance, $textbotlang);
            return;
        }
        deletemessage($from_id, $message_id);
        topup_linkmsg_show($from_id, $user['lang'] ?? 'fa', 'nowpayment', $textbotlang['users']['Balance']['linkpayments']);
        $invoice = "{$user['Processing_value_tow']}|{$user['Processing_value_one']}";
        $built = nowpayment_invoice_build($from_id, $user['lang'] ?? 'fa', $user['Processing_value'], $invoice, $textbotlang, $setting);
        if ($built['error'] === 'toolow') {
            topup_linkmsg_drop($from_id);
            topup_range_notice($from_id, $user['lang'] ?? 'fa', 'nowpayment', $mainbalance, $maxbalance, $textbotlang);
            return;
        }
        if ($built['error'] !== null) {
            topup_linkmsg_drop($from_id);
            sendmessage($from_id, $textbotlang['users']['Balance']['errorLinkPayment'], $keyboard, 'HTML');
            step('home', $from_id);
            if ($built['error'] === 'api' && strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => sprintf($textbotlang['Admin']['reportgroup']['errorCryptoLink2'], $built['apiMessage'], $from_id, $username),
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        topup_linkmsg_help($from_id, 'helpnowpayment');
        topup_track_invoice_message($built['randomString'], topup_linkmsg_finish($from_id, $built['text'], $built['keyboard']));
    } elseif ($datain == "iranpay1") {
        $rates = rate_arze();
        // the TRON half can fail while the dollar half is fine, and this
        // gateway divides by it
        if ($rates === null || (int) ($rates['TRX'] ?? 0) <= 0) {
            topup_linkmsg_drop($from_id);
            sendmessage($from_id, $textbotlang['users']['Balance']['errorLinkPayment'], $keyboard, 'HTML');
            step('home', $from_id);
            return;
        }
        $trx = $rates['TRX'];
        $usd = $rates['USD'];
        $trxprice = round($user['Processing_value'] / $trx, 2);
        $usdprice = $user['Processing_value'] / $usd;
        $mainbalance = pay_value("minbalanceiranpay1", $user['lang'] ?? null);
        $maxbalance = pay_value("maxbalanceiranpay1", $user['lang'] ?? null);
        if ($user['Processing_value'] < $mainbalance || $user['Processing_value'] > $maxbalance) {
            topup_range_notice($from_id, $user['lang'] ?? 'fa', 'iranpay1', $mainbalance, $maxbalance, $textbotlang);
            return;
        }
        deletemessage($from_id, $message_id);
        topup_linkmsg_show($from_id, $user['lang'] ?? 'fa', 'iranpay1', $textbotlang['users']['Balance']['linkpayments']);
        $dateacc = date('Y/m/d H:i:s');
        $randomString = bin2hex(random_bytes(5));
        $invoice = "{$user['Processing_value_tow']}|{$user['Processing_value_one']}";
        $stmt = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice) VALUES (?,?,?,?,?,?,?)");
        $payment_Status = "Unpaid";
        $Payment_Method = "Currency Rial 1";
        $stmt->execute([$from_id, $randomString, $dateacc, $user['Processing_value'], $payment_Status, $Payment_Method, $invoice]);
        $pay = createInvoiceiranpay1($user['Processing_value'], $randomString);
        if ($pay['status'] != "100") {
            $text_error = $pay['message'];
            topup_linkmsg_drop($from_id);
            sendmessage($from_id, $textbotlang['users']['Balance']['errorLinkPayment'], $keyboard, 'HTML');
            step('home', $from_id);
            $ErrorsLinkPayment = sprintf($textbotlang['Admin']['reportgroup']['errorPaymentLink'], $text_error, $from_id, $Payment_Method, $username);
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $ErrorsLinkPayment,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        update("Payment_report", "dec_not_confirmed", $pay['Authority'], "id_order", $randomString);
        $paymentkeyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['keyboard']['payment'], 'url' => $pay['payment_url_bot']]
                ]
            ]
        ]);
        $pricetoman = number_format($user['Processing_value'], 0);
        $textnowpayments = sprintf($textbotlang['users']['Balance']['transactionCreated'], $randomString, $pricetoman);
        topup_linkmsg_help($from_id, 'helpiranpay1');
        topup_track_invoice_message($randomString, topup_linkmsg_finish($from_id, $textnowpayments, $paymentkeyboard));
    } elseif ($datain == "iranpay2") {
        $rates = rate_arze();
        // the TRON half can fail while the dollar half is fine, and this
        // gateway divides by it
        if ($rates === null || (int) ($rates['TRX'] ?? 0) <= 0) {
            topup_linkmsg_drop($from_id);
            sendmessage($from_id, $textbotlang['users']['Balance']['errorLinkPayment'], $keyboard, 'HTML');
            step('home', $from_id);
            return;
        }
        $trx = $rates['TRX'];
        $usd = $rates['USD'];
        $trxprice = $user['Processing_value'] / $trx;
        $usdprice = $user['Processing_value'] / $usd;
        $mainbalance = pay_value("minbalanceiranpay2", $user['lang'] ?? null);
        $maxbalance = pay_value("maxbalanceiranpay2", $user['lang'] ?? null);
        if ($user['Processing_value'] < $mainbalance || $user['Processing_value'] > $maxbalance) {
            topup_range_notice($from_id, $user['lang'] ?? 'fa', 'iranpay2', $mainbalance, $maxbalance, $textbotlang);
            return;
        }
        deletemessage($from_id, $message_id);
        topup_linkmsg_show($from_id, $user['lang'] ?? 'fa', 'iranpay2', $textbotlang['users']['Balance']['linkpayments']);
        $dateacc = date('Y/m/d H:i:s');
        $randomString = bin2hex(random_bytes(5));
        $invoice = "{$user['Processing_value_tow']}|{$user['Processing_value_one']}";
        $stmt = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice) VALUES (?,?,?,?,?,?,?)");
        $payment_Status = "Unpaid";
        $Payment_Method = "Currency Rial 2";
        $stmt->execute([$from_id, $randomString, $dateacc, $user['Processing_value'], $payment_Status, $Payment_Method, $invoice]);
        $payment = trnado($randomString, $trxprice);
        if ($payment['IsSuccessful'] != "true") {
            $text_error = json_encode($payment);
            topup_linkmsg_drop($from_id);
            sendmessage($from_id, $textbotlang['users']['Balance']['errorLinkPayment'], $keyboard, 'HTML');
            step('home', $from_id);
            $ErrorsLinkPayment = sprintf($textbotlang['Admin']['reportgroup']['errorPaymentLink2'], $text_error, $from_id, $Payment_Method, $username);
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $ErrorsLinkPayment,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        $paymentkeyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['Balance']['payments'], 'url' => "https://t.me/tronado_robot/customerpayment?startapp={$payment['Data']['Token']}"]
                ]
            ]
        ]);
        $pricetoman = number_format($user['Processing_value'], 0);
        $textnowpayments = sprintf($textbotlang['users']['Balance']['transactionCreated2'], $randomString, $pricetoman);
        topup_linkmsg_help($from_id, 'helpiranpay2');
        topup_track_invoice_message($randomString, topup_linkmsg_finish($from_id, $textnowpayments, $paymentkeyboard));
    } elseif ($datain == "iranpay3") {
        $dateacc = date('Y/m/d');
        $query = "SELECT SUM(price) as price FROM Payment_report WHERE  Payment_Method = 'Currency Rial 1' AND  time LIKE '%$dateacc%'";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $sumpayment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (intval($sumpayment['price']) > 1000000) {
            sendmessage($from_id, $textbotlang['users']['Balance']['queueBusy'], null, 'HTML');
            return;
        }
        $rates = rate_arze();
        // the TRON half can fail while the dollar half is fine, and this
        // gateway divides by it
        if ($rates === null || (int) ($rates['TRX'] ?? 0) <= 0) {
            topup_linkmsg_drop($from_id);
            sendmessage($from_id, $textbotlang['users']['Balance']['errorLinkPayment'], $keyboard, 'HTML');
            step('home', $from_id);
            return;
        }
        $trx = $rates['TRX'];
        $usd = $rates['USD'];
        $trxprice = $user['Processing_value'] / $trx;
        $usdprice = $user['Processing_value'] / $usd;
        $mainbalance = pay_value("minbalanceiranpay", $user['lang'] ?? null);
        $maxbalance = pay_value("maxbalanceiranpay", $user['lang'] ?? null);
        if ($user['Processing_value'] < $mainbalance || $user['Processing_value'] > $maxbalance) {
            topup_range_notice($from_id, $user['lang'] ?? 'fa', 'iranpay3', $mainbalance, $maxbalance, $textbotlang);
            return;
        }
        deletemessage($from_id, $message_id);
        topup_linkmsg_show($from_id, $user['lang'] ?? 'fa', 'iranpay3', $textbotlang['users']['Balance']['linkpayments']);
        $dateacc = date('Y/m/d H:i:s');
        $randomString = bin2hex(random_bytes(5));
        $invoice = "{$user['Processing_value_tow']}|{$user['Processing_value_one']}";
        $stmt = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice) VALUES (?,?,?,?,?,?,?)");
        $payment_Status = "Unpaid";
        $Payment_Method = "Currency Rial 3";
        $stmt->execute([$from_id, $randomString, $dateacc, $user['Processing_value'], $payment_Status, $Payment_Method, $invoice]);
        $paylink = createInvoice($trxprice);
        if (!$paylink['success']) {
            $text_error = $paylink['message'];
            topup_linkmsg_drop($from_id);
            sendmessage($from_id, $textbotlang['users']['Balance']['errorLinkPayment'], $keyboard, 'HTML');
            step('home', $from_id);
            $ErrorsLinkPayment = sprintf($textbotlang['Admin']['reportgroup']['errorPaymentLink3'], $text_error, $from_id, $Payment_Method, $username);
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $ErrorsLinkPayment,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        update("Payment_report", "dec_not_confirmed", $paylink['data']['id'], "id_order", $randomString);
        $pricetoman = number_format($user['Processing_value'], 0);
        $paymentkeyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['keyboard']['diamondPayment'], 'url' => "t.me/AvidTrx_Bot?start=" . $paylink['data']['id']]
                ],
            ]
        ]);
        $textnowpayments = sprintf($textbotlang['users']['Balance']['transactionCreated3'], $randomString, $pricetoman);
        topup_linkmsg_help($from_id, 'helpiranpay3');
        topup_linkmsg_finish($from_id, $textnowpayments, $paymentkeyboard);
        step("getvoocherx", $from_id);
        savedata("clear", "id_payment", $randomString);
    } elseif ($datain == "digitaltron") {
        $rates = rate_arze();
        // the TRON half can fail while the dollar half is fine, and this
        // gateway divides by it
        if ($rates === null || (int) ($rates['TRX'] ?? 0) <= 0) {
            topup_linkmsg_drop($from_id);
            sendmessage($from_id, $textbotlang['users']['Balance']['errorLinkPayment'], $keyboard, 'HTML');
            step('home', $from_id);
            return;
        }
        $trx = $rates['TRX'];
        $usd = $rates['USD'];
        $trxprice = round($user['Processing_value'] / $trx, 2);
        $usdprice = round($user['Processing_value'] / $usd, 2);
        if ($trxprice <= 1) {
            sendmessage($from_id, $textbotlang['users']['Balance']['changeto'], null, 'HTML');
            return;
        }
        $mainbalancedigitaltron = pay_value("minbalancedigitaltron", $user['lang'] ?? null);
        $maxbalancedigitaltron = pay_value("maxbalancedigitaltron", $user['lang'] ?? null);
        if ($user['Processing_value'] < $mainbalancedigitaltron || $user['Processing_value'] > $maxbalancedigitaltron) {
            topup_range_notice($from_id, $user['lang'] ?? 'fa', 'digitaltron', $mainbalancedigitaltron, $maxbalancedigitaltron, $textbotlang);
            return;
        }
        deletemessage($from_id, $message_id);
        topup_linkmsg_show($from_id, $user['lang'] ?? 'fa', 'digitaltron', $textbotlang['users']['Balance']['linkpayments']);
        $dateacc = date('Y/m/d H:i:s');
        $randomString = bin2hex(random_bytes(5));
        $invoice = "{$user['Processing_value_tow']}|{$user['Processing_value_one']}";
        $stmt = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice) VALUES (?,?,?,?,?,?,?)");
        $payment_Status = "Unpaid";
        $Payment_Method = "arze digital offline";
        $stmt->execute([$from_id, $randomString, $dateacc, $user['Processing_value'], $payment_Status, $Payment_Method, $invoice]);
        $affilnecurrency = pay_value("walletaddress", $user['lang'] ?? null);
        $paymentkeyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['keyboard']['sendDepositLink'], 'callback_data' => "sendresidarze-{$randomString}"]
                ]
            ]
        ]);
        $formatprice = number_format($user['Processing_value'], 0);
        $textnowpayments = sprintf($textbotlang['users']['Balance']['transactionCreatedTron'], $randomString, $affilnecurrency, $trxprice, $formatprice);
        topup_linkmsg_help($from_id, 'helpofflinearze');
        topup_track_invoice_message($randomString, topup_linkmsg_finish($from_id, $textnowpayments, $paymentkeyboard));
    } elseif ($datain == "ton") {
        $mainbalance = topup_effective_gateway_min($user['lang'] ?? 'fa', 'ton', pay_value("minbalanceton", $user['lang'] ?? null));
        $maxbalance = topup_effective_gateway_max($user['lang'] ?? 'fa', 'ton', pay_value("maxbalanceton", $user['lang'] ?? null));
        if ($user['Processing_value'] < $mainbalance || $user['Processing_value'] > $maxbalance) {
            topup_range_notice($from_id, $user['lang'] ?? 'fa', 'ton', $mainbalance, $maxbalance, $textbotlang);
            return;
        }
        deletemessage($from_id, $message_id);
        topup_linkmsg_show($from_id, $user['lang'] ?? 'fa', 'ton', $textbotlang['users']['Balance']['linkpayments']);
        $invoice = "{$user['Processing_value_tow']}|{$user['Processing_value_one']}";
        $built = ton_invoice_build($from_id, $user['lang'] ?? 'fa', $user['Processing_value'], $invoice, $textbotlang, $setting);
        if ($built['error'] === 'toolow') {
            topup_linkmsg_drop($from_id);
            topup_range_notice($from_id, $user['lang'] ?? 'fa', 'ton', $mainbalance, $maxbalance, $textbotlang);
            return;
        }
        if ($built['error'] === 'noaddress') {
            // nothing the customer can do about this one - say so plainly
            topup_linkmsg_drop($from_id);
            sendmessage($from_id, topup_noaddress_caption_for($user['lang'] ?? 'fa', 'ton', $textbotlang['users']['Balance']['tonNoAddress']), $keyboard, 'HTML');
            step('home', $from_id);
            return;
        }
        if ($built['error'] !== null) {
            topup_linkmsg_drop($from_id);
            sendmessage($from_id, $textbotlang['users']['Balance']['errorLinkPayment'], $keyboard, 'HTML');
            step('home', $from_id);
            return;
        }
        topup_linkmsg_help($from_id, 'helpton');
        topup_track_invoice_message($built['randomString'], topup_linkmsg_finish($from_id, $built['text'], $built['keyboard']));
    } elseif ($datain == "trx") {
        $mainbalance = topup_effective_gateway_min($user['lang'] ?? 'fa', 'trx', pay_value("minbalancetrx", $user['lang'] ?? null));
        $maxbalance = topup_effective_gateway_max($user['lang'] ?? 'fa', 'trx', pay_value("maxbalancetrx", $user['lang'] ?? null));
        if ($user['Processing_value'] < $mainbalance || $user['Processing_value'] > $maxbalance) {
            topup_range_notice($from_id, $user['lang'] ?? 'fa', 'trx', $mainbalance, $maxbalance, $textbotlang);
            return;
        }
        deletemessage($from_id, $message_id);
        topup_linkmsg_show($from_id, $user['lang'] ?? 'fa', 'trx', $textbotlang['users']['Balance']['linkpayments']);
        $invoice = "{$user['Processing_value_tow']}|{$user['Processing_value_one']}";
        $built = trx_invoice_build($from_id, $user['lang'] ?? 'fa', $user['Processing_value'], $invoice, $textbotlang, $setting);
        if ($built['error'] === 'noaddress') {
            topup_linkmsg_drop($from_id);
            sendmessage($from_id, topup_noaddress_caption_for($user['lang'] ?? 'fa', 'trx', $textbotlang['users']['Balance']['trxNoAddress']), $keyboard, 'HTML');
            step('home', $from_id);
            return;
        }
        if ($built['error'] !== null) {
            topup_linkmsg_drop($from_id);
            sendmessage($from_id, $textbotlang['users']['Balance']['errorLinkPayment'], $keyboard, 'HTML');
            step('home', $from_id);
            return;
        }
        topup_linkmsg_help($from_id, 'helptrx');
        topup_track_invoice_message($built['randomString'], topup_linkmsg_finish($from_id, $built['text'], $built['keyboard']));
    } elseif ($datain == "usdtbep") {
        $mainbalance = topup_effective_gateway_min($user['lang'] ?? 'fa', 'usdtbep', pay_value("minbalanceusdtbep", $user['lang'] ?? null));
        $maxbalance = topup_effective_gateway_max($user['lang'] ?? 'fa', 'usdtbep', pay_value("maxbalanceusdtbep", $user['lang'] ?? null));
        if ($user['Processing_value'] < $mainbalance || $user['Processing_value'] > $maxbalance) {
            topup_range_notice($from_id, $user['lang'] ?? 'fa', 'usdtbep', $mainbalance, $maxbalance, $textbotlang);
            return;
        }
        deletemessage($from_id, $message_id);
        topup_linkmsg_show($from_id, $user['lang'] ?? 'fa', 'usdtbep', $textbotlang['users']['Balance']['linkpayments']);
        $invoice = "{$user['Processing_value_tow']}|{$user['Processing_value_one']}";
        $built = usdtbep_invoice_build($from_id, $user['lang'] ?? 'fa', $user['Processing_value'], $invoice, $textbotlang, $setting);
        if ($built['error'] === 'toolow') {
            // the toman figure rounded down to nothing in Tether - the range
            // message says what the smallest workable amount is
            topup_linkmsg_drop($from_id);
            topup_range_notice($from_id, $user['lang'] ?? 'fa', 'usdtbep', $mainbalance, $maxbalance, $textbotlang);
            return;
        }
        if ($built['error'] === 'noaddress') {
            // nothing the customer can do about this one - say so plainly
            topup_linkmsg_drop($from_id);
            sendmessage($from_id, topup_noaddress_caption_for($user['lang'] ?? 'fa', 'usdtbep', $textbotlang['users']['Balance']['usdtbepNoAddress']), $keyboard, 'HTML');
            step('home', $from_id);
            return;
        }
        if ($built['error'] !== null) {
            topup_linkmsg_drop($from_id);
            sendmessage($from_id, $textbotlang['users']['Balance']['errorLinkPayment'], $keyboard, 'HTML');
            step('home', $from_id);
            return;
        }
        topup_track_invoice_message($built['randomString'], topup_linkmsg_finish($from_id, $built['text'], $built['keyboard']));
    } elseif ($datain == "startelegrams") {
        $mainbalance = topup_effective_gateway_min($user['lang'] ?? 'fa', 'startelegrams', pay_value("minbalancestar", $user['lang'] ?? null));
        $maxbalance = topup_effective_gateway_max($user['lang'] ?? 'fa', 'startelegrams', pay_value("maxbalancestar", $user['lang'] ?? null));
        if ($user['Processing_value'] < $mainbalance || $user['Processing_value'] > $maxbalance) {
            topup_range_notice($from_id, $user['lang'] ?? 'fa', 'startelegrams', $mainbalance, $maxbalance, $textbotlang);
            return;
        }
        deletemessage($from_id, $message_id);
        topup_linkmsg_show($from_id, $user['lang'] ?? 'fa', 'startelegrams', $textbotlang['users']['Balance']['linkpayments']);
        $invoice = "{$user['Processing_value_tow']}|{$user['Processing_value_one']}";
        $built = star_invoice_build($from_id, $user['lang'] ?? 'fa', $user['Processing_value'], $invoice, $textbotlang, $setting);
        if ($built['error'] === 'toolow') {
            topup_linkmsg_drop($from_id);
            topup_range_notice($from_id, $user['lang'] ?? 'fa', 'startelegrams', $mainbalance, $maxbalance, $textbotlang);
            return;
        }
        if ($built['error'] !== null) {
            topup_linkmsg_drop($from_id);
            sendmessage($from_id, $textbotlang['users']['Balance']['errorLinkPayment'], $keyboard, 'HTML');
            step('home', $from_id);
            if ($built['error'] === 'api' && strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => sprintf($textbotlang['Admin']['reportgroup']['errorStarInvoice'], $built['apiMessage'], $from_id, 'Star Telegram', $username),
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        topup_linkmsg_help($from_id, 'helpstar');
        topup_track_invoice_message($built['randomString'], topup_linkmsg_finish($from_id, $built['text'], $built['keyboard']));
    }
