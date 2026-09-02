<?php
require_once 'config.php';
$setting = select("setting", "*", null, null, "select");
$textbotlang = languagechange();
if (!function_exists('getPaySettingValue')) {
    function getPaySettingValue($name)
    {
        $result = select("PaySetting", "ValuePay", "NamePay", $name, "select");
        return $result['ValuePay'] ?? null;
    }
}
//-----------------------------[  text panel  ]-------------------------------
$adminrulecheck = select("admin", "*", "id_admin", $from_id, "select");
if (!$adminrulecheck) {
    $adminrulecheck = array(
        'rule' => '',
    );
}
$users = select("user", "*", "id", $from_id, "select");
if ($users == false) {
    $users = array();
    $users = array(
        'step' => '',
        'agent' => '',
        'limit_usertest' => '',
        'Processing_value' => '',
        'Processing_value_four' => '',
        'cardpayment' => ""
    );
}
$replacements = [
    'text_usertest' => $textbotlang['textbot']['userTest'],
    'text_Purchased_services' => $textbotlang['textbot']['purchasedServices'],
    'text_support' => $textbotlang['textbot']['support'],
    'text_help' => $textbotlang['textbot']['help'],
    'accountwallet' => $textbotlang['textbot']['accountWallet'],
    'addbalance' => $textbotlang['textbot']['addBalance'],
    'text_sell' => $textbotlang['textbot']['sell'],
    'text_Tariff_list' => $textbotlang['textbot']['tariffList'],
    'text_affiliates' => $textbotlang['textbot']['affiliates'],
    'text_wheel_luck' => $textbotlang['textbot']['wheelLuck'],
    'text_extend' => $textbotlang['textbot']['extend'],
    'text_change_language' => $textbotlang['language']['changeButton']
];
$admin_idss = select("admin", "*", "id_admin", $from_id, "count");
$temp_addtional_key = [];
//----------------[  helper: strip leading emoji  ]----------------
if (!function_exists('strip_leading_emoji')) {
    function strip_leading_emoji($s)
    {
        return trim(preg_replace('/^[\x{203C}\x{2049}\x{2139}\x{2194}-\x{2199}\x{21A9}-\x{21AA}\x{231A}-\x{231B}\x{23E9}-\x{23EC}\x{23F0}\x{23F3}\x{24C2}\x{25AA}-\x{25AB}\x{25B6}\x{25C0}\x{25FB}-\x{25FE}\x{2600}-\x{27BF}\x{2934}-\x{2935}\x{2B05}-\x{2B07}\x{2B1B}-\x{2B1C}\x{2B50}\x{2B55}\x{3030}\x{303D}\x{3297}\x{3299}\x{FE0E}\x{FE0F}\x{200D}\x{2764}\x{20E3}\x{1F000}-\x{1FAFF}\x{1F1E6}-\x{1F1FF}\s]*/u', '', (string) $s));
    }
}
//----------------[  helper: split leading emoji from text  ]----------------
if (!function_exists('split_leading_emoji')) {
    function split_leading_emoji($s)
    {
        $s = (string) $s;
        $rest = strip_leading_emoji($s);
        if ($rest === '' || $rest === $s) {
            return ['', $s];
        }
        $emoji = trim(mb_substr($s, 0, mb_strlen($s) - mb_strlen($rest)));
        return [$emoji, $rest];
    }
}
$keyboardLayout = json_decode($setting['keyboardmain'], true);
$keyboardRows = [];
if (is_array($keyboardLayout) && isset($keyboardLayout['keyboard']) && is_array($keyboardLayout['keyboard'])) {
    $keyboardRows = $keyboardLayout['keyboard'];
}
// simple mode: hide default emojis from button texts (custom emoji/icons stay)
$simple_emoji_mode = (is_array($keyboardLayout) && !empty($keyboardLayout['simple_emoji']));
// global emoji position: left = after text, right = before text
$global_emoji_pos = (is_array($keyboardLayout) && isset($keyboardLayout['emoji_pos_global']) && $keyboardLayout['emoji_pos_global'] === 'left') ? 'left' : 'right';

if (!empty($keyboardRows)) {
    $allowed_btn_styles = ['primary', 'success', 'danger'];
    foreach ($keyboardRows as $kb_r => $kb_row) {
        if (!is_array($kb_row)) {
            continue;
        }
        foreach ($kb_row as $kb_c => $kb_btn) {
            if (is_array($kb_btn) && isset($kb_btn['style']) && !in_array($kb_btn['style'], $allowed_btn_styles, true)) {
                unset($keyboardRows[$kb_r][$kb_c]['style']);
            }
            if (is_array($kb_btn) && isset($kb_btn['style_reply']) && !in_array($kb_btn['style_reply'], $allowed_btn_styles, true)) {
                unset($keyboardRows[$kb_r][$kb_c]['style_reply']);
            }
        }
    }
}

if (!function_exists('build_main_keyboard')) {
    // builds the user's main menu keyboard; call it again after a language switch
    // so the button labels follow the newly selected language
    function build_main_keyboard()
    {
        global $setting, $textbotlang, $users, $admin_idss, $keyboardRows, $simple_emoji_mode, $global_emoji_pos;
        $temp_addtional_key = [];
        $replacements = [
            'text_usertest' => $textbotlang['textbot']['userTest'],
            'text_Purchased_services' => $textbotlang['textbot']['purchasedServices'],
            'text_support' => $textbotlang['textbot']['support'],
            'text_help' => $textbotlang['textbot']['help'],
            'accountwallet' => $textbotlang['textbot']['accountWallet'],
            'addbalance' => $textbotlang['textbot']['addBalance'],
            'text_sell' => $textbotlang['textbot']['sell'],
            'text_Tariff_list' => $textbotlang['textbot']['tariffList'],
            'text_affiliates' => $textbotlang['textbot']['affiliates'],
            'text_wheel_luck' => $textbotlang['textbot']['wheelLuck'],
            'text_extend' => $textbotlang['textbot']['extend'],
            'text_change_language' => $textbotlang['language']['changeButton']
        ];
        if ($setting['inlinebtnmain'] == "oninline" && !empty($keyboardRows)) {
            $trace_keyboard = $keyboardRows;
            foreach ($trace_keyboard as $key => $callback_set) {
                foreach ($callback_set as $keyboard_key => $keyboard) {
                    if ($keyboard['text'] == "text_sell") {
                        $trace_keyboard[$key][$keyboard_key]['callback_data'] = "buy";
                    }
                    if ($keyboard['text'] == "accountwallet") {
                        $trace_keyboard[$key][$keyboard_key]['callback_data'] = "account";
                    }
                    if ($keyboard['text'] == "addbalance") {
                        $trace_keyboard[$key][$keyboard_key]['callback_data'] = "Add_Balance";
                    }
                    if ($keyboard['text'] == "text_Tariff_list") {
                        $trace_keyboard[$key][$keyboard_key]['callback_data'] = "Tariff_list";
                    }
                    if ($keyboard['text'] == "text_wheel_luck") {
                        $trace_keyboard[$key][$keyboard_key]['callback_data'] = "wheel_luck";
                    }
                    if ($keyboard['text'] == "text_affiliates") {
                        $trace_keyboard[$key][$keyboard_key]['callback_data'] = "affiliatesbtn";
                    }
                    if ($keyboard['text'] == "text_extend") {
                        $trace_keyboard[$key][$keyboard_key]['callback_data'] = "extendbtn";
                    }
                    if ($keyboard['text'] == "text_support") {
                        $trace_keyboard[$key][$keyboard_key]['callback_data'] = "supportbtns";
                    }
                    if ($keyboard['text'] == "text_Purchased_services") {
                        $trace_keyboard[$key][$keyboard_key]['callback_data'] = "backorder";
                    }
                    if ($keyboard['text'] == "text_help") {
                        $trace_keyboard[$key][$keyboard_key]['callback_data'] = "helpbtns";
                    }
                    if ($keyboard['text'] == "text_usertest") {
                        $trace_keyboard[$key][$keyboard_key]['callback_data'] = "usertestbtn";
                    }
                    if ($keyboard['text'] == "text_change_language") {
                        $trace_keyboard[$key][$keyboard_key]['callback_data'] = "change_language";
                    }
                }
            }
            if ($admin_idss != 0) {
                $temp_addtional_key[] = ['text' => $textbotlang['Admin']['panelAdmin'], 'callback_data' => "admin"];
            }
            if ($users['agent'] != "f") {
                $temp_addtional_key[] = ['text' => $textbotlang['textbot']['agentPanel'], 'callback_data' => "agentpanel"];
            }
            if ($users['agent'] == "f" && $setting['statusagentrequest'] == "onrequestagent") {
                $temp_addtional_key[] = ['text' => $textbotlang['textbot']['requestAgent'], 'callback_data' => "requestagent"];
            }
            $keyboard = ['inline_keyboard' => []];
            $keyboardcustom = $trace_keyboard;
            $keyboardcustom = json_decode(strtr(strval(json_encode($keyboardcustom)), $replacements), true);
            $keyboardfiltered = [];
            foreach ($keyboardcustom as $kb_r => $kb_row) {
                if (!is_array($kb_row)) {
                    continue;
                }
                $kb_newrow = [];
                foreach ($kb_row as $kb_c => $kb_btn) {
                    if (!is_array($kb_btn)) {
                        continue;
                    }
                    if (!empty($kb_btn['hidden'])) {
                        continue;
                    }
                    if (isset($kb_btn['custom_text']) && $kb_btn['custom_text'] !== '') {
                        $kb_btn['text'] = $kb_btn['custom_text'];
                    }
                    if ($simple_emoji_mode && empty($kb_btn['icon_emoji']) && empty($kb_btn['emoji'])) {
                        $kb_btn['text'] = strip_leading_emoji($kb_btn['text']);
                    }
                    if (isset($kb_btn['icon_emoji']) && $kb_btn['icon_emoji'] !== '') {
                        $kb_btn['icon_custom_emoji_id'] = $kb_btn['icon_emoji'];
                        $kb_btn['text'] = strip_leading_emoji($kb_btn['text']);
                    } elseif (isset($kb_btn['emoji']) && $kb_btn['emoji'] !== '') {
                        if ($global_emoji_pos === 'left') {
                            $kb_btn['text'] = strip_leading_emoji($kb_btn['text']) . ' ' . $kb_btn['emoji'];
                        } else {
                            $kb_btn['text'] = $kb_btn['emoji'] . ' ' . strip_leading_emoji($kb_btn['text']);
                        }
                    } else {
                        // no custom emoji: reposition the default text emoji per global setting
                        list($kb_def_emoji, $kb_def_rest) = split_leading_emoji($kb_btn['text']);
                        if ($kb_def_emoji !== '') {
                            $kb_btn['text'] = ($global_emoji_pos === 'left') ? $kb_def_rest . ' ' . $kb_def_emoji : $kb_def_emoji . ' ' . $kb_def_rest;
                        }
                    }
                    unset($kb_btn['style_reply'], $kb_btn['emoji'], $kb_btn['sticker'], $kb_btn['icon_emoji'], $kb_btn['hidden'], $kb_btn['custom_text'], $kb_btn['emoji_pos']);
                    $kb_newrow[] = $kb_btn;
                }
                if (!empty($kb_newrow)) {
                    $keyboardfiltered[] = $kb_newrow;
                }
            }
            $keyboardfiltered[] = $temp_addtional_key;
            $keyboard['inline_keyboard'] = $keyboardfiltered;
            $keyboard = json_encode($keyboard);
        } else {
            if ($admin_idss != 0) {
                $temp_addtional_key[] = ['text' => $textbotlang['Admin']['panelAdmin']];
            }
            if ($users['agent'] != "f") {
                $temp_addtional_key[] = ['text' => $textbotlang['textbot']['agentPanel']];
            }
            if ($users['agent'] == "f" && $setting['statusagentrequest'] == "onrequestagent") {
                $temp_addtional_key[] = ['text' => $textbotlang['textbot']['requestAgent']];
            }
            $keyboard = ['keyboard' => [], 'resize_keyboard' => true];
            $keyboardcustom = $keyboardRows;
            $keyboardcustom = json_decode(strtr(strval(json_encode($keyboardcustom)), $replacements), true);
            $keyboardfiltered = [];
            foreach ($keyboardcustom as $kb_r => $kb_row) {
                if (!is_array($kb_row)) {
                    continue;
                }
                $kb_newrow = [];
                foreach ($kb_row as $kb_c => $kb_btn) {
                    if (!is_array($kb_btn)) {
                        continue;
                    }
                    if (!empty($kb_btn['hidden'])) {
                        continue;
                    }
                    if (isset($kb_btn['style_reply'])) {
                        $kb_btn['style'] = $kb_btn['style_reply'];
                    } else {
                        unset($kb_btn['style']);
                    }
                    if (isset($kb_btn['custom_text']) && $kb_btn['custom_text'] !== '') {
                        $kb_btn['text'] = $kb_btn['custom_text'];
                    }
                    if ($simple_emoji_mode && empty($kb_btn['icon_emoji']) && empty($kb_btn['emoji'])) {
                        $kb_btn['text'] = strip_leading_emoji($kb_btn['text']);
                    }
                    if (isset($kb_btn['icon_emoji']) && $kb_btn['icon_emoji'] !== '') {
                        $kb_btn['icon_custom_emoji_id'] = $kb_btn['icon_emoji'];
                        $kb_btn['text'] = strip_leading_emoji($kb_btn['text']);
                    } elseif (isset($kb_btn['emoji']) && $kb_btn['emoji'] !== '') {
                        if ($global_emoji_pos === 'left') {
                            $kb_btn['text'] = strip_leading_emoji($kb_btn['text']) . ' ' . $kb_btn['emoji'];
                        } else {
                            $kb_btn['text'] = $kb_btn['emoji'] . ' ' . strip_leading_emoji($kb_btn['text']);
                        }
                    } else {
                        // no custom emoji: reposition the default text emoji per global setting
                        list($kb_def_emoji, $kb_def_rest) = split_leading_emoji($kb_btn['text']);
                        if ($kb_def_emoji !== '') {
                            $kb_btn['text'] = ($global_emoji_pos === 'left') ? $kb_def_rest . ' ' . $kb_def_emoji : $kb_def_emoji . ' ' . $kb_def_rest;
                        }
                    }
                    unset($kb_btn['style_reply'], $kb_btn['emoji'], $kb_btn['sticker'], $kb_btn['icon_emoji'], $kb_btn['hidden'], $kb_btn['custom_text'], $kb_btn['emoji_pos']);
                    $kb_newrow[] = $kb_btn;
                }
                if (!empty($kb_newrow)) {
                    $keyboardfiltered[] = $kb_newrow;
                }
            }
            $keyboardfiltered[] = $temp_addtional_key;
            $keyboard['keyboard'] = $keyboardfiltered;
            $keyboard = json_encode($keyboard);
        }
        return $keyboard;
    }
}
$keyboard = build_main_keyboard();

