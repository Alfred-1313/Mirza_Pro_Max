<?php
require_once '../config.php';
require_once '../function.php';
$textbotlang = languagechange();
require_once '../botapi.php';

$reportbackup = select("topicid", "idreport", "report", "backupfile", "select")['idreport'];
$destination = getcwd();
$setting = select("setting", "*");
$sourcefir = dirname($destination);
$botlist = select("botsaz", "*", null, null, "fetchAll");
if ($botlist) {
    foreach ($botlist as $bot) {
        $folderName = $bot['id_user'] . $bot['username'];
        // from / with the full paths, so the zip holds the same names it always did
        $agentBase = ltrim("$sourcefir/vpnbot/$folderName", '/');
        if (backup_zip_create("$destination/file.zip", '', ["$agentBase/data", "$agentBase/product.json", "$agentBase/product_name.json"]) !== '') {
            continue;
        }
        telegram('sendDocument', [
            'chat_id' => $setting['Channel_Report'],
            'message_thread_id' => $reportbackup,
            'document' => new CURLFile('file.zip'),
            'caption' => "@{$bot['username']} | {$bot['id_user']}",
        ]);
        unlink('file.zip');
    }
}

$dbBackupEnabled = ($setting['backup_db_enabled'] ?? '1') !== '0';
if ($dbBackupEnabled) {
    $backup_file_name = 'backup_' . date("Y-m-d") . '.sql';
    $zip_file_name = 'backup_' . date("Y-m-d") . '.zip';
    $dbhost = empty($dbhost) ? "localhost" : $dbhost;
    $command = "mysqldump -h $dbhost -u $usernamedb -p'$passworddb' --no-tablespaces --ssl-mode=DISABLED $dbname > $backup_file_name";

    $output = [];
    $return_var = 0;
    exec($command, $output, $return_var);
    if ($return_var !== 0) {
        telegram('sendmessage', [
            'chat_id' => $setting['Channel_Report'],
            'message_thread_id' => $reportbackup,
            'text' => $textbotlang['keyboard']['backupError'],
        ]);
    } else {
        $dbBackupPassword = $setting['backup_db_password'] ?? '';
        $zipWhy = backup_zip_create($destination . '/' . $zip_file_name, $destination, [$backup_file_name], $dbBackupPassword);
        if ($zipWhy === '') {
            telegram('sendDocument', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $reportbackup,
                'document' => new CURLFile($zip_file_name),
                'caption' => $textbotlang['hardcoded']['backupDatabaseCaption'],
            ]);
            unlink($zip_file_name);
        } elseif ($dbBackupPassword !== '') {
            // never the bare dump when a password is set - that is exactly
            // what the password is there to keep from going out
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $reportbackup,
                'text' => backup_zip_error_text($zipWhy, $textbotlang['hardcoded']['backupWhatDb'], $textbotlang),
                'parse_mode' => 'HTML',
            ]);
        } else {
            telegram('sendDocument', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $reportbackup,
                'document' => new CURLFile($backup_file_name),
                'caption' => $textbotlang['hardcoded']['backupDatabaseCaption'],
            ]);
        }
        unlink($backup_file_name);
    }
}

// bot code folder backup - separate zip, separate optional password (see 🗄 تنظیمات بکاپ)
$botBackupEnabled = ($setting['backup_bot_enabled'] ?? '1') !== '0';
if ($botBackupEnabled) {
    $botFolderZipName = 'botfolder_' . date("Y-m-d") . '.zip';
    $botFolderZipPath = $destination . '/' . $botFolderZipName;
    if (is_file($botFolderZipPath)) {
        unlink($botFolderZipPath);
    }
    $botRootDir = dirname(__DIR__);
    $botBackupPassword = $setting['backup_bot_password'] ?? '';
    $zipWhy = backup_zip_create($botFolderZipPath, $botRootDir, ['.'], $botBackupPassword, ['.git/*', '*.bak_*']);
    if ($zipWhy === '') {
        telegram('sendDocument', [
            'chat_id' => $setting['Channel_Report'],
            'message_thread_id' => $reportbackup,
            'document' => new CURLFile($botFolderZipPath),
            'caption' => $textbotlang['hardcoded']['botFolderBackupCaption'],
        ]);
        unlink($botFolderZipPath);
    } else {
        telegram('sendmessage', [
            'chat_id' => $setting['Channel_Report'],
            'message_thread_id' => $reportbackup,
            'text' => backup_zip_error_text($zipWhy, $textbotlang['hardcoded']['backupWhatBot'], $textbotlang),
            'parse_mode' => 'HTML',
        ]);
    }
}