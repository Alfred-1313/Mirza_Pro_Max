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
    return json_encode(['inline_keyboard' => [[genbtn_render($def, genbtn_override($bm_lang, genbtn_alias_to_key($alias), 0), $def['callback_data'])]]]);
};
$keyboardbuy = $bm_btn('b1');
$keyboardstart = $bm_btn('b2');
$keyboardusertest = $bm_btn('b3');
$keyboardhelpbtn = $bm_btn('b4');
$keyboardaffiliates = $bm_btn('b5');
$keyboardaddbalance = $bm_btn('b6');
for ($i = 0; $i < 20; $i++) {
    $iduser = $userid[$i];
    unset($userid[$i]);
    $userid = array_values($userid);
    if ($info['type'] == "unpinmessage") {
        unpinmessage($iduser->id);
    } elseif ($info['type'] == "sendmessage" or $info['type'] == "xdaynotmessage") {
        // a message with a 🎨 item of its own (the top-up gift) gets its sticker,
        // in each reader's language
        if (!empty($info['hint']) && function_exists('bottext_extras_key_hint')) {
            bottext_extras_key_hint($info['hint']);
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
        $meesage = forwardMessage($info['id_admin'], $info['message'], $iduser->id);
        if ($meesage['ok'] and $info['pingmessage'] == "yes") {
            pinmessage($iduser->id, $meesage['result']['message_id']);
        }
    }
}

file_put_contents('users.json',json_encode($userid,true));