<?php
// ⏱ اکانت ساعتی, every minute: what each hourly service used since the last
// run comes out of its wallet, the panel is told what the wallet still covers,
// and an empty wallet stops it (payg.php, payg_cron).
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
$textbotlang = languagechange();
$ManagePanel = new ManagePanel();

payg_cron();
