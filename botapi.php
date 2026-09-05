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

    // answerCallbackQuery's text is capped at 200 characters by Telegram, and
    // going over doesn't truncate - the whole call is rejected and the admin
    // simply sees no alert at all, with nothing in the logs to explain it.
    // Trim here so a long alert degrades to a shortened one instead of silence.
    if (strcasecmp($method, 'answerCallbackQuery') === 0 && isset($datas['text']) && mb_strlen((string) $datas['text']) > 200) {
        $datas['text'] = mb_substr((string) $datas['text'], 0, 199) . '…';
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
                $bts_segs = preg_split('/%[-+0-9.]*[a-zA-Z]/', $bts_val);
                $bts_seg0 = trim($bts_segs[0] ?? '');
                $bts_seg1 = trim($bts_segs[1] ?? '');
                $bts_seg0 = mb_substr($bts_seg0, 0, 40);
                $bts_seg1 = mb_substr($bts_seg1, 0, 40);
                if (mb_strlen($bts_seg0) < 3 && $bts_seg1 === '') {
                    continue;
                }
                // stickers/reactions are stored PER LANGUAGE by bt_media_set()
                // ({key: {fa: id}}), so they have to be read back through
                // bt_media_lookup(). Casting the entry straight to string
                // yielded the literal "Array" as the file_id, which Telegram
                // rejects - every sticker silently failed to send.
                global $user;
                $bts_lang = $user['lang'] ?? 'fa';
                $bts_cache[] = [
                    'key' => $bts_k,
                    'seg0' => $bts_seg0,
                    'seg1' => $bts_seg1,
                    'sticker' => function_exists('bt_media_lookup') ? bt_media_lookup($bts_st, $bts_k, $bts_lang) : '',
                    'reaction' => function_exists('bt_media_lookup') ? bt_media_lookup($bts_re, $bts_k, $bts_lang) : '',
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
if (!function_exists('bottext_send_extras')) {
    // Fires the sticker/reaction an admin attached to a bot message. Shared by
    // sendmessage() AND Editmessagetext(): most of the purchase flow navigates
    // by EDITING the open message rather than sending a new one, so a sticker
    // wired only into sendmessage() never appeared for the panel/category/
    // product captions (users.sell.serviceSelect has no sendmessage call site
    // at all). The once-per-key-per-chat guard is shared too, so a flow that
    // sends and then edits the same caption still only fires one sticker.
    // Returns the message id of the sticker it sent (0 when it sent none), so a
    // caller that owns a multi-screen flow can delete that sticker again when the
    // next screen replaces it. sendmessage() passes it back as
    // '_sticker_message_id'.
    function bottext_send_extras($chat_id, $text, $bot_token = null)
    {
        static $bts_sent = [];
        $bts_extras = bottext_extras_for_text($text);
        if ($bts_extras === null) {
            return 0;
        }
        $bts_uid = $bts_extras['key'] . '|' . $chat_id;
        if (isset($bts_sent[$bts_uid])) {
            return 0;
        }
        $bts_sent[$bts_uid] = true;
        if ($bts_extras['reaction'] !== '') {
            global $update;
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
            $bts_res = telegram('sendSticker', [
                'chat_id' => $chat_id,
                'sticker' => $bts_extras['sticker'],
            ], $bot_token);
            return (int) ($bts_res['result']['message_id'] ?? 0);
        }
        return 0;
    }
}
function sendmessage($chat_id,$text,$keyboard,$parse_mode,$bot_token = null){
    if(intval($chat_id) == 0)return ['ok' => false];
    $bts_stickerId = bottext_send_extras($chat_id, $text, $bot_token);
    $sm_result = telegram('sendmessage',[
        'chat_id' => $chat_id,
        'text' => $text,
        'reply_markup' => $keyboard,
        'parse_mode' => $parse_mode,

        ],$bot_token);
    // the caller may need to take this sticker away again when the screen it
    // belongs to is replaced (the purchase flow does exactly that)
    if ($bts_stickerId > 0 && is_array($sm_result)) {
        $sm_result['_sticker_message_id'] = $bts_stickerId;
    }
    return $sm_result;
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
    bottext_send_extras($chat_id, $text);
    return telegram('editmessagetext', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'reply_markup' => $keyboard,
        'parse_mode' => $parse_mode,

    ]);
}
 function deletemessage($chat_id, $message_id){
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