<?php
require_once 'config.php';
function telegram($method, $datas = [], $token = null)
{
    global $APIKEY;

    $GLOBALS['bt_any_reply'] = true;
    $token = $token === null ? $APIKEY : $token;
    $url = "https://api.telegram.org/bot" . $token . "/" . $method;

    if (isset($datas['message_thread_id']) && intval($datas['message_thread_id']) <= 0) {
        unset($datas['message_thread_id']);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        error_log('Unable to initialise cURL for Telegram request.');
        return [
            'ok' => false,
            'description' => 'Unable to initialise cURL for Telegram request.'
        ];
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);

    $rawResponse = curl_exec($ch);
    if ($rawResponse === false) {
        $curlError = curl_error($ch);

        if ($curlError !== '') {
            error_log('Telegram request failed: ' . $curlError);
        }

        return [
            'ok' => false,
            'description' => $curlError !== '' ? $curlError : 'Telegram request failed.'
        ];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $decodedResponse = json_decode($rawResponse, true);
    if (!is_array($decodedResponse)) {
        $logSnippet = substr($rawResponse, 0, 200);
        error_log(sprintf('Invalid response from Telegram API (HTTP %d): %s', $httpCode, $logSnippet));

        return [
            'ok' => false,
            'error_code' => $httpCode,
            'description' => 'Invalid response received from Telegram.'
        ];
    }

    if (isset($decodedResponse['ok']) && !$decodedResponse['ok']) {
        error_log(json_encode($decodedResponse));
    }

    return $decodedResponse;
}
//----------------[  bot text extras: sticker + reaction for customized texts  ]----------------
if (!function_exists('bottext_resolve_key')) {
    function bottext_resolve_key($key)
    {
        global $textbotlang;
        $node = $textbotlang;
        foreach (explode('.', (string) $key) as $p) {
            if (!is_array($node) || !array_key_exists($p, $node)) {
                return '';
            }
            $node = $node[$p];
        }
        return is_string($node) ? $node : '';
    }
}
if (!function_exists('bottext_extras_for_text')) {
    function bottext_extras_for_text($text)
    {
        $text = (string) $text;
        if (trim($text) === '') {
            return null;
        }
        static $bts_cache = null;
        if ($bts_cache === null) {
            $bts_cache = [];
            $bts_setting = select("setting", "*", null, null, "select");
            $bts_layout = json_decode((string) ($bts_setting['keyboardmain'] ?? ''), true);
            $bts_st = (is_array($bts_layout) && isset($bts_layout['text_stickers']) && is_array($bts_layout['text_stickers'])) ? $bts_layout['text_stickers'] : [];
            $bts_re = (is_array($bts_layout) && isset($bts_layout['text_reactions']) && is_array($bts_layout['text_reactions'])) ? $bts_layout['text_reactions'] : [];
            foreach (array_unique(array_merge(array_keys($bts_st), array_keys($bts_re))) as $bts_k) {
                $bts_val = bottext_resolve_key($bts_k);
                if (trim($bts_val) === '') {
                    continue;
                }
                // match against the literal segments around sprintf placeholders
                $bts_segs = preg_split('/%[-+0-9.]*[a-zA-Z]|\{[a-zA-Z_]+\}/', $bts_val);
                $bts_seg0 = trim($bts_segs[0] ?? '');
                $bts_seg1 = trim($bts_segs[1] ?? '');
                $bts_seg0 = mb_substr($bts_seg0, 0, 40);
                $bts_seg1 = mb_substr($bts_seg1, 0, 40);
                if (mb_strlen($bts_seg0) < 3 && $bts_seg1 === '') {
                    continue;
                }
                global $user;
                $bts_lang = (is_array($user) && !empty($user['lang'])) ? $user['lang'] : 'fa';
                $bts_cache[] = [
                    'key' => $bts_k,
                    'seg0' => $bts_seg0,
                    'seg1' => $bts_seg1,
                    'sticker' => bt_media_lookup($bts_st, $bts_k, $bts_lang),
                    'reaction' => bt_media_lookup($bts_re, $bts_k, $bts_lang),
                ];
            }
        }
        foreach ($bts_cache as $bts_it) {
            if (($bts_it['seg0'] === '' || mb_strpos($text, $bts_it['seg0']) === 0) && ($bts_it['seg1'] === '' || mb_strpos($text, $bts_it['seg1']) !== false)) {
                return $bts_it;
            }
        }
        return null;
    }
}
if (!function_exists('bottext_extras_sent_store')) {
    // one de-dupe store for the whole request, shared by everything below so a
    // screen cannot fire its sticker twice - and so it can be inspected without
    // being consumed
    function &bottext_extras_sent_store()
    {
        static $bts_sent = [];
        return $bts_sent;
    }
}
if (!function_exists('bottext_sticker_forget')) {
    // Deletes the sticker the message manager auto-fired for the screen the user
    // is currently looking at, and clears the note of it.
    //
    // A sticker is decoration for one screen, but it is a separate message, so
    // replacing the screen used to leave it stranded: choosing a category
    // swapped the caption for the product list and left the category sticker
    // hanging above it. Called whenever that screen is replaced, and again
    // before a new sticker is fired, so two never coexist.
    function bottext_sticker_retire($chat_id, $owner_id = null)
    {
        static $bts_busy = false;
        if ($bts_busy || !function_exists('select') || !function_exists('update')) {
            return;
        }
        $bts_row = select("user", "*", "id", $chat_id, "select");
        if (!is_array($bts_row) || !array_key_exists('bt_sticker_id', $bts_row)) {
            return;
        }
        $bts_old = (string) $bts_row['bt_sticker_id'];
        if (!ctype_digit($bts_old) || intval($bts_old) <= 0) {
            return;
        }
        // when an owner is named, only retire the sticker if it really belongs
        // to that message - deleting some unrelated message must not strip a
        // sticker whose own caption is still on screen
        if ($owner_id !== null) {
            $bts_owner = (string) ($bts_row['bt_sticker_owner'] ?? '');
            if (!ctype_digit($bts_owner) || intval($bts_owner) !== intval($owner_id)) {
                return;
            }
        }
        // clear the note BEFORE deleting: deletemessage() calls back in here, and
        // an empty note is what stops that from going round again
        update("user", "bt_sticker_id", "0", "id", $chat_id);
        update("user", "bt_sticker_owner", "0", "id", $chat_id);
        $bts_busy = true;
        deletemessage($chat_id, intval($bts_old));
        $bts_busy = false;
    }
}
if (!function_exists('bottext_sticker_remember')) {
    // $owner_id is the message the sticker was fired for. The sticker lives
    // exactly as long as that message does.
    function bottext_sticker_remember($chat_id, $message_id, $owner_id = 0)
    {
        if (!function_exists('update')) {
            return;
        }
        $bts_id = intval($message_id);
        update("user", "bt_sticker_id", $bts_id > 0 ? (string) $bts_id : "0", "id", $chat_id);
        update("user", "bt_sticker_owner", intval($owner_id) > 0 ? (string) intval($owner_id) : "0", "id", $chat_id);
    }
}
if (!function_exists('bottext_pending_sticker')) {
    // Would $text still produce a sticker if it were sent right now?
    // Deliberately does NOT mark it as sent - only an actual send does that.
    function bottext_pending_sticker($chat_id, $text)
    {
        $bts_extras = bottext_extras_for_text($text);
        if ($bts_extras === null || $bts_extras['sticker'] === '') {
            return false;
        }
        $bts_sent = &bottext_extras_sent_store();
        return !isset($bts_sent[$bts_extras['key'] . '|' . $chat_id]);
    }
}
if (!function_exists('bottext_fire_extras')) {
    // Sends the sticker (and the reaction, where there is a user message to
    // react to) that the message manager has configured for $text, and returns
    // the sticker's message_id.
    //
    // Shared by sendmessage() AND Editmessagetext(). It used to live inside
    // sendmessage() only - but a screen reached by tapping an inline button is
    // delivered by EDITING the message that is already there, never by sending a
    // new one. So every such screen (the category picker, the product list)
    // silently ignored its sticker no matter what the admin set.
    //
    // The de-dupe list is static here, so both senders share it and one screen
    // cannot fire the same sticker twice within a request.
    function bottext_fire_extras($chat_id, $text, $bot_token = null)
    {
        $bts_sent = &bottext_extras_sent_store();
        $bts_extras = bottext_extras_for_text($text);
        if ($bts_extras === null) {
            return null;
        }
        $bts_uid = $bts_extras['key'] . '|' . $chat_id;
        if (isset($bts_sent[$bts_uid])) {
            return null;
        }
        $bts_sent[$bts_uid] = true;
        if ($bts_extras['reaction'] !== '') {
            global $update;
            // only a genuine incoming user message can carry a reaction; an edit
            // is driven by a callback, where there is nothing of the user's to
            // react to, so this simply stays silent there
            $bts_mid = $update['message']['message_id'] ?? 0;
            if ($bts_mid) {
                telegram('setMessageReaction', [
                    'chat_id' => $chat_id,
                    'message_id' => $bts_mid,
                    'reaction' => json_encode([['type' => 'emoji', 'emoji' => $bts_extras['reaction']]]),
                ], $bot_token);
            }
        }
        if ($bts_extras['sticker'] !== '') {
            // never let two auto-fired stickers sit in the chat at once
            bottext_sticker_retire($chat_id);
            $bts_sticker_resp = telegram('sendSticker', [
                'chat_id' => $chat_id,
                'sticker' => $bts_extras['sticker'],
            ], $bot_token);
            // the caller records it against the message it belongs to, once that
            // message exists and its id is known
            return $bts_sticker_resp['result']['message_id'] ?? null;
        }
        return null;
    }
}
function sendmessage($chat_id,$text,$keyboard,$parse_mode,$bot_token = null,$entities = null){
    if(intval($chat_id) == 0)return ['ok' => false];
    $bts_sticker_message_id = bottext_fire_extras($chat_id, $text, $bot_token);
    $send_params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'reply_markup' => $keyboard,
    ];
    if (!empty($entities)) {
        $send_params['entities'] = json_encode($entities);
    } else {
        $send_params['parse_mode'] = $parse_mode;
    }
    $bts_main_result = telegram('sendmessage', $send_params, $bot_token);
    if (!empty($bts_sticker_message_id)) {
        $bts_main_result['_sticker_message_id'] = $bts_sticker_message_id;
        bottext_sticker_remember($chat_id, $bts_sticker_message_id, $bts_main_result['result']['message_id'] ?? 0);
    }
    return $bts_main_result;
}
function sendDocument($chat_id, $documentPath, $caption) {
        return telegram('sendDocument',[
        'chat_id' => $chat_id,
        'document' => new CURLFile($documentPath),
        'caption' => $caption,
        ]);
}