$keyboardPanel = json_encode([
    'inline_keyboard' => [
        [['text' => $textbotlang['textbot']['addBalance'], 'callback_data' => "Add_Balance"]],
        [['text' => $textbotlang['bottext']['btn_close'], 'callback_data' => "mmclose", 'style' => 'danger']],
    ],
    'resize_keyboard' => true
]);
if ($adminrulecheck['rule'] == "administrator") {
    $keyboardadmin = json_encode([
        'keyboard' => [
            [['text' => $textbotlang['Admin']['Status']['btn']]],
            [['text' => $textbotlang['Admin']['btnKeyboard']['managementPanel']], ['text' => $textbotlang['Admin']['btnKeyboard']['addPanel']]],
            [['text' => $textbotlang['Admin']['btnKeyboard']['manageUser']], ['text' => $textbotlang['keyboard']['shopSettings']]],
            [['text' => $textbotlang['keyboard']['supportSection']], ['text' => $textbotlang['keyboard']['educationSection']]],
            [['text' => $textbotlang['keyboard']['botReport']], ['text' => $textbotlang['keyboard']['panelFeatures']]],
            [['text' => $textbotlang['keyboard']['generalSettings']], ['text' => $textbotlang['keyboard']['pendingReceipts']]],
            [['text' => $textbotlang['bottext']['open_button']]],
            [['text' => $textbotlang['users']['backbtn']]]
        ],
        'resize_keyboard' => true
    ]);
}
if ($adminrulecheck['rule'] == "Seller") {
    $keyboardadmin = json_encode([
        'keyboard' => [
            [['text' => $textbotlang['Admin']['Status']['btn']]],
            [['text' => $textbotlang['keyboard']['manageUser']]],
            [['text' => $textbotlang['users']['backbtn']]]
        ],
        'resize_keyboard' => true
    ]);
}
if ($adminrulecheck['rule'] == "support") {
    $keyboardadmin = json_encode([
        'keyboard' => [
            [['text' => $textbotlang['keyboard']['manageUser']]],
            [['text' => $textbotlang['users']['backbtn']]]
        ],
        'resize_keyboard' => true
    ]);
}
$CartManage = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['setCardNumber']], ['text' => $textbotlang['keyboard']['deleteCardNumber']]],
        [['text' => $textbotlang['keyboard']['offlineGatewayPv']]],
        [['text' => $textbotlang['keyboard']['disableShowCard']], ['text' => $textbotlang['keyboard']['enableShowCard']]],
        [['text' => $textbotlang['keyboard']['groupShowCard']]],
        [['text' => $textbotlang['keyboard']['exportActiveCardUsers']]],
        [['text' => $textbotlang['keyboard']['showCartAfterFirstPay']]],
        [['text' => $textbotlang['keyboard']['setEducationCartToCart']]],
        [['text' => $textbotlang['keyboard']['autoConfirmNoCheck']]],
        [['text' => $textbotlang['keyboard']['excludeUserAutoConfirm']]],
        [['text' => $textbotlang['keyboard']['autoConfirmNoCheckTime']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$trnado = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['setEducationIranPay2']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$keyboardzarinpal = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['setEducationZarinPal']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$aqayepardakht = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['setEducationAqayePardakht']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$NowPaymentsManage = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['setEducationPlisio']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$setting_panel = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['featureStatus']]],
        [['text' => $textbotlang['keyboard']['botReports']], ['text' => $textbotlang['keyboard']['channelSettings']]],
        [['text' => $textbotlang['keyboard']['activateWebPanel']]],
        [['text' => $textbotlang['keyboard']['miniAppSettingsBtn']]],
        [['text' => $textbotlang['keyboard']['optimizeBot']]],
        [['text' => $textbotlang['keyboard']['adminSection']]],
        [['text' => $textbotlang['keyboard']['setTestAccountLimitAll']]],
        [['text' => $textbotlang['keyboard']['backupSettingsBtn']]],
        [['text' => $textbotlang['keyboard']['agentMembershipFee']], ['text' => $textbotlang['keyboard']['qrBackground']]],
        [['text' => $textbotlang['keyboard']['reWebhookAgentBots']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
//----------------[  color manage keyboard  ]----------------
$keyboard_colormanage = json_encode([
    'keyboard' => [
        [['text' => '🎛 رنگ دکمه‌های شیشه‌ای']],
        [['text' => '⌨️ رنگ دکمه‌های کیبوردی']],
        [['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
//----------------[  emoji & sticker keyboard  ]----------------
$keyboard_emojisticker = json_encode([
    'keyboard' => [
        [['text' => '😀 ایموجی دکمه‌ها']],
        [['text' => '✨ استیکر پریمیوم دکمه‌ها']],
        [['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
//----------------[  button settings keyboard  ]----------------
$keyboard_btnsettings = json_encode([
    'keyboard' => [
        [['text' => '🎨 رنگ‌بندی دکمه‌ها']],
        [['text' => '🎭 ایموجی و استیکر دکمه‌ها']],
        [['text' => '📐 چیدمان دکمه‌ها']],
        [['text' => '✏️ نام و نمایش دکمه‌ها']],
        [['text' => '🌐 تنظیمات تغییر زبان']],
        [['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$PaySettingcard = getPaySettingValue("Cartstatus");
$PaySettingnow = getPaySettingValue("nowpaymentstatus");
$PaySettingaqayepardakht = getPaySettingValue("statusaqayepardakht");
$PaySettingpv = pay_value("Cartstatuspv", $users['lang'] ?? null, 'offcardpv');
$usernamecart = getPaySettingValue("CartDirect");
$Swapino = getPaySettingValue("statusSwapWallet");
$trnadoo = getPaySettingValue("statustarnado");
$paymentverify = pay_value("checkpaycartfirst", $users['lang'] ?? null, 'offpayverify');
$stmt = $pdo->prepare("SELECT * FROM Payment_report WHERE id_user = :user_id AND payment_Status = 'paid' ");
$stmt->bindValue(':user_id', $from_id);
$stmt->execute();
$paymentexits = $stmt->rowCount();
$zarinpal = getPaySettingValue("zarinpalstatus");
$affilnecurrency = getPaySettingValue("digistatus");
$arzireyali3 = getPaySettingValue("statusiranpay3");
$paymentstatussnotverify = getPaySettingValue("paymentstatussnotverify");
$paymentsstartelegram = getPaySettingValue("statusstar");
$payment_status_nowpayment = getPaySettingValue("statusnowpayment");
$step_payment = [
    'inline_keyboard' => []
];
if ($PaySettingcard == "oncard" && intval($users['cardpayment']) == 1) {
    if ($PaySettingpv == "oncardpv") {
        $step_payment['inline_keyboard'][] = [
            ['text' => $textbotlang['textbot']['cartToCart'], 'url' => "https://t.me/$usernamecart"],
        ];
    } else {
        $step_payment['inline_keyboard'][] = [
            ['text' => $textbotlang['textbot']['cartToCart'], 'callback_data' => "cart_to_offline"],
        ];
    }
}
if (($paymentexits == 0 && $paymentverify == "onpayverify"))
    unset($step_payment['inline_keyboard']);
if ($PaySettingnow == "onnowpayment") {
    $step_payment['inline_keyboard'][] = [
        ['text' => $textbotlang['textbot']['nowPayment'], 'callback_data' => "plisio"]
    ];
}
if ($payment_status_nowpayment == "1") {
    $step_payment['inline_keyboard'][] = [
        ['text' => $textbotlang['textbot']['cryptoPayment'], 'callback_data' => "nowpayment"]
    ];
}
if ($affilnecurrency == "ondigi") {
    $step_payment['inline_keyboard'][] = [
        ['text' => $textbotlang['textbot']['nowPaymentTron'], 'callback_data' => "digitaltron"]
    ];
}
if ($Swapino == "onSwapinoBot") {
    $step_payment['inline_keyboard'][] = [
        ['text' => $textbotlang['textbot']['iranPay2'], 'callback_data' => "iranpay1"]
    ];
}
if ($trnadoo == "onternado") {
    $step_payment['inline_keyboard'][] = [
        ['text' => $textbotlang['textbot']['iranPay3'], 'callback_data' => "iranpay2"]
    ];
}
if ($arzireyali3 == "oniranpay3" && $paymentexits >= 2) {
    $step_payment['inline_keyboard'][] = [
        ['text' => $textbotlang['textbot']['iranPay1'], 'callback_data' => "iranpay3"]
    ];
}
if ($PaySettingaqayepardakht == "onaqayepardakht") {
    $step_payment['inline_keyboard'][] = [
        ['text' => $textbotlang['textbot']['aqayePardakht'], 'callback_data' => "aqayepardakht"]
    ];
}
if ($zarinpal == "onzarinpal") {
    $step_payment['inline_keyboard'][] = [
        ['text' => $textbotlang['textbot']['zarinPal'], 'callback_data' => "zarinpal"]
    ];
}
if ($paymentstatussnotverify == "onverifypay") {
    $step_payment['inline_keyboard'][] = [
        ['text' => $textbotlang['textbot']['paymentNotVerify'], 'callback_data' => "paymentnotverify"]
    ];
}
if (intval($paymentsstartelegram) == 1) {
    $step_payment['inline_keyboard'][] = [
        ['text' => $textbotlang['textbot']['starTelegram'], 'callback_data' => "startelegrams"]
    ];
}
// keep only the gateways this user's language is allowed to see (set in
// 💎 Financial -> gateways per language); an unrestricted language keeps all
$step_payment['inline_keyboard'] = gateway_filter_rows(
    $step_payment['inline_keyboard'] ?? [],
    $users['lang'] ?? 'fa',
    $textbotlang['textbot']['cartToCart'] ?? null
);
// per-language button styling (order/width/emoji/color/rename), set via
// 🎨 شخصی‌سازی نمایش دکمه‌ها -> 💳 روش‌های پرداخت
$step_payment['inline_keyboard'] = gateway_apply_button_style(
    $step_payment['inline_keyboard'],
    $users['lang'] ?? 'fa',
    $textbotlang['textbot']['cartToCart'] ?? null
);
// If the routing left nothing, telling the buyer to "pick a method below" is
// worse than useless - every call site uses $noCreditText so they all say the
// honest thing instead.
$step_payment_none = empty($step_payment['inline_keyboard']);
$noCreditText = $step_payment_none
    ? $textbotlang['users']['sell']['noPaymentMethod']
    : $textbotlang['users']['sell']['noCredit'];
$step_payment['inline_keyboard'][] = [
    ['text' => $textbotlang['keyboard']['closeList'], 'callback_data' => "colselist", 'style' => 'danger']
];
$step_payment = json_encode($step_payment);
$keyboardhelpadmin = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['addEducation']], ['text' => $textbotlang['keyboard']['deleteEducation']]],
        [['text' => $textbotlang['keyboard']['editEducation']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$shopkeyboard = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['shopFeatureStatus']]],
        [['text' => $textbotlang['keyboard']['manageProducts']], ['text' => $textbotlang['keyboard']['manageCategory']]],
        [['text' => $textbotlang['keyboard']['financial']], ['text' => $textbotlang['Admin']['LangScope']['hubBtn']]],
        [['text' => $textbotlang['keyboard']['topupPackages']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$keyboard_Category_manage = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['addCategory']], ['text' => $textbotlang['keyboard']['deleteCategory']]],
        [['text' => $textbotlang['keyboard']['editCategoryMenu']]],
        [['text' => $textbotlang['keyboard']['backToShopMenu']]]
    ],
    'resize_keyboard' => true
]);
$keyboard_shop_manage = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['addProduct']], ['text' => $textbotlang['Admin']['BulkProduct']['btn']]],
        [['text' => $textbotlang['keyboard']['editProduct']], ['text' => $textbotlang['keyboard']['deleteProduct']]],
        [['text' => $textbotlang['keyboard']['increaseGroupPrice']], ['text' => $textbotlang['keyboard']['decreaseGroupPrice']]],
        [['text' => $textbotlang['Admin']['MoveProd']['hubBtn']]],
        [['text' => $textbotlang['keyboard']['backToShopMenu']]]
    ],
    'resize_keyboard' => true
]);
// the single add-product flow gets the same skip affordance the bulk flow has
$addproduct_note_keyboard = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['Admin']['BulkProduct']['skipNoteBtn']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$bulkproduct_note_keyboard = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['Admin']['BulkProduct']['skipNoteBtn']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
if ($setting['inlinebtnmain'] == "oninline") {
    $confrimrolls = json_encode([
        'inline_keyboard' => [
            [
                ['text' => $textbotlang['keyboard']['acceptRules'], 'callback_data' => "acceptrule"],
            ],
        ]
    ]);
} else {
    $confrimrolls = json_encode([
        'keyboard' => [
            [['text' => $textbotlang['keyboard']['acceptRules']]],
        ],
        'resize_keyboard' => true
    ]);
}
$request_contact = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['sendPhoneNumber'], 'request_contact' => true]],
        [['text' => $textbotlang['users']['backbtn']]]
    ],
    'resize_keyboard' => true
]);
$Feature_status = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['viewAccountInfoFeature']]],
        [['text' => $textbotlang['keyboard']['testAccountFeature']], ['text' => $textbotlang['keyboard']['educationFeature']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$channelkeyboard = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['addChannel']], ['text' => $textbotlang['keyboard']['deleteChannel']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
if ($setting['inlinebtnmain'] == "oninline") {
    $backuser = json_encode([
        'inline_keyboard' => [
            [['text' => $textbotlang['users']['backbtn'], 'callback_data' => "backuser"]]
        ],
    ]);
} else {
    $backuser = json_encode([
        'keyboard' => [
            [['text' => $textbotlang['users']['backbtn']]]
        ],
        'resize_keyboard' => true,
    ]);
}
$backadmin = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true,
]);
// purchase flow: pick a custom service name, use a bot-generated random one, or
// cancel back to the plan list - always inline regardless of the main-menu
// glass/reply toggle, same as every other purchase-flow action button
$selectUsernameKb = sell_selectUsername_kb($user['lang'] ?? 'fa', $textbotlang);
//------------------  [ list panel ]----------------//
$stmt = $pdo->prepare("SHOW TABLES LIKE 'marzban_panel'");
$stmt->execute();
$result = $stmt->fetchAll();
$table_exists = count($result) > 0;
$namepanel = [];
if ($table_exists) {
    $stmt = $pdo->prepare("SELECT * FROM marzban_panel");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $namepanel[] = [$row['name_panel']];
    }
    $list_marzban_panel = [
        'keyboard' => [],
        'resize_keyboard' => true,
    ];
    foreach ($namepanel as $button) {
        $list_marzban_panel['keyboard'][] = [
            ['text' => $button[0]]
        ];
    }
    $list_marzban_panel['keyboard'][] = [
        ['text' => $textbotlang['Admin']['backAdminBtn']],
        ['text' => $textbotlang['Admin']['backMenuBtn']]
    ];
    $json_list_marzban_panel = json_encode($list_marzban_panel);
    //------------------  [ list panel inline ]----------------//
    $stmt = $pdo->prepare("SELECT * FROM marzban_panel");
    $stmt->execute();
    $list_marzban_panel_edit_product = ['inline_keyboard' => []];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $list_marzban_panel_edit_product['inline_keyboard'][] = [['text' => $row['name_panel'], 'callback_data' => 'locationedit_' . $row['code_panel']]];
    }
    $list_marzban_panel_edit_product['inline_keyboard'][] = [['text' => $textbotlang['keyboard']['allPanels'], 'callback_data' => 'locationedit_all']];
    $list_marzban_panel_edit_product['inline_keyboard'][] = [['text' => $textbotlang['keyboard']['backToPreviousMenu'], 'callback_data' => 'backproductadmin']];
    $list_marzban_panel_edit_product = json_encode($list_marzban_panel_edit_product);
}
//------------------  [ list channel ]----------------//
$stmt = $pdo->prepare("SHOW TABLES LIKE 'channels'");
$stmt->execute();
$result = $stmt->fetchAll();
$table_exists = count($result) > 0;
$list_channels = [];
if ($table_exists) {
    $stmt = $pdo->prepare("SELECT * FROM channels");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $list_channels[] = [$row['link']];
    }
    $list_channels_join = [
        'keyboard' => [],
        'resize_keyboard' => true,
    ];
    foreach ($list_channels as $button) {
        $list_channels_join['keyboard'][] = [
            ['text' => $button[0]]
        ];
    }
    $list_channels_join['keyboard'][] = [
        ['text' => $textbotlang['Admin']['backAdminBtn']],
        ['text' => $textbotlang['Admin']['backMenuBtn']]
    ];
    $list_channels_joins = json_encode($list_channels_join);
}
//------------------  [ list card ]----------------//
$stmt = $pdo->prepare("SHOW TABLES LIKE 'card_number'");
$stmt->execute();
$result = $stmt->fetchAll();
$table_exists = count($result) > 0;
$list_card = [];
if ($table_exists) {
    $stmt = $pdo->prepare("SELECT * FROM card_number");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $list_card[] = [$row['cardnumber']];
    }
    $list_card_remove = [
        'keyboard' => [],
        'resize_keyboard' => true,
    ];
    foreach ($list_card as $button) {
        $list_card_remove['keyboard'][] = [
            ['text' => $button[0]]
        ];
    }
    $list_card_remove['keyboard'][] = [
        ['text' => $textbotlang['Admin']['backAdminBtn']],
        ['text' => $textbotlang['Admin']['backMenuBtn']]
    ];
    $list_card_remove = json_encode($list_card_remove);
}
//------------------  [ help list ]----------------//
$stmt = $pdo->prepare("SHOW TABLES LIKE 'help'");
$stmt->execute();
$result = $stmt->fetchAll();
$table_exists = count($result) > 0;
if ($table_exists) {
    $stmt = $pdo->prepare("SELECT * FROM help");
    $stmt->execute();
    $helpkey = [];
    $stmt = $pdo->prepare("SELECT * FROM help");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $helpkey[] = [$row['name_os']];
    }
    $help_arrke = [
        'keyboard' => [],
        'resize_keyboard' => true,
    ];
    foreach ($helpkey as $button) {
        $help_arrke['keyboard'][] = [
            ['text' => $button[0]]
        ];
    }
    $help_arrke['keyboard'][] = [
        ['text' => $textbotlang['users']['backbtn']],
    ];
    $json_list_helpkey = json_encode($help_arrke);
}
//------------------  [ help list ]----------------//
$stmt = $pdo->prepare("SELECT * FROM help");
$stmt->execute();
$helpcwtgory = ['inline_keyboard' => []];
$datahelp = [];
$help_cat_disp_lang = $users['lang'] ?? 'fa';
$help_cat_buttons_byfa = [];
while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (in_array($result['category'], $datahelp))
        continue;
    if ($result['category'] == null)
        continue;
    $datahelp[] = $result['category'];
    $help_cat_view = help_resolve_lang($result, $help_cat_disp_lang);
    $help_cat_label = ($help_cat_view['category'] !== '') ? $help_cat_view['category'] : $result['category'];
    $help_cat_buttons_byfa[$result['category']] = ['text' => $help_cat_label, 'callback_data' => "helpctgoryـ{$result['category']}"];
}
// apply the admin's per-language order/width/emoji (📐 چیدمان / 🎭 ایموجی
// under 🎨 نمایش دسته‌بندی و آموزش‌ها), keyed by the base (fa) category
// string — see help_layout_items()'s docblock for why
$help_cat_section = help_layout_section($help_cat_disp_lang, 'categories');
$help_cat_ordered = help_layout_apply_order(array_keys($help_cat_buttons_byfa), $help_cat_section['order']);
foreach ($help_cat_ordered as $help_cat_fa_key) {
    $help_cat_emoji = $help_cat_section['emoji'][$help_cat_fa_key] ?? '';
    if ($help_cat_emoji !== '') {
        $help_cat_buttons_byfa[$help_cat_fa_key]['text'] = $help_cat_emoji . ' ' . $help_cat_buttons_byfa[$help_cat_fa_key]['text'];
    }
    $help_cat_color = $help_cat_section['color'][$help_cat_fa_key] ?? '';
    if ($help_cat_color !== '' && in_array($help_cat_color, ['primary', 'success', 'danger'], true)) {
        $help_cat_buttons_byfa[$help_cat_fa_key]['style'] = $help_cat_color;
    }
}
$helpcwtgory['inline_keyboard'] = array_merge($helpcwtgory['inline_keyboard'], help_layout_chunk_rows($help_cat_ordered, $help_cat_buttons_byfa, $help_cat_section['width']));
if ($setting['linkappstatus'] == "1") {
    $helpcwtgory['inline_keyboard'][] = [
        ['text' => $textbotlang['keyboard']['appDownloadLink'], 'callback_data' => "linkappdownlod"],
    ];
}
$helpcwtgory['inline_keyboard'][] = [
    ['text' => $textbotlang['bottext']['btn_close'], 'callback_data' => "mmclose", 'style' => 'danger'],
];
$json_list_helpـcategory = json_encode($helpcwtgory);


