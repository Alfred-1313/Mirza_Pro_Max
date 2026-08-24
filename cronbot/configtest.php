<?php
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
$ManagePanel = new ManagePanel();
$textbotlang = languagechange();
usertest_maybe_auto_reset();
        $stmt = $pdo->prepare("SELECT * FROM invoice WHERE status != 'disabled' AND name_product = :mp1 ORDER BY RAND() LIMIT 15");
        $stmt->execute([':mp1' => $textbotlang['Admin']['adminphp']['db_test_service_name']]);
        while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $resultt  = trim($result['username']);
        $marzban_list_get = select("marzban_panel","*","name_panel",$result['Service_location'],"select");
        if($marzban_list_get == false)continue;
        $user = select("user","*","id",$result['id_user'],"select");
        $get_username_Check = $ManagePanel->DataUser($result['Service_location'],$result['username']);
        // per-panel "delete test account after N hours", counted from when the user
        // took the test (invoice.time_sell, a unix timestamp - same basis on_hold.php
        // already uses). This catches tests that were never used: an untouched account
        // stays 'on_hold' forever, which the status check below deliberately allows.
        // 0 / empty / unset = feature off for this panel, so nothing changes by default.
        $del_hours_usertest = intval($marzban_list_get['del_usertest'] ?? 0);
        $time_sell_usertest = (string) $result['time_sell'];
        $expired_by_age_usertest = false;
        if ($del_hours_usertest > 0 && ctype_digit($time_sell_usertest) && intval($time_sell_usertest) > 0) {
            $expired_by_age_usertest = ((time() - intval($time_sell_usertest)) / 3600) >= $del_hours_usertest;
        }
    if ($expired_by_age_usertest || !in_array($get_username_Check['status'],['active','on_hold',"Unsuccessful","disabled"])) {
            $ManagePanel->RemoveUser($result['Service_location'],$resultt);
        update("invoice","status","disabled","username",$resultt);
        if(intval($user['status_cron']) != 0){
         $Response = test_expired_kb($user['lang'] ?? 'fa', $textbotlang);
        $textexpire = str_replace('{username}', $resultt, $textbotlang['textbot']['testExpired']);
        $textexpire = strtr($textexpire, bottext_user_placeholders($user, $result['id_user']));
        sendmessage($result['id_user'], $textexpire, $Response, 'HTML');
        }
    }
}