function forwardMessage($chat_id,$message_id,$chat_id_user){
    return telegram('forwardMessage',[
        'from_chat_id'=> $chat_id,
        'message_id'=> $message_id,
        'chat_id'=> $chat_id_user,
    ]);
}
function sendphoto($chat_id,$photoid,$caption){
    telegram('sendphoto',[
        'chat_id' => $chat_id,
        'photo'=> $photoid,
        'caption'=> $caption,
    ]);
}
function sendvideo($chat_id,$videoid,$caption){
    telegram('sendvideo',[
        'chat_id' => $chat_id,
        'video'=> $videoid,
        'caption'=> $caption,
    ]);
}
function senddocumentsid($chat_id,$documentid,$caption){
    telegram('sendDocument',[
        'chat_id' => $chat_id,
        'document'=> $documentid,
        'caption'=> $caption,
    ]);
}
function Editmessagetext($chat_id, $message_id, $text, $keyboard,$parse_mode = 'HTML'){
    // A screen that has a sticker configured cannot be delivered by an edit: the
    // edited message keeps its original position in the chat, so the sticker -
    // which is necessarily a new message - always lands UNDERNEATH the caption.
    // Replace the message instead, and let sendmessage() do what it already does
    // everywhere else: sticker first, caption directly after it.
    // Only screens with a sticker take this path; every other edit is untouched.
    //
    // Either way the screen on display is being replaced, so its own sticker has
    // to go first - otherwise it outlives the caption it belonged to.
    bottext_sticker_retire($chat_id, $message_id);
    if (bottext_pending_sticker($chat_id, $text)) {
        deletemessage($chat_id, $message_id);
        return sendmessage($chat_id, $text, $keyboard, $parse_mode);
    }
    // no sticker, but there may still be a reaction to fire
    $bts_sticker_message_id = bottext_fire_extras($chat_id, $text);
    $bts_result = telegram('editmessagetext', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'reply_markup' => $keyboard,
        'parse_mode' => $parse_mode,

    ]);
    if (!empty($bts_sticker_message_id)) {
        $bts_result['_sticker_message_id'] = $bts_sticker_message_id;
    }
    return $bts_result;
}
 function deletemessage($chat_id, $message_id){
  // A sticker belongs to one message. Handlers that move the user on by deleting
  // the current screen and sending the next one - rather than editing it - would
  // otherwise strand it: choosing a category does exactly that, and left the
  // category sticker hanging above the service list.
  if (function_exists('bottext_sticker_retire')) {
    bottext_sticker_retire($chat_id, $message_id);
  }
  telegram('deletemessage', [
'chat_id' => $chat_id, 
'message_id' => $message_id,
]);
 }