//------------------  [ help app ]----------------//
$stmt = $pdo->prepare("SELECT * FROM app");
$stmt->execute();
$helpapp = ['inline_keyboard' => []];
while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $helpapp['inline_keyboard'][] = [
        ['text' => $result['name'], 'url' => $result['link']]
    ];
}
$helpapp['inline_keyboard'][] = [
    ['text' => $textbotlang['users']['backbtn'], 'callback_data' => "backuser"],
];
$json_list_helpـlink = json_encode($helpapp);
//------------------  [ help app admin ]----------------//
$stmt = $pdo->prepare("SELECT * FROM app");
$stmt->execute();
$helpappremove = ['keyboard' => [], 'resize_keyboard' => true];
while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $helpappremove['keyboard'][] = [
        ['text' => $result['name']],
    ];
}
$helpappremove['keyboard'][] = [
    ['text' => $textbotlang['Admin']['backAdminBtn']],
];
$json_list_remove_helpـlink = json_encode($helpappremove);
//------------------  [ listpanelusers ]----------------//
$stmt = $pdo->prepare("SELECT * FROM marzban_panel WHERE status = 'active' AND (agent = :agent OR agent = 'all') AND (FIND_IN_SET(:userlang, lang) OR lang = 'all' OR lang IS NULL OR lang = '')");
$stmt->bindParam(':agent', $users['agent']);
$stmt->bindValue(':userlang', $users['lang'] ?? 'fa', PDO::PARAM_STR);
$stmt->execute();
$list_marzban_panel_users = ['inline_keyboard' => []];
$panelcount = select("marzban_panel", "*", "status", "active", "count");
// per-language button styling (order/width/emoji/color/rename), set via
// 🎨 شخصی‌سازی نمایش دکمه‌ها -> پنل‌ها in the admin panel
$lp_userlang = $users['lang'] ?? 'fa';
$lp_section = help_layout_section($lp_userlang, 'panel');
$lp_cbprefix = ($users['step'] == "getusernameinfo") ? "locationnotuser_" : "location_";
$lp_buttons = [];
while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($result['hide_user'] != null && in_array($from_id, json_decode($result['hide_user'], true)))
        continue;
    if ($result['type'] == "Manualsale") {
        $stmts = $pdo->prepare("SELECT * FROM manualsell WHERE codepanel = :codepanel AND status = 'active'");
        $stmts->bindParam(':codepanel', $result['code_panel']);
        $stmts->execute();
        $configexits = $stmts->rowCount();
        if (intval($configexits) == 0)
            continue;
    }
    $lp_key = (string) $result['code_panel'];
    $lp_name = $lp_section['rename'][$lp_key] ?? $result['name_panel'];
    $lp_emo = help_layout_emoji_prefix($lp_key, $lp_section);
    $lp_name = $lp_emo['prefix'] . $lp_name;
    $lp_btn = ['text' => $lp_name, 'callback_data' => "{$lp_cbprefix}{$result['code_panel']}"];
    if ($lp_emo['icon'] !== '') {
        $lp_btn['icon_custom_emoji_id'] = $lp_emo['icon'];
    }
    $lp_color = $lp_section['color'][$lp_key] ?? '';
    if ($lp_color !== '' && in_array($lp_color, ['primary', 'success', 'danger'], true)) {
        $lp_btn['style'] = $lp_color;
    }
    $lp_buttons[$lp_key] = $lp_btn;
}
$lp_ordered = help_layout_apply_order(array_keys($lp_buttons), $lp_section['order']);
$list_marzban_panel_users['inline_keyboard'] = array_merge($list_marzban_panel_users['inline_keyboard'], help_layout_chunk_rows($lp_ordered, $lp_buttons, $lp_section['width']));
$statusnote = false;
if ($setting['statusnamecustom'] == 'onnamecustom')
    $statusnote = true;
if ($setting['statusnoteforf'] == "0" && $users['agent'] == "f")
    $statusnote = false;
$list_marzban_panel_users['inline_keyboard'][] = [
    ['text' => $textbotlang['bottext']['btn_close'], 'callback_data' => "sellclose", 'style' => 'danger'],
];
$list_marzban_panel_user = json_encode($list_marzban_panel_users);


//------------------  [ listpanelusers omdhe ]----------------//
$stmt = $pdo->prepare("SELECT * FROM marzban_panel WHERE status = 'active' AND (agent = :agent OR agent = 'all') AND (FIND_IN_SET(:userlang, lang) OR lang = 'all' OR lang IS NULL OR lang = '')");
$stmt->bindParam(':agent', $users['agent']);
$stmt->bindValue(':userlang', $users['lang'] ?? 'fa', PDO::PARAM_STR);
$stmt->execute();
$list_marzban_panel_users_om = ['inline_keyboard' => []];
while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($result['hide_user'] != null and in_array($from_id, json_decode($result['hide_user'], true)))
        continue;
    $list_marzban_panel_users_om['inline_keyboard'][] = [
        ['text' => $result['name_panel'], 'callback_data' => "locationom_{$result['code_panel']}"]
    ];
}
$list_marzban_panel_users_om['inline_keyboard'][] = [
    ['text' => $textbotlang['users']['backbtn'], 'callback_data' => "backuser"],
];
$list_marzban_panel_userom = json_encode($list_marzban_panel_users_om);

//------------------  [ change location ]----------------//
$stmt = $pdo->prepare("SELECT * FROM marzban_panel WHERE status = 'active' AND (agent = :agent OR agent = 'all') AND name_panel != :name_panel AND (FIND_IN_SET(:userlang, lang) OR lang = 'all' OR lang IS NULL OR lang = '')");
$stmt->bindValue(':name_panel', $users['Processing_value_four'], PDO::PARAM_STR);
$stmt->bindValue(':agent', $users['agent'], PDO::PARAM_STR);
$stmt->bindValue(':userlang', $users['lang'] ?? 'fa', PDO::PARAM_STR);
$stmt->execute();
$list_marzban_panel_users_change = ['inline_keyboard' => []];
$panelcount = select("marzban_panel", "*", "status", "active", "count");
if ($panelcount > 10) {
    $temp_row = [];
    while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($result['hide_user'] != null && in_array($from_id, json_decode($result['hide_user'], true)))
            continue;

        $temp_row[] = ['text' => $result['name_panel'], 'callback_data' => "changelocselectlo-{$result['code_panel']}"];
        if (count($temp_row) == 2) {
            $list_marzban_panel_users_change['inline_keyboard'][] = $temp_row;
            $temp_row = [];
        }
    }
    if (!empty($temp_row)) {
        $list_marzban_panel_users_change['inline_keyboard'][] = $temp_row;
    }
} else {
    while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($result['hide_user'] != null and in_array($from_id, json_decode($result['hide_user'], true)))
            continue;
        $list_marzban_panel_users_change['inline_keyboard'][] = [
            ['text' => $result['name_panel'], 'callback_data' => "changelocselectlo-{$result['code_panel']}"]
        ];
    }
}
$list_marzban_panel_users_change['inline_keyboard'][] = [
    ['text' => $textbotlang['users']['backbtn'], 'callback_data' => "backorder"],
];
$list_marzban_panel_userschange = json_encode($list_marzban_panel_users_change);


//------------------  [ listpanelusers test ]----------------//
$stmt = $pdo->prepare("SELECT * FROM marzban_panel WHERE TestAccount = 'ONTestAccount' AND (agent = :agent OR agent = 'all')");
$stmt->bindValue(':agent', $users['agent'], PDO::PARAM_STR);
$stmt->execute();
$list_marzban_panel_usertest = ['inline_keyboard' => []];
while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($result['hide_user'] != null and in_array($from_id, json_decode($result['hide_user'], true)))
        continue;
    $list_marzban_panel_usertest['inline_keyboard'][] = [
        ['text' => $result['name_panel'], 'callback_data' => "locationtest_{$result['code_panel']}"]
    ];
}
$list_marzban_panel_usertest['inline_keyboard'][] = [
    ['text' => $textbotlang['bottext']['btn_close'], 'callback_data' => "mmclose", 'style' => 'danger'],
];
$list_marzban_usertest = json_encode($list_marzban_panel_usertest);


