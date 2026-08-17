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
        shell_exec("zip -r $destination/file.zip $sourcefir/vpnbot/$folderName/data $sourcefir/vpnbot/$folderName/product.json $sourcefir/vpnbot/$folderName/product_name.json");
        telegram('sendDocument', [
            'chat_id' => $setting['Channel_Report'],
            'message_thread_id' => $reportbackup,
            'document' => new CURLFile('file.zip'),
            'caption' => "@{$bot['username']} | {$bot['id_user']}",
        ]);
        unlink('file.zip');
    }
}

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
    if (is_file($zip_file_name)) {
        unlink($zip_file_name);
    }
    $dbBackupPassword = $setting['backup_db_password'] ?? '';
    if ($dbBackupPassword !== '') {
        shell_exec("zip -j -P " . escapeshellarg($dbBackupPassword) . " " . escapeshellarg($zip_file_name) . " " . escapeshellarg($backup_file_name));
    } else {
        shell_exec("zip -j " . escapeshellarg($zip_file_name) . " " . escapeshellarg($backup_file_name));
    }
    if (is_file($zip_file_name)) {
        telegram('sendDocument', [
            'chat_id' => $setting['Channel_Report'],
            'message_thread_id' => $reportbackup,
            'document' => new CURLFile($zip_file_name),
            'caption' => $textbotlang['hardcoded']['backupDatabaseCaption'],
        ]);
        unlink($zip_file_name);
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

// bot code folder backup - separate zip, separate optional password (see 🗄 تنظیمات بکاپ)
$botFolderZipName = 'botfolder_' . date("Y-m-d") . '.zip';
$botFolderZipPath = $destination . '/' . $botFolderZipName;
if (is_file($botFolderZipPath)) {
    unlink($botFolderZipPath);
}
$botRootDir = dirname(__DIR__);
$botBackupPassword = $setting['backup_bot_password'] ?? '';
$zipExcludes = "-x '.git/*' -x '*.bak_*'";
if ($botBackupPassword !== '') {
    $botZipCommand = "cd " . escapeshellarg($botRootDir) . " && zip -r -P " . escapeshellarg($botBackupPassword) . " " . escapeshellarg($botFolderZipPath) . " . " . $zipExcludes;
} else {
    $botZipCommand = "cd " . escapeshellarg($botRootDir) . " && zip -r " . escapeshellarg($botFolderZipPath) . " . " . $zipExcludes;
}
shell_exec($botZipCommand);
if (is_file($botFolderZipPath)) {
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
        'text' => $textbotlang['keyboard']['backupError'],
    ]);
}