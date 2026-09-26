<?php
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';
$textbotlang = languagechange();
if(!is_file('info'))return;
if(!is_file('users.json'))return;


$userid = json_decode(file_get_contents('users.json'));
if(is_file('info')){
$info = json_decode(file_get_contents('info'),true);
}
$count = 0;
if(count($userid) == 0){
    if(isset($info['id_admin'])){
    deletemessage($info['id_admin'], $info['id_message']);
    sendmessage($info['id_admin'], $textbotlang['hardcoded']['bulkMessageDone'], null, 'HTML');
    unlink('info');
    unlink('users.json');
    }
    return;
    
}
// the buttons under the message are in its readers' language (the whole
// list is one language tab's users); the admin's progress stays Persian
$bm_lang = in_array($info['lang'] ?? '', panel_langs(), true) ? $info['lang'] : 'fa';
$bm_tx = lang_tab_texts($bm_lang);
$count_remein = count($userid);
$textprocces = sprintf($textbotlang['hardcoded']['bulkMessageProgress'], $count_remein);
$cancelmessage = json_encode([
        'inline_keyboard' => [
            [
                ['text' => $textbotlang['keyboard']['cancelOperation'], 'callback_data' => 'cancel_sendmessage'],
            ],
        ]
    ]);
Editmessagetext($info['id_admin'], $info['id_message'],$textprocces, $cancelmessage);
// each button as that language's tab has it in 🎨 (👤 پیام‌ها و دکمه‌های مدیریت کاربر)
$bm_btn = function ($alias) use ($bm_lang, $bm_tx) {
    $def = genbtn_defs($alias, $bm_tx)[0];
    // marked with which button it is, for its own tap sticker (genbtn_tap_cb)
    return json_encode(['inline_keyboard' => [[genbtn_render($def, genbtn_override($bm_lang, genbtn_alias_to_key($alias), 0), genbtn_tap_cb($alias, $def['callback_data']))]]]);
};
$keyboardbuy = $bm_btn('b1');
$keyboardstart = $bm_btn('b2');
$keyboardusertest = $bm_btn('b3');
$keyboardhelpbtn = $bm_btn('b4');
$keyboardaffiliates = $bm_btn('b5');
$keyboardaddbalance = $bm_btn('b6');
// a broadcast the admin wrote goes out in this tab's 🎨 wrapping ({message} is
// the admin's text) with that item's sticker; the top-up gift has an item of
// its own and goes as it is
$bm_hint = !empty($info['hint']) ? $info['hint'] : 'users.broadcast.message';
if (empty($info['hint']) && ($info['type'] == "sendmessage" or $info['type'] == "xdaynotmessage")) {
    $bm_tpl = (string) ($bm_tx['users']['broadcast']['message'] ?? '{message}');
    $info['message'] = strpos($bm_tpl, '{message}') !== false
        ? str_replace('{message}', (string) $info['message'], $bm_tpl)
        : trim($bm_tpl) . "\n\n" . $info['message'];
}
// a forwarded message cannot be wrapped, but its sticker can go before it
$bm_fwdSticker = '';
if ($info['type'] == "forwardmessage" && function_exists('bt_effective_sticker')) {
    $bm_layout = json_decode((string) (select("setting", "*", null, null, "select")['keyboardmain'] ?? ''), true);
    $bm_fwdSticker = bt_effective_sticker(is_array($bm_layout['text_stickers'] ?? null) ? $bm_layout['text_stickers'] : [], 'users.broadcast.message', $bm_lang);
}
for ($i = 0; $i < 20; $i++) {
    $iduser = $userid[$i];
    unset($userid[$i]);
    $userid = array_values($userid);
    if ($info['type'] == "unpinmessage") {
        unpinmessage($iduser->id);
    } elseif ($info['type'] == "sendmessage" or $info['type'] == "xdaynotmessage") {
        // its 🎨 sticker, in each reader's language
        if (function_exists('bottext_extras_key_hint')) {
            bottext_extras_key_hint($bm_hint);
        }
        if ($info['btnmessage'] == "none") {
            $meesage = sendmessage($iduser->id, $info['message'], null, 'HTML');
        } elseif ($info['btnmessage'] == "buy") {
            $meesage = sendmessage($iduser->id, $info['message'], $keyboardbuy, 'HTML');
        } elseif ($info['btnmessage'] == "start") {
            $meesage = sendmessage($iduser->id, $info['message'], $keyboardstart, 'HTML');
        } elseif ($info['btnmessage'] == "usertestbtn") {
            $meesage = sendmessage($iduser->id, $info['message'], $keyboardusertest, 'HTML');
        } elseif ($info['btnmessage'] == "helpbtn") {
            $meesage = sendmessage($iduser->id, $info['message'], $keyboardhelpbtn, 'HTML');
        } elseif ($info['btnmessage'] == "affiliatesbtn") {
            $meesage = sendmessage($iduser->id, $info['message'], $keyboardaffiliates, 'HTML');
        } elseif ($info['btnmessage'] == "addbalance") {
            $meesage = sendmessage($iduser->id, $info['message'], $keyboardaddbalance, 'HTML');
        }

        if ($meesage['ok'] == false and $meesage['description'] == "Forbidden: bot was blocked by the user") {
            $invoicecount = select("invoice", "*", "id_user", $iduser->id, "count");
            $userinfo = select("user", "Balance", "id", $iduser->id, "select");
            if ($invoicecount == 0 and $userinfo['Balance'] == 0) {
                $Id_user = $iduser->id;
                $stmt = $pdo->prepare("DELETE FROM user WHERE id = :mp1");
                $stmt->execute([':mp1' => $Id_user]);
            }
        }

        if ($meesage['ok'] and $info['pingmessage'] == "yes") {
            pinmessage($iduser->id, $meesage['result']['message_id']);
        }
    } elseif ($info['type'] == "forwardmessage") {
        if ($bm_fwdSticker !== '') {
            telegram('sendSticker', ['chat_id' => $iduser->id, 'sticker' => $bm_fwdSticker]);
        }
        $meesage = forwardMessage($info['id_admin'], $info['message'], $iduser->id);
        if ($meesage['ok'] and $info['pingmessage'] == "yes") {
            pinmessage($iduser->id, $meesage['result']['message_id']);
        }
    }
}

file_put_contents('users.json',json_encode($userid,true));