//--------------------------------------------------
$stmt = $pdo->prepare("SHOW TABLES LIKE 'protocol'");
$stmt->execute();
$result = $stmt->fetchAll();
$table_exists = count($result) > 0;
if ($table_exists) {
    $getdataprotocol = select("protocol", "*", null, null, "fetchAll");
    $protocol = [];
    foreach ($getdataprotocol as $result) {
        $protocol[] = [['text' => $result['NameProtocol']]];
    }
    $protocol[] = [['text' => $textbotlang['Admin']['backAdminBtn']]];
    $keyboardprotocollist = json_encode(['resize_keyboard' => true, 'keyboard' => $protocol]);
}
//--------------------------------------------------
$stmt = $pdo->prepare("SHOW TABLES LIKE 'product'");
$stmt->execute();
$result = $stmt->fetchAll();
$table_exists = count($result) > 0;
if ($table_exists) {
    $product = [];
    $stmt = $pdo->prepare("SELECT * FROM product WHERE Location = :text or Location = '/all' ");
    $stmt->bindParam(':text', $text, PDO::PARAM_STR);
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $product[] = [$row['name_product']];
    }
    $list_product = [
        'keyboard' => [],
        'resize_keyboard' => true,
    ];
    $list_product['keyboard'][] = [
        ['text' => $textbotlang['Admin']['backAdminBtn']],
    ];
    foreach ($product as $button) {
        $list_product['keyboard'][] = [
            ['text' => $button[0]]
        ];
    }
    $json_list_product_list_admin = json_encode($list_product);
}
//--------------------------------------------------
$stmt = $pdo->prepare("SHOW TABLES LIKE 'Discount'");
$stmt->execute();
$result = $stmt->fetchAll();
$table_exists = count($result) > 0;
if ($table_exists) {
    $Discount = [];
    $stmt = $pdo->prepare("SELECT * FROM Discount");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $Discount[] = [$row['code']];
    }
    $list_Discount = [
        'keyboard' => [],
        'resize_keyboard' => true,
    ];
    $list_Discount['keyboard'][] = [
        ['text' => $textbotlang['Admin']['backAdminBtn']],
    ];
    foreach ($Discount as $button) {
        $list_Discount['keyboard'][] = [
            ['text' => $button[0]]
        ];
    }
    $json_list_Discount_list_admin = json_encode($list_Discount);
}
//--------------------------------------------------
$stmt = $pdo->prepare("SHOW TABLES LIKE 'Inbound'");
$stmt->execute();
$result = $stmt->fetchAll();
$table_exists = count($result) > 0;
if ($table_exists) {
    $Inboundkeyboard = [];
    $stmt = $pdo->prepare("SELECT * FROM Inbound WHERE location = :Processing_value AND protocol = :text");
    $stmt->bindParam(':text', $text, PDO::PARAM_STR);
    $stmt->bindParam(':Processing_value', $users['Processing_value'], PDO::PARAM_STR);
    $stmt->execute();
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $Inboundkeyboard[] = [$row['NameInbound']];
        }

    }
    $list_Inbound = [
        'keyboard' => [],
        'resize_keyboard' => true,
    ];
    foreach ($Inboundkeyboard as $button) {
        $list_Inbound['keyboard'][] = [
            ['text' => $button[0]]
        ];
    }
    $list_Inbound['keyboard'][] = [
        ['text' => $textbotlang['Admin']['backAdminBtn']],
    ];
    $json_list_Inbound_list_admin = json_encode($list_Inbound);
}
//--------------------------------------------------
$stmt = $pdo->prepare("SHOW TABLES LIKE 'DiscountSell'");
$stmt->execute();
$result = $stmt->fetchAll();
$table_exists = count($result) > 0;
if ($table_exists) {
    $DiscountSell = [];
    $stmt = $pdo->prepare("SELECT * FROM DiscountSell");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $DiscountSell[] = [$row['codeDiscount']];
    }
    $list_Discountsell = [
        'keyboard' => [],
        'resize_keyboard' => true,
    ];
    $list_Discountsell['keyboard'][] = [
        ['text' => $textbotlang['Admin']['backAdminBtn']],
    ];
    foreach ($DiscountSell as $button) {
        $list_Discountsell['keyboard'][] = [
            ['text' => $button[0]]
        ];
    }
    $json_list_Discount_list_admin_sell = json_encode($list_Discountsell);
}
$payment = sell_confirm_kb($user['lang'] ?? 'fa', $textbotlang, "confirmandgetservice");
$paymentom = sell_confirm_kb($user['lang'] ?? 'fa', $textbotlang, "confirmandgetservice");
$change_product = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['price']], ['text' => $textbotlang['keyboard']['volume']], ['text' => $textbotlang['keyboard']['time']]],
        [['text' => $textbotlang['keyboard']['productName']], ['text' => $textbotlang['keyboard']['userType']]],
        [['text' => $textbotlang['keyboard']['volumeResetType']], ['text' => $textbotlang['keyboard']['note']]],
        [['text' => $textbotlang['keyboard']['productLocation']], ['text' => $textbotlang['keyboard']['category']]],
        [['text' => $textbotlang['keyboard']['setInbound']], ['text' => $textbotlang['keyboard']['showFirstPurchase']]],
        [['text' => $textbotlang['keyboard']['hidePanel']], ['text' => $textbotlang['keyboard']['deleteAllHiddenPanels']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);

$keyboardprotocol = json_encode([
    'keyboard' => [
        [['text' => "vless"], ['text' => "vmess"], ['text' => "trojan"]],
        [['text' => "shadowsocks"]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$MethodUsername = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['usernameSequential']]],
        [['text' => $textbotlang['keyboard']['numericIdRandom']]],
        [['text' => $textbotlang['keyboard']['customUsername']]],
        [['text' => $textbotlang['keyboard']['customUsernameRandom']]],
        [['text' => $textbotlang['keyboard']['customTextRandom']]],
        [['text' => $textbotlang['keyboard']['customTextSequential']]],
        [['text' => $textbotlang['keyboard']['numericIdSequential']]],
        [['text' => $textbotlang['keyboard']['agentCustomTextSequential']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
if (!function_exists('panel_menu_groups')) {
    // Themed grouping for the panel-settings menu. The membership lists below
    // are a superset across every panel type; which of them actually appear is
    // decided per type by panel_menu_split(), reading the type's own existing
    // flat keyboard. That way a button can never silently disappear when a
    // panel type has an unusual button set - see the "other" bucket.
    function panel_menu_groups($textbotlang)
    {
        $k = $textbotlang['keyboard'];
        $g = function ($keys) use ($k) {
            $o = [];
            foreach ($keys as $key) {
                $v = $k[$key] ?? '';
                if ($v !== '') {
                    $o[] = $v;
                }
            }
            return $o;
        };
        return [
            'connection' => [
                'label' => $textbotlang['Admin']['PanelMenu']['connectionBtn'],
                'items' => $g(['panelName', 'editPanelUrl', 'editUsername', 'editPassword', 'subLinkDomain', 'panelSetting', 'duplicatePanel', 'deletePanel']),
            ],
            'account' => [
                'label' => $textbotlang['Admin']['PanelMenu']['accountBtn'],
                'items' => $g(['usernameMethod', 'renewalMethod', 'accountCreateLimit', 'changeUserGroup', 'setProtocolInbound', 'setInboundId', 'setGroupName', 'serviceSettings', 'inboundDeactivate', 'addConfig', 'editConfig', 'deleteConfig']),
            ],
            'pricing' => [
                'label' => $textbotlang['Admin']['PanelMenu']['pricingBtn'],
                'items' => $g(['customVolumePrice', 'customTimePrice', 'extraVolumePrice', 'extraTimePrice', 'changeLocationPrice', 'minCustomVolume', 'maxCustomVolume', 'minCustomTime', 'maxCustomTime']),
            ],
            'test' => [
                'label' => $textbotlang['Admin']['PanelMenu']['testBtn'],
                'items' => $g(['testServiceTime', 'testAccountVolume', 'testDeleteTime']),
            ],
            'visibility' => [
                'label' => $textbotlang['Admin']['PanelMenu']['visibilityBtn'],
                'items' => $g(['hidePanelForUser', 'removeFromHiddenList']),
            ],
        ];
    }
}
if (!function_exists('panel_menu_split')) {
    function panel_menu_split($flatJson, $textbotlang)
    {
        $kb = json_decode($flatJson, true);
        $labels = [];
        foreach (($kb['keyboard'] ?? []) as $row) {
            foreach ($row as $b) {
                if (!empty($b['text'])) {
                    $labels[] = $b['text'];
                }
            }
        }
        // these keep their place on the top level and are not grouped
        $pinned = [
            $textbotlang['keyboard']['panelFeatureStatus'],
            $textbotlang['Admin']['backAdminBtn'],
            $textbotlang['Admin']['backMenuBtn'],
        ];
        $labels = array_values(array_diff($labels, $pinned));
        $out = [];
        foreach (panel_menu_groups($textbotlang) as $id => $grp) {
            $picked = [];
            foreach ($grp['items'] as $item) {
                if (in_array($item, $labels, true)) {
                    $picked[] = $item;
                    $labels = array_values(array_diff($labels, [$item]));
                }
            }
            if (!empty($picked)) {
                $out[$id] = ['label' => $grp['label'], 'items' => $picked];
            }
        }
        // anything this panel type has that no group claimed
        if (!empty($labels)) {
            $out['other'] = [
                'label' => $textbotlang['Admin']['PanelMenu']['otherBtn'],
                'items' => array_values($labels),
            ];
        }
        return $out;
    }
}
if (!function_exists('panel_menu_group_labels')) {
    function panel_menu_group_labels($textbotlang)
    {
        $p = $textbotlang['Admin']['PanelMenu'];
        return [$p['connectionBtn'], $p['accountBtn'], $p['pricingBtn'], $p['testBtn'], $p['visibilityBtn'], $p['otherBtn']];
    }
}
if (!function_exists('panel_menu_rows')) {
    function panel_menu_rows($items)
    {
        $rows = [];
        $pair = [];
        foreach ($items as $it) {
            $pair[] = ['text' => $it];
            if (count($pair) === 2) {
                $rows[] = $pair;
                $pair = [];
            }
        }
        if (!empty($pair)) {
            $rows[] = $pair;
        }
        return $rows;
    }
}
if (!function_exists('panel_menu_top_json')) {
    function panel_menu_top_json($flatJson, $textbotlang)
    {
        $kb = json_decode($flatJson, true);
        $all = [];
        foreach (($kb['keyboard'] ?? []) as $row) {
            foreach ($row as $b) {
                if (!empty($b['text'])) {
                    $all[] = $b['text'];
                }
            }
        }
        $rows = [];
        if (in_array($textbotlang['keyboard']['panelFeatureStatus'], $all, true)) {
            $rows[] = [['text' => $textbotlang['keyboard']['panelFeatureStatus']]];
        }
        $labels = [];
        foreach (panel_menu_split($flatJson, $textbotlang) as $grp) {
            $labels[] = $grp['label'];
        }
        foreach (panel_menu_rows($labels) as $r) {
            $rows[] = $r;
        }
        $back = [];
        if (in_array($textbotlang['Admin']['backAdminBtn'], $all, true)) {
            $back[] = ['text' => $textbotlang['Admin']['backAdminBtn']];
        }
        if (in_array($textbotlang['Admin']['backMenuBtn'], $all, true)) {
            $back[] = ['text' => $textbotlang['Admin']['backMenuBtn']];
        }
        if (!empty($back)) {
            $rows[] = $back;
        }
        return json_encode(['keyboard' => $rows, 'resize_keyboard' => true]);
    }
}
if (!function_exists('panel_menu_submenu_json')) {
    function panel_menu_submenu_json($flatJson, $groupLabel, $textbotlang)
    {
        foreach (panel_menu_split($flatJson, $textbotlang) as $grp) {
            if ($grp['label'] !== $groupLabel) {
                continue;
            }
            $rows = panel_menu_rows($grp['items']);
            $rows[] = [['text' => $textbotlang['Admin']['PanelMenu']['backToPanelBtn']]];
            return json_encode(['keyboard' => $rows, 'resize_keyboard' => true]);
        }
        return null;
    }
}
$optionMarzban = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['panelFeatureStatus']]],
        [['text' => $textbotlang['keyboard']['panelName']], ['text' => $textbotlang['keyboard']['deletePanel']]],
        [['text' => $textbotlang['keyboard']['duplicatePanel']]],
        [['text' => $textbotlang['keyboard']['editPassword']], ['text' => $textbotlang['keyboard']['editUsername']]],
        [['text' => $textbotlang['keyboard']['editPanelUrl']], ['text' => $textbotlang['keyboard']['setProtocolInbound']]],
        [['text' => $textbotlang['keyboard']['renewalMethod']], ['text' => $textbotlang['keyboard']['usernameMethod']]],
        [['text' => $textbotlang['keyboard']['accountCreateLimit']], ['text' => $textbotlang['keyboard']['changeUserGroup']]],
        [['text' => $textbotlang['keyboard']['testServiceTime']], ['text' => $textbotlang['keyboard']['testAccountVolume']]],
        [['text' => $textbotlang['keyboard']['testDeleteTime']]],
        [['text' => $textbotlang['keyboard']['customVolumePrice']], ['text' => $textbotlang['keyboard']['extraVolumePrice']]],
        [['text' => $textbotlang['keyboard']['extraTimePrice']], ['text' => $textbotlang['keyboard']['customTimePrice']]],
        [['text' => $textbotlang['keyboard']['changeLocationPrice']]],
        [['text' => $textbotlang['keyboard']['minCustomVolume']], ['text' => $textbotlang['keyboard']['maxCustomVolume']]],
        [['text' => $textbotlang['keyboard']['minCustomTime']], ['text' => $textbotlang['keyboard']['maxCustomTime']]],
        [['text' => $textbotlang['keyboard']['inboundDeactivate']]],
        [['text' => $textbotlang['keyboard']['hidePanelForUser']]],
        [['text' => $textbotlang['keyboard']['removeFromHiddenList']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$optionrebecca = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['panelFeatureStatus']]],
        [['text' => $textbotlang['keyboard']['panelName']], ['text' => $textbotlang['keyboard']['deletePanel']]],
        [['text' => $textbotlang['keyboard']['duplicatePanel']]],
        [['text' => $textbotlang['keyboard']['editPassword']]],
        [['text' => $textbotlang['keyboard']['editPanelUrl']], ['text' => $textbotlang['keyboard']['setProtocolInbound']]],
        [['text' => $textbotlang['keyboard']['renewalMethod']], ['text' => $textbotlang['keyboard']['usernameMethod']]],
        [['text' => $textbotlang['keyboard']['accountCreateLimit']], ['text' => $textbotlang['keyboard']['changeUserGroup']]],
        [['text' => $textbotlang['keyboard']['testServiceTime']], ['text' => $textbotlang['keyboard']['testAccountVolume']]],
        [['text' => $textbotlang['keyboard']['testDeleteTime']]],
        [['text' => $textbotlang['keyboard']['customVolumePrice']], ['text' => $textbotlang['keyboard']['extraVolumePrice']]],
        [['text' => $textbotlang['keyboard']['extraTimePrice']], ['text' => $textbotlang['keyboard']['customTimePrice']]],
        [['text' => $textbotlang['keyboard']['changeLocationPrice']]],
        [['text' => $textbotlang['keyboard']['minCustomVolume']], ['text' => $textbotlang['keyboard']['maxCustomVolume']]],
        [['text' => $textbotlang['keyboard']['minCustomTime']], ['text' => $textbotlang['keyboard']['maxCustomTime']]],
        [['text' => $textbotlang['keyboard']['inboundDeactivate']]],
        [['text' => $textbotlang['keyboard']['hidePanelForUser']]],
        [['text' => $textbotlang['keyboard']['removeFromHiddenList']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$optionibsng = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['panelFeatureStatus']]],
        [['text' => $textbotlang['keyboard']['panelName']], ['text' => $textbotlang['keyboard']['deletePanel']]],
        [['text' => $textbotlang['keyboard']['duplicatePanel']]],
        [['text' => $textbotlang['keyboard']['editPassword']], ['text' => $textbotlang['keyboard']['editUsername']]],
        [['text' => $textbotlang['keyboard']['editPanelUrl']], ['text' => $textbotlang['keyboard']['setGroupName']]],
        [['text' => $textbotlang['keyboard']['renewalMethod']], ['text' => $textbotlang['keyboard']['usernameMethod']]],
        [['text' => $textbotlang['keyboard']['accountCreateLimit']], ['text' => $textbotlang['keyboard']['changeUserGroup']]],
        [['text' => $textbotlang['keyboard']['customVolumePrice']], ['text' => $textbotlang['keyboard']['extraVolumePrice']]],
        [['text' => $textbotlang['keyboard']['extraTimePrice']], ['text' => $textbotlang['keyboard']['customTimePrice']]],
        [['text' => $textbotlang['keyboard']['minCustomVolume']], ['text' => $textbotlang['keyboard']['maxCustomVolume']]],
        [['text' => $textbotlang['keyboard']['minCustomTime']], ['text' => $textbotlang['keyboard']['maxCustomTime']]],
        [['text' => $textbotlang['keyboard']['hidePanelForUser']]],
        [['text' => $textbotlang['keyboard']['removeFromHiddenList']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$option_mikrotik = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['panelFeatureStatus']]],
        [['text' => $textbotlang['keyboard']['panelName']], ['text' => $textbotlang['keyboard']['deletePanel']]],
        [['text' => $textbotlang['keyboard']['duplicatePanel']]],
        [['text' => $textbotlang['keyboard']['editPassword']], ['text' => $textbotlang['keyboard']['editUsername']]],
        [['text' => $textbotlang['keyboard']['editPanelUrl']], ['text' => $textbotlang['keyboard']['setGroupName']]],
        [['text' => $textbotlang['keyboard']['renewalMethod']], ['text' => $textbotlang['keyboard']['usernameMethod']]],
        [['text' => $textbotlang['keyboard']['accountCreateLimit']], ['text' => $textbotlang['keyboard']['changeUserGroup']]],
        [['text' => $textbotlang['keyboard']['customVolumePrice']], ['text' => $textbotlang['keyboard']['extraVolumePrice']]],
        [['text' => $textbotlang['keyboard']['extraTimePrice']], ['text' => $textbotlang['keyboard']['customTimePrice']]],
        [['text' => $textbotlang['keyboard']['minCustomVolume']], ['text' => $textbotlang['keyboard']['maxCustomVolume']]],
        [['text' => $textbotlang['keyboard']['minCustomTime']], ['text' => $textbotlang['keyboard']['maxCustomTime']]],
        [['text' => $textbotlang['keyboard']['hidePanelForUser']]],
        [['text' => $textbotlang['keyboard']['removeFromHiddenList']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$options_ui = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['panelFeatureStatus']]],
        [['text' => $textbotlang['keyboard']['panelName']], ['text' => $textbotlang['keyboard']['deletePanel']]],
        [['text' => $textbotlang['keyboard']['duplicatePanel']]],
        [['text' => $textbotlang['keyboard']['editPassword']], ['text' => $textbotlang['keyboard']['editUsername']]],
        [['text' => $textbotlang['keyboard']['editPanelUrl']], ['text' => $textbotlang['keyboard']['setProtocolInbound']]],
        [['text' => $textbotlang['keyboard']['renewalMethod']], ['text' => $textbotlang['keyboard']['usernameMethod']]],
        [['text' => $textbotlang['keyboard']['accountCreateLimit']], ['text' => $textbotlang['keyboard']['changeUserGroup']]],
        [['text' => $textbotlang['keyboard']['testServiceTime']], ['text' => $textbotlang['keyboard']['testAccountVolume']]],
        [['text' => $textbotlang['keyboard']['testDeleteTime']]],
        [['text' => $textbotlang['keyboard']['customVolumePrice']], ['text' => $textbotlang['keyboard']['extraVolumePrice']]],
        [['text' => $textbotlang['keyboard']['extraTimePrice']], ['text' => $textbotlang['keyboard']['customTimePrice']]],
        [['text' => $textbotlang['keyboard']['changeLocationPrice']]],
        [['text' => $textbotlang['keyboard']['minCustomVolume']], ['text' => $textbotlang['keyboard']['maxCustomVolume']]],
        [['text' => $textbotlang['keyboard']['minCustomTime']], ['text' => $textbotlang['keyboard']['maxCustomTime']]],
        [['text' => $textbotlang['keyboard']['inboundDeactivate']]],
        [['text' => $textbotlang['keyboard']['hidePanelForUser']]],
        [['text' => $textbotlang['keyboard']['removeFromHiddenList']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$optionwg = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['panelFeatureStatus']]],
        [['text' => $textbotlang['keyboard']['panelName']], ['text' => $textbotlang['keyboard']['deletePanel']]],
        [['text' => $textbotlang['keyboard']['duplicatePanel']]],
        [['text' => $textbotlang['keyboard']['editPassword']]],
        [['text' => $textbotlang['keyboard']['editPanelUrl']], ['text' => $textbotlang['keyboard']['setInboundId']]],
        [['text' => $textbotlang['keyboard']['renewalMethod']], ['text' => $textbotlang['keyboard']['usernameMethod']]],
        [['text' => $textbotlang['keyboard']['accountCreateLimit']], ['text' => $textbotlang['keyboard']['changeUserGroup']]],
        [['text' => $textbotlang['keyboard']['testServiceTime']], ['text' => $textbotlang['keyboard']['testAccountVolume']]],
        [['text' => $textbotlang['keyboard']['testDeleteTime']]],
        [['text' => $textbotlang['keyboard']['customVolumePrice']], ['text' => $textbotlang['keyboard']['extraVolumePrice']]],
        [['text' => $textbotlang['keyboard']['extraTimePrice']], ['text' => $textbotlang['keyboard']['customTimePrice']]],
        [['text' => $textbotlang['keyboard']['changeLocationPrice']]],
        [['text' => $textbotlang['keyboard']['minCustomVolume']], ['text' => $textbotlang['keyboard']['maxCustomVolume']]],
        [['text' => $textbotlang['keyboard']['minCustomTime']], ['text' => $textbotlang['keyboard']['maxCustomTime']]],
        [['text' => $textbotlang['keyboard']['inboundDeactivate']]],
        [['text' => $textbotlang['keyboard']['hidePanelForUser']]],
        [['text' => $textbotlang['keyboard']['removeFromHiddenList']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$optionmarzneshin = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['panelFeatureStatus']]],
        [['text' => $textbotlang['keyboard']['panelName']], ['text' => $textbotlang['keyboard']['deletePanel']]],
        [['text' => $textbotlang['keyboard']['duplicatePanel']]],
        [['text' => $textbotlang['keyboard']['editPassword']], ['text' => $textbotlang['keyboard']['editUsername']]],
        [['text' => $textbotlang['keyboard']['editPanelUrl']], ['text' => $textbotlang['keyboard']['renewalMethod']]],
        [['text' => $textbotlang['keyboard']['usernameMethod']]],
        [['text' => $textbotlang['keyboard']['serviceSettings']], ['text' => $textbotlang['keyboard']['accountCreateLimit']]],
        [['text' => $textbotlang['keyboard']['changeUserGroup']]],
        [['text' => $textbotlang['keyboard']['testServiceTime']], ['text' => $textbotlang['keyboard']['testAccountVolume']]],
        [['text' => $textbotlang['keyboard']['testDeleteTime']]],
        [['text' => $textbotlang['keyboard']['changeLocationPrice']], ['text' => $textbotlang['keyboard']['extraVolumePrice']]],
        [['text' => $textbotlang['keyboard']['extraTimePrice']], ['text' => $textbotlang['keyboard']['customVolumePrice']]],
        [['text' => $textbotlang['keyboard']['customTimePrice']]],
        [['text' => $textbotlang['keyboard']['minCustomVolume']], ['text' => $textbotlang['keyboard']['maxCustomVolume']]],
        [['text' => $textbotlang['keyboard']['minCustomTime']], ['text' => $textbotlang['keyboard']['maxCustomTime']]],
        [['text' => $textbotlang['keyboard']['hidePanelForUser']]],
        [['text' => $textbotlang['keyboard']['removeFromHiddenList']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$optionManualsale = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['panelFeatureStatus']]],
        [['text' => $textbotlang['keyboard']['panelName']], ['text' => $textbotlang['keyboard']['deletePanel']]],
        [['text' => $textbotlang['keyboard']['duplicatePanel']]],
        [['text' => $textbotlang['keyboard']['usernameMethod']]],
        [['text' => $textbotlang['keyboard']['accountCreateLimit']], ['text' => $textbotlang['keyboard']['changeUserGroup']]],
        [['text' => $textbotlang['keyboard']['addConfig']], ['text' => $textbotlang['keyboard']['deleteConfig']]],
        [['text' => $textbotlang['keyboard']['editConfig']]],
        [['text' => $textbotlang['keyboard']['hidePanelForUser']]],
        [['text' => $textbotlang['keyboard']['removeFromHiddenList']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$optionX_ui_single = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['panelFeatureStatus']]],
        [['text' => $textbotlang['keyboard']['panelName']], ['text' => $textbotlang['keyboard']['deletePanel']]],
        [['text' => $textbotlang['keyboard']['duplicatePanel']]],
        [['text' => $textbotlang['keyboard']['editPassword']]],
        [['text' => $textbotlang['keyboard']['editPanelUrl']], ['text' => $textbotlang['keyboard']['renewalMethod']]],
        [['text' => $textbotlang['keyboard']['setProtocolInbound']]],
        [['text' => $textbotlang['keyboard']['usernameMethod']], ['text' => $textbotlang['keyboard']['subLinkDomain']]],
        [['text' => $textbotlang['keyboard']['changeUserGroup']], ['text' => $textbotlang['keyboard']['accountCreateLimit']]],
        [['text' => $textbotlang['keyboard']['testServiceTime']], ['text' => $textbotlang['keyboard']['testAccountVolume']]],
        [['text' => $textbotlang['keyboard']['testDeleteTime']]],
        [['text' => $textbotlang['keyboard']['changeLocationPrice']], ['text' => $textbotlang['keyboard']['extraVolumePrice']]],
        [['text' => $textbotlang['keyboard']['extraTimePrice']], ['text' => $textbotlang['keyboard']['customVolumePrice']]],
        [['text' => $textbotlang['keyboard']['customTimePrice']]],
        [['text' => $textbotlang['keyboard']['minCustomVolume']], ['text' => $textbotlang['keyboard']['maxCustomVolume']]],
        [['text' => $textbotlang['keyboard']['minCustomTime']], ['text' => $textbotlang['keyboard']['maxCustomTime']]],
        [['text' => $textbotlang['keyboard']['hidePanelForUser']]],
        [['text' => $textbotlang['keyboard']['removeFromHiddenList']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$optionalireza_single = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['panelFeatureStatus']]],
        [['text' => $textbotlang['keyboard']['panelName']], ['text' => $textbotlang['keyboard']['deletePanel']]],
        [['text' => $textbotlang['keyboard']['duplicatePanel']]],
        [['text' => $textbotlang['keyboard']['editPassword']], ['text' => $textbotlang['keyboard']['editUsername']]],
        [['text' => $textbotlang['keyboard']['editPanelUrl']], ['text' => $textbotlang['keyboard']['renewalMethod']]],
        [['text' => $textbotlang['keyboard']['setInboundId']]],
        [['text' => $textbotlang['keyboard']['usernameMethod']]],
        [['text' => $textbotlang['keyboard']['subLinkDomain']]],
        [['text' => $textbotlang['keyboard']['changeUserGroup']], ['text' => $textbotlang['keyboard']['accountCreateLimit']]],
        [['text' => $textbotlang['keyboard']['testServiceTime']], ['text' => $textbotlang['keyboard']['testAccountVolume']]],
        [['text' => $textbotlang['keyboard']['testDeleteTime']]],
        [['text' => $textbotlang['keyboard']['changeLocationPrice']], ['text' => $textbotlang['keyboard']['extraVolumePrice']]],
        [['text' => $textbotlang['keyboard']['extraTimePrice']], ['text' => $textbotlang['keyboard']['customVolumePrice']]],
        [['text' => $textbotlang['keyboard']['customTimePrice']]],
        [['text' => $textbotlang['keyboard']['minCustomVolume']], ['text' => $textbotlang['keyboard']['maxCustomVolume']]],
        [['text' => $textbotlang['keyboard']['minCustomTime']], ['text' => $textbotlang['keyboard']['maxCustomTime']]],
        [['text' => $textbotlang['keyboard']['hidePanelForUser']]],
        [['text' => $textbotlang['keyboard']['removeFromHiddenList']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$optionhiddfy = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['panelFeatureStatus']]],
        [['text' => $textbotlang['keyboard']['panelName']], ['text' => $textbotlang['keyboard']['deletePanel']]],
        [['text' => $textbotlang['keyboard']['duplicatePanel']]],
        [['text' => $textbotlang['keyboard']['editPanelUrl']], ['text' => $textbotlang['keyboard']['renewalMethod']]],
        [['text' => $textbotlang['keyboard']['changeUserGroup']]],
        [['text' => $textbotlang['keyboard']['usernameMethod']]],
        [['text' => $textbotlang['keyboard']['subLinkDomain']]],
        [['text' => $textbotlang['keyboard']['accountCreateLimit']], ['text' => "🔗 uuid admin"]],
        [['text' => $textbotlang['keyboard']['testServiceTime']], ['text' => $textbotlang['keyboard']['testAccountVolume']]],
        [['text' => $textbotlang['keyboard']['testDeleteTime']]],
        [['text' => $textbotlang['keyboard']['changeLocationPrice']], ['text' => $textbotlang['keyboard']['extraVolumePrice']]],
        [['text' => $textbotlang['keyboard']['extraTimePrice']], ['text' => $textbotlang['keyboard']['customVolumePrice']]],
        [['text' => $textbotlang['keyboard']['customTimePrice']]],
        [['text' => $textbotlang['keyboard']['minCustomVolume']], ['text' => $textbotlang['keyboard']['maxCustomVolume']]],
        [['text' => $textbotlang['keyboard']['minCustomTime']], ['text' => $textbotlang['keyboard']['maxCustomTime']]],
        [['text' => $textbotlang['keyboard']['hidePanelForUser']]],
        [['text' => $textbotlang['keyboard']['removeFromHiddenList']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
if ($setting['statussupportpv'] == "onpvsupport") {
    $supportoption = json_encode([
        'inline_keyboard' => [
            [
                ['text' => $textbotlang['textbot']['faq'], 'callback_data' => "fqQuestions"],
                ['text' => $textbotlang['keyboard']['sendMessageToSupport'], 'url' => "https://t.me/{$setting['id_support']}"],
            ],
            [
                ['text' => $textbotlang['keyboard']['backToMainMenu'], 'callback_data' => "backuser"]
            ],

        ]
    ]);
} else {
    $supportoption = json_encode([
        'inline_keyboard' => [
            [
                ['text' => $textbotlang['textbot']['faq'], 'callback_data' => "fqQuestions"],
                ['text' => $textbotlang['keyboard']['sendMessageToSupport'], 'callback_data' => "support"],
            ],
            [
                ['text' => $textbotlang['keyboard']['backToMainMenu'], 'callback_data' => "backuser"]
            ],

        ]
    ]);
}
$adminrule = json_encode([
    'keyboard' => [
        [['text' => "administrator"], ['text' => "Seller"], ['text' => "support"]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$affiliates = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['setAffiliatePercent']]],
        [['text' => $textbotlang['keyboard']['setAffiliateBanner']]],
        [['text' => $textbotlang['keyboard']['purchaseCommission']], ['text' => $textbotlang['keyboard']['startGift']]],
        [['text' => $textbotlang['keyboard']['firstPurchaseCommission']]],
        [['text' => $textbotlang['keyboard']['startGiftAmount']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$keyboardexportdata = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['exportUsers']], ['text' => $textbotlang['keyboard']['exportOrders']]],
        [['text' => $textbotlang['keyboard']['exportPayments']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$helpedit = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['editName']], ['text' => $textbotlang['keyboard']['editDescription']]],
        [['text' => $textbotlang['keyboard']['editMedia']], ['text' => $textbotlang['keyboard']['editCategory']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$Methodextend = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['resetVolumeTime']]],
        [['text' => $textbotlang['keyboard']['addTimeVolumeNextMonth']]],
        [['text' => $textbotlang['keyboard']['resetTimeAddVolume']]],
        [['text' => $textbotlang['keyboard']['resetVolumeAddTime']]],
        [['text' => $textbotlang['keyboard']['addTimeConvertVolume']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$keyboardtimereset = json_encode([
    'keyboard' => [
        [['text' => "no_reset"], ['text' => "day"], ['text' => "week"]],
        [['text' => "month"], ['text' => "year"]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$keyboardtypepanel = json_encode([
    'inline_keyboard' => [
        [
            ['text' => $textbotlang['keyboard']['marzban'], 'callback_data' => "typepanel#marzban"],
            ['text' => $textbotlang['keyboard']['marzneshin'], 'callback_data' => "typepanel#marzneshin"]
        ],
        [
            ['text' => $textbotlang['keyboard']['passargadPanel'], 'callback_data' => "typepanel#pasarguard"],
            ['text' => $textbotlang['keyboard']['mirzaAgentPanel'], 'callback_data' => "typepanel#mirza_agent"]
        ],
        [
            ['text' => $textbotlang['keyboard']['panelTypeSanaei'], 'callback_data' => 'typepanel#x-ui_single'],
            ['text' => $textbotlang['keyboard']['panelTypeAlireza'], 'callback_data' => 'typepanel#alireza_single']
        ],
        [
            ['text' => $textbotlang['keyboard']['manualSale'], 'callback_data' => 'typepanel#Manualsale'],
            ['text' => $textbotlang['keyboard']['hiddify'], 'callback_data' => 'typepanel#hiddify'],
        ],
        [
            ['text' => "WGDashboard", 'callback_data' => 'typepanel#WGDashboard'],
            ['text' => "s_ui", 'callback_data' => 'typepanel#s_ui']
        ],
        [
            ['text' => "ibsng", 'callback_data' => 'typepanel#ibsng'],
            ['text' => $textbotlang['keyboard']['mikrotik'], 'callback_data' => 'typepanel#mikrotik']
        ],
        [
            ['text' => $textbotlang['keyboard']['rebecca'], 'callback_data' => 'typepanel#rebecca']
        ],
        [
            ['text' => $textbotlang['Admin']['backAdminBtn'], 'callback_data' => 'admin']
        ]
    ],
]);

$panelechekc = select("marzban_panel", "*", "MethodUsername", $textbotlang['keyboard']['usernameMethodAgentCustom'], "count");
if ($setting['inlinebtnmain'] == "oninline") {
    $keyboardagent = [
        'inline_keyboard' => [
            [
                ['text' => $textbotlang['keyboard']['bulkPurchase'], 'callback_data' => "kharidanbuh"],
                ['text' => $textbotlang['keyboard']['selectCustomName'], 'callback_data' => "selectname"]
            ],
            [
                ['text' => $textbotlang['users']['backbtn'], 'callback_data' => "backuser"]
            ]
        ],
        'resize_keyboard' => true
    ];
    if ($panelechekc == 0) {
        unset($keyboardagent['inline_keyboard'][0][1]);
    }
} else {
    $keyboardagent = [
        'keyboard' => [
            [['text' => $textbotlang['keyboard']['bulkPurchase']], ['text' => $textbotlang['keyboard']['selectCustomName']]],
            [['text' => $textbotlang['users']['backbtn']]]
        ],
        'resize_keyboard' => true
    ];
    if ($panelechekc == 0) {
        unset($keyboardagent['keyboard'][0][1]);
    }
}
$keyboardagent = json_encode($keyboardagent);
$Swapinokey = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['setEducationIranPay1']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);

$tronnowpayments = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['setEducationCryptoOffline']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$optionathmarzban = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['manualCreateConfig']], ['text' => $textbotlang['keyboard']['manageNodes']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$optionathx_ui = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['manualCreateConfig']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$configedit = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['configDetails']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$iranpaykeyboard = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['setEducationIranPay3']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$supportcenter = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['setSupportId']]],
        [['text' => $textbotlang['keyboard']['addDepartment']], ['text' => $textbotlang['keyboard']['deleteDepartment']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
//------------------  [ list departeman ]----------------//
$stmt = $pdo->prepare("SHOW TABLES LIKE 'departman'");
$stmt->execute();
$result = $stmt->fetchAll();
$table_exists = count($result) > 0;
$departeman = [];
if ($table_exists) {
    $stmt = $pdo->prepare("SELECT * FROM departman");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $departeman[] = [$row['name_departman']];
    }
    $departemans = [
        'keyboard' => [],
        'resize_keyboard' => true,
    ];
    foreach ($departeman as $button) {
        $departemans['keyboard'][] = [
            ['text' => $button[0]]
        ];
    }
    $departemans['keyboard'][] = [
        ['text' => $textbotlang['Admin']['backAdminBtn']],
        ['text' => $textbotlang['Admin']['backMenuBtn']]
    ];
    $departemanslist = json_encode($departemans);
}
// list departeman
$list_departman = ['inline_keyboard' => []];
$stmt = $pdo->prepare("SELECT * FROM departman");
$stmt->execute();
while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $list_departman['inline_keyboard'][] = [
        ['text' => $result['name_departman'], 'callback_data' => "departman_{$result['id']}"]
    ];
}
$list_departman['inline_keyboard'][] = [
    ['text' => $textbotlang['users']['backbtn'], 'callback_data' => "backuser"],
];
$list_departman = json_encode($list_departman);
$active_panell = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['botReports']]],
    ],
    'resize_keyboard' => true
]);
$lottery = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['setFirstPrize']], ['text' => $textbotlang['keyboard']['setSecondPrize']]],
        [['text' => $textbotlang['keyboard']['setThirdPrize']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']]]
    ],
    'resize_keyboard' => true
]);
$wheelkeyboard = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['lotteryWinAmount']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']]]
    ],
    'resize_keyboard' => true
]);
$keyboardlinkapp = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['addApp']], ['text' => $textbotlang['keyboard']['deleteApp']]],
        [['text' => $textbotlang['keyboard']['editApp']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
function KeyboardProduct($location, $query, $pricediscount, $datakeyboard, $statuscustom = false, $backuser = "backuser", $valuetow = null, $customvolume = "customsellvolume")
{
    global $pdo, $textbotlang, $from_id, $user;
    $product = ['inline_keyboard' => []];
    $statusshowprice = select("shopSetting", "*", "Namevalue", "statusshowprice", "select")['value'];
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    if ($valuetow != null) {
        $valuetow = "-$valuetow";
    } else {
        $valuetow = "";
    }
    // per-language button styling (order/width/emoji/color/rename), set via
    // 🎨 شخصی‌سازی نمایش دکمه‌ها -> محصولات in the admin panel
    $kp_userlang = $user['lang'] ?? 'fa';
    $kp_section = help_layout_section($kp_userlang, 'product');
    $kp_buttons = [];
    while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $hide_panel = json_decode($result['hide_panel'], true);
        if (in_array($location, $hide_panel))
            continue;
        $stmts2 = $pdo->prepare("SELECT * FROM invoice WHERE Status != 'Unpaid' AND id_user = :id_user");
        $stmts2->bindValue(':id_user', $from_id);
        $stmts2->execute();
        $countorder = $stmts2->rowCount();
        if ($result['one_buy_status'] == "1" && $countorder != 0)
            continue;
        if (intval($pricediscount) != 0) {
            $resultper = ($result['price_product'] * $pricediscount) / 100;
            $result['price_product'] = $result['price_product'] - $resultper;
        }
        $kp_key = (string) $result['id'];
        $kp_name = $kp_section['rename'][$kp_key] ?? $result['name_product'];
        $namekeyboard = $kp_name . " - " . money($result['price_product'], $result['currency'] ?? null);
        if ($statusshowprice == "onshowprice") {
            $kp_name = $namekeyboard;
        }
        $kp_emo = help_layout_emoji_prefix($kp_key, $kp_section);
        $kp_name = $kp_emo['prefix'] . $kp_name;
        $kp_btn = ['text' => $kp_name, 'callback_data' => "{$datakeyboard}{$result['code_product']}{$valuetow}"];
        if ($kp_emo['icon'] !== '') {
            $kp_btn['icon_custom_emoji_id'] = $kp_emo['icon'];
        }
        $kp_color = $kp_section['color'][$kp_key] ?? '';
        if ($kp_color !== '' && in_array($kp_color, ['primary', 'success', 'danger'], true)) {
            $kp_btn['style'] = $kp_color;
        }
        $kp_buttons[$kp_key] = $kp_btn;
    }
    $kp_ordered = help_layout_apply_order(array_keys($kp_buttons), $kp_section['order']);
    $product['inline_keyboard'] = array_merge($product['inline_keyboard'], help_layout_chunk_rows($kp_ordered, $kp_buttons, $kp_section['width']));
    if ($statuscustom)
        $product['inline_keyboard'][] = [['text' => $textbotlang['users']['customSellVolume']['title'], 'callback_data' => $customvolume]];
    // dedicated label, not the shared users.status.backinfo (~30 other sites use
    // that one for unrelated "back to invoice/account" screens) - this button
    // specifically returns to whichever step preceded the product list, and its
    // own caption+sticker are retired automatically by Editmessagetext()/
    // deletemessage() the moment the target screen replaces this one
    $product['inline_keyboard'][] = [
        ['text' => $textbotlang['users']['sell']['backToPreviousBtn'], 'callback_data' => $backuser],
    ];
    return json_encode($product);
}
function KeyboardCategory($location, $agent, $backuser = "backuser")
{
    global $pdo, $textbotlang, $user;
    $ls_userlang = $user['lang'] ?? 'fa';
    $stmt = $pdo->prepare("SELECT * FROM category");
    $stmt->execute();
    $list_category = ['inline_keyboard' => [],];
    // per-language button styling (order/width/emoji/color/rename), set via
    // 🎨 شخصی‌سازی نمایش دکمه‌ها -> دسته‌بندی‌ها in the admin panel
    $lc_section = help_layout_section($ls_userlang, 'category');
    $lc_buttons = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!lang_scope_matches($row['lang'] ?? 'all', $ls_userlang)) {
            continue;
        }
        $stmts = $pdo->prepare("SELECT * FROM product WHERE (Location = :location OR Location = '/all') AND category = :category AND agent = :agent AND (FIND_IN_SET(:userlang, lang) OR lang = 'all' OR lang IS NULL OR lang = '')");
        $stmts->bindParam(':location', $location, PDO::PARAM_STR);
        $stmts->bindParam(':category', $row['remark'], PDO::PARAM_STR);
        $stmts->bindParam(':agent', $agent);
        $stmts->bindValue(':userlang', $ls_userlang, PDO::PARAM_STR);
        $stmts->execute();
        if ($stmts->rowCount() == 0)
            continue;
        $lc_key = (string) $row['id'];
        $lc_name = $lc_section['rename'][$lc_key] ?? $row['remark'];
        $lc_emo = help_layout_emoji_prefix($lc_key, $lc_section);
        $lc_name = $lc_emo['prefix'] . $lc_name;
        $lc_btn = ['text' => $lc_name, 'callback_data' => "categorynames_" . $row['id']];
        if ($lc_emo['icon'] !== '') {
            $lc_btn['icon_custom_emoji_id'] = $lc_emo['icon'];
        }
        $lc_color = $lc_section['color'][$lc_key] ?? '';
        if ($lc_color !== '' && in_array($lc_color, ['primary', 'success', 'danger'], true)) {
            $lc_btn['style'] = $lc_color;
        }
        $lc_buttons[$lc_key] = $lc_btn;
    }
    $lc_ordered = help_layout_apply_order(array_keys($lc_buttons), $lc_section['order']);
    $list_category['inline_keyboard'] = array_merge($list_category['inline_keyboard'], help_layout_chunk_rows($lc_ordered, $lc_buttons, $lc_section['width']));
    // $backuser has always been accepted here but was silently dropped - every
    // sibling picker (KeyboardProduct, keyboardTimeCategory) renders it as a row.
    // When 🖥 نمایش انتخاب پنل is OFF the panel screen this would return to was
    // never shown in the first place, so a close button takes its place instead,
    // matching every other screen this session that got the same treatment.
    $lc_panelshow = (select("setting", "statuspanelshow", null, null, "select")['statuspanelshow'] ?? 'onpanelshow') == 'onpanelshow';
    if ($lc_panelshow) {
        $list_category['inline_keyboard'][] = [[
            'text' => $textbotlang['users']['sell']['backToPanelListBtn'],
            'callback_data' => $backuser,
            'style' => 'danger',
        ]];
    } else {
        $list_category['inline_keyboard'][] = [[
            'text' => $textbotlang['bottext']['btn_close'],
            'callback_data' => 'mmclose',
            'style' => 'danger',
        ]];
    }
    return json_encode($list_category);
}