function getFileddire($photoid){
  return telegram('getFile', [
'file_id' => $photoid, 
]);
 }
function pinmessage($from_id,$message_id){
  return telegram('pinChatMessage', [
'chat_id' => $from_id, 
'message_id' => $message_id, 
]);
 }
 function unpinmessage($from_id){
  return telegram('unpinAllChatMessages', [
'chat_id' => $from_id, 
]);
 }
  function answerInlineQuery($inline_query_id,$results){
  return telegram('answerInlineQuery', [
      "inline_query_id" => $inline_query_id,
        "results" => json_encode($results)
]);
 }
function convertPersianNumbersToEnglish($string) {
    $persian_numbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english_numbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    return str_replace($persian_numbers, $english_numbers, $string);
}

function isDuplicateUpdate($updateId)
{
    if (!is_numeric($updateId) || $updateId <= 0) {
        return false;
    }

    $cacheDir = __DIR__ . '/storage/cache';
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
        return false;
    }

    $cacheFile = $cacheDir . '/recent_updates.json';
    $handle = fopen($cacheFile, 'c+');
    if ($handle === false) {
        return false;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return false;
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        $recentUpdates = $contents ? json_decode($contents, true) : [];
        if (!is_array($recentUpdates)) {
            $recentUpdates = [];
        }

        $now = time();
        $timeToLive = 120; // seconds

        // Drop expired entries
        foreach ($recentUpdates as $id => $timestamp) {
            if (!is_numeric($timestamp) || ($now - (int)$timestamp) > $timeToLive) {
                unset($recentUpdates[$id]);
            }
        }

        if (array_key_exists($updateId, $recentUpdates)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return true;
        }

        $recentUpdates[$updateId] = $now;

        // keep size reasonable
        if (count($recentUpdates) > 200) {
            asort($recentUpdates);
            $recentUpdates = array_slice($recentUpdates, -200, null, true);
        }

        $encoded = json_encode($recentUpdates);
        if ($encoded !== false) {
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $encoded);
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    } catch (Throwable $e) {
        try {
            flock($handle, LOCK_UN);
        } catch (Throwable $ignored) {
        }
        fclose($handle);
        return false;
    }

    return false;
}
// #-----------------------------#
$update = json_decode(file_get_contents("php://input"), true);
$update_id = $update['update_id'] ?? 0;
if (isDuplicateUpdate($update_id)) {
    http_response_code(200);
    exit;
}
$from_id = $update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? $update["inline_query"]['from']['id'] ?? 0;
$time_message = $update['message']['date'] ?? $update['callback_query']['date'] ?? $update["inline_query"]['date'] ?? 0;
$is_bot = $update['message']['from']['is_bot'] ?? false;
$chat_member = $update['chat_member'] ?? null;
$language_code = strtolower($update['message']['from']['language_code'] ?? $update['callback_query']['from']['language_code'] ?? "fa");
$Chat_type = $update["message"]["chat"]["type"] ?? $update['callback_query']['message']['chat']['type'] ?? '';
$text = $update["message"]["text"]  ?? '';
if(isset($update['pre_checkout_query'])){
    $Chat_type = "private";
    $from_id = $update['pre_checkout_query']['from']['id'];
}
$text =convertPersianNumbersToEnglish($text);
$text_inline = $update["callback_query"]["message"]['text'] ?? '';
$message_id = $update["message"]["message_id"] ?? $update["callback_query"]["message"]["message_id"] ?? 0;
$time_message = $update["message"]["date"] ?? $update["callback_query"]["date"] ?? 0;
$photo = $update["message"]["photo"] ?? 0;
$document = $update["message"]["document"] ?? 0;
$fileid = $update["message"]["document"]["file_id"] ?? 0;
$photoid = $photo ? end($photo)["file_id"] : '';
$caption = $update["message"]["caption"] ?? '';
$video = $update["message"]["video"] ?? 0;
$videoid = $video ? $video["file_id"] : 0;
$forward_from_id = $update["message"]["reply_to_message"]["forward_from"]["id"] ?? 0;
$datain = $update["callback_query"]["data"] ?? '';
$last_name = $update['message']['from']['last_name']  ?? $update["callback_query"]["from"]["last_name"] ?? $update["inline_query"]['from']['last_name'] ?? '';
$first_name = $update['message']['from']['first_name']  ?? $update["callback_query"]["from"]["first_name"] ?? $update["inline_query"]['from']['first_name'] ?? '';
$username = $update['message']['from']['username'] ?? $update['callback_query']['from']['username'] ?? $update["callback_query"]["from"]["username"] ?? 'NOT_USERNAME';
$user_phone =$update["message"]["contact"]["phone_number"] ?? 0;
$contact_id = $update["message"]["contact"]["user_id"] ?? 0;
$callback_query_id = $update["callback_query"]["id"] ?? 0;
$inline_query_id = $update["inline_query"]["id"] ?? 0;
$query = $update["inline_query"]["query"] ?? 0;