function keyboardTimeCategory($name_panel, $agent, $callback_data = "producttime_", $callback_data_back = "backuser", $statuscustomvolume = false, $statusbtnextend = false)
{
    global $pdo, $textbotlang;
    $stmt = $pdo->prepare("SELECT (Service_time) FROM product WHERE (Location = :name_panel OR Location = '/all') AND  agent = :agent");
    $stmt->bindValue(':name_panel', $name_panel, PDO::PARAM_STR);
    $stmt->bindValue(':agent', $agent, PDO::PARAM_STR);
    $stmt->execute();
    $montheproduct = array_flip(array_flip($stmt->fetchAll(PDO::FETCH_COLUMN)));
    $monthkeyboard = ['inline_keyboard' => []];
    if (in_array("1", $montheproduct)) {
        $monthkeyboard['inline_keyboard'][] = [
            ['text' => $textbotlang['common']['duration']['1day'], 'callback_data' => "{$callback_data}1"]
        ];
    }
    if (in_array("7", $montheproduct)) {
        $monthkeyboard['inline_keyboard'][] = [
            ['text' => $textbotlang['common']['duration']['7day'], 'callback_data' => "{$callback_data}7"]
        ];
    }
    if (in_array("31", $montheproduct)) {
        $monthkeyboard['inline_keyboard'][] = [
            ['text' => $textbotlang['common']['duration']['1'], 'callback_data' => "{$callback_data}31"]
        ];
    }
    if (in_array("30", $montheproduct)) {
        $monthkeyboard['inline_keyboard'][] = [
            ['text' => $textbotlang['common']['duration']['1'], 'callback_data' => "{$callback_data}30"]
        ];
    }
    if (in_array("61", $montheproduct)) {
        $monthkeyboard['inline_keyboard'][] = [
            ['text' => $textbotlang['common']['duration']['2'], 'callback_data' => "{$callback_data}61"]
        ];
    }
    if (in_array("60", $montheproduct)) {
        $monthkeyboard['inline_keyboard'][] = [
            ['text' => $textbotlang['common']['duration']['2'], 'callback_data' => "{$callback_data}60"]
        ];
    }
    if (in_array("91", $montheproduct)) {
        $monthkeyboard['inline_keyboard'][] = [
            ['text' => $textbotlang['common']['duration']['3'], 'callback_data' => "{$callback_data}91"]
        ];
    }
    if (in_array("90", $montheproduct)) {
        $monthkeyboard['inline_keyboard'][] = [
            ['text' => $textbotlang['common']['duration']['3'], 'callback_data' => "{$callback_data}90"]
        ];
    }
    if (in_array("121", $montheproduct)) {
        $monthkeyboard['inline_keyboard'][] = [
            ['text' => $textbotlang['common']['duration']['4'], 'callback_data' => "{$callback_data}121"]
        ];
    }
    if (in_array("120", $montheproduct)) {
        $monthkeyboard['inline_keyboard'][] = [
            ['text' => $textbotlang['common']['duration']['4'], 'callback_data' => "{$callback_data}120"]
        ];
    }
    if (in_array("181", $montheproduct)) {
        $monthkeyboard['inline_keyboard'][] = [
            ['text' => $textbotlang['common']['duration']['6'], 'callback_data' => "{$callback_data}181"]
        ];
    }
    if (in_array("180", $montheproduct)) {
        $monthkeyboard['inline_keyboard'][] = [
            ['text' => $textbotlang['common']['duration']['6'], 'callback_data' => "{$callback_data}180"]
        ];
    }
    if (in_array("365", $montheproduct)) {
        $monthkeyboard['inline_keyboard'][] = [
            ['text' => $textbotlang['common']['duration']['365'], 'callback_data' => "{$callback_data}365"]
        ];
    }
    if (in_array("0", $montheproduct)) {
        $monthkeyboard['inline_keyboard'][] = [
            ['text' => $textbotlang['common']['duration']['byVolume'], 'callback_data' => "{$callback_data}0"]
        ];
    }
    if ($statusbtnextend)
        $monthkeyboard['inline_keyboard'][] = [['text' => $textbotlang['keyboard']['renewCurrentPlan'], 'callback_data' => "exntedagei"]];
    if ($statuscustomvolume == true)
        $monthkeyboard['inline_keyboard'][] = [['text' => $textbotlang['users']['customSellVolume']['title'], 'callback_data' => "customsellvolume"]];
    $monthkeyboard['inline_keyboard'][] = [
        ['text' => $textbotlang['users']['status']['backinfo'], 'callback_data' => $callback_data_back]
    ];
    return json_encode($monthkeyboard);
}
$Startelegram = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['setEducationStar']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$keyboardchangelimit = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['freeLimit']], ['text' => $textbotlang['keyboard']['generalLimit']]],
        [['text' => $textbotlang['keyboard']['resetAllUsersLimit']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']]]
    ],
    'resize_keyboard' => true
]);
function KeyboardCategoryadmin()
{
    global $pdo, $textbotlang;
    $stmt = $pdo->prepare("SELECT * FROM category");
    $stmt->execute();
    $list_category = [
        'keyboard' => [],
        'resize_keyboard' => true,
    ];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $list_category['keyboard'][] = [['text' => $row['remark']]];
    }
    $list_category['keyboard'][] = [
        ['text' => $textbotlang['Admin']['backAdminBtn']],
    ];
    return json_encode($list_category);
}
$nowpayment_setting_keyboard = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['setEducationNowPayment']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
$Exception_auto_cart_keyboard = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['excludeUser']], ['text' => $textbotlang['keyboard']['removeUserFromList']]],
        [['text' => $textbotlang['keyboard']['showUserList']]],
        [['text' => $textbotlang['keyboard']['backToCardSettings']]]
    ],
    'resize_keyboard' => true
]);
function keyboard_config($config_split, $id_invoice, $back_active = true, $kind = 'usertest')
{
    global $textbotlang, $user;
    $cc_lang = $user['lang'] ?? 'fa';
    $cc_setting = select("setting", "*", null, null, "select");
    $cc_colOrderField = ($kind === 'buy') ? 'configColOrderBuy' : 'configColOrder';
    $cc_nameFirst = (($cc_setting[$cc_colOrderField] ?? '') === 'name_first');
    $cc_get = configdisplay_element_current($cc_lang, 0, $textbotlang, $kind);
    $cc_hConfig = configdisplay_element_current($cc_lang, 1, $textbotlang, $kind);
    $cc_hName = configdisplay_element_current($cc_lang, 2, $textbotlang, $kind);
    $keyboard_config = ['inline_keyboard' => []];
    $cc_headerConfig = ['text' => $cc_hConfig['text'], 'callback_data' => "none"];
    if ($cc_hConfig['style'] !== '') {
        $cc_headerConfig['style'] = $cc_hConfig['style'];
    }
    $cc_headerName = ['text' => $cc_hName['text'], 'callback_data' => "none"];
    if ($cc_hName['style'] !== '') {
        $cc_headerName['style'] = $cc_hName['style'];
    }
    $keyboard_config['inline_keyboard'][] = $cc_nameFirst ? [$cc_headerName, $cc_headerConfig] : [$cc_headerConfig, $cc_headerName];
    for ($i = 0; $i < count($config_split); $i++) {
        $config = $config_split[$i];
        $split_config = explode("://", $config);
        $type_prtocol = $split_config[0];
        $split_config = $split_config[1];
        if (isBase64($split_config)) {
            $split_config = base64_decode($split_config);
        }
        if ($type_prtocol == "vmess") {
            $split_config = json_decode($split_config, true)['ps'];
        } elseif ($type_prtocol == "ss") {
            $split_config = explode("#", $split_config)[1];
        } else {
            $split_config = explode("#", $split_config)[1];
        }
        $cc_getBtn = ['text' => $cc_get['text'], 'callback_data' => "configget_{$id_invoice}_$i"];
        if ($cc_get['style'] !== '') {
            $cc_getBtn['style'] = $cc_get['style'];
        }
        $cc_nameBtn = ['text' => urldecode($split_config), 'callback_data' => "none"];
        $keyboard_config['inline_keyboard'][] = $cc_nameFirst ? [$cc_nameBtn, $cc_getBtn] : [$cc_getBtn, $cc_nameBtn];

    }
    $cc_getAll = configdisplay_element_current($cc_lang, 3, $textbotlang, $kind);
    $cc_getAllBtn = ['text' => $cc_getAll['text'], 'callback_data' => "configget_$id_invoice" . "_1520"];
    if ($cc_getAll['style'] !== '') {
        $cc_getAllBtn['style'] = $cc_getAll['style'];
    }
    $keyboard_config['inline_keyboard'][] = [$cc_getAllBtn];
    if ($back_active) {
        // the buy path got its own red "بازگشت به منوی قبل" per admin request -
        // usertest's back button is untouched, still the shared "↪️ بازگشت"
        if ($kind === 'buy') {
            $keyboard_config['inline_keyboard'][] = [['text' => '🔙 بازگشت به منوی قبل', 'callback_data' => "product_$id_invoice", 'style' => 'danger']];
        } else {
            $keyboard_config['inline_keyboard'][] = [['text' => $textbotlang['users']['status']['backinfo'], 'callback_data' => "product_$id_invoice"]];
        }
    }
    return json_encode($keyboard_config);
}
$keyboard_buy = json_encode([
    'inline_keyboard' => [
        [
            ['text' => $textbotlang['keyboard']['buySubscription'], 'callback_data' => 'buy'],
        ],
    ]
]);
$keyboard_stat = json_encode([
    'inline_keyboard' => [
        [
            ['text' => $textbotlang['keyboard']['totalStats'], 'callback_data' => 'stat_all_bot'],
        ],
        [
            ['text' => $textbotlang['keyboard']['lastHourStats'], 'callback_data' => 'hoursago_stat'],
        ],
        [
            ['text' => $textbotlang['keyboard']['today'], 'callback_data' => 'today_stat'],
            ['text' => $textbotlang['keyboard']['yesterday'], 'callback_data' => 'yesterday_stat'],
        ],
        [
            ['text' => $textbotlang['keyboard']['currentMonth'], 'callback_data' => 'month_current_stat'],
            ['text' => $textbotlang['keyboard']['lastMonth'], 'callback_data' => 'month_old_stat'],
        ],
        [
            ['text' => $textbotlang['keyboard']['statsAtDate'], 'callback_data' => 'view_stat_time'],
        ]
    ]
]);
$option_mirza = json_encode([
    'keyboard' => [
        [['text' => $textbotlang['keyboard']['panelFeatureStatus']]],
        [['text' => $textbotlang['keyboard']['panelName']], ['text' => $textbotlang['keyboard']['deletePanel']]],
        [['text' => $textbotlang['keyboard']['duplicatePanel']]],
        [['text' => $textbotlang['keyboard']['editPassword']]],
        [['text' => $textbotlang['keyboard']['editPanelUrl']], ['text' => $textbotlang['keyboard']['panelSetting']]],
        [['text' => $textbotlang['keyboard']['accountCreateLimit']], ['text' => $textbotlang['keyboard']['changeUserGroup']]],
        [['text' => $textbotlang['keyboard']['customVolumePrice']], ['text' => $textbotlang['keyboard']['extraVolumePrice']]],
        [['text' => $textbotlang['keyboard']['extraTimePrice']], ['text' => $textbotlang['keyboard']['customTimePrice']]],
        [['text' => $textbotlang['keyboard']['minCustomVolume']], ['text' => $textbotlang['keyboard']['maxCustomVolume']]],
        [['text' => $textbotlang['keyboard']['minCustomTime']], ['text' => $textbotlang['keyboard']['maxCustomTime']]],
        [['text' => $textbotlang['keyboard']['hidePanelForUser']]],
        [['text' => $textbotlang['keyboard']['removeFromHiddenList']]],
        [['text' => $textbotlang['Admin']['backAdminBtn']], ['text' => $textbotlang['Admin']['backMenuBtn']]]
    ],
    'resize_keyboard' => true
]);
if (!function_exists('language_button_labels')) {
    // every localized label of the "change language" reply button, so a tap is
    // recognised whatever language the user is currently using
    function language_button_labels()
    {
        return [
            '🌏 تغییر زبان',
            '🌏 Change language',
            '🌏 Сменить язык',
            '🌏 切换语言',
            '🌏 Dili üýtgetmek',
            '🌐 تغییر زبان',
        ];
    }
}

if (!function_exists('language_picker_payload')) {
    function language_picker_payload()
    {
        global $setting;
        $lsw = json_decode((string) ($setting['lang_switch'] ?? ''), true);
        $lsw_all = ['fa', 'en', 'ru', 'zh', 'tk'];
        $lsw_langs = (is_array($lsw['langs'] ?? null) && !empty($lsw['langs'])) ? array_values(array_intersect($lsw_all, $lsw['langs'])) : $lsw_all;
        if (empty($lsw_langs)) {
            $lsw_langs = $lsw_all;
        }
        $lsw_meta = [
            'fa' => ['label' => '🇮🇷 فارسی', 'line' => '🌐 لطفاً زبان خود را انتخاب کنید'],
            'en' => ['label' => '🇬🇧 English', 'line' => '🌐 Please select your language'],
            'ru' => ['label' => '🇷🇺 Русский', 'line' => '🌐 Пожалуйста, выберите язык'],
            'zh' => ['label' => '🇨🇳 中文', 'line' => '🌐 请选择您的语言'],
            'tk' => ['label' => '🇹🇲 Türkmençe', 'line' => '🌐 Haýyş edýäris, diliňizi saýlaň'],
        ];
        $lsw_lines = [];
        $lsw_buttons = [];
        foreach ($lsw_langs as $lsw_code) {
            if (!isset($lsw_meta[$lsw_code])) {
                continue;
            }
            $lsw_lines[] = $lsw_meta[$lsw_code]['line'];
            $lsw_buttons[] = ['text' => $lsw_meta[$lsw_code]['label'], 'callback_data' => "setlang:{$lsw_code}"];
        }
        $lsw_caption = "═══════════════════════\n       🌍 WELCOME\n═══════════════════════\n\n"
            . implode("\n", $lsw_lines)
            . "\n\n━━━━━━━━━━━━━━━━━━━━━━━\n👇 Tap to continue:";
        $lsw_rows = array_chunk($lsw_buttons, 2);
        return [$lsw_caption, json_encode(['inline_keyboard' => $lsw_rows])];
    }
}

function keyboard_list_text($lang, $groupFilter = null)
{
    global $textbotlang;
    $keyboard_text = ['inline_keyboard' => []];
    // load THIS TAB's own language file (not the admin's account language) so
    // the item labels below visually match whichever language is being edited
    $bt_tab_texts = require __DIR__ . '/lang/' . $lang . '.php';
    if ($lang !== 'fa' && is_array($bt_tab_texts)) {
        $bt_tab_fa = require __DIR__ . '/lang/fa.php';
        if (is_array($bt_tab_fa)) {
            $bt_tab_texts = bt_lang_fill_defaults($bt_tab_texts, $bt_tab_fa);
        }
    }
    if (is_array($bt_tab_texts)) {
        bottext_apply_overrides($bt_tab_texts, $lang);
    }
    $keyboard_list_text = $bt_tab_texts['bottext']['items'] ?? $textbotlang['bottext']['items'];
    $bt_unknown_label = $bt_tab_texts['bottext']['unknownMsgLabel'] ?? '💬 پیام نام‌شناس';
    // language is chosen exactly once, here on the flat home list - group
    // screens no longer show this row at all, so there is nothing left to
    // "re-select"; the row's own dispatcher (admin.php's bt_lang: handler)
    // still accepts an optional :{group} suffix from round 27, kept as a
    // harmless unused capability rather than reverted
    if ($groupFilter === null) {
        $keyboard_text['inline_keyboard'][] = [
            ['text' => ($lang == 'fa' ? "✅" : "") . $textbotlang['bottext']['langs']['fa'], 'callback_data' => "bt_lang:fa", 'style' => 'primary'],
            ['text' => ($lang == 'en' ? "✅" : "") . $textbotlang['bottext']['langs']['en'], 'callback_data' => "bt_lang:en", 'style' => 'primary'],
            ['text' => ($lang == 'ru' ? "✅" : "") . $textbotlang['bottext']['langs']['ru'], 'callback_data' => "bt_lang:ru", 'style' => 'primary'],
            ['text' => ($lang == 'zh' ? "✅" : "") . $textbotlang['bottext']['langs']['zh'], 'callback_data' => "bt_lang:zh", 'style' => 'primary'],
            ['text' => ($lang == 'tk' ? "✅" : "") . $textbotlang['bottext']['langs']['tk'], 'callback_data' => "bt_lang:tk", 'style' => 'primary'],
        ];
    }
    // buttons are renamed via the ✏️ نام و نمایش دکمه‌ها manager — keep this section for messages only.
    // textbot.testExpired is also skipped here on purpose: it's only reachable
    // from inside 🔑 تنظیم اکانت تست (see $bt_home_sections below and
    // bottext_item_menu_payload() in admin.php) - it used to ALSO have its own
    // home-list row, which gave it two different entry points and made its
    // "back" button ambiguous (it could only point to one of them). Now there
    // is exactly one way in, so "back" is always correct.
    $bt_skip_keys = ['textbot.sell', 'textbot.purchasedServices', 'textbot.extend', 'textbot.userTest', 'textbot.accountWallet', 'textbot.addBalance', 'textbot.tariffList', 'textbot.support', 'textbot.help', 'textbot.affiliates', 'textbot.discount', 'textbot.wheelLuck', 'textbot.faq', 'textbot.testExpired', 'textbot.afterPay', 'textbot.preInvoice', 'textbot.getConfigHintBuy', 'textbot.getConfigHintTest', 'users.status.infoFull', 'users.Balance.sendReceipt', 'users.Balance.chargeSuccess', 'users.Balance.chargeSuccessDiscount'];
    $bt_can_react_keys = ['users.text_start', 'textbot.faqDesc', 'textbot.tariffListDesc', 'textbot.rules', 'users.unknownMsg'];
    $bt_list_setting = select("setting", "*", null, null, "select");
    $bt_list_edit = json_decode((string) ($bt_list_setting['text_edit'] ?? ''), true);
    $bt_list_layout = json_decode((string) ($bt_list_setting['keyboardmain'] ?? ''), true);
    $bt_list_st = (is_array($bt_list_layout) && isset($bt_list_layout['text_stickers']) && is_array($bt_list_layout['text_stickers'])) ? $bt_list_layout['text_stickers'] : [];
    $bt_list_re = (is_array($bt_list_layout) && isset($bt_list_layout['text_reactions']) && is_array($bt_list_layout['text_reactions'])) ? $bt_list_layout['text_reactions'] : [];
    // returns [decorated label, telegram button "style" ('success' = real green
    // button, Bot API 9.4+; '' = no style key at all, Telegram's own default]
    $bt_list_be = json_decode((string) ($bt_list_setting['button_edit'] ?? ''), true);
    // A few items were later given stores of their own beyond text/sticker/reaction.
    // Per-button label and colour overrides live in setting.button_edit under the
    // item's own dotted key, which the generic test below already covers; anything
    // that does NOT follow that shape needs an entry here, or the item can be fully
    // configured and still render as untouched - the exact complaint this fixes.
    $bt_extra_stores = [
        // 🔑 تنظیم اکانت تست also owns the config-column display settings
        'users.usertest.selectUsernamePrompt' => function ($lang, $be, $setting) {
            return !empty($be[$lang]['configDisplay'])
                || (string) ($setting['configColOrder'] ?? '') !== '';
        },
        // the confirm/cancel row is shared by all three "تأیید خرید" screens -
        // whichever one is customized, the other two should show it too
        'users.sell.preInvoice' => function ($lang, $be, $setting) {
            return !empty($be[$lang]['users.sell.confirmButtons']);
        },
        'users.sell.preInvoice2' => function ($lang, $be, $setting) {
            return !empty($be[$lang]['users.sell.confirmButtons']);
        },
        'textbot.preInvoice' => function ($lang, $be, $setting) {
            return !empty($be[$lang]['users.sell.confirmButtons']);
        },
        // the buy-only config-column display settings (separate from usertest's above)
        'users.status.getConfigHintBuy' => function ($lang, $be, $setting) {
            return !empty($be[$lang]['configDisplayBuy'])
                || (string) ($setting['configColOrderBuy'] ?? '') !== '';
        },
    ];
    $bt_decorate = function ($key, $label) use ($lang, $bt_list_edit, $bt_list_st, $bt_list_re, $bt_can_react_keys, $bt_list_be, $bt_list_setting, $bt_extra_stores) {
        // a real dotted lookup, not [$parts[0]][$parts[1]]: three-part keys such as
        // users.sell.serviceSelect and users.sell.selectCategory share their first
        // two segments, so the old test turned every sibling green the moment one
        // of them was edited - and missed nothing only because it over-matched
        $custom = is_array($bt_list_edit) && bottext_dotted_isset($bt_list_edit[$lang] ?? null, $key);
        $sticker = bt_media_lookup($bt_list_st, $key, $lang);
        $react = in_array($key, $bt_can_react_keys, true) ? bt_media_lookup($bt_list_re, $key, $lang) : '';
        $buttons = is_array($bt_list_be) && !empty($bt_list_be[$lang][$key]);
        if (!$buttons && isset($bt_extra_stores[$key])) {
            $buttons = (bool) $bt_extra_stores[$key]($lang, is_array($bt_list_be) ? $bt_list_be : [], $bt_list_setting);
        }
        $suffix = '';
        if ($custom) {
            $suffix .= ' ✏️';
        }
        if ($react !== '') {
            $suffix .= ' ❤️';
        }
        if ($sticker !== '') {
            $suffix .= ' 🖼';
        }
        if ($buttons) {
            $suffix .= ' 🔘';
        }
        $style = ($custom || $buttons || $sticker !== '' || $react !== '') ? 'success' : '';
        return [$label . $suffix, $style];
    };
    // items tagged with a 'group' live in their own submenu instead of the
    // main list - collect them here so a single summary row can open it
    $bt_grouped = [];
    foreach ($keyboard_list_text as $data) {
        if (!empty($data['group'])) {
            $bt_grouped[$data['group']][] = $data;
        }
    }
    if ($groupFilter !== null) {
        $bt_cur_section = null;
        foreach (($bt_grouped[$groupFilter] ?? []) as $data) {
            $bt_section = $data['section'] ?? null;
            if ($bt_section !== null && $bt_section !== $bt_cur_section) {
                // white/default style - a non-navigating label, not an action
                $keyboard_text['inline_keyboard'][] = [['text' => bt_section_meta($bt_section)['label'], 'callback_data' => "bt_sep|{$bt_section}"]];
                $bt_cur_section = $bt_section;
            }
            list($bt_label, $bt_style) = $bt_decorate($data['key'], $data['label']);
            // blue by default, green (from $bt_decorate) once something is customized
            $bt_btn = ['text' => $bt_label, 'callback_data' => "bt_edit|$lang|{$data['key']}", 'style' => ($bt_style !== '' ? $bt_style : 'primary')];
            $keyboard_text['inline_keyboard'][] = [$bt_btn];
            if ($groupFilter === 'myservices' && $data['key'] === 'users.sell.service_sell') {
                $keyboard_text['inline_keyboard'][] = [['text' => bt_section_meta('myservices_related')['label'], 'callback_data' => 'bt_sep|myservices_related']];
                list($bt_sf_label, $bt_sf_style) = $bt_decorate('users.status.infoFull', '📊 پیام و دکمه‌های صفحه‌ی وضعیت سرویس');
                $keyboard_text['inline_keyboard'][] = [['text' => $bt_sf_label, 'callback_data' => "bt_edit|$lang|users.status.infoFull", 'style' => ($bt_sf_style !== '' ? $bt_sf_style : 'primary')]];
            }
            if ($groupFilter === 'myservices' && $data['key'] === 'users.status.getConfigHintBuy') {
                // caption is edited via the normal bt_edit button above - this
                // extra row opens the buy-only column-order/button-styling hub,
                // a full independent clone of usertest's "🗂 تنظیم نمایش و کپشن
                // کانفیگ" (function.php: config_col_order_payload($kind='buy'))
                $keyboard_text['inline_keyboard'][] = [['text' => '🗂 تنظیم ترتیب و رنگ دکمه‌های دریافت کانفیگ', 'callback_data' => "btact|cfgcolbuy|{$lang}|users.status.getConfigHintBuy", 'style' => 'primary']];
            }
            if ($groupFilter === 'buyflow' && $data['key'] === 'users.sell.serviceSelectFirst') {
                // the 3 sub-screens BtnStyle's own hub offered are exposed
                // directly here now, under their own white divider, instead of
                // behind one more tap into a separate hub screen. Each still
                // targets its existing, already-working btnstyle_kindhub:
                // callback - no new dispatch handler needed for any of them.
                $keyboard_text['inline_keyboard'][] = [['text' => bt_section_meta('btnstyle')['label'], 'callback_data' => 'bt_sep|btnstyle']];
                $keyboard_text['inline_keyboard'][] = [['text' => $textbotlang['Admin']['LangScope']['panelStyleBtn'], 'callback_data' => 'btnstyle_kindhub:panel:fa', 'style' => 'primary']];
                $keyboard_text['inline_keyboard'][] = [['text' => $textbotlang['Admin']['LangScope']['categoryStyleBtn'], 'callback_data' => 'btnstyle_kindhub:category:fa', 'style' => 'primary']];
                $keyboard_text['inline_keyboard'][] = [['text' => $textbotlang['Admin']['LangScope']['productStyleBtn'], 'callback_data' => 'btnstyle_kindhub:product:fa', 'style' => 'primary']];
            }
        }
        if ($groupFilter === 'buyflow') {
            $keyboard_text['inline_keyboard'][] = [['text' => bt_section_meta('cfgdeliv_link')['label'], 'callback_data' => 'bt_sep|cfgdeliv_link']];
            $keyboard_text['inline_keyboard'][] = [['text' => '📌 نحوه‌ی نمایش کانفیگ', 'callback_data' => "cfgdeliv|list|{$lang}|b", 'style' => 'primary']];
        }
        $keyboard_text['inline_keyboard'][] = [['text' => $textbotlang['bottext']['resetAllLabel'], 'callback_data' => "bt_group_resetall|$lang|$groupFilter", 'style' => 'danger']];
        $keyboard_text['inline_keyboard'][] = [['text' => $textbotlang['bottext']['backToListLabel'], 'callback_data' => "btact|back|$lang", 'style' => 'danger']];
        $keyboard_text['inline_keyboard'][] = [['text' => $textbotlang['bottext']['btn_close'], 'callback_data' => 'bt_close', 'style' => 'danger']];
        $bt_captionKey = ($groupFilter === 'myservices') ? 'groupServicesCaption' : 'groupBuyflowCaption';
        $bt_caption_tpl = $bt_tab_texts['bottext'][$bt_captionKey] ?? $textbotlang['bottext'][$bt_captionKey];
        $bt_caption = strtr($bt_caption_tpl, ['{lang}' => $textbotlang['bottext']['langs'][$lang] ?? $lang]);
        return [$bt_caption, json_encode($keyboard_text)];
    }
    // items with a genuinely separate purpose get grouped under a white,
    // non-navigating section label (bt_sep|) - mirrors the exact pattern
    // already used inside the 🛒 پیام‌های مراحل خرید group submenu. Defined
    // here (not as a bottext.items 'section' tag) so this stays self-contained
    // and independent of the underlying array's storage order.
    $bt_home_sections = [
        'home_general' => ['users.text_start', 'textbot.faqDesc', 'textbot.tariffListDesc', 'textbot.rules'],
        // preInvoice/afterPay used to be listed directly here; they now live
        // inside the 🛒 پیام‌های مراحل خرید group itself (section
        // 'preinvoice_afterpay') - this divider now only leads into the two
        // ready-made submenus below, so its item list stays empty
        'home_purchase' => [],
        'home_usertest' => ['users.usertest.selectUsernamePrompt'],
    ];
    $bt_home_sectioned_keys = array_merge(...array_values($bt_home_sections));
    foreach ($bt_home_sections as $bt_sec_key => $bt_sec_items) {
        $keyboard_text['inline_keyboard'][] = [['text' => bt_section_meta($bt_sec_key)['label'], 'callback_data' => "bt_sep|{$bt_sec_key}"]];
        foreach ($bt_sec_items as $bt_item_key) {
            $bt_data = null;
            foreach ($keyboard_list_text as $d) {
                if (($d['key'] ?? '') === $bt_item_key) {
                    $bt_data = $d;
                    break;
                }
            }
            if ($bt_data === null) {
                continue;
            }
            list($bt_label, $bt_style) = $bt_decorate($bt_data['key'], $bt_data['label']);
            // blue by default (home list), green once customized
            $bt_btn = ['text' => $bt_label, 'callback_data' => "bt_edit|$lang|{$bt_data['key']}", 'style' => ($bt_style !== '' ? $bt_style : 'primary')];
            $keyboard_text['inline_keyboard'][] = [$bt_btn];
        }
        if ($bt_sec_key === 'home_purchase') {
            // the two ready-made submenus belong in this section - each row
            // has to answer for its own children: an item customized inside
            // the submenu otherwise leaves no trace at all on this screen.
            // Fires once per render regardless of $bt_sec_items (now empty -
            // preInvoice/afterPay moved into the buyflow group itself)
            if (!empty($bt_grouped['buyflow'])) {
                $bt_group_custom = false;
                foreach ($bt_grouped['buyflow'] as $bt_g) {
                    list(, $bt_g_style) = $bt_decorate($bt_g['key'], $bt_g['label']);
                    if ($bt_g_style !== '') {
                        $bt_group_custom = true;
                        break;
                    }
                }
                $keyboard_text['inline_keyboard'][] = [['text' => $textbotlang['bottext']['groupBuyflowLabel'], 'callback_data' => "bt_group|$lang|buyflow", 'style' => 'primary']];
            }
            if (!empty($bt_grouped['myservices'])) {
                $bt_svc_custom = false;
                foreach ($bt_grouped['myservices'] as $bt_g) {
                    list(, $bt_g_style) = $bt_decorate($bt_g['key'], $bt_g['label']);
                    if ($bt_g_style !== '') {
                        $bt_svc_custom = true;
                        break;
                    }
                }
                $keyboard_text['inline_keyboard'][] = [['text' => $textbotlang['bottext']['groupServicesLabel'], 'callback_data' => "bt_group|$lang|myservices", 'style' => 'primary']];
            }
        }
    }
    // anything ungrouped that isn't in one of the sections above still needs
    // to render somewhere, or a future item silently vanishes from this screen
    foreach ($keyboard_list_text as $data) {
        if (in_array($data['key'], $bt_skip_keys, true) || !empty($data['group']) || (isset($data['label']) && mb_strpos($data['label'], 'دکمه:') === 0) || in_array($data['key'], $bt_home_sectioned_keys, true)) {
            continue;
        }
        list($bt_label, $bt_style) = $bt_decorate($data['key'], $data['label']);
        $bt_btn = ['text' => $bt_label, 'callback_data' => "bt_edit|$lang|{$data['key']}", 'style' => ($bt_style !== '' ? $bt_style : 'primary')];
        $keyboard_text['inline_keyboard'][] = [$bt_btn];
    }
    // relocated here from its old home under ⚙️ تنظیمات عمومی so both sticker
    // systems live under one umbrella - this hub controls the main-menu
    // reply-keyboard buttons themselves (color/emoji/sticker/layout/rename),
    // a different data model from the per-message items above
    $keyboard_text['inline_keyboard'][] = [['text' => bt_section_meta('home_tools')['label'], 'callback_data' => 'bt_sep|home_tools']];
    $keyboard_text['inline_keyboard'][] = [['text' => $textbotlang['bottext']['btnSettingsLabel'], 'callback_data' => 'bt_btnsettings', 'style' => 'primary']];
    // promoted out of the button-settings hub into its own direct row - it
    // controls the end-user language-picker menu, unrelated to button appearance
    $keyboard_text['inline_keyboard'][] = [['text' => $textbotlang['bottext']['langSwitchLabel'], 'callback_data' => 'bt_langswitch', 'style' => 'primary']];
    // the warning tiers live in their own column and have no bottext key, so this
    // row does its own check instead of going through $bt_decorate. It asks
    // "is anything customized?", NOT "does a threshold exist?" - a bare threshold
    // is structure, and its own reset button never removes one, so keying the
    // green off the array being non-empty made the row impossible to clear.
    $bt_volpct_btn = ['text' => '🔋 هشدار مصرف بسته', 'callback_data' => "volpct|hub|$lang", 'style' => 'primary'];
    if (function_exists('volumepct_has_custom') && volumepct_has_custom($lang)) {
        $bt_volpct_btn['style'] = 'success';
    }
    $keyboard_text['inline_keyboard'][] = [$bt_volpct_btn];
    list($bt_um_label, $bt_um_style) = $bt_decorate('users.unknownMsg', $bt_unknown_label);
    $bt_um_btn = ['text' => $bt_um_label, 'callback_data' => "bt_edit|$lang|users.unknownMsg", 'style' => ($bt_um_style !== '' ? $bt_um_style : 'primary')];
    $keyboard_text['inline_keyboard'][] = [$bt_um_btn];
    $keyboard_text['inline_keyboard'][] = [['text' => $textbotlang['bottext']['resetAllLabel'], 'callback_data' => "bt_resetall|$lang", 'style' => 'danger']];
    $keyboard_text['inline_keyboard'][] = [['text' => $textbotlang['bottext']['btn_close'], 'callback_data' => 'bt_close', 'style' => 'danger']];
    $bt_caption_tpl = $bt_tab_texts['bottext']['home_text'] ?? $textbotlang['bottext']['home_text'];
    $bt_caption = strtr($bt_caption_tpl, ['{lang}' => $textbotlang['bottext']['langs'][$lang] ?? $lang]);
    return [$bt_caption, json_encode($keyboard_text)];
}

//----------------[  normalize custom-emoji button text  ]----------------
// if user taps a reply button whose text was customized with an emoji,
// rewrite $text back to the default label so bot matching keeps working
if (!empty($text) && !empty($keyboardRows)) {
    foreach ($keyboardRows as $ne_row) {
        if (!is_array($ne_row)) {
            continue;
        }
        foreach ($ne_row as $ne_btn) {
            if (is_array($ne_btn) && isset($ne_btn['text'], $replacements[$ne_btn['text']])) {
                if (!empty($ne_btn['custom_text'])) {
                    $ne_base = $ne_btn['custom_text'];
                    if (!empty($ne_btn['emoji'])) {
                        if ($global_emoji_pos === 'left') {
                            $ne_base = strip_leading_emoji($ne_btn['custom_text']) . ' ' . $ne_btn['emoji'];
                        } else {
                            $ne_base = $ne_btn['emoji'] . ' ' . strip_leading_emoji($ne_btn['custom_text']);
                        }
                    } elseif (!empty($ne_btn['icon_emoji'])) {
                        $ne_base = strip_leading_emoji($ne_btn['custom_text']);
                    } else {
                        list($ne_ct_emoji, $ne_ct_rest) = split_leading_emoji($ne_btn['custom_text']);
                        if ($ne_ct_emoji !== '') {
                            $ne_base = ($global_emoji_pos === 'left') ? $ne_ct_rest . ' ' . $ne_ct_emoji : $ne_ct_emoji . ' ' . $ne_ct_rest;
                        }
                    }
                    if ($text === $ne_base) {
                        $text = $replacements[$ne_btn['text']];
                        break 2;
                    }
                }
                if (!empty($ne_btn['emoji'])) {
                    if ($global_emoji_pos === 'left') {
                        $ne_constructed = strip_leading_emoji($replacements[$ne_btn['text']]) . ' ' . $ne_btn['emoji'];
                    } else {
                        $ne_constructed = $ne_btn['emoji'] . ' ' . strip_leading_emoji($replacements[$ne_btn['text']]);
                    }
                    if ($text === $ne_constructed) {
                        $text = $replacements[$ne_btn['text']];
                        break 2;
                    }
                }
                if (!empty($ne_btn['icon_emoji'])) {
                    $ne_constructed = strip_leading_emoji($replacements[$ne_btn['text']]);
                    if ($text === $ne_constructed) {
                        $text = $replacements[$ne_btn['text']];
                        break 2;
                    }
                }
                list($ne_def_emoji, $ne_def_rest) = split_leading_emoji($replacements[$ne_btn['text']]);
                if ($ne_def_emoji !== '') {
                    $ne_repos = ($global_emoji_pos === 'left') ? $ne_def_rest . ' ' . $ne_def_emoji : $ne_def_emoji . ' ' . $ne_def_rest;
                    if ($text === $ne_repos) {
                        $text = $replacements[$ne_btn['text']];
                        break 2;
                    }
                }
                if ($text === strip_leading_emoji($replacements[$ne_btn['text']])) {
                    $text = $replacements[$ne_btn['text']];
                    break 2;
                }
            }
        }
    }
}

//----------------[  button premium sticker sender  ]----------------
// when a user taps a main-menu button, send its custom sticker before the response
$sticker_callback_map = [
    'buy' => 'text_sell',
    'buyfresh' => 'text_sell',
    'account' => 'accountwallet',
    'Add_Balance' => 'addbalance',
    'Tariff_list' => 'text_Tariff_list',
    'wheel_luck' => 'text_wheel_luck',
    'affiliatesbtn' => 'text_affiliates',
    'extendbtn' => 'text_extend',
    'supportbtns' => 'text_support',
    'helpbtns' => 'text_help',
    'usertestbtn' => 'text_usertest',
    'change_language' => 'text_change_language',
];
$sticker_btn_key = null;
$menu_tap_from_text = false;
if (!empty($datain) && isset($sticker_callback_map[$datain])) {
    $sticker_btn_key = $sticker_callback_map[$datain];
} elseif (!empty($text)) {
    $text_stripped = strip_leading_emoji($text);
    foreach ($replacements as $rep_key => $rep_val) {
        if ($text === $rep_val || $text_stripped === strip_leading_emoji($rep_val)) {
            $sticker_btn_key = $rep_key;
            $menu_tap_from_text = true;
            break;
        }
    }
}
// Tapping a main-menu button on the reply keyboard posts the label itself as a
// message FROM the user, which then sits in the chat forever. Remember its id so
// the screen it opened can take it away from its own close button, together with
// the caption and the sticker. Callback taps are deliberately excluded: there
// $message_id is the bot's own glass main menu, which has to survive.
if ($menu_tap_from_text) {
    $mt_msId = (int) ($message_id ?? 0);
    update("user", "menu_tap_id", $mt_msId > 0 ? (string) $mt_msId : "0", "id", $from_id);
} elseif ($sticker_btn_key !== null) {
    // a glass tap opens a screen the stashed message does not belong to - drop
    // it rather than risk closing an unrelated message later
    update("user", "menu_tap_id", "0", "id", $from_id);
}
if ($sticker_btn_key !== null) {
    // only the sticker sent on THIS tap may be closed later: a button with no
    // sticker of its own would otherwise inherit the previous one's id and its
    // ❌ بستن would delete a sticker belonging to an older screen
    update("user", "menu_sticker_id", "0", "id", $from_id);
}
if ($sticker_btn_key === 'text_sell') {
    // a fresh buy attempt starts clean - never let a leftover id from an
    // earlier purchase get mistaken for this run's sticker by KeyboardCategory()
    update("user", "Processing_value_tow", "", "id", $from_id);
}
if ($sticker_btn_key !== null && !empty($keyboardRows) && function_exists('telegram')) {
    foreach ($keyboardRows as $st_row) {
        if (!is_array($st_row)) {
            continue;
        }
        foreach ($st_row as $st_btn) {
            if (is_array($st_btn) && ($st_btn['text'] ?? '') === $sticker_btn_key && !empty($st_btn['sticker'])) {
                $st_sent = telegram('sendSticker', [
                    'chat_id' => $from_id,
                    'sticker' => $st_btn['sticker'],
                ]);
                // stashed so the location_ handler in index.php can delete this
                // sticker once a panel is picked and the next screen replaces the
                // panel list - only for the buy button, never other main-menu ones
                if ($sticker_btn_key === 'text_sell') {
                    $st_stickerId = (int) ($st_sent['result']['message_id'] ?? 0);
                    if ($st_stickerId > 0) {
                        update("user", "Processing_value_tow", (string) $st_stickerId, "id", $from_id);
                    }
                }
                // every main-menu sticker is remembered here so whichever screen
                // it accompanied can remove it from its own ❌ بستن button. Kept
                // in a dedicated column rather than the heavily-overloaded
                // Processing_value_tow, which other flows re-purpose mid-request.
                $st_msId = (int) ($st_sent['result']['message_id'] ?? 0);
                update("user", "menu_sticker_id", $st_msId > 0 ? (string) $st_msId : "0", "id", $from_id);
                break 2;
            }
        }
    }
}

// ---- panel settings menu: flat definitions above are kept as the source of
// truth for the submenus; the $option* variables that ~50 call sites already
// send now carry the grouped top-level menu instead of one long flat list ----
$panel_menu_flat = [
    'marzban' => $optionMarzban,
    'rebecca' => $optionrebecca,
    'ibsng' => $optionibsng,
    'mikrotik' => $option_mikrotik,
    's_ui' => $options_ui,
    'WGDashboard' => $optionwg,
    'marzneshin' => $optionmarzneshin,
    'Manualsale' => $optionManualsale,
    'x-ui_single' => $optionX_ui_single,
    'alireza_single' => $optionalireza_single,
    'hiddify' => $optionhiddfy,
    'mirza_agent' => $option_mirza,
];
$panel_menu_top = [];
foreach ($panel_menu_flat as $__pmtype => $__pmflat) {
    $panel_menu_top[$__pmtype] = panel_menu_top_json($__pmflat, $textbotlang);
}
$optionMarzban = $panel_menu_top['marzban'];
$optionrebecca = $panel_menu_top['rebecca'];
$optionibsng = $panel_menu_top['ibsng'];
$option_mikrotik = $panel_menu_top['mikrotik'];
$options_ui = $panel_menu_top['s_ui'];
$optionwg = $panel_menu_top['WGDashboard'];
$optionmarzneshin = $panel_menu_top['marzneshin'];
$optionManualsale = $panel_menu_top['Manualsale'];
$optionX_ui_single = $panel_menu_top['x-ui_single'];
$optionalireza_single = $panel_menu_top['alireza_single'];
$optionhiddfy = $panel_menu_top['hiddify'];
$option_mirza = $panel_menu_top['mirza_agent'];
// If the admin is currently sitting inside one of the panel submenus, every
// "action finished" message should land them back in that submenu instead of
// bouncing them out to the top menu. The ~50 call sites all send one of the
// $option* variables, so overriding them here covers every one of them without
// touching a single call site. Opening a panel afresh resets this (admin.php,
// the GetLocationEdit branch).
if (!empty($user['panel_submenu']) && !empty($user['Processing_value'])) {
    $__pmpanel = select("marzban_panel", "*", "name_panel", $user['Processing_value'], "select");
    if (!empty($__pmpanel)) {
        $__pmtype2 = $__pmpanel['type'] ?? 'marzban';
        $__pmflat2 = $panel_menu_flat[$__pmtype2] ?? $panel_menu_flat['marzban'];
        $__pmsub = panel_menu_submenu_json($__pmflat2, $user['panel_submenu'], $textbotlang);
        if ($__pmsub !== null) {
            $optionMarzban = $__pmsub;
            $optionrebecca = $__pmsub;
            $optionibsng = $__pmsub;
            $option_mikrotik = $__pmsub;
            $options_ui = $__pmsub;
            $optionwg = $__pmsub;
            $optionmarzneshin = $__pmsub;
            $optionManualsale = $__pmsub;
            $optionX_ui_single = $__pmsub;
            $optionalireza_single = $__pmsub;
            $optionhiddfy = $__pmsub;
            $option_mirza = $__pmsub;
        }
    }
}
