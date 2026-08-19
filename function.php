<?php
require_once 'vendor/autoload.php';
require 'config.php';
require 'vendor/autoload.php';
ini_set('error_log', 'error_log');

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Font\OpenSans;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

#-----------shell helper utilities------------#
function assertSqlIdentifier($name, $allowFieldExpr = false)
{
    if ($name === null) {
        return;
    }
    // Identifiers (table/column names) cannot be bound as parameters, so they
    // are validated against a strict allow-list to prevent SQL injection.
    $pattern = $allowFieldExpr ? '/^[\p{L}\p{N}_*,()\s.`]+$/u' : '/^[\p{L}\p{N}_.`]+$/u';
    if (!preg_match($pattern, (string) $name)) {
        error_log('Blocked unsafe SQL identifier: ' . $name);
        throw new InvalidArgumentException('Invalid SQL identifier');
    }
}
function isShellExecAvailable()
{
    static $isAvailable;

    if ($isAvailable !== null) {
        return $isAvailable;
    }

    if (!function_exists('shell_exec')) {
        $isAvailable = false;
        return $isAvailable;
    }

    $disabledFunctions = ini_get('disable_functions');
    if (!empty($disabledFunctions) && stripos($disabledFunctions, 'shell_exec') !== false) {
        $isAvailable = false;
        return $isAvailable;
    }

    $isAvailable = true;
    return $isAvailable;
}

function getCrontabBinary()
{
    static $resolvedPath;

    if ($resolvedPath !== null) {
        return $resolvedPath ?: null;
    }

    $candidateDirectories = [
        '/usr/local/bin',
        '/usr/bin',
        '/bin',
        '/usr/sbin',
        '/sbin',
    ];

    $environmentPath = getenv('PATH');
    if ($environmentPath !== false && $environmentPath !== '') {
        foreach (explode(PATH_SEPARATOR, $environmentPath) as $pathDirectory) {
            $pathDirectory = trim($pathDirectory);
            if ($pathDirectory !== '' && !in_array($pathDirectory, $candidateDirectories, true)) {
                $candidateDirectories[] = $pathDirectory;
            }
        }
    }

    foreach ($candidateDirectories as $directory) {
        $executablePath = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'crontab';
        if (@is_file($executablePath) && @is_executable($executablePath)) {
            $resolvedPath = $executablePath;
            return $resolvedPath;
        }
    }

    if (isShellExecAvailable()) {
        $whichOutput = @shell_exec('command -v crontab 2>/dev/null');
        if (is_string($whichOutput)) {
            $whichOutput = trim($whichOutput);
            if ($whichOutput !== '' && @is_executable($whichOutput)) {
                $resolvedPath = $whichOutput;
                return $resolvedPath;
            }
        }
    }

    $resolvedPath = '';
    error_log('Unable to locate the crontab executable on this system.');

    return null;
}

function runShellCommand($command)
{
    if (!isShellExecAvailable()) {
        error_log('shell_exec is not available; unable to run command: ' . $command);
        return null;
    }

    if (getenv('PATH') === false || trim((string) getenv('PATH')) === '') {
        putenv('PATH=/usr/local/bin:/usr/bin:/bin');
    }

    return shell_exec($command);
}

function deleteDirectory($directory)
{
    if (!file_exists($directory)) {
        return true;
    }

    if (!is_dir($directory)) {
        return @unlink($directory);
    }

    $items = scandir($directory);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            if (!deleteDirectory($path)) {
                return false;
            }
        } else {
            if (!@unlink($path)) {
                return false;
            }
        }
    }

    return @rmdir($directory);
}

function ensureTableUtf8mb4($table)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $currentCollation = $stmt->fetchColumn();

        if ($currentCollation === false) {
            error_log("Failed to detect current collation for table {$table}");
            return false;
        }

        if (stripos((string) $currentCollation, 'utf8mb4') === 0) {
            return true;
        }

        $pdo->exec("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        return true;
    } catch (PDOException $e) {
        error_log('Failed to convert table to utf8mb4: ' . $e->getMessage());
        return false;
    }
}

function ensureCardNumberTableSupportsUnicode()
{
    global $pdo;

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return;
    }

    try {
        $pdo->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");

        $createQuery = "CREATE TABLE IF NOT EXISTS card_number (" .
            "cardnumber varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PRIMARY KEY," .
            "namecard varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL" .
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $pdo->exec($createQuery);

        ensureTableUtf8mb4('card_number');

        $columnInfo = $pdo->query("SHOW FULL COLUMNS FROM card_number WHERE Field IN ('cardnumber', 'namecard')");
        if ($columnInfo instanceof PDOStatement) {
            while ($column = $columnInfo->fetch(PDO::FETCH_ASSOC)) {
                $collation = $column['Collation'] ?? '';
                if (!is_string($collation) || stripos($collation, 'utf8mb4') === false) {
                    $field = $column['Field'];
                    $type = $field === 'cardnumber' ? 'varchar(500)' : 'varchar(1000)';
                    $alter = sprintf(
                        "ALTER TABLE card_number MODIFY %s %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci%s",
                        $field,
                        $type,
                        $field === 'cardnumber' ? ' PRIMARY KEY' : ' NOT NULL'
                    );
                    $pdo->exec($alter);
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('Unexpected error while ensuring card_number utf8mb4 compatibility: ' . $e->getMessage());
    }
}

function normaliseUpdateValue($value)
{
    if (is_array($value) || is_object($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return $value;
}

function copyDirectoryContents($source, $destination)
{
    if (!is_dir($source)) {
        return false;
    }

    if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
        return false;
    }

    $items = scandir($source);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $sourcePath = $source . DIRECTORY_SEPARATOR . $item;
        $destinationPath = $destination . DIRECTORY_SEPARATOR . $item;

        if (is_dir($sourcePath)) {
            if (!copyDirectoryContents($sourcePath, $destinationPath)) {
                return false;
            }
        } else {
            if (!@copy($sourcePath, $destinationPath)) {
                return false;
            }
        }
    }

    return true;
}

#-----------function------------#
function step($step, $from_id)
{
    global $pdo;
    $stmt = $pdo->prepare('UPDATE user SET step = ? WHERE id = ?');
    $stmt->execute([$step, $from_id]);
    clearSelectCache('user');
}
function determineColumnTypeFromValue($value)
{
    if (is_bool($value)) {
        return 'TINYINT(1)';
    }

    if (is_int($value)) {
        return 'INT(11)';
    }

    if (is_float($value)) {
        return 'DOUBLE';
    }

    if ($value === null) {
        return 'VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    if (is_string($value)) {
        if (function_exists('mb_strlen')) {
            $length = mb_strlen($value, 'UTF-8');
        } else {
            $length = strlen($value);
        }

        if ($length <= 191) {
            return 'VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        if ($length <= 500) {
            return 'VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        return 'TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    return 'TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
}
function ensureColumnExistsForUpdate($tableName, $fieldName, $valueSample = null)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->execute([$tableName, $fieldName]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $datatype = determineColumnTypeFromValue($valueSample);

        $defaultValue = null;
        if (is_bool($valueSample)) {
            $defaultValue = $valueSample ? '1' : '0';
        } elseif (is_scalar($valueSample) && $valueSample !== null) {
            $defaultValue = (string) $valueSample;
        }

        addFieldToTable($tableName, $fieldName, $defaultValue, $datatype);
    } catch (PDOException $e) {
        error_log('Failed to ensure column exists: ' . $e->getMessage());
    }
}
function update($table, $field, $newValue, $whereField = null, $whereValue = null)
{
    global $pdo, $user;

    assertSqlIdentifier($table);
    assertSqlIdentifier($field, true);
    assertSqlIdentifier($whereField);

    $valueToStore = normaliseUpdateValue($newValue);

    ensureColumnExistsForUpdate($table, $field, $valueToStore);

    $executeUpdate = function ($value) use ($pdo, $table, $field, $whereField, $whereValue) {
        if ($whereField !== null) {
            $stmt = $pdo->prepare("SELECT $field FROM $table WHERE $whereField = ? FOR UPDATE");
            $stmt->execute([$whereValue]);
            $stmt = $pdo->prepare("UPDATE $table SET $field = ? WHERE $whereField = ?");
            $stmt->execute([$value, $whereValue]);
        } else {
            $stmt = $pdo->prepare("UPDATE $table SET $field = ?");
            $stmt->execute([$value]);
        }
    };

    try {
        $executeUpdate($valueToStore);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Incorrect string value') !== false) {
            $tableConverted = ensureTableUtf8mb4($table);
            if ($tableConverted) {
                try {
                    $executeUpdate($valueToStore);
                } catch (PDOException $retryException) {
                    error_log('Retry after charset conversion failed: ' . $retryException->getMessage());
                    throw $retryException;
                }
            } else {
                $fallbackValue = is_string($valueToStore) ? @iconv('UTF-8', 'UTF-8//IGNORE', $valueToStore) : $valueToStore;
                if ($fallbackValue === false) {
                    $fallbackValue = '';
                }
                $executeUpdate($fallbackValue);
            }
        } else {
            throw $e;
        }
    }

    $date = date("Y-m-d H:i:s");
    if (!isset($user['step'])) {
        $user['step'] = '';
    }
    $logValue = is_scalar($valueToStore) ? $valueToStore : json_encode($valueToStore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $logss = "{$table}_{$field}_{$logValue}_{$whereField}_{$whereValue}_{$user['step']}_$date";
    if ($field != "message_count" && $field != "last_message_time") {
        file_put_contents('log.txt', "\n" . $logss, FILE_APPEND);
    }

    clearSelectCache($table);
}
function &getSelectCacheStore()
{
    static $store = [
    'results' => [],
    'tableIndex' => [],
    ];

    return $store;
}

function clearSelectCache($table = null)
{
    $store = &getSelectCacheStore();

    if ($table === null) {
        $store['results'] = [];
        $store['tableIndex'] = [];
        return;
    }

    if (!isset($store['tableIndex'][$table])) {
        return;
    }

    foreach (array_keys($store['tableIndex'][$table]) as $cacheKey) {
        unset($store['results'][$cacheKey]);
    }

    unset($store['tableIndex'][$table]);
}

function select($table, $field, $whereField = null, $whereValue = null, $type = "select", $options = [])
{
    global $pdo;

    assertSqlIdentifier($table);
    assertSqlIdentifier($field, true);
    assertSqlIdentifier($whereField);

    $useCache = true;
    if (is_array($options) && array_key_exists('cache', $options)) {
        $useCache = (bool) $options['cache'];
    }

    $cacheKey = null;
    if ($useCache) {
        $cacheKey = hash('sha256', json_encode([
            $table,
            $field,
            $whereField,
            $whereValue,
            $type,
        ], JSON_UNESCAPED_UNICODE));

        $store = &getSelectCacheStore();
        if (isset($store['results'][$cacheKey])) {
            return $store['results'][$cacheKey];
        }
    }

    $query = "SELECT $field FROM $table";

    if ($whereField !== null) {
        $query .= " WHERE $whereField = :whereValue";
    }

    try {
        $stmt = $pdo->prepare($query);
        if ($whereField !== null) {
            $stmt->bindParam(':whereValue', $whereValue, PDO::PARAM_STR);
        }

        $stmt->execute();
        if ($type == "count") {
            $result = $stmt->rowCount();
        } elseif ($type == "FETCH_COLUMN") {
            $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($table === 'admin' && $field === 'id_admin') {
                global $adminnumber;
                if (!is_array($results)) {
                    $results = [];
                }

                $results = array_values(array_unique(array_filter($results, function ($value) {
                    return $value !== null && $value !== '';
                })));

                if (empty($results) && isset($adminnumber) && $adminnumber !== '') {
                    $results[] = (string) $adminnumber;
                }
            }
            $result = $results;
        } elseif ($type == "fetchAll") {
            $result = $stmt->fetchAll();
        } else {
            $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
            $result = $fetched === false ? null : $fetched;
        }
    } catch (PDOException $e) {
        error_log("Query failed: " . $e->getMessage());
    }

    if ($useCache && $cacheKey !== null) {
        $store = &getSelectCacheStore();
        $store['results'][$cacheKey] = $result;
        if (!isset($store['tableIndex'][$table])) {
            $store['tableIndex'][$table] = [];
        }
        $store['tableIndex'][$table][$cacheKey] = true;
    }

    return $result;
}

function getPaySettingValue($name, $default = null)
{
    $result = select("PaySetting", "ValuePay", "NamePay", $name, "select");
    if (!is_array($result) || !array_key_exists('ValuePay', $result)) {
        return $default;
    }

    return $result['ValuePay'];
}
function generateUUID()
{
    $data = openssl_random_pseudo_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

    return $uuid;
}
function rate_arze()
{
    $arze_rate = [];
    $requests_tron = json_decode(file_get_contents('https://api.diadata.org/v1/assetQuotation/Tron/0x0000000000000000000000000000000000000000'), true);
    $html_read = file_get_contents("https://www.bon-bast.com/");
    preg_match('/<span>\s*([\d,]+)\s*<\/span>/', $html_read, $matches);
    if (!empty($matches[1])) {
        $requestsusd = str_replace(',', '', $matches[1]);
    }
    $arze_rate['USD'] = intval($requestsusd);
    $arze_rate['TRX'] = intval($requests_tron['Price'] * $arze_rate['USD']);

    return $arze_rate;
}
function updatePaymentMessageId($response, $orderId)
{
    if (!is_array($response)) {
        error_log("Failed to send payment message for order {$orderId}: unexpected response");
        return false;
    }

    if (empty($response['ok'])) {
        error_log("Failed to send payment message for order {$orderId}: " . json_encode($response));
        return false;
    }

    if (!isset($response['result']['message_id'])) {
        error_log("Missing message_id for order {$orderId}: " . json_encode($response));
        return false;
    }

    update("Payment_report", "message_id", intval($response['result']['message_id']), "id_order", $orderId);
    return true;
}
function nowPayments($payment, $price_amount, $order_id, $order_description)
{
    global $domainhosts;
    $apinowpayments = select("PaySetting", "*", "NamePay", "marchent_tronseller", "select")['ValuePay'];
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.nowpayments.io/v1/' . $payment,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 7000,
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => 1,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array(
            'x-api-key:' . $apinowpayments,
            'Content-Type: application/json'
        ),
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
        'price_amount' => $price_amount,
        'price_currency' => 'usd',
        'order_id' => $order_id,
        'order_description' => $order_description,
        'ipn_callback_url' => "https://" . $domainhosts . "/payment/nowpayment.php"
    ]));

    $response = curl_exec($curl);
    return json_decode($response, true);
}
function StatusPayment($paymentid)
{
    $apinowpayments = select("PaySetting", "*", "NamePay", "marchent_tronseller", "select")['ValuePay'];
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.nowpayments.io/v1/payment/' . $paymentid,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'x-api-key:' . $apinowpayments
        ),
    ));
    $response = curl_exec($curl);
    $response = json_decode($response, true);
    return $response;
}
function channel(array $id_channel)
{
    global $from_id;
    $channel_link = array();
    foreach ($id_channel as $channel) {
        $response = telegram('getChatMember', [
            'chat_id' => $channel,
            'user_id' => $from_id
        ]);
        if ($response['ok']) {
            if (!in_array($response['result']['status'], ['member', 'creator', 'administrator'])) {
                $channel_link[] = $channel;
            }
        }
    }
    if (count($channel_link) == 0) {
        return [];
    } else {
        return $channel_link;
    }
}
function isValidDate($date)
{
    return (strtotime($date) != false);
}
function trnado($order_id, $price)
{
    global $domainhosts;
    $apitronseller = select("PaySetting", "*", "NamePay", "apiternado", "select")['ValuePay'];
    $walletaddress = select("PaySetting", "*", "NamePay", "walletaddress", "select")['ValuePay'];
    $urlpay = select("PaySetting", "*", "NamePay", "urlpaymenttron", "select")['ValuePay'];
    $curl = curl_init();
    $data = array(
        "PaymentID" => $order_id,
        "WalletAddress" => $walletaddress,
        "TronAmount" => $price,
        "CallbackUrl" => "https://" . $domainhosts . "/payment/tronado.php"
    );
    $datasend = json_encode($data);
    curl_setopt_array($curl, array(
        CURLOPT_URL => "$urlpay",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => array(
            'x-api-key:' . $apitronseller,
            'Content-Type: application/json',
            'Cookie: ASP.NET_SessionId=spou2s5lo4nnxkjtavscrrlo'
        ),
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, $datasend);

    $response = curl_exec($curl);

    return json_decode($response, true);
}
function formatBytes($bytes, $precision = 2): string
{
    global $textbotlang;
    $base = log($bytes, 1024);
    $power = $bytes > 0 ? floor($base) : 0;
    $suffixes = [
        $textbotlang['hardcoded']['unitByte'],
        $textbotlang['hardcoded']['unitKilobyte'],
        $textbotlang['hardcoded']['unitMegabyte'],
        $textbotlang['hardcoded']['unitGigabyteFn'],
        $textbotlang['hardcoded']['unitTerabyte'],
    ];
    return round(pow(1024, $base - $power), $precision) . ' ' . $suffixes[$power];
}
function generateUsername($from_id, $Metode, $username, $randomString, $text, $namecustome, $usernamecustom)
{
    global $textbotlang;
    $setting = select("setting", "*", null, null, "select");
    $user = select("user", "*", "id", $from_id, "select");
    if ($user == false) {
        $user = array();
        $user = array(
            'number_username' => '',
        );
    }
    if ($Metode == $textbotlang['keyboard']['numericIdRandom']) {
        return $from_id . "_" . $randomString;
    } elseif ($Metode == $textbotlang['keyboard']['usernameSequential']) {
        if ($username == "NOT_USERNAME") {
            if (preg_match('/^\w{3,32}$/', $namecustome)) {
                $username = $namecustome;
            }
        }
        return $username . "_" . $user['number_username'];
    } elseif ($Metode == $textbotlang['keyboard']['customUsername'])
        return $text;
    elseif ($Metode == $textbotlang['keyboard']['customUsernameRandom']) {
        $random_number = rand(1000000, 9999999);
        return $text . "_" . $random_number;
    } elseif ($Metode == $textbotlang['keyboard']['customTextRandom']) {
        return $namecustome . "_" . $randomString;
    } elseif ($Metode == $textbotlang['keyboard']['customTextSequential']) {
        return $namecustome . "_" . $setting['numbercount'];
    } elseif ($Metode == $textbotlang['keyboard']['numericIdSequential']) {
        return $from_id . "_" . $user['number_username'];
    } elseif ($Metode == $textbotlang['keyboard']['agentCustomTextSequential']) {
        if ($usernamecustom == "none") {
            return $namecustome . "_" . $setting['numbercount'];
        }
        return $usernamecustom . "_" . $user['number_username'];
    }
}
function outputlink($text)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $text);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 10000);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        return null;
    } else {
        return $response;
    }
}
function DirectPayment($order_id, $image = 'images.jpg')
{
    global $pdo, $ManagePanel, $textbotlang, $keyboardextendfnished, $keyboard, $Confirm_pay, $from_id, $message_id;
    $buyreport = select("topicid", "idreport", "report", "buyreport", "select")['idreport'];
    $admin_ids = select("admin", "id_admin", null, null, "FETCH_COLUMN");
    $otherservice = select("topicid", "idreport", "report", "otherservice", "select")['idreport'];
    $otherreport = select("topicid", "idreport", "report", "otherreport", "select")['idreport'];
    $errorreport = select("topicid", "idreport", "report", "errorreport", "select")['idreport'];
    $porsantreport = select("topicid", "idreport", "report", "porsantreport", "select")['idreport'];
    $setting = select("setting", "*");
    $Payment_report = select("Payment_report", "*", "id_order", $order_id, "select");
    $format_price_cart = number_format($Payment_report['price']);
    $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
    $steppay = explode("|", $Payment_report['id_invoice']);
    update("user", "Processing_value", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_one", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_tow", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_four", "0", "id", $Balance_id['id']);
    if ($steppay[0] == "getconfigafterpay") {
        $get_invoice = select("invoice", "*", "username", $steppay[1], "select");
        $stmt = $pdo->prepare("SELECT * FROM product WHERE name_product = :name_product AND (Location = :Service_location  or Location = '/all')");
        $stmt->bindParam(':name_product', $get_invoice['name_product'], PDO::PARAM_STR);
        $stmt->bindParam(':Service_location', $get_invoice['Service_location'], PDO::PARAM_STR);
        $stmt->execute();
        $info_product = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($get_invoice['name_product'] == $textbotlang['extracted']['index_php']['customVolumeButton'] || $get_invoice['name_product'] == $textbotlang['extracted']['index_php']['customServiceButton']) {
            $info_product['data_limit_reset'] = "no_reset";
            $info_product['Volume_constraint'] = $get_invoice['Volume'];
            $info_product['name_product'] = $textbotlang['users']['customSellVolume']['title'];
            $info_product['code_product'] = "customvolume";
            $info_product['Service_time'] = $get_invoice['Service_time'];
            $info_product['price_product'] = $get_invoice['price_product'];
        } else {
            $stmt = $pdo->prepare("SELECT * FROM product WHERE name_product = :name_product AND (Location = :Service_location  or Location = '/all')");
            $stmt->bindParam(':name_product', $get_invoice['name_product'], PDO::PARAM_STR);
            $stmt->bindParam(':Service_location', $get_invoice['Service_location'], PDO::PARAM_STR);
            $stmt->execute();
            $info_product = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        $username_ac = $get_invoice['username'];
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $get_invoice['Service_location'], "select");
        $date = strtotime("+" . $get_invoice['Service_time'] . "days");
        if (intval($get_invoice['Service_time']) == 0) {
            $timestamp = 0;
        } else {
            $timestamp = strtotime(date("Y-m-d H:i:s", $date));
        }
        $datac = array(
            'expire' => $timestamp,
            'data_limit' => $get_invoice['Volume'] * pow(1024, 3),
            'from_id' => $Balance_id['id'],
            'username' => $Balance_id['username'],
            'type' => 'buy'
        );
        $dataoutput = $ManagePanel->createUser($marzban_list_get['name_panel'], $info_product['code_product'], $username_ac, $datac);
        if ($dataoutput['username'] == null) {
            $dataoutput['msg'] = json_encode($dataoutput['msg']);
            $balance = $Balance_id['Balance'] + $Payment_report['price'];
            update("user", "Balance", $balance, "id", $Balance_id['id']);
            sendmessage($Balance_id['id'], $textbotlang['users']['sell']['errorConfig'], $keyboard, 'HTML');
            sendmessage($Balance_id['id'], sprintf($textbotlang['hardcoded']['serviceCreateFailedRefund'], $balance), $keyboard, 'HTML');
            $texterros = sprintf($textbotlang['hardcoded']['configCreateError'], $dataoutput['msg'], $Balance_id['id'], $Balance_id['username'], $marzban_list_get['name_panel']);
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $texterros,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        $Shoppinginfo = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['keyboard']['viewTutorial'], 'callback_data' => "helpbtn"],
                ]
            ]
        ]);
        $output_config_link = "";
        $config = "";
        if ($marzban_list_get['config'] == "onconfig" && is_array($dataoutput['configs'])) {
            foreach ($dataoutput['configs'] as $link) {
                $config .= "\n" . $link;
            }
        }
        $output_config_link = $marzban_list_get['sublink'] == "onsublink" ? $dataoutput['subscription_url'] : "";
        $textbotlang['textbot']['afterPay'] = $marzban_list_get['type'] == "Manualsale" ? $textbotlang['textbot']['manual'] : $textbotlang['textbot']['afterPay'];
        $textbotlang['textbot']['afterPay'] = $marzban_list_get['type'] == "WGDashboard" ? $textbotlang['textbot']['wgDashboard'] : $textbotlang['textbot']['afterPay'];
        $textbotlang['textbot']['afterPay'] = $marzban_list_get['type'] == "ibsng" || $marzban_list_get['type'] == "mikrotik" ? $textbotlang['textbot']['afterPayIbsng'] : $textbotlang['textbot']['afterPay'];
        if (intval($get_invoice['Service_time']) == 0)
            $get_invoice['Service_time'] = $textbotlang['users']['status']['unlimited'];
        $textcreatuser = str_replace('{username}', $dataoutput['username'], $textbotlang['textbot']['afterPay']);
        $textcreatuser = str_replace('{name_service}', $get_invoice['name_product'], $textcreatuser);
        $textcreatuser = str_replace('{location}', $marzban_list_get['name_panel'], $textcreatuser);
        $textcreatuser = str_replace('{day}', $get_invoice['Service_time'], $textcreatuser);
        $textcreatuser = str_replace('{volume}', $get_invoice['Volume'], $textcreatuser);
        $textcreatuser = str_replace('{config}', "<code>{$output_config_link}</code>", $textcreatuser);
        $textcreatuser = str_replace('{links}', $config, $textcreatuser);
        $textcreatuser = str_replace('{links2}', "{$output_config_link}", $textcreatuser);
        if ($marzban_list_get['type'] == "Manualsale" || $marzban_list_get['type'] == "ibsng" || $marzban_list_get['type'] == "mikrotik") {
            $textcreatuser = str_replace('{password}', $dataoutput['subscription_url'], $textcreatuser);
            update("invoice", "user_info", $dataoutput['subscription_url'], "id_invoice", $get_invoice['id_invoice']);
        }
        sendMessageService($marzban_list_get, $dataoutput['configs'], $output_config_link, $dataoutput['username'], $Shoppinginfo, $textcreatuser, $get_invoice['id_invoice'], $get_invoice['id_user'], $image);
        $partsdic = explode("_", $Balance_id['Processing_value_four'], $get_invoice['id_user']);
        if ($partsdic[0] == "dis") {
            $SellDiscountlimit = select("DiscountSell", "*", "codeDiscount", $partsdic[1], "select");
            $value = intval($SellDiscountlimit['usedDiscount']) + 1;
            update("DiscountSell", "usedDiscount", $value, "codeDiscount", $partsdic[1]);
            $stmt = $pdo->prepare("INSERT INTO Giftcodeconsumed (id_user,code) VALUES (:id_user,:code)");
            $stmt->bindParam(':id_user', $Balance_id['id']);
            $stmt->bindParam(':code', $partsdic[1]);
            $stmt->execute();
            $text_report = sprintf($textbotlang['hardcoded']['discountCodeUsedAdmin'], $Balance_id['username'], $Balance_id['id'], $partsdic[1]);
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $otherreport,
                    'text' => $text_report,
                ]);
            }
        }
        $affiliatescommission = select("affiliates", "*", null, null, "select");
        $marzbanporsant_one_buy = select("affiliates", "*", null, null, "select");
        $stmt = $pdo->prepare("SELECT * FROM invoice WHERE name_product != :name_product  AND id_user = :id_user AND Status != 'Unpaid'");
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->bindParam(':name_product', $textbotlang['Admin']['adminphp']['db_test_service_name']);
        $stmt->execute();
        $countinvoice = $stmt->rowCount();
        if ($affiliatescommission['status_commission'] == "oncommission" && ($Balance_id['affiliates'] != null && intval($Balance_id['affiliates']) != 0)) {
            if ($marzbanporsant_one_buy['porsant_one_buy'] == "on_buy_porsant") {
                if ($countinvoice <= 1) {
                    $result = ($Payment_report['price'] * $setting['affiliatespercentage']) / 100;
                    $user_Balance = select("user", "*", "id", $Balance_id['affiliates'], "select");
                    if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['affiliates'], $admin_ids)) {
                        sendmessage($Balance_id['affiliates'], $textbotlang['extracted']['index_php']['earned2Points'], null, 'html');
                        $scorenew = $user_Balance['score'] + 2;
                        update("user", "score", $scorenew, "id", $Balance_id['affiliates']);
                    }
                    $Balance_prim = $user_Balance['Balance'] + $result;
                    $dateacc = date('Y/m/d H:i:s');
                    update("user", "Balance", $Balance_prim, "id", $Balance_id['affiliates']);
                    $result = number_format($result);
                    $textadd = sprintf($textbotlang['hardcoded']['affiliateCommissionPaidUserFn'], $result);
                    $textreportport = sprintf($textbotlang['hardcoded']['affiliateCommissionPaidLogFn'], $result, $Balance_id['affiliates'], $Balance_id['id'], $dateacc);
                    if (strlen($setting['Channel_Report']) > 0) {
                        telegram('sendmessage', [
                            'chat_id' => $setting['Channel_Report'],
                            'message_thread_id' => $porsantreport,
                            'text' => $textreportport,
                            'parse_mode' => "HTML"
                        ]);
                    }
                    sendmessage($Balance_id['affiliates'], $textadd, null, 'HTML');
                }
            } else {

                $result = ($Payment_report['price'] * $setting['affiliatespercentage']) / 100;
                $user_Balance = select("user", "*", "id", $Balance_id['affiliates'], "select");
                if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['affiliates'], $admin_ids)) {
                    sendmessage($Balance_id['affiliates'], $textbotlang['extracted']['index_php']['earned2Points'], null, 'html');
                    $scorenew = $user_Balance['score'] + 2;
                    update("user", "score", $scorenew, "id", $Balance_id['affiliates']);
                }
                $Balance_prim = $user_Balance['Balance'] + $result;
                $dateacc = date('Y/m/d H:i:s');
                update("user", "Balance", $Balance_prim, "id", $Balance_id['affiliates']);
                $result = number_format($result);
                $textadd = sprintf($textbotlang['hardcoded']['affiliateCommissionPaidUserFn2'], $result);
                $textreportport = sprintf($textbotlang['hardcoded']['affiliateCommissionPaidLogFn2'], $result, $Balance_id['affiliates'], $Balance_id['id'], $dateacc);
                if (strlen($setting['Channel_Report']) > 0) {
                    telegram('sendmessage', [
                        'chat_id' => $setting['Channel_Report'],
                        'message_thread_id' => $porsantreport,
                        'text' => $textreportport,
                        'parse_mode' => "HTML"
                    ]);
                }
                sendmessage($Balance_id['affiliates'], $textadd, null, 'HTML');
            }
        }
        if ($marzban_list_get['MethodUsername'] == $textbotlang['keyboard']['customTextSequential'] || $marzban_list_get['MethodUsername'] == $textbotlang['keyboard']['usernameSequential'] || $marzban_list_get['MethodUsername'] == $textbotlang['keyboard']['numericIdSequential'] || $marzban_list_get['MethodUsername'] == $textbotlang['keyboard']['agentCustomTextSequential']) {
            $value = intval($Balance_id['number_username']) + 1;
            update("user", "number_username", $value, "id", $Balance_id['id']);
            if ($marzban_list_get['MethodUsername'] == $textbotlang['keyboard']['customTextSequential'] || $marzban_list_get['MethodUsername'] == $textbotlang['keyboard']['agentCustomTextSequential']) {
                $value = intval($setting['numbercount']) + 1;
                update("setting", "numbercount", $value);
            }
        }
        $Balance_prims = $Balance_id['Balance'] - $get_invoice['price_product'];
        if ($Balance_prims <= 0)
            $Balance_prims = 0;
        update("user", "Balance", $Balance_prims, "id", $Balance_id['id']);
        $balanceformatsell = select("user", "Balance", "id", $get_invoice['id_user'], "select")['Balance'];
        $balanceformatsell = number_format($balanceformatsell, 0);
        $balancebefore = number_format($Balance_id['Balance'], 0);
        $timejalali = jdate('Y/m/d H:i:s');
        $textonebuy = "";
        if ($countinvoice == 1) {
            $textonebuy = $textbotlang['extracted']['index_php']['firstPurchaseLabel'];
        }
        $Response = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['Admin']['manageUser']['manageUserBtn'], 'callback_data' => 'manageuser_' . $Balance_id['id']],
                ],
            ]
        ]);
        $text_report = sprintf($textbotlang['hardcoded']['accountCreateReportAfterPay'], $textonebuy, $Balance_id['id'], $Balance_id['username'], $username_ac, $get_invoice['Service_location'], $get_invoice['Service_time'], $get_invoice['name_product'], $get_invoice['Volume'], $balancebefore, $balanceformatsell, $get_invoice['id_invoice'], $Balance_id['agent'], $Balance_id['number'], $get_invoice['price_product'], $Payment_report['price'], $timejalali);
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $buyreport,
                'text' => $text_report,
                'parse_mode' => "HTML",
                'reply_markup' => $Response
            ]);
        }
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], $textbotlang['extracted']['index_php']['earned1Point'], null, 'html');
            $scorenew = $Balance_id['score'] + 1;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        update("invoice", "Status", "active", "username", $get_invoice['username']);
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {
            update("invoice", "Status", "active", "id_invoice", $get_invoice['id_invoice']);
            $textconfrom = sprintf($textbotlang['hardcoded']['paymentConfirmedNewService'], $username_ac, $get_invoice['Service_location'], $Balance_id['id'], $Payment_report['id_order'], $Balance_id['username'], $Balance_id['Balance'], $format_price_cart, $Payment_report['dec_not_confirmed']);
            Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
        }
    } elseif ($steppay[0] == "getextenduser") {
        $balanceformatsell = number_format(select("user", "Balance", "id", $Balance_id['id'], "select")['Balance'], 0);
        $partsdic = explode("%", $steppay[1]);
        $usernamepanel = $partsdic[0];
        $sql = "SELECT * FROM service_other WHERE username = :username  AND value  LIKE CONCAT('%', :value, '%') AND id_user = :id_user ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':username', $usernamepanel, PDO::PARAM_STR);
        $stmt->bindParam(':value', $partsdic[1], PDO::PARAM_STR);
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->execute();
        $data_order = $stmt->fetch(PDO::FETCH_ASSOC);
        $service_other = $data_order;
        if ($service_other == false) {
            sendmessage($Balance_id['id'], $textbotlang['hardcoded']['renewGenericError'], $keyboard, 'HTML');
            return;
        }
        $service_other = json_decode($service_other['value'], true);
        $codeproduct = $service_other['code_product'];
        $nameloc = select("invoice", "*", "username", $usernamepanel, "select");
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $nameloc['Service_location'], "select");
        if ($codeproduct == "custom_volume") {
            $prodcut['code_product'] = "custom_volume";
            $prodcut['name_product'] = $nameloc['name_product'];
            $prodcut['price_product'] = $data_order['price'];
            $prodcut['Service_time'] = $service_other['Service_time'];
            $prodcut['Volume_constraint'] = $service_other['volumebuy'];
        } else {
            $stmt = $pdo->prepare("SELECT * FROM product WHERE (Location = :mp2 OR Location = '/all') AND agent= :mp3 AND code_product = :mp4");
            $stmt->execute([':mp2' => $nameloc['Service_location'], ':mp3' => $Balance_id['agent'], ':mp4' => $codeproduct]);
            $prodcut = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($nameloc['name_product'] == $textbotlang['hardcoded']['testServiceNameFn']) {
            update("invoice", "name_product", $prodcut['name_product'], "id_invoice", $nameloc['id_invoice']);
            update("invoice", "price_product", $prodcut['price_product'], "id_invoice", $nameloc['id_invoice']);
        }
        $dateacc = date('Y/m/d H:i:s');
        $DataUserOut = $ManagePanel->DataUser($nameloc['Service_location'], $nameloc['username']);
        $Balance_Low_user = 0;
        update("user", "Balance", $Balance_Low_user, "id", $Balance_id['id']);
        $extend = $ManagePanel->extend($marzban_list_get['Methodextend'], $prodcut['Volume_constraint'], $prodcut['Service_time'], $nameloc['username'], $prodcut['code_product'], $marzban_list_get['code_panel']);
        if ($extend['status'] == false) {
            $balance = $Balance_id['Balance'] + $Payment_report['price'];
            update("user", "Balance", $balance, "id", $Balance_id['id']);
            sendmessage($Balance_id['id'], $textbotlang['users']['sell']['errorConfig'], $keyboard, 'HTML');
            sendmessage($Balance_id['id'], sprintf($textbotlang['hardcoded']['serviceRenewFailedRefund'], $balance), $keyboard, 'HTML');
            $extend['msg'] = json_encode($extend['msg']);
            $textreports = sprintf($textbotlang['hardcoded']['renewServiceErrorFn'], $marzban_list_get['name_panel'], $nameloc['username'], $extend['msg']);
            sendmessage($nameloc['id_user'], $textbotlang['extracted']['index_php']['renewServiceError'], null, 'HTML');
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $textreports,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }

        update("service_other", "output", json_encode($extend), "id", $data_order['id']);
        update("service_other", "status", "paid", "id", $data_order['id']);
        $partsdic = explode("_", $Balance_id['Processing_value_four']);
        if ($partsdic[0] == "dis") {
            $SellDiscountlimit = select("DiscountSell", "*", "codeDiscount", $partsdic[1], "select");
            $value = intval($SellDiscountlimit['usedDiscount']) + 1;
            update("DiscountSell", "usedDiscount", $value, "codeDiscount", $partsdic[1]);
            $stmt = $pdo->prepare("INSERT INTO Giftcodeconsumed (id_user,code) VALUES (:id_user,:code)");
            $stmt->bindParam(':id_user', $Balance_id['id']);
            $stmt->bindParam(':code', $partsdic[1]);
            $stmt->execute();
            $text_report = sprintf($textbotlang['hardcoded']['discountCodeUsedAdminFn'], $Balance_id['username'], $Balance_id['id'], $partsdic[1]);
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $otherreport,
                    'text' => $text_report,
                ]);
            }
        }
        $keyboardextendfnished = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['status']['backlist'], 'callback_data' => "backorder"],
                ],
                [
                    ['text' => $textbotlang['users']['status']['backservice'], 'callback_data' => "product_" . $nameloc['id_invoice']],
                ]
            ]
        ]);
        if ($Balance_id['agent'] == "f") {
            $valurcashbackextend = select("shopSetting", "*", "Namevalue", "chashbackextend", "select")['value'];
        } else {
            $valurcashbackextend = json_decode(select("shopSetting", "*", "Namevalue", "chashbackextend_agent", "select")['value'], true)[$Balance_id['agenr']];
        }
        if (intval($valurcashbackextend) != 0) {
            $result = ($prodcut['price_product'] * $valurcashbackextend) / 100;
            $pricelastextend = $result;
            update("user", "Balance", $pricelastextend, "id", $Balance_id['id']);
            sendmessage($Balance_id['id'], sprintf($textbotlang['hardcoded']['renewGiftChargedFn'], $result), null, 'HTML');
        }
        $priceproductformat = number_format($prodcut['price_product']);
        $textextend = sprintf($textbotlang['hardcoded']['renewServiceSuccessFn'], $usernamepanel, $prodcut['name_product'], $priceproductformat);
        sendmessage($Balance_id['id'], $textextend, $keyboardextendfnished, 'HTML');
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], $textbotlang['extracted']['index_php']['earned2Points'], null, 'html');
            $scorenew = $Balance_id['score'] + 2;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        $timejalali = jdate('Y/m/d H:i:s');
        $text_report = sprintf($textbotlang['hardcoded']['renewReportAdminFn'], $Balance_id['id'], $Balance_id['username'], $usernamepanel, $nameloc['Service_location'], $prodcut['name_product'], $prodcut['Volume_constraint'], $prodcut['Service_time'], $priceproductformat, $balanceformatsell, $timejalali);
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $otherservice,
                'text' => $text_report,
                'parse_mode' => "HTML"
            ]);
        }
        update("invoice", "Status", "active", "id_invoice", $nameloc['id_invoice']);
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {

            $textconfrom = sprintf($textbotlang['hardcoded']['paymentConfirmedRenew'], $usernamepanel, $prodcut['name_product'], $nameloc['Service_location'], $Balance_id['id'], $Payment_report['id_order'], $Balance_id['username'], $Balance_id['Balance'], $format_price_cart, $Payment_report['dec_not_confirmed']);
            Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
        }
    } elseif ($steppay[0] == "getextravolumeuser") {
        $steppay = explode("%", $steppay[1]);
        $volume = $steppay[1];
        $nameloc = select("invoice", "*", "username", $steppay[0], "select");
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $nameloc['Service_location'], "select");
        $Balance_Low_user = 0;
        $inboundid = $marzban_list_get['inboundid'];
        if ($nameloc['inboundid'] != null) {
            $inboundid = $nameloc['inboundid'];
        }
        update("user", "Balance", $Balance_Low_user, "id", $Balance_id['id']);
        $DataUserOut = $ManagePanel->DataUser($nameloc['Service_location'], $steppay[0]);
        $data_for_database = json_encode(array(
            'volume_value' => $volume,
            'old_volume' => $DataUserOut['data_limit'],
            'expire_old' => $DataUserOut['expire']
        ));
        $dateacc = date('Y/m/d H:i:s');
        $type = "extra_user";
        $extra_volume = $ManagePanel->extra_volume($nameloc['username'], $marzban_list_get['code_panel'], $volume);
        if ($extra_volume['status'] == false) {
            $extra_volume['msg'] = json_encode($extra_volume['msg']);
            $textreports = sprintf($textbotlang['hardcoded']['extraVolumeErrorFn'], $marzban_list_get['name_panel'], $nameloc['username'], $extra_volume['msg']);
            sendmessage($nameloc['id_user'], $textbotlang['extracted']['index_php']['extraVolumeServiceError'], null, 'HTML');
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $textreports,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        $stmt = $pdo->prepare("INSERT IGNORE INTO service_other (id_user, username,value,type,time,price,output) VALUES (:id_user,:username,:value,:type,:time,:price,:output)");
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->bindParam(':username', $steppay[0]);
        $stmt->bindParam(':value', $data_for_database);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':time', $dateacc);
        $stmt->bindParam(':price', $Payment_report['price']);
        $stmt->bindParam(':output', json_encode($extra_volume));
        $stmt->execute();
        $keyboardextrafnished = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['status']['backservice'], 'callback_data' => "product_" . $nameloc['id_invoice']],
                ]
            ]
        ]);
        $volumesformat = number_format($Payment_report['price'], 0);
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], $textbotlang['extracted']['index_php']['earned1Point'], null, 'html');
            $scorenew = $Balance_id['score'] + 1;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        $textvolume = sprintf($textbotlang['hardcoded']['extraVolumeSuccessFn'], $steppay[0], $volume, $volumesformat);
        sendmessage($Balance_id['id'], $textvolume, $keyboardextrafnished, 'HTML');
        $volumes = $volume;
        if ($Payment_report['Payment_Method'] == "cart to cart") {
            $textconfrom = sprintf($textbotlang['hardcoded']['paymentConfirmedExtraVolume'], $volumes, $steppay[0], $Balance_id['id'], $Payment_report['id_order'], $Balance_id['username'], $Balance_id['Balance'], $format_price_cart);
            Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
        }
        update("invoice", "Status", "active", "id_invoice", $nameloc['id_invoice']);
        $text_report = sprintf($textbotlang['hardcoded']['extraVolumeReportAdminFn'], $Balance_id['id'], $volumes, $Payment_report['price'], $steppay[0], $Balance_id['Balance']);
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $otherservice,
                'text' => $text_report,
                'parse_mode' => "HTML"
            ]);
        }
    } elseif ($steppay[0] == "getextratimeuser") {
        $steppay = explode("%", $steppay[1]);
        $tmieextra = $steppay[1];
        $nameloc = select("invoice", "*", "username", $steppay[0], "select");
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $nameloc['Service_location'], "select");
        $Balance_Low_user = 0;
        $inboundid = $marzban_list_get['inboundid'];
        if ($nameloc['inboundid'] != false) {
            $inboundid = $nameloc['inboundid'];
        }
        update("user", "Balance", $Balance_Low_user, "id", $nameloc['id_user']);
        $DataUserOut = $ManagePanel->DataUser($nameloc['Service_location'], $steppay[0]);
        $data_for_database = json_encode(array(
            'day' => $tmieextra,
            'old_volume' => $DataUserOut['data_limit'],
            'expire_old' => $DataUserOut['expire']
        ));
        $dateacc = date('Y/m/d H:i:s');
        $type = "extra_time_user";
        $timeservice = $DataUserOut['expire'] - time();
        $day = floor($timeservice / 86400);
        $extra_time = $ManagePanel->extra_time($nameloc['username'], $marzban_list_get['code_panel'], $tmieextra);
        if ($extra_time['status'] == false) {
            $extra_time['msg'] = json_encode($extra_time['msg']);
            $textreports = sprintf($textbotlang['hardcoded']['extraTimeErrorFn'], $marzban_list_get['name_panel'], $nameloc['username'], $extra_time['msg']);
            sendmessage($from_id, $textbotlang['extracted']['index_php']['extraVolumeServiceError'], null, 'HTML');
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $textreports,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        $stmt = $pdo->prepare("INSERT IGNORE INTO service_other (id_user, username,value,type,time,price,output) VALUES (:id_user,:username,:value,:type,:time,:price,:output)");
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->bindParam(':username', $steppay[0]);
        $stmt->bindParam(':value', $data_for_database);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':time', $dateacc);
        $stmt->bindParam(':price', $Payment_report['price']);
        $stmt->bindParam(':output', json_encode($extra_time));
        $stmt->execute();
        $keyboardextrafnished = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['status']['backservice'], 'callback_data' => "product_" . $nameloc['id_invoice']],
                ]
            ]
        ]);
        $volumesformat = number_format($Payment_report['price']);
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], $textbotlang['extracted']['index_php']['earned1Point'], null, 'html');
            $scorenew = $Balance_id['score'] + 1;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        $textextratime = sprintf($textbotlang['hardcoded']['extraTimeSuccessFn'], $steppay[0], $tmieextra, $volumesformat);
        sendmessage($Balance_id['id'], $textextratime, $keyboardextrafnished, 'HTML');
        if ($Payment_report['Payment_Method'] == "cart to cart") {
            $volumes = $tmieextra;
            $textconfrom = sprintf($textbotlang['hardcoded']['paymentConfirmedExtraTime'], $volumes, $steppay[0], $Balance_id['id'], $Payment_report['id_order'], $Balance_id['username'], $Balance_id['Balance'], $format_price_cart);
            Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
        }
        update("invoice", "Status", "active", "id_invoice", $nameloc['id_invoice']);
        $text_report = sprintf($textbotlang['hardcoded']['extraTimeReportAdminFn'], $Balance_id['id'], $volumes, $Payment_report['price'], $steppay[0]);
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $otherservice,
                'text' => $text_report,
            ]);
        }
    } else {
        $Balance_confrim = intval($Balance_id['Balance']) + intval($Payment_report['price']);
        update("user", "Balance", $Balance_confrim, "id", $Payment_report['id_user']);
        update("Payment_report", "payment_Status", "paid", "id_order", $Payment_report['id_order']);
        $Payment_report['price'] = number_format($Payment_report['price'], 0);
        $format_price_cart = $Payment_report['price'];
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {
            $textconfrom = sprintf($textbotlang['hardcoded']['newPaymentBalanceChargeFn'], $Balance_id['id'], $Payment_report['id_order'], $Balance_id['username'], $format_price_cart, $Balance_id['Balance'], $Payment_report['dec_not_confirmed']);
            Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
        }
        sendmessage($Payment_report['id_user'], sprintf($textbotlang['hardcoded']['balanceChargedThanks'], $Payment_report['price'], $Payment_report['id_order']), null, 'HTML');
    }
}
function plisio($order_id, $price, $from_id)
{
    $apinowpayments = select("PaySetting", "ValuePay", "NamePay", "apinowpayment", "select")['ValuePay'];
    $api_key = $apinowpayments;

    $url = 'https://api.plisio.net/api/v1/invoices/new';
    $url .= '?source_currency=USD';
    $url .= '&source_amount=' . urlencode($price);
    $url .= '&order_number=' . urlencode($order_id);
    $url .= '&email=customer@plisio.net';
    $url .= '&order_name=' . urlencode('TopUp - ' . $from_id);
    $url .= '&language=fa';
    $url .= '&api_key=' . urlencode($api_key);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = json_decode(curl_exec($ch), true);
    return $response['data'];
}
function checkConnection($address, $port)
{
    $socket = @stream_socket_client("tcp://$address:$port", $errno, $errstr, 5);
    if ($socket) {
        fclose($socket);
        return true;
    } else {
        return false;
    }
}
function savedata($type, $namefiled, $valuefiled)
{
    global $from_id;
    if ($type == "clear") {
        $datauser = [];
        $datauser[$namefiled] = $valuefiled;
        $data = json_encode($datauser);
        update("user", "Processing_value", $data, "id", $from_id);
    } elseif ($type == "save") {
        $userdata = select("user", "*", "id", $from_id, "select");
        $dataperevieos = json_decode($userdata['Processing_value'], true);
        $dataperevieos[$namefiled] = $valuefiled;
        update("user", "Processing_value", json_encode($dataperevieos), "id", $from_id);
    }
}
if (!function_exists('gw_lang_settings_map')) {
    // Per-language overrides for the PaySetting key/value table. The shape is
    //   {"en": {"pay": {"minbalancecart": "5"}, "cards": [{"number":..,"name":..}]}}
    // An absent key means "inherit the global value", so this stays purely
    // additive: nothing changes until an admin overrides something explicitly.
    function gw_lang_settings_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['lang_gwsettings'] ?? ''), true);
        $cache = is_array($m) ? $m : [];
        return $cache;
    }
}
if (!function_exists('gw_lang_settings_save')) {
    function gw_lang_settings_save(array $map)
    {
        // drop languages that ended up with nothing overridden, so "is anything
        // customised?" stays a simple isset() everywhere else
        foreach ($map as $l => $entry) {
            if (empty($entry['pay']) && empty($entry['cards'])) {
                unset($map[$l]);
            }
        }
        // an empty PHP array encodes as [] - keep it an object so the column
        // always holds the same shape it is decoded back into
        $json = empty($map) ? '{}' : json_encode($map, JSON_UNESCAPED_UNICODE);
        update("setting", "lang_gwsettings", $json, null, null);
        gw_lang_settings_map(true);
    }
}
if (!function_exists('gw_pay_override_has')) {
    function gw_pay_override_has($name, $lang)
    {
        $m = gw_lang_settings_map();
        return $lang !== null && isset($m[$lang]['pay'][$name]);
    }
}
if (!function_exists('pay_value')) {
    // Override-aware getPaySettingValue: the one function every payment flow
    // should read through. Passing $lang = null gives the plain global value.
    function pay_value($name, $lang = null, $default = null)
    {
        if ($lang !== null) {
            $m = gw_lang_settings_map();
            if (isset($m[$lang]['pay'][$name]) && $m[$lang]['pay'][$name] !== '') {
                return $m[$lang]['pay'][$name];
            }
        }
        $row = select("PaySetting", "ValuePay", "NamePay", $name, "select");
        if (!is_array($row) || !array_key_exists('ValuePay', $row)) {
            return $default;
        }
        return $row['ValuePay'];
    }
}
if (!function_exists('gw_pay_override_set')) {
    function gw_pay_override_set($name, $lang, $value)
    {
        $m = gw_lang_settings_map();
        if ($value === null || $value === '') {
            unset($m[$lang]['pay'][$name]);
            if (isset($m[$lang]['pay']) && empty($m[$lang]['pay'])) {
                unset($m[$lang]['pay']);
            }
        } else {
            $m[$lang]['pay'][$name] = (string) $value;
        }
        gw_lang_settings_save($m);
    }
}
if (!function_exists('gw_cards_for_lang')) {
    // Card-to-card is the one gateway whose "setting" is a list, not a scalar:
    // it reads from its own table globally, so the override mirrors that shape.
    function gw_cards_for_lang($lang)
    {
        $m = gw_lang_settings_map();
        if ($lang !== null && !empty($m[$lang]['cards']) && is_array($m[$lang]['cards'])) {
            return array_values($m[$lang]['cards']);
        }
        $rows = select("card_number", "*", null, null, "fetchAll");
        $out = [];
        foreach ((array) $rows as $r) {
            if (!empty($r['cardnumber'])) {
                $out[] = ['number' => $r['cardnumber'], 'name' => $r['namecard'] ?? ''];
            }
        }
        return $out;
    }
}
if (!function_exists('gw_cards_has_override')) {
    function gw_cards_has_override($lang)
    {
        $m = gw_lang_settings_map();
        return $lang !== null && !empty($m[$lang]['cards']);
    }
}
if (!function_exists('gw_cards_set')) {
    function gw_cards_set($lang, $cards)
    {
        $m = gw_lang_settings_map();
        $clean = [];
        foreach ((array) $cards as $c) {
            $num = trim((string) ($c['number'] ?? ''));
            if ($num !== '') {
                $clean[] = ['number' => $num, 'name' => trim((string) ($c['name'] ?? ''))];
            }
        }
        if (empty($clean)) {
            unset($m[$lang]['cards']);
        } else {
            $m[$lang]['cards'] = $clean;
        }
        gw_lang_settings_save($m);
    }
}
if (!function_exists('card_invoice_caption_get')) {
    function card_invoice_caption_get()
    {
        $setting = select("setting", "*", null, null, "select");
        $data = json_decode((string) ($setting['card_invoice_caption'] ?? ''), true);
        return is_array($data) ? $data : [];
    }
}
if (!function_exists('card_invoice_caption_for')) {
    // per-language override of textbot.cart - empty/unset means "use the
    // global default template", same contract as topup_caption_for()
    function card_invoice_caption_for($lang, $default)
    {
        $m = card_invoice_caption_get();
        $v = trim((string) ($m[$lang] ?? ''));
        return $v !== '' ? $v : $default;
    }
}
if (!function_exists('card_invoice_caption_set')) {
    function card_invoice_caption_set($lang, $text)
    {
        $m = card_invoice_caption_get();
        $text = trim((string) $text);
        if ($text === '') {
            unset($m[$lang]);
        } else {
            $m[$lang] = $text;
        }
        update("setting", "card_invoice_caption", empty($m) ? '{}' : json_encode($m, JSON_UNESCAPED_UNICODE), null, null);
    }
}
if (!function_exists('card_invoice_expired_caption_get')) {
    // {lang: "raw HTML"} - admin-editable per-language override of
    // users.Balance.cardInvoiceExpiredCaption (the "فاکتور منقضی شد" notice
    // an invoice message gets edited into once it expires). Empty/unset
    // means "use the default translation text" - same contract as every
    // other card_invoice_caption_*-style store.
    function card_invoice_expired_caption_get()
    {
        $setting = select("setting", "*", null, null, "select");
        $data = json_decode((string) ($setting['card_invoice_expired_caption'] ?? ''), true);
        return is_array($data) ? $data : [];
    }
}
if (!function_exists('card_invoice_expired_caption_for')) {
    function card_invoice_expired_caption_for($lang, $default)
    {
        $m = card_invoice_expired_caption_get();
        $v = trim((string) ($m[$lang] ?? ''));
        return $v !== '' ? $v : $default;
    }
}
if (!function_exists('card_invoice_expired_caption_set')) {
    function card_invoice_expired_caption_set($lang, $text)
    {
        $m = card_invoice_expired_caption_get();
        $text = trim((string) $text);
        if ($text === '') {
            unset($m[$lang]);
        } else {
            $m[$lang] = $text;
        }
        update("setting", "card_invoice_expired_caption", empty($m) ? '{}' : json_encode($m, JSON_UNESCAPED_UNICODE), null, null);
    }
}
if (!function_exists('card_invoice_btnstyle_map')) {
    function card_invoice_btnstyle_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['card_invoice_btnstyle'] ?? ''), true);
        $cache = is_array($m) ? $m : [];
        return $cache;
    }
}
if (!function_exists('card_invoice_btnstyle_save')) {
    function card_invoice_btnstyle_save(array $map)
    {
        foreach ($map as $lang => $byWhich) {
            foreach ((array) $byWhich as $which => $style) {
                if (empty($style)) {
                    unset($map[$lang][$which]);
                }
            }
            if (empty($map[$lang])) {
                unset($map[$lang]);
            }
        }
        update("setting", "card_invoice_btnstyle", empty($map) ? '{}' : json_encode($map, JSON_UNESCAPED_UNICODE), null, null);
        card_invoice_btnstyle_map(true);
    }
}
if (!function_exists('card_invoice_btnstyle_for')) {
    // $which: 'copyCard' or 'paidReceipt' - the 2 fixed buttons under the
    // invoice. Same {label, color} shape as topup_btnstyle, no emoji (never
    // asked for on these two).
    function card_invoice_btnstyle_for($lang, $which)
    {
        $m = card_invoice_btnstyle_map();
        $v = $m[$lang][$which] ?? [];
        return is_array($v) ? $v : [];
    }
}
if (!function_exists('card_invoice_btnstyle_set')) {
    function card_invoice_btnstyle_set($lang, $which, array $style)
    {
        $m = card_invoice_btnstyle_map();
        $clean = [];
        $label = trim((string) ($style['label'] ?? ''));
        if ($label !== '') {
            $clean['label'] = $label;
        }
        $color = (string) ($style['color'] ?? '');
        if (in_array($color, ['success', 'danger', 'primary'], true)) {
            $clean['color'] = $color;
        }
        if (!isset($m[$lang]) || !is_array($m[$lang])) {
            $m[$lang] = [];
        }
        $m[$lang][$which] = $clean;
        card_invoice_btnstyle_save($m);
    }
}
if (!function_exists('card_invoice_render')) {
    // Renders the (possibly admin-customised) invoice template against a
    // LIST of cards, not just one. {card_number}/{name_card} together mark a
    // repeating block: whichever line(s) span from the first of these two
    // placeholders to the second (inclusive) get repeated once per card, each
    // repetition substituted with that card's own number/name, joined by a
    // blank line - every other line in the template appears exactly once.
    // If only one of the two placeholders is present, just its own line
    // repeats. If neither is present, the template is used as-is (the admin
    // chose not to show card details inline at all - not this function's
    // call to prevent).
    function card_invoice_render($template, array $cards, array $otherReplacements)
    {
        $posNum = strpos($template, '{card_number}');
        $posName = strpos($template, '{name_card}');
        if (($posNum === false && $posName === false) || empty($cards)) {
            $first = $cards[0] ?? ['number' => '', 'name' => ''];
            return strtr($template, $otherReplacements + [
                '{card_number}' => $first['number'] ?? '',
                '{name_card}' => $first['name'] ?? '',
            ]);
        }
        $blockStart = $posNum === false ? $posName : ($posName === false ? $posNum : min($posNum, $posName));
        $blockEndPos = $posNum === false ? $posName : ($posName === false ? $posNum : max($posNum, $posName));
        $lineStart = strrpos(substr($template, 0, $blockStart), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $lineEndPos = strpos($template, "\n", $blockEndPos);
        $lineEnd = $lineEndPos === false ? strlen($template) : $lineEndPos;
        $block = substr($template, $lineStart, $lineEnd - $lineStart);
        $rendered = [];
        foreach ($cards as $c) {
            $name = trim((string) ($c['name'] ?? ''));
            $rendered[] = strtr($block, [
                '{card_number}' => (string) ($c['number'] ?? ''),
                '{name_card}' => $name !== '' ? $name : '—',
            ]);
        }
        $result = substr($template, 0, $lineStart) . implode("\n\n", $rendered) . substr($template, $lineEnd);
        return strtr($result, $otherReplacements);
    }
}if (!function_exists('gw_minmax_fields')) {
    // pulls the [minField, maxField] PaySetting names for a gateway straight
    // out of gw_field_registry(), so the top-up flow never has to duplicate
    // that per-gateway naming table (iranpay3's fields are literally named
    // "...iranpay", not "...iranpay3" - exactly the kind of mismatch a
    // hand-rolled second copy of this map would eventually get wrong)
    function gw_minmax_fields($key)
    {
        $min = null;
        $max = null;
        foreach (gw_field_registry()[$key] ?? [] as $field) {
            if ($field['type'] !== 'money') {
                continue;
            }
            if ($field['label'] === 'minLabel') {
                $min = $field['field'];
            } elseif ($field['label'] === 'maxLabel') {
                $max = $field['field'];
            }
        }
        return [$min, $max];
    }
}
if (!function_exists('gw_field_scope')) {
    // 'global' = one value for the whole bot (stored straight in PaySetting),
    // 'lang'   = per-language, overridable, read through pay_value().
    function gw_field_scope($field)
    {
        return (($field['scope'] ?? 'lang') === 'global') ? 'global' : 'lang';
    }
}
if (!function_exists('pay_global_set')) {
    // Upsert a PaySetting row. A plain UPDATE silently no-ops when the row was
    // never created by the installer (this really happens - see
    // gateway_globally_set's note about paymentstatussnotverify), which would
    // make a credential look saved while nothing was written.
    function pay_global_set($name, $value)
    {
        $exists = select("PaySetting", "NamePay", "NamePay", $name, "select");
        if (!is_array($exists) || !array_key_exists('NamePay', $exists)) {
            global $pdo;
            $stmt = $pdo->prepare("INSERT INTO PaySetting (NamePay, ValuePay) VALUES (?, ?)");
            $stmt->execute([$name, $value]);
        } else {
            update("PaySetting", "ValuePay", $value, "NamePay", $name);
        }
        clearSelectCache("PaySetting");
    }
}
if (!function_exists('gw_field_registry')) {
    // Which settings each gateway exposes for per-language overriding, in the
    // order they are shown. 'money' fields matter most: a 20,000 minimum makes
    // sense in Toman and is nonsense in dollars, so every gateway gets them.
    function gw_field_registry()
    {
        // min/max amount fields removed 2026-08-08 at the user's request (no
        // per-language, or per-gateway, amount limits at all any more); card's
        // separate "holder name" field removed too - each card entry already
        // carries its own name (see gw_cards_set), the standalone field was a
        // redundant leftover from the old single-global-card model
        //
        // 'scope' => 'global' means the value lives directly in PaySetting and
        // is shared by every language. Integration credentials (API keys,
        // merchant ids, the payout wallet) are global by nature - there is one
        // processor account per bot - AND, critically, the payment code reads
        // them with a direct select() rather than pay_value(), so a
        // per-language override would be stored and then silently ignored.
        // Anything without 'scope' is per-language and read through pay_value().
        return [
            'card' => [
                ['field' => 'cards', 'type' => 'cards', 'label' => 'cardsLabel'],
                ['field' => 'CartDirect', 'type' => 'text', 'label' => 'cartDirectLabel', 'scope' => 'global'],
                ['field' => 'chashbackcart', 'type' => 'text', 'label' => 'cashbackLabel', 'scope' => 'global'],
                ['field' => 'cardInvoiceExpireMinutes', 'type' => 'number', 'label' => 'invoiceExpireLabel'],
            ],
            'plisio' => [
                ['field' => 'apinowpayment', 'type' => 'text', 'label' => 'apiKeyLabel', 'scope' => 'global'],
                ['field' => 'chashbackplisio', 'type' => 'text', 'label' => 'cashbackLabel', 'scope' => 'global'],
                ['field' => 'plisioInvoiceExpireMinutes', 'type' => 'number', 'label' => 'invoiceExpireLabel'],
            ],
            'nowpayment' => [
                ['field' => 'marchent_tronseller', 'type' => 'text', 'label' => 'apiKeyLabel', 'scope' => 'global'],
                ['field' => 'cashbacknowpayment', 'type' => 'text', 'label' => 'cashbackLabel', 'scope' => 'global'],
            ],
            'digitaltron' => [
                ['field' => 'walletaddress', 'type' => 'text', 'label' => 'walletLabel'],
            ],
            'iranpay1' => [
                ['field' => 'marchent_floypay', 'type' => 'text', 'label' => 'merchantLabel', 'scope' => 'global'],
                ['field' => 'chashbackiranpay1', 'type' => 'text', 'label' => 'cashbackLabel', 'scope' => 'global'],
            ],
            'iranpay2' => [
                ['field' => 'apiternado', 'type' => 'text', 'label' => 'apiKeyLabel', 'scope' => 'global'],
                ['field' => 'walletaddress', 'type' => 'text', 'label' => 'walletLabel'],
                ['field' => 'urlpaymenttron', 'type' => 'text', 'label' => 'payUrlLabel', 'scope' => 'global'],
                ['field' => 'chashbackiranpay2', 'type' => 'text', 'label' => 'cashbackLabel', 'scope' => 'global'],
            ],
            'iranpay3' => [
                ['field' => 'apiiranpay', 'type' => 'text', 'label' => 'apiKeyLabel', 'scope' => 'global'],
                ['field' => 'chashbackiranpay3', 'type' => 'text', 'label' => 'cashbackLabel', 'scope' => 'global'],
            ],
            'aqayepardakht' => [
                ['field' => 'merchant_id_aqayepardakht', 'type' => 'text', 'label' => 'merchantLabel', 'scope' => 'global'],
                ['field' => 'chashbackaqaypardokht', 'type' => 'text', 'label' => 'cashbackLabel', 'scope' => 'global'],
            ],
            'zarinpal' => [
                ['field' => 'merchant_zarinpal', 'type' => 'text', 'label' => 'merchantLabel', 'scope' => 'global'],
                ['field' => 'chashbackzarinpal', 'type' => 'text', 'label' => 'cashbackLabel', 'scope' => 'global'],
            ],
            'paymentnotverify' => [],
            'startelegrams' => [
                ['field' => 'chashbackstar', 'type' => 'text', 'label' => 'cashbackLabel', 'scope' => 'global'],
            ],
        ];
    }
}
if (!function_exists('gw_has_any_override')) {
    // Drives the green "this language is customised" button in the admin hub.
    function gw_has_any_override($gatewayKey, $lang)
    {
        $reg = gw_field_registry();
        foreach ($reg[$gatewayKey] ?? [] as $f) {
            if (gw_field_scope($f) === 'global') {
                continue; // global fields have no per-language override to find
            }
            if ($f['type'] === 'cards') {
                if (gw_cards_has_override($lang)) {
                    return true;
                }
            } elseif (gw_pay_override_has($f['field'], $lang)) {
                return true;
            }
        }
        // invoice caption + button styling live outside gw_field_registry()
        // (they're card-specific, not a generic gateway field type), so
        // the "customised" indicator has to check them separately
        if ($gatewayKey === 'card') {
            if (trim((string) (card_invoice_caption_get()[$lang] ?? '')) !== '') {
                return true;
            }
            if (!empty(card_invoice_btnstyle_map()[$lang])) {
                return true;
            }
            if (trim((string) (card_invoice_expired_caption_get()[$lang] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }
}
if (!function_exists('gw_lang_has_any_override')) {
    function gw_lang_has_any_override($lang)
    {
        foreach (array_keys(gw_field_registry()) as $k) {
            if (gw_has_any_override($k, $lang)) {
                return true;
            }
        }
        return false;
    }
}
if (!function_exists('gw_reset_gateway')) {
    function gw_reset_gateway($gatewayKey, $lang)
    {
        $m = gw_lang_settings_map();
        foreach (gw_field_registry()[$gatewayKey] ?? [] as $f) {
            if (gw_field_scope($f) === 'global') {
                continue; // "reset this language" must never wipe a shared credential
            }
            if ($f['type'] === 'cards') {
                unset($m[$lang]['cards']);
            } else {
                unset($m[$lang]['pay'][$f['field']]);
            }
        }
        if (isset($m[$lang]['pay']) && empty($m[$lang]['pay'])) {
            unset($m[$lang]['pay']);
        }
        gw_lang_settings_save($m);
        // same reasoning as gw_has_any_override() above - these two live in
        // their own storage, so "reset this language" has to clear them here
        if ($gatewayKey === 'card') {
            card_invoice_caption_set($lang, '');
            $btnMap = card_invoice_btnstyle_map(true);
            unset($btnMap[$lang]);
            card_invoice_btnstyle_save($btnMap);
            card_invoice_expired_caption_set($lang, '');
        }
    }
}
if (!function_exists('gateway_registry')) {
    function gateway_registry($textbotlang)
    {
        return [
            'card' => $textbotlang['textbot']['cartToCart'],
            'plisio' => $textbotlang['textbot']['nowPayment'],
            'nowpayment' => $textbotlang['textbot']['cryptoPayment'],
            'digitaltron' => $textbotlang['textbot']['nowPaymentTron'],
            'iranpay1' => $textbotlang['textbot']['iranPay2'],
            'iranpay2' => $textbotlang['textbot']['iranPay3'],
            'iranpay3' => $textbotlang['textbot']['iranPay1'],
            'aqayepardakht' => $textbotlang['textbot']['aqayePardakht'],
            'zarinpal' => $textbotlang['textbot']['zarinPal'],
            'paymentnotverify' => $textbotlang['textbot']['paymentNotVerify'],
            'startelegrams' => $textbotlang['textbot']['starTelegram'],
        ];
    }
}
if (!function_exists('gateway_all_keys')) {
    // Stable identifiers for the payment buttons, taken from their callback_data
    // (card-to-card is keyed by name because it can render as a url button).
    function gateway_all_keys()
    {
        return ['card', 'plisio', 'nowpayment', 'digitaltron', 'iranpay1', 'iranpay2',
            'iranpay3', 'aqayepardakht', 'zarinpal', 'paymentnotverify', 'startelegrams'];
    }
}
if (!function_exists('gateway_lang_map')) {
    function gateway_lang_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['lang_gateways'] ?? ''), true);
        $cache = is_array($m) ? $m : [];
        return $cache;
    }
}
if (!function_exists('gateway_allowed_for_lang')) {
    // No entry for a language means "no restriction" - every gateway that is
    // globally enabled stays visible. Restrictions are opt-in per language.
    function gateway_allowed_for_lang($key, $lang)
    {
        $map = gateway_lang_map();
        if (!isset($map[$lang]) || !is_array($map[$lang])) {
            return true;
        }
        return in_array($key, $map[$lang], true);
    }
}
if (!function_exists('gateway_set_for_lang')) {
    function gateway_set_for_lang($lang, array $keys)
    {
        $map = gateway_lang_map();
        $map[$lang] = array_values(array_intersect(gateway_all_keys(), $keys));
        update("setting", "lang_gateways", json_encode($map, JSON_UNESCAPED_UNICODE), null, null);
        gateway_lang_map(true);
    }
}
if (!function_exists('topup_packages_map')) {
    // {lang: {gatewayKey: [{"amount": "50000", "label": ""}, ...]}}. Amount is
    // a bare string in that language's own currency (see currency_for_lang) -
    // same convention as every other per-language money field this session.
    // An empty label falls back to money(amount) formatting wherever it's shown.
    function topup_packages_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['topup_packages'] ?? ''), true);
        $cache = is_array($m) ? $m : [];
        return $cache;
    }
}
if (!function_exists('topup_packages_save')) {
    function topup_packages_save(array $map)
    {
        foreach ($map as $lang => $byGateway) {
            foreach ((array) $byGateway as $gw => $list) {
                if (empty($list)) {
                    unset($map[$lang][$gw]);
                }
            }
            if (empty($map[$lang])) {
                unset($map[$lang]);
            }
        }
        $json = empty($map) ? '{}' : json_encode($map, JSON_UNESCAPED_UNICODE);
        update("setting", "topup_packages", $json, null, null);
        topup_packages_map(true);
    }
}
if (!function_exists('topup_emoji_simple_for')) {
    function topup_emoji_simple_for($lang, $gatewayKey)
    {
        $m = topup_btnstyle_map();
        return !empty($m[$lang][$gatewayKey]['_emojiSimple']);
    }
}
if (!function_exists('topup_emoji_simple_toggle')) {
    function topup_emoji_simple_toggle($lang, $gatewayKey)
    {
        $m = topup_btnstyle_map();
        if (!empty($m[$lang][$gatewayKey]['_emojiSimple'])) {
            unset($m[$lang][$gatewayKey]['_emojiSimple']);
        } else {
            $m[$lang][$gatewayKey]['_emojiSimple'] = true;
        }
        topup_btnstyle_save($m);
    }
}
if (!function_exists('topup_emoji_pos_bulk_set')) {
    function topup_emoji_pos_bulk_set($lang, $gatewayKey, $pos)
    {
        $pos = $pos === 'left' ? 'left' : 'right';
        foreach (array_keys(topup_packages_for($lang, $gatewayKey)) as $i) {
            topup_packages_set_style($lang, $gatewayKey, $i, null, null, null, $pos);
        }
        foreach (['custom', 'back'] as $which) {
            $style = topup_btnstyle_for($lang, $gatewayKey, $which);
            $style['pos'] = $pos;
            topup_btnstyle_set($lang, $gatewayKey, $which, $style);
        }
    }
}
if (!function_exists('topup_packages_for')) {
        function topup_packages_for($lang, $gatewayKey, $forRender = false)
    {
        $m = topup_packages_map();
        $list = $m[$lang][$gatewayKey] ?? [];
        $list = is_array($list) ? array_values($list) : [];
        if ($forRender && topup_emoji_simple_for($lang, $gatewayKey)) {
            foreach ($list as &$p) {
                $p['noEmoji'] = true;
            }
            unset($p);
        }
        return $list;
    }
}
if (!function_exists('topup_packages_set')) {
    function topup_packages_set($lang, $gatewayKey, array $packages)
    {
        $m = topup_packages_map();
        $clean = [];
        foreach ($packages as $p) {
            $amount = trim((string) ($p['amount'] ?? ''));
            if ($amount === '' || !money_valid($amount, currency_for_lang($lang))) {
                continue;
            }
            $entry = ['amount' => money_normalize($amount), 'label' => trim((string) ($p['label'] ?? ''))];
            $emoji = trim((string) ($p['emoji'] ?? ''));
            if ($emoji !== '') {
                $entry['emoji'] = $emoji;
            }
            $emojiIcon = trim((string) ($p['emojiIcon'] ?? ''));
            if ($emojiIcon !== '') {
                $entry['emojiIcon'] = $emojiIcon;
            }
            $pos = (string) ($p['pos'] ?? '');
            if (in_array($pos, ['left', 'right'], true)) {
                $entry['pos'] = $pos;
            }
            $color = (string) ($p['color'] ?? '');
            if (in_array($color, ['success', 'danger', 'primary'], true)) {
                $entry['color'] = $color;
            }
            $clean[] = $entry;
        }
        if (empty($clean)) {
            unset($m[$lang][$gatewayKey]);
        } else {
            $m[$lang][$gatewayKey] = $clean;
        }
        topup_packages_save($m);
    }
}
if (!function_exists('topup_packages_add')) {
    function topup_packages_add($lang, $gatewayKey, $amount, $label = '')
    {
        $list = topup_packages_for($lang, $gatewayKey);
        $list[] = ['amount' => $amount, 'label' => $label];
        topup_packages_set($lang, $gatewayKey, $list);
    }
}
if (!function_exists('topup_packages_update')) {
    function topup_packages_update($lang, $gatewayKey, $index, $amount, $label)
    {
        $list = topup_packages_for($lang, $gatewayKey);
        if (!isset($list[$index])) {
            return;
        }
        $list[$index]['amount'] = $amount;
        $list[$index]['label'] = $label;
        topup_packages_set($lang, $gatewayKey, $list);
    }
}
if (!function_exists('topup_packages_set_style')) {
    // $emoji/$color/$emojiIcon/$pos: null means "leave this field untouched" -
    // pass an explicit '' to actually clear one. $emoji !== null also clears
    // any premium emojiIcon (they'd otherwise show a stale mismatched glyph);
    // setting emojiIcon does not need the reverse, callers always pass the
    // matching plain-text fallback in $emoji at the same time.
    function topup_packages_set_style($lang, $gatewayKey, $index, $emoji = null, $color = null, $emojiIcon = null, $pos = null)
    {
        $list = topup_packages_for($lang, $gatewayKey);
        if (!isset($list[$index])) {
            return;
        }
        if ($emoji !== null) {
            $list[$index]['emoji'] = $emoji;
            if ($emojiIcon === null) {
                unset($list[$index]['emojiIcon']);
            }
        }
        if ($emojiIcon !== null) {
            $list[$index]['emojiIcon'] = $emojiIcon;
        }
        if ($color !== null) {
            $list[$index]['color'] = $color;
        }
        if ($pos !== null) {
            $list[$index]['pos'] = $pos;
        }
        topup_packages_set($lang, $gatewayKey, $list);
    }
}

if (!function_exists('topup_packages_swap')) {
    // swaps two arbitrary packages by index - the reorder UX is "pick any
    // two buttons, they trade places", not a neighbour-only up/down move
    function topup_packages_swap($lang, $gatewayKey, $i, $j)
    {
        $list = topup_packages_for($lang, $gatewayKey);
        if (!isset($list[$i]) || !isset($list[$j]) || $i === $j) {
            return;
        }
        [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        topup_packages_set($lang, $gatewayKey, $list);
    }
}
if (!function_exists('topup_packages_remove')) {
    function topup_packages_remove($lang, $gatewayKey, $index)
    {
        $list = topup_packages_for($lang, $gatewayKey);
        unset($list[$index]);
        topup_packages_set($lang, $gatewayKey, array_values($list));
    }
}

if (!function_exists('topup_extract_leading_emoji')) {
    function topup_extract_leading_emoji($s)
    {
        $s = (string) $s;
        $pattern = '/^[\x{203C}\x{2049}\x{2139}\x{2194}-\x{2199}\x{21A9}-\x{21AA}\x{231A}-\x{231B}\x{23E9}-\x{23EC}\x{23F0}\x{23F3}\x{24C2}\x{25AA}-\x{25AB}\x{25B6}\x{25C0}\x{25FB}-\x{25FE}\x{2600}-\x{27BF}\x{2934}-\x{2935}\x{2B05}-\x{2B07}\x{2B1B}-\x{2B1C}\x{2B50}\x{2B55}\x{3030}\x{303D}\x{3297}\x{3299}\x{FE0E}\x{FE0F}\x{200D}\x{2764}\x{20E3}\x{1F000}-\x{1FAFF}\x{1F1E6}-\x{1F1FF}\s]*/u';
        preg_match($pattern, $s, $m);
        $emoji = trim($m[0] ?? '');
        $remainder = trim(preg_replace($pattern, '', $s));
        return [$emoji, $remainder];
    }
}
if (!function_exists('topup_styled_button')) {
    // Shared button-builder for anything on the topup screens that can carry
    // an admin-set label/emoji(+premium)/position/color override: real
    // packages AND the fixed "custom amount"/"back" buttons on the same
    // screen (see topup_btnstyle_* below). One definition so رنگ/ایموجی/جای
    // ایموجی always render identically everywhere they're used.
        function topup_styled_button($defaultLabel, array $style, $callbackData, $defaultColor = '')
    {
        $label = trim((string) ($style['label'] ?? ''));
        $baseText = $label !== '' ? $label : $defaultLabel;
        $noEmoji = !empty($style['noEmoji']);
        $emoji = trim((string) ($style['emoji'] ?? ''));
        $emojiIcon = trim((string) ($style['emojiIcon'] ?? ''));
        if ($noEmoji) {
            $emoji = '';
            $emojiIcon = '';
            $fallback = topup_extract_leading_emoji($baseText);
            $baseText = $fallback[1];
        } elseif ($emoji === '' && $emojiIcon === '') {
            $fallback = topup_extract_leading_emoji($baseText);
            if ($fallback[0] !== '') {
                $emoji = $fallback[0];
                $baseText = $fallback[1];
            }
        }
        $pos = ($style['pos'] ?? 'right') === 'left' ? 'left' : 'right';
        $text = $baseText;
        if ($emoji !== '') {
            $text = $pos === 'left' ? ($text . ' ' . $emoji) : ($emoji . ' ' . $text);
        }
        $btn = ['text' => $text];
        if ($callbackData !== '') {
            $btn['callback_data'] = $callbackData;
        }
        if ($emojiIcon !== '') {
            $btn['icon_custom_emoji_id'] = $emojiIcon;
        }
        $color = (string) ($style['color'] ?? '');
        if (!in_array($color, ['success', 'danger', 'primary'], true)) {
            $color = $defaultColor;
        }
        if (in_array($color, ['success', 'danger', 'primary'], true)) {
            $btn['style'] = $color;
        }
        return $btn;
    }
}
if (!function_exists('topup_package_label')) {
    function topup_package_label($pkg, $lang)
    {
        $default = money($pkg['amount'] ?? '0', currency_for_lang($lang));
        return topup_styled_button($default, $pkg, '')['text'];
    }
}
if (!function_exists('topup_package_button')) {
    // full button (text + optional colour/premium-emoji style) for one
    // package - the single place both the real user-facing screen and the
    // admin grid build from
    function topup_package_button($pkg, $lang, $callbackData)
    {
        $default = money($pkg['amount'] ?? '0', currency_for_lang($lang));
        return topup_styled_button($default, $pkg, $callbackData);
    }
}
if (!function_exists('topup_btnstyle_map')) {
    // {lang: {gatewayKey: {"back": {...style...}, "custom": {...style...}}}}
    // admin-set label/emoji/position/color overrides for the two FIXED
    // buttons under the preset-package grid (custom amount / back) - same
    // style shape as one package entry, minus "amount". Empty = use the
    // built-in default label/color (see topup_styled_button's $defaultColor).
    function topup_btnstyle_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['topup_btnstyle'] ?? ''), true);
        $cache = is_array($m) ? $m : [];
        return $cache;
    }
}
if (!function_exists('topup_btnstyle_save')) {
    function topup_btnstyle_save(array $map)
    {
        foreach ($map as $lang => $byGateway) {
            foreach ((array) $byGateway as $gw => $byWhich) {
                foreach ((array) $byWhich as $which => $style) {
                    if (empty($style)) {
                        unset($map[$lang][$gw][$which]);
                    }
                }
                if (empty($map[$lang][$gw])) {
                    unset($map[$lang][$gw]);
                }
            }
            if (empty($map[$lang])) {
                unset($map[$lang]);
            }
        }
        $json = empty($map) ? '{}' : json_encode($map, JSON_UNESCAPED_UNICODE);
        update("setting", "topup_btnstyle", $json, null, null);
        topup_btnstyle_map(true);
    }
}
if (!function_exists('topup_btnstyle_for')) {
        function topup_btnstyle_for($lang, $gatewayKey, $which, $forRender = false)
    {
        $m = topup_btnstyle_map();
        $v = $m[$lang][$gatewayKey][$which] ?? [];
        $v = is_array($v) ? $v : [];
        if ($forRender && topup_emoji_simple_for($lang, $gatewayKey)) {
            $v['noEmoji'] = true;
        }
        return $v;
    }
}
if (!function_exists('topup_btnstyle_set')) {
        function topup_btnstyle_set($lang, $gatewayKey, $which, array $style)
    {
        $m = topup_btnstyle_map();
        $clean = [];
        $label = trim((string) ($style['label'] ?? ''));
        if ($label !== '') {
            $clean['label'] = $label;
        }
        $emoji = trim((string) ($style['emoji'] ?? ''));
        if ($emoji !== '') {
            $clean['emoji'] = $emoji;
        }
        $emojiIcon = trim((string) ($style['emojiIcon'] ?? ''));
        if ($emojiIcon !== '') {
            $clean['emojiIcon'] = $emojiIcon;
        }
        if (!empty($style['noEmoji'])) {
            $clean['noEmoji'] = true;
        }
        $pos = (string) ($style['pos'] ?? '');
        if (in_array($pos, ['left', 'right'], true)) {
            $clean['pos'] = $pos;
        }
        $color = (string) ($style['color'] ?? '');
        if (in_array($color, ['success', 'danger', 'primary'], true)) {
            $clean['color'] = $color;
        }
        $m[$lang][$gatewayKey][$which] = $clean;
        topup_btnstyle_save($m);
    }
}

if (!function_exists('topup_card_invoice_generate')) {
    // The real card-to-card invoice: pending-payment check, min/max balance
    // check, card selection, Payment_report insert, invoice message. This is
    // the exact body that used to live only under get_step_payment's
    // "cart_to_offline" tap handler - extracted verbatim (the DB-writing
    // logic is byte-identical to before) so BOTH that original tap trigger
    // AND the topup amount-confirm step (which now skips the extra confirm
    // tap and calls this directly) share one definition. Never duplicate
    // this logic at a third call site - call this function instead.
    function card_invoice_build($from_id, $lang, $amount, $idInvoice, $textbotlang, array $setting)
    {
        global $pdo;
        // shows every card configured for this language (falls back to the
        // shared card_number table when this language has none of its own),
        // not just one random pick - card_invoice_render() below repeats the
        // {card_number}/{name_card} block once per card. Shared by the
        // original amount-pick flow AND the expired-invoice "ساخت فاکتور
        // جدید" reissue flow - pure builder, no message send/edit/delete of
        // its own, so both callers stay in charge of their own delivery.
        $cardList = gw_cards_for_lang($lang);
        if (empty($cardList)) {
            return null;
        }
        $valueprice = number_format($amount);
        $expireMinutes = (int) pay_value('cardInvoiceExpireMinutes', $lang, 30);
        if ($expireMinutes < 1) {
            $expireMinutes = 30;
        }
        $captionTemplate = card_invoice_caption_for($lang, $textbotlang['textbot']['cart']);
        $textcart = card_invoice_render($captionTemplate, $cardList, [
            '{price}' => $valueprice,
            '{minutes}' => $expireMinutes,
        ]);
        $dateacc = date('Y/m/d H:i:s');
        $randomString = bin2hex(random_bytes(5));
        $stmt = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice) VALUES (?,?,?,?,?,?,?)");
        $payment_Status = "Unpaid";
        $Payment_Method = "cart to cart";
        $stmt->execute([$from_id, $randomString, $dateacc, $amount, $payment_Status, $Payment_Method, $idInvoice]);
        if ($setting['statuscopycart'] == "1") {
            $copyStyle = card_invoice_btnstyle_for($lang, 'copyCard');
            $copyLabel = trim((string) ($copyStyle['label'] ?? '')) !== '' ? $copyStyle['label'] : $textbotlang['keyboard']['copyCardNumber'];
            $copyColor = in_array($copyStyle['color'] ?? '', ['success', 'danger', 'primary'], true) ? $copyStyle['color'] : '';
            $copyRows = [];
            foreach ($cardList as $c) {
                $cName = trim((string) ($c['name'] ?? ''));
                $label = (count($cardList) > 1 && $cName !== '') ? ($copyLabel . ' — ' . $cName) : $copyLabel;
                $copyBtn = ['text' => $label, 'copy_text' => ['text' => (string) ($c['number'] ?? '')]];
                if ($copyColor !== '') {
                    $copyBtn['style'] = $copyColor;
                }
                $copyRows[] = [$copyBtn];
            }
            $receiptStyle = card_invoice_btnstyle_for($lang, 'paidReceipt');
            $receiptBtn = topup_styled_button($textbotlang['keyboard']['paidSendReceipt'], $receiptStyle, "sendresidcart-" . $randomString);
            $sendresidcart = json_encode(['inline_keyboard' => array_merge($copyRows, [[$receiptBtn]])]);
        } else {
            $receiptStyle = card_invoice_btnstyle_for($lang, 'paidReceipt');
            $receiptBtn = topup_styled_button($textbotlang['keyboard']['paidSendReceipt'], $receiptStyle, "sendresidcart-" . $randomString);
            $sendresidcart = json_encode(['inline_keyboard' => [[$receiptBtn]]]);
        }
        return [
            'text' => $textcart,
            'keyboard' => $sendresidcart,
            'randomString' => $randomString,
        ];
    }
    function plisio_invoice_caption_get()
    {
        $setting = select("setting", "*", null, null, "select");
        $data = json_decode((string) ($setting['plisio_invoice_caption'] ?? ''), true);
        return is_array($data) ? $data : [];
    }
    function plisio_invoice_caption_for($lang, $default)
    {
        $m = plisio_invoice_caption_get();
        $v = trim((string) ($m[$lang] ?? ''));
        return $v !== '' ? $v : $default;
    }
    function plisio_invoice_caption_set($lang, $text)
    {
        $m = plisio_invoice_caption_get();
        $text = trim((string) $text);
        if ($text === '') {
            unset($m[$lang]);
        } else {
            $m[$lang] = $text;
        }
        update("setting", "plisio_invoice_caption", empty($m) ? '{}' : json_encode($m, JSON_UNESCAPED_UNICODE), null, null);
    }
    function plisio_invoice_expired_caption_get()
    {
        $setting = select("setting", "*", null, null, "select");
        $data = json_decode((string) ($setting['plisio_invoice_expired_caption'] ?? ''), true);
        return is_array($data) ? $data : [];
    }
    function plisio_invoice_expired_caption_for($lang, $default)
    {
        $m = plisio_invoice_expired_caption_get();
        $v = trim((string) ($m[$lang] ?? ''));
        return $v !== '' ? $v : $default;
    }
    function plisio_invoice_expired_caption_set($lang, $text)
    {
        $m = plisio_invoice_expired_caption_get();
        $text = trim((string) $text);
        if ($text === '') {
            unset($m[$lang]);
        } else {
            $m[$lang] = $text;
        }
        update("setting", "plisio_invoice_expired_caption", empty($m) ? '{}' : json_encode($m, JSON_UNESCAPED_UNICODE), null, null);
    }
    function plisio_invoice_btnstyle_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['plisio_invoice_btnstyle'] ?? ''), true);
        $cache = is_array($m) ? $m : [];
        return $cache;
    }
    function plisio_invoice_btnstyle_save(array $map)
    {
        foreach ($map as $lang => $byWhich) {
            foreach ((array) $byWhich as $which => $style) {
                if (empty($style)) {
                    unset($map[$lang][$which]);
                }
            }
            if (empty($map[$lang])) {
                unset($map[$lang]);
            }
        }
        update("setting", "plisio_invoice_btnstyle", empty($map) ? '{}' : json_encode($map, JSON_UNESCAPED_UNICODE), null, null);
        plisio_invoice_btnstyle_map(true);
    }
    function plisio_invoice_btnstyle_for($lang, $which)
    {
        return plisio_invoice_btnstyle_map()[$lang][$which] ?? [];
    }
    function plisio_invoice_btnstyle_set($lang, $which, array $style)
    {
        $map = plisio_invoice_btnstyle_map(true);
        $map[$lang][$which] = $style;
        plisio_invoice_btnstyle_save($map);
    }
    function plisio_invoice_btnstyle_items($textbotlang)
    {
        return [
            'pay' => $textbotlang['users']['Balance']['payments'],
            'reissue' => $textbotlang['users']['Balance']['reissueInvoiceBtn'],
        ];
    }
    // Extracted from the inline "plisio" handler in index.php, same shape/
    // reasoning as card_invoice_build(): a pure builder with no Telegram API
    // calls of its own (though it DOES call the real rate_arze()/plisio()
    // external HTTP APIs - never execute this in a test, source-verify only,
    // same rule already established for topup_card_invoice_generate()).
    // Shared by the original amount-pick flow AND the "ساخت فاکتور جدید"
    // reissue flow. Returns ['error' => null|'rate'|'toolow'|'api', ...] -
    // richer than card_invoice_build()'s plain null because plisio has 3
    // distinct failure modes, each needing its own user-facing message.
    function plisio_invoice_build($from_id, $lang, $amount, $idInvoice, $textbotlang, array $setting)
    {
        global $pdo;
        $rates = rate_arze();
        if ($rates === null) {
            return ['error' => 'rate'];
        }
        $usd = $rates['USD'];
        $usdprice = $amount / $usd;
        if ($usdprice <= 1) {
            return ['error' => 'toolow'];
        }
        $randomString = bin2hex(random_bytes(5));
        $pay = plisio($randomString, $usdprice, $from_id);
        if (isset($pay['message'])) {
            return ['error' => 'api', 'apiMessage' => $pay['message']];
        }
        $dateacc = date('Y/m/d H:i:s');
        $stmt = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice,dec_not_confirmed) VALUES (?,?,?,?,?,?,?,?)");
        $payment_Status = "Unpaid";
        $Payment_Method = "plisio";
        $stmt->execute([$from_id, $randomString, $dateacc, $amount, $payment_Status, $Payment_Method, $idInvoice, $pay['txn_id']]);
        $expireMinutes = (int) pay_value('plisioInvoiceExpireMinutes', $lang, 30);
        if ($expireMinutes < 1) {
            $expireMinutes = 30;
        }
        $captionTemplate = plisio_invoice_caption_for($lang, $textbotlang['users']['Balance']['cryptoInstruction']);
        $textnowpayments = strtr($captionTemplate, [
            '{order}' => $randomString,
            '{price}' => number_format($amount, 0),
            '{usd}' => number_format($usd),
            '{minutes}' => $expireMinutes,
        ]);
        $payStyle = plisio_invoice_btnstyle_for($lang, 'pay');
        $payBtn = topup_styled_button($textbotlang['users']['Balance']['payments'], $payStyle, '', '');
        $payBtn['url'] = $pay['invoice_url'];
        $paymentkeyboard = json_encode(['inline_keyboard' => [[$payBtn]]]);
        return [
            'error' => null,
            'text' => $textnowpayments,
            'keyboard' => $paymentkeyboard,
            'randomString' => $randomString,
        ];
    }
    function topup_card_invoice_generate($from_id, array $user, $message_id, $textbotlang, array $setting)
    {
        global $pdo;
        $checkpay = $pdo->prepare("SELECT * FROM Payment_report WHERE id = :user_id AND payment_Status = 'Unpaid'");
        $checkpay->bindValue(':user_id', $from_id, PDO::PARAM_STR);
        $checkpay->execute();
        if (($checkpay)->rowCount() != 0) {
            sendmessage($from_id, $textbotlang['users']['Balance']['pendingPayment'], null, 'HTML');
            return;
        }
        $mainbalance = pay_value("minbalancecart", $user['lang'] ?? null);
        $maxbalance = pay_value("maxbalancecart", $user['lang'] ?? null);
        if ($user['Processing_value'] < $mainbalance || $user['Processing_value'] > $maxbalance) {
            $mainbalance = number_format($mainbalance);
            $maxbalance = number_format($maxbalance);
            sendmessage($from_id, strtr($textbotlang['users']['Balance']['depositRange'], ['{mainbalance}' => $mainbalance, '{maxbalance}' => $maxbalance]), null, 'HTML');
            return;
        }
        $invoice = "{$user['Processing_value_tow']}|{$user['Processing_value_one']}";
        $built = card_invoice_build($from_id, $user['lang'] ?? 'fa', $user['Processing_value'], $invoice, $textbotlang, $setting);
        if ($built === null) {
            sendmessage($from_id, $textbotlang['users']['Balance']['noActiveCard'], null, 'HTML');
            return;
        }
        deletemessage($from_id, $message_id);
        $gethelp = select("PaySetting", "ValuePay", "NamePay", "helpcart", "select")['ValuePay'];
        if ($gethelp != 2) {
            $data = json_decode($gethelp, true);
            if ($data['type'] == "text") {
                sendmessage($from_id, $data['text'], null, 'HTML');
            } elseif ($data['type'] == "photo") {
                sendphoto($from_id, $data['photoid'], $data['text']);
            } elseif ($data['type'] == "video") {
                sendvideo($from_id, $data['videoid'], $data['text']);
            }
        }
        $newMessageId = telegram('sendmessage', [
            'chat_id' => $from_id,
            'text' => $built['text'],
            'reply_markup' => $built['keyboard'],
            'parse_mode' => "html",
        ]);
        updatePaymentMessageId($newMessageId, $built['randomString']);
    }
}
if (!function_exists('topup_plisio_invoice_generate')) {
    // The real plisio full flow: min/max balance check, plisio_invoice_build()
    // call, 3-way error dispatch, helpplisio extra content, invoice send +
    // track. Extracted verbatim from the old inline "$datain == 'plisio'"
    // handler body so both that original confirm-tap trigger AND the new
    // confirm-skip branches (toppick:/topup_custom:, mirroring card) share it.
    function topup_plisio_invoice_generate($from_id, array $user, $message_id, $textbotlang, array $setting)
    {
        global $pdo, $keyboard, $username, $errorreport;
        $mainbalanceplisio = pay_value("minbalanceplisio", $user['lang'] ?? null);
        $maxbalanceplisio = pay_value("maxbalanceplisio", $user['lang'] ?? null);
        if ($user['Processing_value'] < $mainbalanceplisio || $user['Processing_value'] > $maxbalanceplisio) {
            $mainbalanceplisio = number_format($mainbalanceplisio);
            $maxbalanceplisio = number_format($maxbalanceplisio);
            sendmessage($from_id, strtr($textbotlang['users']['Balance']['depositRangePlisio'], ['{mainbalance}' => $mainbalanceplisio, '{maxbalance}' => $maxbalanceplisio]), null, 'HTML');
            return;
        }
        deletemessage($from_id, $message_id);
        sendmessage($from_id, $textbotlang['users']['Balance']['linkpayments'], $keyboard, 'HTML');
        $invoice = "{$user['Processing_value_tow']}|{$user['Processing_value_one']}";
        $built = plisio_invoice_build($from_id, $user['lang'] ?? 'fa', $user['Processing_value'], $invoice, $textbotlang, $setting);
        if ($built['error'] === 'rate') {
            sendmessage($from_id, $textbotlang['users']['Balance']['errorLinkPayment'], $keyboard, 'HTML');
            step('home', $from_id);
            return;
        }
        if ($built['error'] === 'toolow') {
            sendmessage($from_id, $textbotlang['users']['Balance']['nowpayments'], null, 'HTML');
            return;
        }
        if ($built['error'] === 'api') {
            sendmessage($from_id, $textbotlang['users']['Balance']['errorLinkPayment'], $keyboard, 'HTML');
            step('home', $from_id);
            $ErrorsLinkPayment = sprintf($textbotlang['Admin']['reportgroup']['errorCryptoLink'], $built['apiMessage'], $from_id, $username);
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
        $gethelp = select("PaySetting", "ValuePay", "NamePay", "helpplisio", "select")['ValuePay'];
        if ($gethelp != 2) {
            $data = json_decode($gethelp, true);
            if ($data['type'] == "text") {
                sendmessage($from_id, $data['text'], null, 'HTML');
            } elseif ($data['type'] == "photo") {
                sendphoto($from_id, $data['photoid'], null);
            } elseif ($data['type'] == "video") {
                sendvideo($from_id, $data['videoid'], null);
            }
        }
        $message_id = sendmessage($from_id, $built['text'], $built['keyboard'], 'HTML');
        updatePaymentMessageId($message_id, $built['randomString']);
    }
}
if (!function_exists('plisio_expire_notify')) {
    // shared by cronbot/payment_expire.php's time-based check AND
    // cronbot/plisio.php's Plisio-API-status check, so whichever one
    // actually catches a given row first, the user sees the identical
    // edit-in-place + reissue-button treatment (not the old plain text)
    function plisio_expire_notify($id_user, $id_order, $price, $message_id, $payer_lang)
    {
        $plisioExpireLang = languagechange(null, $payer_lang);
        $expiredCapTemplate = plisio_invoice_expired_caption_for($payer_lang, $plisioExpireLang['users']['Balance']['plisioInvoiceExpiredCaption']);
        $expiredCaption = strtr($expiredCapTemplate, ['{price}' => number_format($price)]);
        $reissueStyle = plisio_invoice_btnstyle_for($payer_lang, 'reissue');
        $reissueBtn = topup_styled_button($plisioExpireLang['users']['Balance']['reissueInvoiceBtn'], $reissueStyle, "plisioreissue:{$id_order}", 'danger');
        $reissueKb = json_encode(['inline_keyboard' => [[$reissueBtn]]]);
        Editmessagetext($id_user, $message_id, $expiredCaption, $reissueKb, 'HTML');
    }
}
if (!function_exists('topup_custom_back_swapped')) {
    // whether the مبلغ دلخواه/بازگشت pair's LEFT/RIGHT order has been
    // flipped from the default - lives in the same reserved-key style as
    // _columns (topup_btnstyle_save() only strips a $which entry when
    // empty($style); this key is always either absent or literal true, so
    // it's never ambiguously "empty")
    function topup_custom_back_swapped($lang, $gatewayKey)
    {
        $m = topup_btnstyle_map();
        return !empty($m[$lang][$gatewayKey]['_customBackSwapped']);
    }
    function topup_custom_back_toggle($lang, $gatewayKey)
    {
        $m = topup_btnstyle_map();
        if (!empty($m[$lang][$gatewayKey]['_customBackSwapped'])) {
            unset($m[$lang][$gatewayKey]['_customBackSwapped']);
        } else {
            $m[$lang][$gatewayKey]['_customBackSwapped'] = true;
        }
        topup_btnstyle_save($m);
    }
    function topup_columns_toggle_full($lang, $gatewayKey)
    {
        $cur = topup_columns_for($lang, $gatewayKey);
        topup_columns_set($lang, $gatewayKey, $cur === 1 ? 3 : 1);
    }
}
if (!function_exists('topup_columns_for')) {
    // packages-per-row for this gateway/language's grid (both the admin
    // نمایش ظاهر screen and the real user topup_pkg screen read this) -
    // 1/2/3 only, default 3 (unchanged historical layout)
    function topup_columns_for($lang, $gatewayKey)
    {
        $m = topup_btnstyle_map();
        $n = (int) ($m[$lang][$gatewayKey]['_columns'] ?? 3);
        return in_array($n, [1, 2, 3], true) ? $n : 3;
    }
}
if (!function_exists('topup_columns_set')) {
    function topup_columns_set($lang, $gatewayKey, $n)
    {
        $n = (int) $n;
        if (!in_array($n, [1, 2, 3], true)) {
            return;
        }
        $m = topup_btnstyle_map();
        $m[$lang][$gatewayKey]['_columns'] = $n;
        topup_btnstyle_save($m);
    }
}
if (!function_exists('topup_captions_map')) {
    // {lang: {gatewayKey: "raw HTML caption text"}} - admin-editable per
    // gateway/language override of users.Balance.pkgPromptTitle. Empty/unset
    // means "use the default translation text" (see topup_caption_for()).
    function topup_captions_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['topup_captions'] ?? ''), true);
        $cache = is_array($m) ? $m : [];
        return $cache;
    }
}
if (!function_exists('topup_caption_for')) {
    function topup_caption_for($lang, $gatewayKey, $default)
    {
        $m = topup_captions_map();
        $v = trim((string) ($m[$lang][$gatewayKey] ?? ''));
        return $v !== '' ? $v : $default;
    }
}
if (!function_exists('topup_caption_has_override')) {
    function topup_caption_has_override($lang, $gatewayKey)
    {
        $m = topup_captions_map();
        return trim((string) ($m[$lang][$gatewayKey] ?? '')) !== '';
    }
}
if (!function_exists('topup_caption_set')) {
    function topup_caption_set($lang, $gatewayKey, $text)
    {
        $m = topup_captions_map();
        $text = trim((string) $text);
        if ($text === '') {
            unset($m[$lang][$gatewayKey]);
            if (empty($m[$lang])) {
                unset($m[$lang]);
            }
        } else {
            $m[$lang][$gatewayKey] = $text;
        }
        $json = empty($m) ? '{}' : json_encode($m, JSON_UNESCAPED_UNICODE);
        update("setting", "topup_captions", $json, null, null);
        topup_captions_map(true);
    }
}
if (!function_exists('topup_custom_captions_map')) {
    // {lang: {gatewayKey: "raw HTML caption text"}} - admin-editable per
    // gateway/language override of users.Balance.customAmountPromptTitle
    // (the "مبلغ دلخواه" ask-a-number prompt). Empty/unset means "use the
    // default translation text" (see topup_custom_caption_for()).
    function topup_custom_captions_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['topup_custom_captions'] ?? ''), true);
        $cache = is_array($m) ? $m : [];
        return $cache;
    }
}
if (!function_exists('topup_custom_caption_for')) {
    function topup_custom_caption_for($lang, $gatewayKey, $default)
    {
        $m = topup_custom_captions_map();
        $v = trim((string) ($m[$lang][$gatewayKey] ?? ''));
        return $v !== '' ? $v : $default;
    }
}
if (!function_exists('topup_custom_caption_has_override')) {
    function topup_custom_caption_has_override($lang, $gatewayKey)
    {
        $m = topup_custom_captions_map();
        return trim((string) ($m[$lang][$gatewayKey] ?? '')) !== '';
    }
}
if (!function_exists('topup_custom_caption_set')) {
    function topup_custom_caption_set($lang, $gatewayKey, $text)
    {
        $m = topup_custom_captions_map();
        $text = trim((string) $text);
        if ($text === '') {
            unset($m[$lang][$gatewayKey]);
            if (empty($m[$lang])) {
                unset($m[$lang]);
            }
        } else {
            $m[$lang][$gatewayKey] = $text;
        }
        $json = empty($m) ? '{}' : json_encode($m, JSON_UNESCAPED_UNICODE);
        update("setting", "topup_custom_captions", $json, null, null);
        topup_custom_captions_map(true);
    }
}
if (!function_exists('topup_minmax_map')) {
    // {lang: {gatewayKey: {"min": "...", "max": "..."}}} - bounds only the
    // topup_custom "مبلغ دلخواه" free-text entry for that gateway/language.
    // Deliberately separate from gw_field_registry()'s (removed) min/max -
    // this is scoped to one text box in one flow, not a general gateway limit.
    function topup_minmax_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['topup_minmax'] ?? ''), true);
        $cache = is_array($m) ? $m : [];
        return $cache;
    }
}
if (!function_exists('topup_minmax_for')) {
    function topup_minmax_for($lang, $gatewayKey)
    {
        $m = topup_minmax_map();
        $v = $m[$lang][$gatewayKey] ?? [];
        $min = isset($v['min']) && $v['min'] !== '' ? $v['min'] : null;
        $max = isset($v['max']) && $v['max'] !== '' ? $v['max'] : null;
        return [$min, $max];
    }
}
if (!function_exists('topup_minmax_set')) {
    function topup_minmax_set($lang, $gatewayKey, $min, $max)
    {
        $m = topup_minmax_map();
        $min = trim((string) $min);
        $max = trim((string) $max);
        if ($min === '' && $max === '') {
            unset($m[$lang][$gatewayKey]);
            if (empty($m[$lang])) {
                unset($m[$lang]);
            }
        } else {
            $entry = [];
            if ($min !== '') {
                $entry['min'] = $min;
            }
            if ($max !== '') {
                $entry['max'] = $max;
            }
            $m[$lang][$gatewayKey] = $entry;
        }
        $json = empty($m) ? '{}' : json_encode($m, JSON_UNESCAPED_UNICODE);
        update("setting", "topup_minmax", $json, null, null);
        topup_minmax_map(true);
    }
}
if (!function_exists('gateway_apply_button_style')) {
    // Applies the admin-configured order/width/emoji/color/rename (set via
    // 🎨 شخصی‌سازی نمایش دکمه‌ها -> 💳 روش‌های پرداخت) to an already-filtered
    // gateway keyboard. Pure post-processing: it never changes WHICH gateways
    // are present, only how they look and are ordered - gateway_filter_rows()
    // (allow-list + fa-only hard filter) must run first, same as today.
    function gateway_apply_button_style($rows, $lang, $cartToCartText = null)
    {
        $section = help_layout_section($lang, 'gateway');
        $buttons = [];
        $order = [];
        $extraRows = [];
        foreach ((array) $rows as $row) {
            $key = null;
            if (count($row) === 1) {
                $key = gateway_button_key($row[0], $cartToCartText);
            }
            if ($key === null) {
                $extraRows[] = $row;
                continue;
            }
            $btn = $row[0];
            $name = $section['rename'][$key] ?? $btn['text'];
            $emo = help_layout_emoji_prefix($key, $section);
            $btn['text'] = $emo['prefix'] . $name;
            if ($emo['icon'] !== '') {
                $btn['icon_custom_emoji_id'] = $emo['icon'];
            }
            $color = $section['color'][$key] ?? '';
            if ($color !== '' && in_array($color, ['primary', 'success', 'danger'], true)) {
                $btn['style'] = $color;
            }
            $buttons[$key] = $btn;
            $order[] = $key;
        }
        if (empty($buttons)) {
            return array_values($rows);
        }
        $ordered = help_layout_apply_order($order, $section['order']);
        $out = help_layout_chunk_rows($ordered, $buttons, $section['width']);
        foreach ($extraRows as $row) {
            $out[] = $row;
        }
        return $out;
    }
}if (!function_exists('topup_method_keyboard')) {
    // $step_payment already carries a shared "❌ بستن لیست" row used by ~9
    // other flows in this bot - the method-first top-up screen doesn't want
    // it (its own بازگشت/close buttons cover that), so this strips just that
    // trailing row for THIS display without touching the shared variable
    // every other flow still relies on unmodified.
    function topup_method_keyboard($stepPaymentJson)
    {
        $kb = json_decode($stepPaymentJson, true);
        if (!is_array($kb['inline_keyboard'] ?? null)) {
            return $stepPaymentJson;
        }
        $rows = $kb['inline_keyboard'];
        $last = end($rows);
        if (is_array($last) && count($last) === 1 && ($last[0]['callback_data'] ?? '') === 'colselist') {
            array_pop($rows);
        }
        $kb['inline_keyboard'] = array_values($rows);
        return json_encode($kb);
    }
}
if (!function_exists('gateway_fa_only_keys')) {
    // These gateways only make sense for Iranian Rial - they must never be
    // offered to a non-Persian language, regardless of what a language's own
    // allow-list says. This is the hard backstop; gateway_filter_rows() is
    // where it actually protects the real checkout flow.
    function gateway_fa_only_keys()
    {
        return ['zarinpal', 'aqayepardakht', 'iranpay1', 'iranpay2', 'iranpay3', 'paymentnotverify'];
    }
}
if (!function_exists('gateway_applicable_for_lang')) {
    function gateway_applicable_for_lang($key, $lang)
    {
        return $lang === 'fa' || !in_array($key, gateway_fa_only_keys(), true);
    }
}
if (!function_exists('gateway_button_key')) {
    function gateway_button_key($btn, $cartToCartText = null)
    {
        $cb = $btn['callback_data'] ?? null;
        if ($cb === 'cart_to_offline') {
            return 'card';
        }
        if ($cb === null && $cartToCartText !== null && ($btn['text'] ?? '') === $cartToCartText) {
            return 'card';
        }
        if ($cb !== null && in_array($cb, gateway_all_keys(), true)) {
            return $cb;
        }
        return null;
    }
}
if (!function_exists('gateway_filter_rows')) {
    // Drops the payment rows this language is not allowed to see. Rows that are
    // not a gateway (the close button) are always kept.
    function gateway_filter_rows($rows, $lang, $cartToCartText = null)
    {
        $out = [];
        foreach ((array) $rows as $row) {
            $keep = [];
            foreach ($row as $btn) {
                $key = gateway_button_key($btn, $cartToCartText);
                $ok = ($key === null)
                    || (gateway_allowed_for_lang($key, $lang) && gateway_applicable_for_lang($key, $lang));
                if ($ok) {
                    $keep[] = $btn;
                }
            }
            if (!empty($keep)) {
                $out[] = array_values($keep);
            }
        }
        return array_values($out);
    }
}
if (!function_exists('lang_scope_matches')) {
    // PHP twin of the SQL visibility filter
    //   (FIND_IN_SET(:userlang, lang) OR lang = 'all' OR lang IS NULL OR lang = '')
    // used wherever a row is filtered in PHP instead of in the query. A stored
    // value may be 'all', empty/NULL, a single code, or a comma list like
    // 'fa,en' - the multi-language case is exactly what a plain !== misses.
    function lang_scope_matches($storedLang, $userLang)
    {
        $s = trim((string) ($storedLang ?? ''));
        if ($s === '' || $s === 'all') {
            return true;
        }
        foreach (explode(',', $s) as $part) {
            if (trim($part) === (string) $userLang) {
                return true;
            }
        }
        return false;
    }
}
if (!function_exists('currency_all')) {
    // Prices are never converted between currencies - a price is a fixed number
    // that belongs to one currency. These helpers only decide which currency a
    // given viewer is in and how to render an amount in it.
    function currency_all($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        global $pdo;
        $cache = [];
        try {
            foreach ($pdo->query("SELECT * FROM currency ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cache[$row['code']] = $row;
            }
        } catch (Exception $e) {
            $cache = [];
        }
        return $cache;
    }
}
if (!function_exists('currency_default_code')) {
    function currency_default_code()
    {
        $all = currency_all();
        if (isset($all['IRT'])) {
            return 'IRT';
        }
        return $all ? array_key_first($all) : 'IRT';
    }
}
if (!function_exists('currency_get')) {
    function currency_get($code)
    {
        $all = currency_all();
        if (isset($all[$code])) {
            return $all[$code];
        }
        $fallback = currency_default_code();
        if (isset($all[$fallback])) {
            return $all[$fallback];
        }
        return ['code' => 'IRT', 'title' => 'IRT', 'symbol' => '', 'decimals' => 0, 'symbol_position' => 'after'];
    }
}
if (!function_exists('currency_lang_map')) {
    function currency_lang_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $map = json_decode((string) ($setting['lang_currency'] ?? ''), true);
        $cache = is_array($map) ? $map : [];
        return $cache;
    }
}
if (!function_exists('currency_for_lang')) {
    function currency_for_lang($lang)
    {
        $map = currency_lang_map();
        $code = $map[$lang] ?? null;
        $all = currency_all();
        return ($code !== null && isset($all[$code])) ? $code : currency_default_code();
    }
}
if (!function_exists('currency_for_user')) {
    // A user's currency is sticky: it is set once and does not follow later
    // language switches, because their wallet balance is denominated in it.
    function currency_for_user($userRow)
    {
        $all = currency_all();
        $own = is_array($userRow) ? ($userRow['currency'] ?? '') : '';
        if ($own !== '' && isset($all[$own])) {
            return $own;
        }
        return currency_for_lang(is_array($userRow) ? ($userRow['lang'] ?? 'fa') : 'fa');
    }
}
if (!function_exists('money_normalize')) {
    // Accepts Persian/Arabic digits and both decimal separators, returns a bare
    // canonical numeric string, or null when the input is not a valid amount.
    function money_normalize($raw)
    {
        $s = trim((string) $raw);
        $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٫', '،'];
        $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '.', ''];
        $s = str_replace($fa, $en, $s);
        $s = str_replace($ar, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $s);
        $s = str_replace([',', ' ', "\xC2\xA0"], '', $s);
        if ($s === '' || !preg_match('/^\d+(\.\d+)?$/', $s)) {
            return null;
        }
        return $s;
    }
}
if (!function_exists('money_valid')) {
    function money_valid($raw, $code = null)
    {
        $n = money_normalize($raw);
        if ($n === null) {
            return false;
        }
        if ($code === null) {
            return true;
        }
        $dec = (int) currency_get($code)['decimals'];
        $dot = strpos($n, '.');
        if ($dot === false) {
            return true;
        }
        return strlen(substr($n, $dot + 1)) <= max(0, $dec);
    }
}
if (!function_exists('money')) {
    // Renders an amount in one currency. Trailing zeros are trimmed so a price
    // typed as 2.1 reads as "2.1" rather than "2.10", and a whole number never
    // grows a decimal tail.
    function money($amount, $code = null, $withSymbol = true)
    {
        $cur = currency_get($code ?? currency_default_code());
        $n = money_normalize($amount);
        if ($n === null) {
            $n = '0';
        }
        $dec = (int) $cur['decimals'];
        $num = number_format((float) $n, $dec, '.', ',');
        if ($dec > 0 && strpos($num, '.') !== false) {
            $num = rtrim(rtrim($num, '0'), '.');
        }
        if (!$withSymbol || $cur['symbol'] === '') {
            return $num;
        }
        return ($cur['symbol_position'] === 'before')
            ? $cur['symbol'] . $num
            : $num . ' ' . $cur['symbol'];
    }
}
function addFieldToTable($tableName, $fieldName, $defaultValue = null, $datatype = "VARCHAR(500)")
{
    global $pdo;

    assertSqlIdentifier($tableName);
    assertSqlIdentifier($fieldName);
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_name = :tableName");
    $stmt->bindParam(':tableName', $tableName);
    $stmt->execute();
    $tableExists = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tableExists['count'] == 0)
        return;
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$pdo->query("SELECT DATABASE()")->fetchColumn(), $tableName, $fieldName]);
    $filedExists = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($filedExists['count'] != 0)
        return;
    $query = "ALTER TABLE $tableName ADD $fieldName $datatype";
    $statement = $pdo->prepare($query);
    $statement->execute();
    if ($defaultValue != null) {
        $stmt = $pdo->prepare("UPDATE $tableName SET $fieldName= ?");
        $stmt->bindParam(1, $defaultValue);
        $stmt->execute();
    }
    echo "The $fieldName field was added ✅";
}
function outtypepanel($typepanel, $message)
{
    global $from_id, $optionMarzban, $optionX_ui_single, $optionhiddfy, $option_mirza, $optionalireza_single, $optionmarzneshin, $option_mikrotik, $optionwg, $options_ui, $optionibsng, $optionrebecca;
    if ($typepanel == "marzban") {
        sendmessage($from_id, $message, $optionMarzban, 'HTML');
    } elseif ($typepanel == "x-ui_single") {
        sendmessage($from_id, $message, $optionX_ui_single, 'HTML');
    } elseif ($typepanel == "hiddify") {
        sendmessage($from_id, $message, $optionhiddfy, 'HTML');
    } elseif ($typepanel == "alireza_single") {
        sendmessage($from_id, $message, $optionalireza_single, 'HTML');
    } elseif ($typepanel == "marzneshin") {
        sendmessage($from_id, $message, $optionmarzneshin, 'HTML');
    } elseif ($typepanel == "WGDashboard") {
        sendmessage($from_id, $message, $optionwg, 'HTML');
    } elseif ($typepanel == "s_ui") {
        sendmessage($from_id, $message, $options_ui, 'HTML');
    } elseif ($typepanel == "ibsng") {
        sendmessage($from_id, $message, $optionibsng, 'HTML');
    } elseif ($typepanel == "mikrotik") {
        sendmessage($from_id, $message, $option_mikrotik, 'HTML');
    } elseif ($typepanel == "mirza_agent") {
        sendmessage($from_id, $message, $option_mirza, 'HTML');
    } elseif ($typepanel == "rebecca") {
        sendmessage($from_id, $message, $optionrebecca, 'HTML');
    }
}

function addBackgroundImage($urlimage, $qrCodeResult, $backgroundPath)
{
    if (!file_exists($backgroundPath)) {
        error_log("addBackgroundImage: File not found at $backgroundPath");
        file_put_contents($urlimage, $qrCodeResult->getString());
        return;
    }

    $qrString = $qrCodeResult->getString();
    $qrCodeImage = imagecreatefromstring($qrString);
    if (!$qrCodeImage) {
        error_log("addBackgroundImage: Failed to create QR Code resource");
        return;
    }

    $backgroundImage = null;

    try {
        $backgroundImage = imagecreatefromjpeg($backgroundPath);
    } catch (Throwable $t) {
        error_log("addBackgroundImage::EXCEPTION loading image: " . $t->getMessage());
    }

    if (!$backgroundImage) {
        $lastError = error_get_last();
        error_log("addBackgroundImage::System Error: " . $lastError['message']);

        imagepng($qrCodeImage, $urlimage);
        imagedestroy($qrCodeImage);
        return;
    }

    $qrCodeWidth = imagesx($qrCodeImage);
    $qrCodeHeight = imagesy($qrCodeImage);
    $backgroundWidth = imagesx($backgroundImage);
    $backgroundHeight = imagesy($backgroundImage);

    $x = ($backgroundWidth - $qrCodeWidth) / 2;
    $y = ($backgroundHeight - $qrCodeHeight) / 2;

    imagecopy($backgroundImage, $qrCodeImage, $x, $y, 0, 0, $qrCodeWidth, $qrCodeHeight);

    imagepng($backgroundImage, $urlimage);

    imagedestroy($qrCodeImage);
    imagedestroy($backgroundImage);
}

function checktelegramip()
{
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($clientIp) || $clientIp === '') {
        return false;
    }

    $clientIp = trim($clientIp);
    if (!filter_var($clientIp, FILTER_VALIDATE_IP)) {
        return false;
    }

    $telegramIpRanges = [
        ['lower' => '149.154.160.0', 'upper' => '149.154.175.255'],
        ['lower' => '91.108.4.0', 'upper' => '91.108.7.255'],
        ['lower' => '2001:67c:4e8::', 'upper' => '2001:67c:4e8:ffff:ffff:ffff:ffff:ffff']
    ];

    foreach ($telegramIpRanges as $range) {
        if (isClientIpInRange($clientIp, $range['lower'], $range['upper'])) {
            return true;
        }
    }

    return false;
}

function isClientIpInRange($clientIp, $lowerBound, $upperBound)
{
    $clientPacked = inet_pton($clientIp);
    $lowerPacked = inet_pton($lowerBound);
    $upperPacked = inet_pton($upperBound);

    if ($clientPacked === false || $lowerPacked === false || $upperPacked === false) {
        return false;
    }

    $length = strlen($clientPacked);
    if ($length !== strlen($lowerPacked) || $length !== strlen($upperPacked)) {
        return false;
    }

    return strcmp($clientPacked, $lowerPacked) >= 0 && strcmp($clientPacked, $upperPacked) <= 0;
}
function addCronIfNotExists($cronCommand)
{
    $commands = is_array($cronCommand) ? $cronCommand : [$cronCommand];
    $commands = array_values(array_filter(array_map('trim', $commands), static function ($command) {
        return $command !== '';
    }));

    if (empty($commands)) {
        return true;
    }

    $logContext = implode('; ', $commands);

    if (!isShellExecAvailable()) {
        error_log('shell_exec is not available; unable to register cron job(s): ' . $logContext);
        return false;
    }

    $crontabBinary = getCrontabBinary();
    if ($crontabBinary === null) {
        error_log('crontab executable not found; unable to register cron job(s): ' . $logContext);
        return false;
    }

    $existingCronJobs = runShellCommand(sprintf('%s -l 2>/dev/null', escapeshellarg($crontabBinary)));
    $existingCronJobs = trim((string) $existingCronJobs);
    $cronLines = $existingCronJobs === '' ? [] : preg_split('/\r?\n/', $existingCronJobs);
    $cronLines = array_values(array_filter(array_map('trim', $cronLines), static function ($line) {
        return $line !== '' && strpos($line, '#') !== 0;
    }));

    $newLineAdded = false;
    foreach ($commands as $command) {
        if (!in_array($command, $cronLines, true)) {
            $cronLines[] = $command;
            $newLineAdded = true;
        }
    }

    if (!$newLineAdded) {
        return true;
    }

    $cronLines = array_values(array_unique($cronLines));
    $cronContent = implode(PHP_EOL, $cronLines) . PHP_EOL;

    $temporaryFile = tempnam(sys_get_temp_dir(), 'cron');
    if ($temporaryFile === false) {
        error_log('Unable to create temporary file for cron job registration.');
        return false;
    }

    if (file_put_contents($temporaryFile, $cronContent) === false) {
        error_log('Unable to write cron configuration to temporary file: ' . $temporaryFile);
        unlink($temporaryFile);
        return false;
    }

    runShellCommand(sprintf('%s %s', escapeshellarg($crontabBinary), escapeshellarg($temporaryFile)));
    unlink($temporaryFile);

    return true;
}

function activecron()
{
    global $domainhosts;

    $cronCommands = [
        "*/15 * * * * curl https://$domainhosts/cronbot/statusday.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/croncard.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/NoticationsService.php",
        "*/5 * * * * curl https://$domainhosts/cronbot/payment_expire.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/sendmessage.php",
        "*/3 * * * * curl https://$domainhosts/cronbot/plisio.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/activeconfig.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/disableconfig.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/iranpay1.php",
        "0 */5 * * * curl https://$domainhosts/cronbot/backupbot.php",
        "*/2 * * * * curl https://$domainhosts/cronbot/gift.php",
        "*/30 * * * * curl https://$domainhosts/cronbot/expireagent.php",
        "*/15 * * * * curl https://$domainhosts/cronbot/on_hold.php",
        "*/2 * * * * curl https://$domainhosts/cronbot/configtest.php",
        "*/15 * * * * curl https://$domainhosts/cronbot/uptime_node.php",
        "*/15 * * * * curl https://$domainhosts/cronbot/uptime_panel.php",
    ];

    addCronIfNotExists($cronCommands);
}
function createInvoice($amount)
{
    global $from_id, $domainhosts;
    $PaySetting = select("PaySetting", "*", "NamePay", "apiiranpay", "select")['ValuePay'];
    $walletaddress = select("PaySetting", "*", "NamePay", "walletaddress", "select")['ValuePay'];

    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://pay.melorinabeauty.com/api/factor/create',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => array('amount' => $amount, 'address' => $walletaddress, 'base' => 'trx'),
        CURLOPT_HTTPHEADER => array(
            'Authorization: Token ' . $PaySetting
        ),
    ));

    $response = curl_exec($curl);
    return json_decode($response, true);
}
function verifpay($id)
{
    global $from_id, $domainhosts;
    $PaySetting = select("PaySetting", "*", "NamePay", "apiiranpay", "select")['ValuePay'];
    $walletaddress = select("PaySetting", "*", "NamePay", "walletaddress", "select")['ValuePay'];
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://pay.melorinabeauty.ir/api/factor/status?id=' . $id,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Authorization: Token ' . $PaySetting
        ),
    ));

    $response = curl_exec($curl);


    return $response;
}
function createInvoiceiranpay1($amount, $id_invoice)
{
    global $domainhosts;
    $PaySetting = select("PaySetting", "*", "NamePay", "marchent_floypay", "select")['ValuePay'];
    $curl = curl_init();
    $amount = intval($amount);
    $data = [
        "ApiKey" => $PaySetting,
        "Hash_id" => $id_invoice,
        "Amount" => $amount . "0",
        "CallbackURL" => "https://$domainhosts/payment/iranpay1.php"
    ];
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://tetra98.com/api/create_order",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => array(
            'accept: application/json',
            'Content-Type: application/json'
        ),
    ));

    $response = curl_exec($curl);
    return json_decode($response, true);
}
function sanitizeUserName($userName)
{
    $forbiddenCharacters = [
        "'",
        "\"",
        "<",
        ">",
        "--",
        "#",
        ";",
        "\\",
        "%",
        "(",
        ")"
    ];

    foreach ($forbiddenCharacters as $char) {
        $userName = str_replace($char, "", $userName);
    }

    return $userName;
}
function publickey()
{
    $privateKey = sodium_crypto_box_keypair();
    $privateKeyEncoded = base64_encode(sodium_crypto_box_secretkey($privateKey));
    $publicKey = sodium_crypto_box_publickey($privateKey);
    $publicKeyEncoded = base64_encode($publicKey);
    $presharedKey = base64_encode(random_bytes(32));
    return [
        'private_key' => $privateKeyEncoded,
        'public_key' => $publicKeyEncoded,
        'preshared_key' => $presharedKey
    ];
}
if (!function_exists('bottext_user_placeholders')) {
    // {tg_username}/{userid} - the person's Telegram identity, distinct from the
    // pre-existing {username} token (which means the newly-provisioned VPN/panel
    // service username in afterPay/afterText/preInvoice/testExpired - a different
    // thing entirely, so this deliberately does NOT reuse that token name)
    function bottext_user_placeholders($user, $from_id)
    {
        $tgUsername = trim((string) (is_array($user) ? ($user['username'] ?? '') : ''));
        if ($tgUsername === '' || strtolower($tgUsername) === 'none') {
            $tgUsername = '';
        }
        return [
            '{tg_username}' => $tgUsername,
            '{userid}' => (string) $from_id,
        ];
    }
}
if (!function_exists('usertest_reset_now')) {
    // applies $limitValue to EVERY user's remaining test-account allowance and
    // saves it as the new default for future signups - shared by both the
    // one-tap manual reset and the periodic automatic reset
    function usertest_reset_now($limitValue)
    {
        $limitValue = (int) $limitValue;
        update("user", "limit_usertest", $limitValue);
        update("setting", "limit_usertest_all", $limitValue);
    }
}
if (!function_exists('usertest_auto_reset_config')) {
    function usertest_auto_reset_config()
    {
        $raw = select("setting", "usertest_auto_reset", null, null, "select")['usertest_auto_reset'] ?? null;
        $cfg = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($cfg)) {
            $cfg = [];
        }
        return [
            'enabled' => !empty($cfg['enabled']),
            'unit' => $cfg['unit'] ?? null,
            'amount' => isset($cfg['amount']) ? (int) $cfg['amount'] : null,
            'last_reset' => isset($cfg['last_reset']) ? (int) $cfg['last_reset'] : null,
        ];
    }
}
if (!function_exists('usertest_auto_reset_save')) {
    function usertest_auto_reset_save(array $cfg)
    {
        update("setting", "usertest_auto_reset", json_encode($cfg, JSON_UNESCAPED_UNICODE), null, null);
    }
}
if (!function_exists('usertest_auto_reset_set')) {
    function usertest_auto_reset_set($unit, $amount)
    {
        usertest_auto_reset_save([
            'enabled' => true,
            'unit' => $unit,
            'amount' => (int) $amount,
            'last_reset' => time(),
        ]);
    }
}
if (!function_exists('usertest_auto_reset_disable')) {
    function usertest_auto_reset_disable()
    {
        $cfg = usertest_auto_reset_config();
        $cfg['enabled'] = false;
        usertest_auto_reset_save($cfg);
    }
}
if (!function_exists('usertest_auto_reset_unit_seconds')) {
    function usertest_auto_reset_unit_seconds($unit)
    {
        $map = ['minute' => 60, 'hour' => 3600, 'week' => 604800, 'month' => 2592000, 'year' => 31536000];
        return $map[$unit] ?? null;
    }
}
if (!function_exists('usertest_auto_reset_unit_label')) {
    function usertest_auto_reset_unit_label($unit, $textbotlang)
    {
        $labels = [
            'minute' => $textbotlang['Admin']['usertestReset']['unitMinute'],
            'hour' => $textbotlang['Admin']['usertestReset']['unitHour'],
            'week' => $textbotlang['Admin']['usertestReset']['unitWeek'],
            'month' => $textbotlang['Admin']['usertestReset']['unitMonth'],
            'year' => $textbotlang['Admin']['usertestReset']['unitYear'],
        ];
        return $labels[$unit] ?? $unit;
    }
}
if (!function_exists('usertest_maybe_auto_reset')) {
    // called from cronbot/configtest.php every 2 minutes - a plain, safe no-op
    // whenever auto-reset is off or the configured interval hasn't elapsed yet
    function usertest_maybe_auto_reset()
    {
        $cfg = usertest_auto_reset_config();
        if (!$cfg['enabled'] || $cfg['unit'] === null || $cfg['amount'] === null || $cfg['amount'] <= 0) {
            return;
        }
        $secondsPerUnit = usertest_auto_reset_unit_seconds($cfg['unit']);
        if ($secondsPerUnit === null) {
            return;
        }
        $intervalSeconds = $secondsPerUnit * $cfg['amount'];
        $lastReset = $cfg['last_reset'] ?? 0;
        if (time() - $lastReset < $intervalSeconds) {
            return;
        }
        $currentLimit = select("setting", "limit_usertest_all", null, null, "select")['limit_usertest_all'] ?? '';
        if ($currentLimit === null || $currentLimit === '') {
            $currentLimit = 1;
        }
        usertest_reset_now($currentLimit);
        $cfg['last_reset'] = time();
        usertest_auto_reset_save($cfg);
    }
}
if (!function_exists('usertest_auto_unit_picker_payload')) {
    function usertest_auto_unit_picker_payload($textbotlang)
    {
        $t = $textbotlang['Admin']['usertestReset'];
        return json_encode(['inline_keyboard' => [
            [['text' => $t['unitMinute'], 'callback_data' => 'usertestrst_autounit_minute'], ['text' => $t['unitHour'], 'callback_data' => 'usertestrst_autounit_hour']],
            [['text' => $t['unitWeek'], 'callback_data' => 'usertestrst_autounit_week'], ['text' => $t['unitMonth'], 'callback_data' => 'usertestrst_autounit_month']],
            [['text' => $t['unitYear'], 'callback_data' => 'usertestrst_autounit_year']],
            [['text' => $t['backBtn'], 'callback_data' => 'usertestrst_back']],
        ]]);
    }
}
if (!function_exists('usertest_reset_hub_payload')) {
    function usertest_reset_hub_payload($textbotlang)
    {
        $t = $textbotlang['Admin']['usertestReset'];
        $currentLimit = select("setting", "limit_usertest_all", null, null, "select")['limit_usertest_all'] ?? '';
        $currentLimit = ($currentLimit === null || $currentLimit === '') ? $t['notSetYet'] : $currentLimit;
        $cfg = usertest_auto_reset_config();
        if ($cfg['enabled'] && $cfg['unit'] !== null && $cfg['amount'] !== null) {
            $unitLabel = usertest_auto_reset_unit_label($cfg['unit'], $textbotlang);
            $autoStatus = strtr($t['autoOnStatus'], ['{amount}' => $cfg['amount'], '{unit}' => $unitLabel]);
            $autoBtnLabel = strtr($t['autoBtnOn'], ['{amount}' => $cfg['amount'], '{unit}' => $unitLabel]);
        } else {
            $autoStatus = $t['autoOffStatus'];
            $autoBtnLabel = $t['autoBtnOff'];
        }
        $lastResetLine = $cfg['last_reset'] ? strtr($t['lastResetLine'], ['{date}' => jdate('Y/m/d H:i', $cfg['last_reset'])]) : $t['lastResetNever'];
        $caption = strtr($t['hubCaption'], [
            '{limit}' => $currentLimit,
            '{autoStatus}' => $autoStatus,
            '{lastReset}' => $lastResetLine,
        ]);
        $kb = ['inline_keyboard' => [
            [['text' => $t['setNumberBtn'], 'callback_data' => 'usertestrst_set']],
            [['text' => $t['resetNowBtn'], 'callback_data' => 'usertestrst_now']],
            [['text' => $autoBtnLabel, 'callback_data' => 'usertestrst_auto']],
            [['text' => $t['closeBtn'], 'callback_data' => 'usertestrst_close']],
        ]];
        return [$caption, json_encode($kb)];
    }
}
if (!function_exists('main_menu_triggers')) {
    // every "start something fresh" entry point across the whole bot - the
    // user-facing main keyboard (both its plain-text label form and its
    // inline/glass callback form, see build_main_keyboard()'s own
    // $replacements + callback_data assignments in keyboard.php) plus the
    // admin panel's own top-level keyboard across all 3 rule tiers
    // (administrator/Seller/support - admin buttons have no inline variant).
    // Deliberately does NOT reach into any deeper sub-menu - "main buttons"
    // only, matching what was actually asked for.
    function main_menu_triggers($textbotlang)
    {
        $texts = [
            $textbotlang['textbot']['userTest'] ?? null,
            $textbotlang['textbot']['purchasedServices'] ?? null,
            $textbotlang['textbot']['support'] ?? null,
            $textbotlang['textbot']['help'] ?? null,
            $textbotlang['textbot']['accountWallet'] ?? null,
            $textbotlang['textbot']['addBalance'] ?? null,
            $textbotlang['textbot']['sell'] ?? null,
            $textbotlang['textbot']['tariffList'] ?? null,
            $textbotlang['textbot']['affiliates'] ?? null,
            $textbotlang['textbot']['wheelLuck'] ?? null,
            $textbotlang['textbot']['extend'] ?? null,
            $textbotlang['language']['changeButton'] ?? null,
            $textbotlang['textbot']['agentPanel'] ?? null,
            $textbotlang['textbot']['requestAgent'] ?? null,
            $textbotlang['Admin']['panelAdmin'] ?? null,
            $textbotlang['Admin']['Status']['btn'] ?? null,
            $textbotlang['Admin']['btnKeyboard']['managementPanel'] ?? null,
            $textbotlang['Admin']['btnKeyboard']['addPanel'] ?? null,
            $textbotlang['keyboard']['quickSetTimePrice'] ?? null,
            $textbotlang['keyboard']['quickSetVolumePrice'] ?? null,
            $textbotlang['Admin']['btnKeyboard']['manageUser'] ?? null,
            $textbotlang['keyboard']['manageUser'] ?? null,
            $textbotlang['keyboard']['shopSettings'] ?? null,
            $textbotlang['keyboard']['supportSection'] ?? null,
            $textbotlang['keyboard']['educationSection'] ?? null,
            $textbotlang['keyboard']['botReport'] ?? null,
            $textbotlang['keyboard']['panelFeatures'] ?? null,
            $textbotlang['keyboard']['generalSettings'] ?? null,
            $textbotlang['keyboard']['pendingReceipts'] ?? null,
            $textbotlang['bottext']['open_button'] ?? null,
            $textbotlang['users']['backbtn'] ?? null,
        ];
        $callbacks = [
            'usertestbtn', 'backorder', 'supportbtns', 'helpbtns', 'account',
            'Add_Balance', 'buy', 'Tariff_list', 'affiliatesbtn', 'wheel_luck',
            'extendbtn', 'change_language', 'agentpanel', 'requestagent', 'admin',
        ];
        $texts = array_values(array_unique(array_filter($texts, function ($v) {
            return $v !== null && $v !== '';
        })));
        return ['texts' => $texts, 'callbacks' => array_values(array_unique($callbacks))];
    }
}
if (!function_exists('is_main_menu_trigger')) {
    function is_main_menu_trigger($text, $datain, $textbotlang)
    {
        $triggers = main_menu_triggers($textbotlang);
        if ((string) $datain !== '' && in_array((string) $datain, $triggers['callbacks'], true)) {
            return true;
        }
        if ((string) $text !== '' && in_array((string) $text, $triggers['texts'], true)) {
            return true;
        }
        return false;
    }
}
if (!function_exists('preempt_active_session')) {
    // cancels whatever multi-step flow the user was mid-way through - both
    // in the DB and in the already-loaded $user array, so the REST of this
    // same request sees a clean 'home' step and the real dispatch for the
    // tapped button runs normally instead of being swallowed by a stale
    // step-gated branch. never touches anything if the user was already home.
    function preempt_active_session(&$user, $from_id)
    {
        $activeStep = (string) ($user['step'] ?? '');
        if ($activeStep === '' || $activeStep === 'none' || $activeStep === 'home') {
            return false;
        }
        // deliberately does NOT delete whatever Processing_value_tow currently
        // points to - keyboard.php's own top-level code (required before this
        // function ever runs) already re-purposes that same field for the
        // NEWLY-tapped button's own legitimate use (e.g. a freshly-sent
        // sticker's message id) before we get here, so its value can no
        // longer be trusted to mean "a stale message from the flow being
        // cancelled" - confirmed live: this is what was deleting the buy
        // button's own just-sent sticker. Only the DB/state gets reset here.
        step('home', $from_id);
        update("user", "Processing_value", "0", "id", $from_id);
        update("user", "Processing_value_one", "0", "id", $from_id);
        update("user", "Processing_value_four", "0", "id", $from_id);
        $user['step'] = 'home';
        $user['Processing_value'] = '0';
        $user['Processing_value_one'] = '0';
        $user['Processing_value_four'] = '0';
        // Processing_value_tow is deliberately left untouched - confirmed
        // that keyboard.php's own top-level code (required earlier in the
        // SAME request, before this function ever runs) already re-purposes
        // it for the newly-tapped button's own fresh use (e.g. text_sell's
        // sticker-message-id stash) - clearing or reading it here would
        // stomp on or misinterpret a value that isn't ours to touch
        return true;
    }
}
if (!function_exists('bt_lang_fill_defaults')) {
    // fills any key missing from a (possibly incomplete) translation with the Persian default,
    // recursively, so a partially-translated lang file never produces blank/broken messages
    function bt_lang_fill_defaults(array $target, array $fallback): array
    {
        foreach ($fallback as $k => $v) {
            if (!array_key_exists($k, $target)) {
                $target[$k] = $v;
            } elseif (is_array($v) && is_array($target[$k])) {
                $target[$k] = bt_lang_fill_defaults($target[$k], $v);
            }
        }
        return $target;
    }
}
function languagechange($path_dir = null, string $lang = 'fa')
{
    global $from_id;
    $user_lang = select("user", "*", "id", $from_id);
    $lang = $user_lang ? $user_lang['lang'] : $lang;
    $allowed = ['fa', 'en', 'ar', 'ru', 'zh', 'tk'];
    if (!in_array($lang, $allowed, true))
        $lang = 'fa';
    $base_dir = $path_dir ?: __DIR__;
    $texts = require $base_dir . '/lang/' . $lang . '.php';
    if ($lang !== 'fa' && is_array($texts)) {
        $fa_texts = require $base_dir . '/lang/fa.php';
        if (is_array($fa_texts)) {
            $texts = bt_lang_fill_defaults($texts, $fa_texts);
        }
    }
    if (is_array($texts))
        bottext_apply_overrides($texts, $lang);
    return $texts;
}
function bottext_apply_overrides(array &$base, $lang)
{
    $row = select("setting", "*", null, null, "select");
    $raw = is_array($row) ? ($row['text_edit'] ?? null) : null;
    if (!is_string($raw) || $raw === '')
        return;
    $map = json_decode($raw, true);
    if (!is_array($map))
        return;
    $langMap = $map[$lang] ?? null;
    if (!is_array($langMap))
        return;
    bottext_merge_overrides($base, $langMap);
}
if (!function_exists('bottext_merge_overrides')) {
    // recursively merges an arbitrary-depth override tree into $base, stopping
    // at string leaves - equivalent to the old single-level (group => {key:
    // value}) merge when the override is exactly 2 levels deep, but also
    // correctly reaches deeper keys like users.sell.selectCategory
    function bottext_merge_overrides(array &$base, array $overrides)
    {
        foreach ($overrides as $k => $v) {
            if (is_string($v)) {
                $base[$k] = $v;
            } elseif (is_array($v)) {
                if (!isset($base[$k]) || !is_array($base[$k])) {
                    $base[$k] = [];
                }
                bottext_merge_overrides($base[$k], $v);
            }
        }
    }
}
if (!function_exists('bottext_dotted_isset')) {
    // true if $arr[$part1][$part2]...[$partN] exists, for a "a.b.c" style key
    function bottext_dotted_isset($arr, $key)
    {
        if (!is_array($arr)) {
            return false;
        }
        $node = $arr;
        foreach (explode('.', $key) as $p) {
            if (!is_array($node) || !array_key_exists($p, $node)) {
                return false;
            }
            $node = $node[$p];
        }
        return true;
    }
}
if (!function_exists('bottext_dotted_set')) {
    // sets $arr[$part1][$part2]...[$partN] = $value, creating intermediate
    // arrays as needed, for a "a.b.c" style key
    function bottext_dotted_set(array &$arr, $key, $value)
    {
        $parts = explode('.', $key);
        $last = array_pop($parts);
        $node = &$arr;
        foreach ($parts as $p) {
            if (!isset($node[$p]) || !is_array($node[$p])) {
                $node[$p] = [];
            }
            $node = &$node[$p];
        }
        $node[$last] = $value;
    }
}
if (!function_exists('bottext_dotted_unset_walk')) {
    function bottext_dotted_unset_walk($node, $parts)
    {
        if (!is_array($node) || empty($parts)) {
            return $node;
        }
        $p = array_shift($parts);
        if (!array_key_exists($p, $node)) {
            return $node;
        }
        if (empty($parts)) {
            unset($node[$p]);
        } else {
            $node[$p] = bottext_dotted_unset_walk($node[$p], $parts);
            if (is_array($node[$p]) && empty($node[$p])) {
                unset($node[$p]);
            }
        }
        return $node;
    }
}
if (!function_exists('bottext_dotted_unset')) {
    // unsets $arr[$part1][$part2]...[$partN] for a "a.b.c" style key, and
    // prunes any intermediate array left empty by the removal
    function bottext_dotted_unset(array &$arr, $key)
    {
        $arr = bottext_dotted_unset_walk($arr, explode('.', $key));
    }
}
if (!function_exists('usertest_prompt_button_defs')) {
    // the 2 buttons on the usertest username-prompt screen (users.usertest.selectUsernamePrompt) -
    // hardcoded to this one item rather than a generic registry since it's the only item that
    // currently needs per-button label/colour overrides
    function usertest_prompt_button_defs($textbotlang)
    {
        return [
            0 => ['name' => '🔴 دکمه انصراف', 'text' => $textbotlang['keyboard']['cancelUsernameBtn'], 'style' => 'danger', 'callback_data' => 'ucancel'],
            1 => ['name' => '🟢 دکمه استفاده از پیش‌فرض', 'text' => $textbotlang['keyboard']['useDefaultUsernameBtn'], 'style' => 'success', 'callback_data' => 'usedefaultname'],
        ];
    }
}
if (!function_exists('usertest_prompt_button_override')) {
    function usertest_prompt_button_override($lang, $idx)
    {
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        return (is_array($be) && isset($be[$lang]['users.usertest.selectUsernamePrompt'][$idx]) && is_array($be[$lang]['users.usertest.selectUsernamePrompt'][$idx]))
            ? $be[$lang]['users.usertest.selectUsernamePrompt'][$idx]
            : [];
    }
}
if (!function_exists('usertest_selectUsername_kb')) {
    // renders the usertest flow's own copy of the cancel/use-default inline keyboard, applying
    // any per-language button overrides - the sell flow keeps using the shared $selectUsernameKb
    // untouched, so these overrides never leak into the (separate) purchase flow
    function usertest_selectUsername_kb($lang, $textbotlang)
    {
        $defs = usertest_prompt_button_defs($textbotlang);
        $buttons = [];
        foreach ($defs as $idx => $d) {
            $ov = usertest_prompt_button_override($lang, $idx);
            $text = (isset($ov['text']) && $ov['text'] !== '') ? $ov['text'] : $d['text'];
            $style = (isset($ov['style']) && in_array($ov['style'], ['primary', 'success', 'danger'], true)) ? $ov['style'] : $d['style'];
            $buttons[] = ['text' => $text, 'callback_data' => $d['callback_data'], 'style' => $style];
        }
        return json_encode(['inline_keyboard' => [$buttons]]);
    }
}
if (!function_exists('usertest_prompt_buttons_payload')) {
    function usertest_prompt_buttons_payload($lang, $textbotlang)
    {
        $defs = usertest_prompt_button_defs($textbotlang);
        $info = "🔘 <b>دکمه‌های اکانت تست</b>\n➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "این ۲ دکمه، زیر کپشن اکانت تست به کاربر نشون داده می‌شن.\n";
        $info .= "➖➖➖➖➖➖➖➖➖➖\n👁 پیش‌نمایش زنده - دقیقاً همینی که کاربر می‌بینه؛ روی هرکدوم بزن تا متن یا رنگش رو تغییر بدی 👇";
        $kb = ['inline_keyboard' => []];
        foreach ($defs as $idx => $d) {
            $ov = usertest_prompt_button_override($lang, $idx);
            $curText = (isset($ov['text']) && $ov['text'] !== '') ? $ov['text'] : $d['text'];
            $curStyle = (isset($ov['style']) && in_array($ov['style'], ['primary', 'success', 'danger'], true)) ? $ov['style'] : $d['style'];
            $kb['inline_keyboard'][] = [['text' => $curText, 'callback_data' => "btact|btn|{$lang}|{$idx}", 'style' => $curStyle]];
        }
        $kb['inline_keyboard'][] = [['text' => '🔁 ریست همه به پیش‌فرض', 'callback_data' => "btact|btnsrstall|{$lang}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => "bt_edit|{$lang}|users.usertest.selectUsernamePrompt"]];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('usertest_prompt_button_detail_payload')) {
    function usertest_prompt_button_detail_payload($lang, $idx, $textbotlang)
    {
        $defs = usertest_prompt_button_defs($textbotlang);
        $d = $defs[$idx] ?? $defs[0];
        $ov = usertest_prompt_button_override($lang, $idx);
        $curText = (isset($ov['text']) && $ov['text'] !== '') ? $ov['text'] : $d['text'];
        $curStyle = (isset($ov['style']) && in_array($ov['style'], ['primary', 'success', 'danger'], true)) ? $ov['style'] : $d['style'];
        $info = "🔘 <b>ویرایش {$d['name']}</b>\n➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "👁 پیش‌نمایش زنده 👇";
        $kb = ['inline_keyboard' => []];
        $kb['inline_keyboard'][] = [['text' => $curText, 'callback_data' => 'none', 'style' => $curStyle]];
        $kb['inline_keyboard'][] = [['text' => '✏️ ویرایش متن', 'callback_data' => "btact|btntext|{$lang}|{$idx}"]];
        $kb['inline_keyboard'][] = [
            ['text' => ($curStyle === 'primary' ? '✅ ' : '') . '🔵 آبی', 'callback_data' => "btact|btnstyle|{$lang}|{$idx}|primary", 'style' => 'primary'],
            ['text' => ($curStyle === 'success' ? '✅ ' : '') . '🟢 سبز', 'callback_data' => "btact|btnstyle|{$lang}|{$idx}|success", 'style' => 'success'],
            ['text' => ($curStyle === 'danger' ? '✅ ' : '') . '🔴 قرمز', 'callback_data' => "btact|btnstyle|{$lang}|{$idx}|danger", 'style' => 'danger'],
        ];
        $kb['inline_keyboard'][] = [['text' => '🔁 ریست این دکمه', 'callback_data' => "btact|btnrst|{$lang}|{$idx}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => "btact|btns|{$lang}"]];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('balancebtn_defs')) {
    function balancebtn_defs($textbotlang)
    {
        return [
            0 => ['name' => '💰 دکمه افزایش موجودی', 'text' => $textbotlang['textbot']['addBalance'], 'style' => 'success', 'callback_data' => 'Add_Balance'],
        ];
    }
}
if (!function_exists('balancebtn_override')) {
    function balancebtn_override($lang)
    {
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        return (is_array($be) && isset($be[$lang]['users.Balance.insufficientBalanceSimple'][0]) && is_array($be[$lang]['users.Balance.insufficientBalanceSimple'][0]))
            ? $be[$lang]['users.Balance.insufficientBalanceSimple'][0]
            : [];
    }
}
if (!function_exists('balancebtn_kb')) {
    // renders the "insufficient balance" message's own top-up button, applying any
    // per-language text/color/premium-emoji override - this is the keyboard actually
    // sent to users at both purchase call sites in index.php
    function balancebtn_kb($lang, $textbotlang)
    {
        $d = balancebtn_defs($textbotlang)[0];
        $ov = balancebtn_override($lang);
        $text = (isset($ov['text']) && $ov['text'] !== '') ? $ov['text'] : $d['text'];
        $style = (isset($ov['style']) && in_array($ov['style'], ['primary', 'success', 'danger'], true)) ? $ov['style'] : $d['style'];
        $pos = (isset($ov['pos']) && $ov['pos'] === 'left') ? 'left' : 'right';
        $btn = ['text' => $text, 'callback_data' => $d['callback_data'], 'style' => $style];
        if (!empty($ov['simple'])) {
            $btn['text'] = strip_leading_emoji($text);
        } elseif (!empty($ov['icon_emoji'])) {
            $btn['text'] = strip_leading_emoji($text);
            $btn['icon_custom_emoji_id'] = $ov['icon_emoji'];
        } else {
            // no custom emoji override set - pos should still be able to move
            // whatever leading emoji the DEFAULT/current text already has
            // baked in (e.g. the default "💰 افزایش موجودی"), not just a
            // separately-configured one
            if (!empty($ov['emoji'])) {
                $emoji = $ov['emoji'];
                $rest = strip_leading_emoji($text);
            } else {
                list($emoji, $rest) = split_leading_emoji($text);
            }
            if ($emoji !== '') {
                $btn['text'] = ($pos === 'left') ? trim($rest . ' ' . $emoji) : trim($emoji . ' ' . $rest);
            }
        }
        return json_encode(['inline_keyboard' => [[$btn]]]);
    }
}
if (!function_exists('balancebtn_payload')) {
    function balancebtn_payload($lang, $textbotlang)
    {
        $d = balancebtn_defs($textbotlang)[0];
        $ov = balancebtn_override($lang);
        $curText = (isset($ov['text']) && $ov['text'] !== '') ? $ov['text'] : $d['text'];
        $curStyle = (isset($ov['style']) && in_array($ov['style'], ['primary', 'success', 'danger'], true)) ? $ov['style'] : $d['style'];
        $curPos = (isset($ov['pos']) && $ov['pos'] === 'left') ? 'left' : 'right';
        $curSimple = !empty($ov['simple']);
        $previewBtn = ['text' => $curText, 'callback_data' => 'none', 'style' => $curStyle];
        $hasEmoji = false;
        if ($curSimple) {
            $previewBtn['text'] = strip_leading_emoji($curText);
        } elseif (!empty($ov['icon_emoji'])) {
            $previewBtn['text'] = strip_leading_emoji($curText);
            $previewBtn['icon_custom_emoji_id'] = $ov['icon_emoji'];
            $hasEmoji = true;
        } else {
            if (!empty($ov['emoji'])) {
                $pvEmoji = $ov['emoji'];
                $pvRest = strip_leading_emoji($curText);
                $hasEmoji = true;
            } else {
                list($pvEmoji, $pvRest) = split_leading_emoji($curText);
            }
            if ($pvEmoji !== '') {
                $previewBtn['text'] = ($curPos === 'left') ? trim($pvRest . ' ' . $pvEmoji) : trim($pvEmoji . ' ' . $pvRest);
            }
        }
        $info = "🔘 <b>دکمه افزایش موجودی</b>\n➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "این دکمه، زیر پیام «موجودی ناکافی» به کاربر نشون داده می‌شه.\n";
        $info .= "➖➖➖➖➖➖➖➖➖➖\n👁 پیش‌نمایش زنده 👇";
        $kb = ['inline_keyboard' => []];
        $kb['inline_keyboard'][] = [$previewBtn];
        $kb['inline_keyboard'][] = [['text' => '✏️ ویرایش متن', 'callback_data' => "btact|bbtntext|{$lang}"]];
        $kb['inline_keyboard'][] = [
            ['text' => ($curStyle === 'primary' ? '✅ ' : '') . '🔵 آبی', 'callback_data' => "btact|bbtnstyle|{$lang}|primary", 'style' => 'primary'],
            ['text' => ($curStyle === 'success' ? '✅ ' : '') . '🟢 سبز', 'callback_data' => "btact|bbtnstyle|{$lang}|success", 'style' => 'success'],
            ['text' => ($curStyle === 'danger' ? '✅ ' : '') . '🔴 قرمز', 'callback_data' => "btact|bbtnstyle|{$lang}|danger", 'style' => 'danger'],
        ];
        $kb['inline_keyboard'][] = [['text' => ($hasEmoji ? '✅ ' : '') . '💎 ایموجی دکمه', 'callback_data' => "btact|bbtnemoji|{$lang}"]];
        $kb['inline_keyboard'][] = [['text' => ($curSimple ? '✅ ' : '') . '🎭 حالت ساده (بدون ایموجی)', 'callback_data' => "btact|bbtnsimple|{$lang}"]];
        $kb['inline_keyboard'][] = [
            ['text' => ($curPos === 'right' ? '✅ ' : '') . '➡️ راست', 'callback_data' => "btact|bbtnpos|{$lang}|right"],
            ['text' => ($curPos === 'left' ? '✅ ' : '') . '⬅️ چپ', 'callback_data' => "btact|bbtnpos|{$lang}|left"],
        ];
        $kb['inline_keyboard'][] = [['text' => '🔁 ریست این دکمه', 'callback_data' => "btact|bbtnrst|{$lang}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => "bt_edit|{$lang}|users.Balance.insufficientBalanceSimple"]];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('volumepct_tiers_map')) {
    // admin-managed list of "at X% used, send this custom message/sticker/button"
    // thresholds for the volume-usage cron warning - same map/save/set-style
    // shape as topup_packages_*, deliberately mirrored for consistency, but its
    // own independent storage column since tiers aren't scoped to a gateway
    function volumepct_tiers_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['volumePctTiers'] ?? ''), true);
        $cache = is_array($m) ? array_values($m) : [];
        return $cache;
    }
}
if (!function_exists('volumepct_tiers_save')) {
    function volumepct_tiers_save(array $tiers)
    {
        $tiers = array_values($tiers);
        $json = empty($tiers) ? '[]' : json_encode($tiers, JSON_UNESCAPED_UNICODE);
        update("setting", "volumePctTiers", $json, null, null);
        volumepct_tiers_map(true);
    }
}
if (!function_exists('volumepct_tier_get')) {
    function volumepct_tier_get($index)
    {
        $tiers = volumepct_tiers_map();
        return $tiers[$index] ?? [];
    }
}
if (!function_exists('volumepct_kind_meta')) {
    // single source of truth for the 4 notice kinds, so no UI/cron code has to
    // scatter its own per-kind conditionals. 'single' kinds are one-off notices
    // (no threshold, exactly one instance, auto-created on first open) rather
    // than admin-managed multi-tier lists.
    function volumepct_kind_meta($kind = null)
    {
        $meta = [
            'vol' => [
                'label' => '📊 مصرف حجم (درصدی)',
                'title' => 'آستانه %s٪ مصرف',
                'rowLabel' => '🎯 %s٪ مصرف',
                'thresholdBtn' => '🎯 تغییر درصد (%s٪)',
                'thresholdPrompt' => '🎯 چند درصد مصرف؟ یه عدد بین ۰ تا ۱۰۰ بفرست (مثلاً 80)',
                'icon' => '🎯',
                'single' => false,
                'max' => 100,
            ],
            'time' => [
                'label' => '⏳ زمان باقی‌مانده',
                'title' => 'آستانه %s روز مانده',
                'rowLabel' => '⏳ %s روز مانده',
                'thresholdBtn' => '⏳ تغییر روز (%s روز)',
                'thresholdPrompt' => '⏳ چند روز مونده به اتمام اشتراک پیام بره؟ یه عدد بفرست (مثلاً 3)',
                'icon' => '⏳',
                'single' => false,
                'max' => 3650,
            ],
            'timeend' => [
                'label' => '⛔ پیام اتمام زمان',
                'title' => 'پیام اتمام زمان اشتراک',
                'rowLabel' => '⛔ پیام اتمام زمان',
                'thresholdBtn' => '',
                'thresholdPrompt' => '',
                'icon' => '⛔',
                'single' => true,
                'max' => 0,
            ],
            'volend' => [
                'label' => '🔚 پایان حجم',
                'title' => 'پیام پایان حجم بسته',
                'rowLabel' => '🔚 پیام پایان حجم',
                'thresholdBtn' => '',
                'thresholdPrompt' => '',
                'icon' => '🔚',
                'single' => true,
                'max' => 0,
            ],
        ];
        if ($kind === null) {
            return $meta;
        }
        return $meta[$kind] ?? $meta['vol'];
    }
}
if (!function_exists('volumepct_tier_kind')) {
    // entries saved before the time/end kinds existed have no 'kind' key at all -
    // they are all volume-percentage tiers, so that is the backward-compatible default
    function volumepct_tier_kind($tier)
    {
        $k = is_array($tier) ? (string) ($tier['kind'] ?? 'vol') : 'vol';
        return array_key_exists($k, volumepct_kind_meta()) ? $k : 'vol';
    }
}
if (!function_exists('volumepct_tiers_for_kind')) {
    // returns [realIndex => tier] preserving the true storage indices, so every
    // callback_data built from these keys still addresses the right row
    function volumepct_tiers_for_kind($kind)
    {
        $out = [];
        foreach (volumepct_tiers_map() as $i => $tier) {
            if (volumepct_tier_kind($tier) === $kind) {
                $out[$i] = $tier;
            }
        }
        return $out;
    }
}
if (!function_exists('volumepct_singleton_index')) {
    // find-or-create for the one-off kinds (timeend/volend) - the admin never
    // "adds" these, they just exist the first time the section is opened
    function volumepct_singleton_index($kind)
    {
        foreach (volumepct_tiers_map() as $i => $tier) {
            if (volumepct_tier_kind($tier) === $kind) {
                return $i;
            }
        }
        return volumepct_tier_add(0, $kind);
    }
}
if (!function_exists('volumepct_tier_add')) {
    function volumepct_tier_add($pct, $kind = 'vol')
    {
        $meta = volumepct_kind_meta($kind);
        $tiers = volumepct_tiers_map();
        $entry = ['pct' => max(0, min($meta['max'], intval($pct)))];
        if ($kind !== 'vol') {
            $entry['kind'] = $kind;
        }
        $tiers[] = $entry;
        volumepct_tiers_save($tiers);
        return count($tiers) - 1;
    }
}
if (!function_exists('volumepct_tier_remove')) {
    function volumepct_tier_remove($index)
    {
        $tiers = volumepct_tiers_map();
        unset($tiers[$index]);
        volumepct_tiers_save(array_values($tiers));
    }
}
if (!function_exists('volumepct_tier_set_pct')) {
    function volumepct_tier_set_pct($index, $pct)
    {
        $tiers = volumepct_tiers_map();
        if (!isset($tiers[$index])) {
            return;
        }
        $meta = volumepct_kind_meta(volumepct_tier_kind($tiers[$index]));
        $tiers[$index]['pct'] = max(0, min($meta['max'], intval($pct)));
        volumepct_tiers_save($tiers);
    }
}
if (!function_exists('volumepct_tier_set_style')) {
    // null = leave that field untouched, matches topup_packages_set_style's
    // established sentinel convention - pass '' explicitly to clear one
    function volumepct_tier_set_style($index, $style = null, $emoji = null, $emojiIcon = null, $pos = null, $simple = null)
    {
        $tiers = volumepct_tiers_map();
        if (!isset($tiers[$index])) {
            return;
        }
        if ($style !== null && in_array($style, ['primary', 'success', 'danger'], true)) {
            $tiers[$index]['style'] = $style;
        }
        if ($emoji !== null) {
            if ($emoji === '') {
                unset($tiers[$index]['emoji']);
            } else {
                $tiers[$index]['emoji'] = $emoji;
            }
            unset($tiers[$index]['emojiIcon']);
        }
        if ($emojiIcon !== null) {
            if ($emojiIcon === '') {
                unset($tiers[$index]['emojiIcon']);
            } else {
                $tiers[$index]['emojiIcon'] = $emojiIcon;
                unset($tiers[$index]['emoji']);
            }
        }
        if ($pos !== null && in_array($pos, ['left', 'right'], true)) {
            $tiers[$index]['pos'] = $pos;
        }
        if ($simple !== null) {
            $tiers[$index]['simple'] = (bool) $simple;
        }
        volumepct_tiers_save($tiers);
    }
}
if (!function_exists('volumepct_tier_set_text')) {
    function volumepct_tier_set_text($index, $lang, $text)
    {
        $tiers = volumepct_tiers_map();
        if (!isset($tiers[$index])) {
            return;
        }
        $tiers[$index]['text'][$lang] = $text;
        volumepct_tiers_save($tiers);
    }
}
if (!function_exists('volumepct_tier_set_btnlabel')) {
    function volumepct_tier_set_btnlabel($index, $lang, $label)
    {
        $tiers = volumepct_tiers_map();
        if (!isset($tiers[$index])) {
            return;
        }
        $tiers[$index]['btnLabel'][$lang] = $label;
        volumepct_tiers_save($tiers);
    }
}
if (!function_exists('volumepct_tier_set_sticker')) {
    function volumepct_tier_set_sticker($index, $lang, $fileId)
    {
        $tiers = volumepct_tiers_map();
        if (!isset($tiers[$index])) {
            return;
        }
        if ($fileId === '') {
            unset($tiers[$index]['sticker'][$lang]);
        } else {
            $tiers[$index]['sticker'][$lang] = $fileId;
        }
        volumepct_tiers_save($tiers);
    }
}
if (!function_exists('volumepct_tier_sticker')) {
    function volumepct_tier_sticker($index, $lang)
    {
        $tier = volumepct_tier_get($index);
        return (string) ($tier['sticker'][$lang] ?? '');
    }
}
if (!function_exists('volumepct_tier_default_text')) {
    function volumepct_tier_default_text($textbotlang, $kind = 'vol')
    {
        $map = [
            'vol' => 'volumePctDefaultText',
            'time' => 'volumeTimeDefaultText',
            'timeend' => 'volumeTimeEndDefaultText',
            'volend' => 'volumeEndDefaultText',
        ];
        $key = $map[$kind] ?? $map['vol'];
        if (!empty($textbotlang['hardcoded'][$key])) {
            return $textbotlang['hardcoded'][$key];
        }
        return "مشتری گرامی {username}\nحجم بسته VPN شما {packagedays} روزه {packagevolume} گیگابایتی {usedpercent} درصد استفاده شده است .\nچنان چه تمایل به مصرف سرویس خود دارید از دکمه زیر استفاده کنید 🫶";
    }
}
if (!function_exists('volumepct_tier_caption')) {
    // computes every placeholder from the real invoice/user/panel-data context -
    // {username} stays the pre-established VPN-service-username meaning (see
    // project_bottext_manager_guide), {tg_username}/{userid} reuse
    // bottext_user_placeholders() verbatim (same Telegram-identity tokens used
    // elsewhere), {expiredate}/{expiretime} use the same jdate() Jalali library
    // already used throughout index.php for admin-facing timestamps
    function volumepct_tier_caption($index, $lang, $textbotlang, $invoice, $user, $userData, $usedPercent)
    {
        require_once __DIR__ . '/jdf.php';
        // formatBytes() reads its unit-suffix labels via `global $textbotlang`
        // rather than a parameter - callers like the notification cron only ever
        // populate $this->textBotLang (a class property), so that global is
        // never actually set there and the unit suffix silently comes out empty.
        // Force it here from the parameter we already have, so this feature's
        // own {remainingvolume} always renders with a real unit regardless of
        // caller context (pre-existing issue in checkVolumeThreshold()'s own
        // formatBytes() call - out of scope here, left untouched, flagged separately).
        $GLOBALS['textbotlang'] = $textbotlang;
        $tier = volumepct_tier_get($index);
        $tpl = $tier['text'][$lang] ?? volumepct_tier_default_text($textbotlang, volumepct_tier_kind($tier));
        $remainingVolumeFormatted = formatBytes(max(0, ($userData['data_limit'] ?? 0) - ($userData['used_traffic'] ?? 0)));
        $packageDays = (string) intval($invoice['Service_time'] ?? 0);
        $packageVolume = !empty($userData['data_limit']) ? (string) round($userData['data_limit'] / (1024 ** 3)) : '0';
        $secsLeft = !empty($userData['expire']) ? max(0, $userData['expire'] - time()) : 0;
        $remainingDays = (string) intdiv($secsLeft, 86400);
        // the leftover hours WITHIN the last partial day, not the total hours -
        // the default caption reads "معادل X روز X ساعت", so these two are meant
        // to be read together, not as two independent totals
        $remainingHours = (string) intdiv($secsLeft % 86400, 3600);
        $expireDate = !empty($userData['expire']) ? jdate('Y/m/d', $userData['expire']) : '';
        $expireTime = !empty($userData['expire']) ? jdate('H:i', $userData['expire']) : '';
        // invoice.time_sell is a unix timestamp written at purchase time
        $purchaseDate = !empty($invoice['time_sell']) && ctype_digit((string) $invoice['time_sell'])
            ? jdate('Y/m/d', (int) $invoice['time_sell'])
            : '';
        $userPlaceholders = function_exists('bottext_user_placeholders')
            ? bottext_user_placeholders($user, $invoice['id_user'] ?? 0)
            : ['{tg_username}' => '', '{userid}' => (string) ($invoice['id_user'] ?? '')];
        return strtr($tpl, array_merge($userPlaceholders, [
            '{username}' => $invoice['username'] ?? '',
            '{usedpercent}' => (string) $usedPercent,
            '{remainingvolume}' => $remainingVolumeFormatted,
            '{packagedays}' => $packageDays,
            '{packagevolume}' => $packageVolume,
            '{remainingtime}' => $remainingDays,
            '{remaininghours}' => $remainingHours,
            '{expiredate}' => $expireDate,
            '{expiretime}' => $expireTime,
            '{purchasedate}' => $purchaseDate,
        ]));
    }
}
if (!function_exists('volumepct_tier_reset_style')) {
    // resets ONE tier's caption/button customization back to default WITHOUT
    // deleting the tier itself (its percentage survives) - text/btnLabel/sticker
    // are language-scoped so only the given language is cleared;
    // style/emoji/emojiIcon/pos/simple are shared across languages, cleared
    // unconditionally
    function volumepct_tier_reset_style($index, $lang)
    {
        $tiers = volumepct_tiers_map();
        if (!isset($tiers[$index])) {
            return;
        }
        unset($tiers[$index]['text'][$lang], $tiers[$index]['btnLabel'][$lang], $tiers[$index]['sticker'][$lang]);
        unset($tiers[$index]['style'], $tiers[$index]['emoji'], $tiers[$index]['emojiIcon'], $tiers[$index]['pos'], $tiers[$index]['simple']);
        volumepct_tiers_save($tiers);
    }
}
if (!function_exists('volumepct_tiers_reset_all_style')) {
    function volumepct_tiers_reset_all_style($lang, $kind = null)
    {
        $indices = ($kind === null)
            ? array_keys(volumepct_tiers_map())
            : array_keys(volumepct_tiers_for_kind($kind));
        foreach ($indices as $i) {
            volumepct_tier_reset_style($i, $lang);
        }
    }
}
if (!function_exists('volumepct_tier_kb')) {
    // renders the tier's own renew/top-up button - reuses the exact
    // 'extend_{invoiceId}' callback the pre-existing single-threshold notifier
    // already relies on (createExtendServiceKeyboard() in
    // NoticationsService.php), so tapping it still triggers the real extend flow
    function volumepct_tier_kb($index, $lang, $textbotlang, $invoiceId)
    {
        $tier = volumepct_tier_get($index);
        $text = $tier['btnLabel'][$lang] ?? ($textbotlang['keyboard']['renewService'] ?? 'تمدید سرویس');
        $style = (isset($tier['style']) && in_array($tier['style'], ['primary', 'success', 'danger'], true)) ? $tier['style'] : 'primary';
        $pos = (isset($tier['pos']) && $tier['pos'] === 'left') ? 'left' : 'right';
        $btn = ['text' => $text, 'callback_data' => 'extend_' . $invoiceId, 'style' => $style];
        if (!empty($tier['simple'])) {
            $btn['text'] = strip_leading_emoji($text);
        } elseif (!empty($tier['emojiIcon'])) {
            $btn['text'] = strip_leading_emoji($text);
            $btn['icon_custom_emoji_id'] = $tier['emojiIcon'];
        } else {
            // no custom emoji override set - pos should still be able to move
            // whatever leading emoji the DEFAULT/current button label already
            // has baked in (e.g. the default "💊 تمدید سرویس")
            if (!empty($tier['emoji'])) {
                $emoji = $tier['emoji'];
                $rest = strip_leading_emoji($text);
            } else {
                list($emoji, $rest) = split_leading_emoji($text);
            }
            if ($emoji !== '') {
                $btn['text'] = ($pos === 'left') ? trim($rest . ' ' . $emoji) : trim($emoji . ' ' . $rest);
            }
        }
        return json_encode(['inline_keyboard' => [[$btn]]]);
    }
}
if (!function_exists('volumepct_hub_payload')) {
    function volumepct_hub_payload($lang, $textbotlang)
    {
        $volCount = count(volumepct_tiers_for_kind('vol'));
        $timeCount = count(volumepct_tiers_for_kind('time'));
        $info = "🔋 <b>هشدار مصرف بسته</b>\n➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "سه نوع هشدار می‌تونی برای کاربرها تنظیم کنی؛ هر کدوم کپشن، استیکر و دکمه‌ی مستقل خودش رو داره:\n\n";
        $info .= "📊 <b>مصرف حجم</b> - وقتی درصد مشخصی از حجم بسته مصرف شد\n";
        $info .= "⏳ <b>زمان باقی‌مانده</b> - وقتی تعداد روز مشخصی تا اتمام اشتراک مونده (+ پیام اتمام زمان)\n";
        $info .= "🔚 <b>پایان حجم</b> - وقتی حجم بسته کاملاً تموم شد\n";
        $info .= "➖➖➖➖➖➖➖➖➖➖\n👇 بخشی که می‌خوای تنظیم کنی رو انتخاب کن:";
        $kb = ['inline_keyboard' => []];
        $kb['inline_keyboard'][] = [['text' => '📊 مصرف حجم (درصدی)' . ($volCount ? " • {$volCount} آستانه" : ''), 'callback_data' => "volpct|sec|{$lang}|vol"]];
        $kb['inline_keyboard'][] = [['text' => '⏳ زمان باقی‌مانده' . ($timeCount ? " • {$timeCount} آستانه" : ''), 'callback_data' => "volpct|sec|{$lang}|time"]];
        $kb['inline_keyboard'][] = [['text' => '🔚 پایان حجم', 'callback_data' => "volpct|sec|{$lang}|volend"]];
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت به لیست', 'callback_data' => "btact|back|{$lang}"]];
        $kb['inline_keyboard'][] = [['text' => $textbotlang['bottext']['btn_close'] ?? '❌ بستن', 'callback_data' => 'bt_close', 'style' => 'danger']];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('volumepct_section_payload')) {
    // the tier list for one multi-tier kind ('vol' or 'time'). Singleton kinds
    // never reach here - their callback resolves straight to a detail screen.
    function volumepct_section_payload($kind, $lang, $textbotlang)
    {
        $meta = volumepct_kind_meta($kind);
        $tiers = volumepct_tiers_for_kind($kind);
        $order = array_keys($tiers);
        // volume tiers read best highest-first (80 then 50); time tiers read
        // best lowest-first (1 day then 3 then 7) - both are "closest to the
        // limit first"
        usort($order, function ($a, $b) use ($tiers, $kind) {
            $av = intval($tiers[$a]['pct'] ?? 0);
            $bv = intval($tiers[$b]['pct'] ?? 0);
            return ($kind === 'time') ? ($av - $bv) : ($bv - $av);
        });
        $info = "{$meta['label']}\n➖➖➖➖➖➖➖➖➖➖\n";
        if ($kind === 'time') {
            $info .= "وقتی به تعداد روز تعیین‌شده تا اتمام اشتراک کاربر برسیم، پیام سفارشی همون آستانه براش ارسال می‌شه.\n";
        } else {
            $info .= "وقتی کاربر به هرکدوم از این آستانه‌های درصد مصرف برسه، پیام سفارشی همون آستانه براش ارسال می‌شه.\n";
        }
        $info .= "➖➖➖➖➖➖➖➖➖➖\n";
        $info .= empty($tiers) ? "فعلاً هیچ آستانه‌ای تعریف نشده." : "👇 روی هر آستانه بزن تا ویرایشش کنی:";
        $kb = ['inline_keyboard' => []];
        foreach ($order as $i) {
            $tier = $tiers[$i];
            $custom = !empty($tier['text'][$lang]);
            $label = sprintf($meta['rowLabel'], intval($tier['pct'] ?? 0)) . ($custom ? ' ✏️' : '');
            $style = (isset($tier['style']) && in_array($tier['style'], ['primary', 'success', 'danger'], true)) ? $tier['style'] : ($custom ? 'success' : 'primary');
            $kb['inline_keyboard'][] = [['text' => $label, 'callback_data' => "volpct|open|{$lang}|{$i}", 'style' => $style]];
        }
        $kb['inline_keyboard'][] = [['text' => '➕ افزودن آستانه جدید', 'callback_data' => "volpct|add|{$lang}|{$kind}"]];
        if ($kind === 'time') {
            $kb['inline_keyboard'][] = [['text' => '⛔ پیام اتمام زمان اشتراک', 'callback_data' => "volpct|sec|{$lang}|timeend"]];
        }
        if (!empty($tiers)) {
            $kb['inline_keyboard'][] = [['text' => '🔁 ریست کپشن و دکمه‌های این بخش', 'callback_data' => "volpct|rstall|{$lang}|{$kind}", 'style' => 'danger']];
        }
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => "volpct|hub|{$lang}"]];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('volumepct_tier_detail_payload')) {
    function volumepct_tier_detail_payload($index, $lang, $textbotlang)
    {
        $tiers = volumepct_tiers_map();
        if (!isset($tiers[$index])) {
            return volumepct_hub_payload($lang, $textbotlang);
        }
        $tier = $tiers[$index];
        $pct = intval($tier['pct'] ?? 0);
        $hasCustomText = !empty($tier['text'][$lang]);
        $curStyle = (isset($tier['style']) && in_array($tier['style'], ['primary', 'success', 'danger'], true)) ? $tier['style'] : 'primary';
        $curPos = (isset($tier['pos']) && $tier['pos'] === 'left') ? 'left' : 'right';
        $curSimple = !empty($tier['simple']);
        $hasSticker = !empty($tier['sticker'][$lang]);
        $hasEmoji = !empty($tier['emoji']) || !empty($tier['emojiIcon']);
        $previewKb = json_decode(volumepct_tier_kb($index, $lang, $textbotlang, 0), true);
        $previewBtn = $previewKb['inline_keyboard'][0][0];
        $previewBtn['callback_data'] = 'none';

        $kind = volumepct_tier_kind($tier);
        $meta = volumepct_kind_meta($kind);
        $currentCaptionRaw = $tier['text'][$lang] ?? volumepct_tier_default_text($textbotlang, $kind);
        $title = $meta['single'] ? $meta['title'] : sprintf($meta['title'], $pct);
        $info = "{$meta['icon']} <b>{$title}</b>\n➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "✏️ کپشن: " . ($hasCustomText ? "سفارشی ✅" : "پیش‌فرض") . "\n";
        $info .= "<blockquote>" . htmlspecialchars($currentCaptionRaw, ENT_QUOTES) . "</blockquote>\n";
        $info .= "🖼 استیکر: " . ($hasSticker ? "ست شده ✅" : "ندارد ❌") . "\n";
        $info .= "➖➖➖➖➖➖➖➖➖➖\n👁 پیش‌نمایش زنده دکمه 👇";

        $kb = ['inline_keyboard' => []];
        $kb['inline_keyboard'][] = [$previewBtn];
        if (!$meta['single']) {
            $kb['inline_keyboard'][] = [['text' => sprintf($meta['thresholdBtn'], $pct), 'callback_data' => "volpct|pct|{$lang}|{$index}"]];
        }
        $kb['inline_keyboard'][] = [['text' => '✏️ ویرایش کپشن', 'callback_data' => "volpct|text|{$lang}|{$index}"]];
        $kb['inline_keyboard'][] = [['text' => '🖼 استیکر', 'callback_data' => "volpct|sticker|{$lang}|{$index}"]];
        $kb['inline_keyboard'][] = [['text' => '✏️ متن دکمه', 'callback_data' => "volpct|btntext|{$lang}|{$index}"]];
        $kb['inline_keyboard'][] = [
            ['text' => ($curStyle === 'primary' ? '✅ ' : '') . '🔵 آبی', 'callback_data' => "volpct|style|{$lang}|{$index}|primary", 'style' => 'primary'],
            ['text' => ($curStyle === 'success' ? '✅ ' : '') . '🟢 سبز', 'callback_data' => "volpct|style|{$lang}|{$index}|success", 'style' => 'success'],
            ['text' => ($curStyle === 'danger' ? '✅ ' : '') . '🔴 قرمز', 'callback_data' => "volpct|style|{$lang}|{$index}|danger", 'style' => 'danger'],
        ];
        $kb['inline_keyboard'][] = [['text' => ($hasEmoji ? '✅ ' : '') . '💎 ایموجی دکمه', 'callback_data' => "volpct|emoji|{$lang}|{$index}"]];
        $kb['inline_keyboard'][] = [['text' => ($curSimple ? '✅ ' : '') . '🎭 حالت ساده (بدون ایموجی)', 'callback_data' => "volpct|simple|{$lang}|{$index}"]];
        $kb['inline_keyboard'][] = [
            ['text' => ($curPos === 'right' ? '✅ ' : '') . '➡️ راست', 'callback_data' => "volpct|pos|{$lang}|{$index}|right"],
            ['text' => ($curPos === 'left' ? '✅ ' : '') . '⬅️ چپ', 'callback_data' => "volpct|pos|{$lang}|{$index}|left"],
        ];
        $kb['inline_keyboard'][] = [['text' => '🔁 ریست کپشن و دکمه', 'callback_data' => "volpct|rst|{$lang}|{$index}", 'style' => 'danger']];
        if (!$meta['single']) {
            $kb['inline_keyboard'][] = [['text' => '🗑 حذف این آستانه', 'callback_data' => "volpct|del|{$lang}|{$index}", 'style' => 'danger']];
        }
        // timeend lives inside the time section; volend is its own top-level section
        if ($kind === 'timeend') {
            $backCb = "volpct|sec|{$lang}|time";
        } elseif ($kind === 'volend') {
            $backCb = "volpct|hub|{$lang}";
        } else {
            $backCb = "volpct|sec|{$lang}|{$kind}";
        }
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => $backCb]];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('config_col_order_payload')) {
    // controls which column comes first on the per-service config-list screen
    // (keyboard_config() in keyboard.php): the "دریافت کانفیگ" button, or the
    // config's display name - shows a live 2-row preview so the admin can see
    // exactly what the header + a sample row will look like before saving
    function config_col_order_payload($textbotlang, $originLang = null, $originKey = null)
    {
        $lang = $originLang ?? 'fa';
        $setting = select("setting", "*", null, null, "select");
        $nameFirst = (($setting['configColOrder'] ?? '') === 'name_first');
        $get = configdisplay_element_current($lang, 0, $textbotlang);
        $hConfig = configdisplay_element_current($lang, 1, $textbotlang);
        $hName = configdisplay_element_current($lang, 2, $textbotlang);
        $headerConfig = ['text' => $hConfig['text'], 'callback_data' => "cfgcolel-1-{$lang}"];
        if ($hConfig['style'] !== '') {
            $headerConfig['style'] = $hConfig['style'];
        }
        $headerName = ['text' => $hName['text'], 'callback_data' => "cfgcolel-2-{$lang}"];
        if ($hName['style'] !== '') {
            $headerName['style'] = $hName['style'];
        }
        $sampleGet = ['text' => $get['text'], 'callback_data' => "cfgcolel-0-{$lang}"];
        if ($get['style'] !== '') {
            $sampleGet['style'] = $get['style'];
        }
        $sampleName = ['text' => '🇩🇪 Germany #1', 'callback_data' => 'none'];
        $getAll = configdisplay_element_current($lang, 3, $textbotlang);
        $getAllBtn = ['text' => $getAll['text'], 'callback_data' => "cfgcolel-3-{$lang}"];
        if ($getAll['style'] !== '') {
            $getAllBtn['style'] = $getAll['style'];
        }
        // callback_data must stay under Telegram's 64-byte limit - the full dotted key
        // pushed this over (69 bytes), so the bt-flavored toggle drops it and the handler
        // hardcodes the one key this is reachable from instead. ALSO: the token must never
        // contain the substring "config_" - index.php has a pre-existing, unanchored
        // preg_match('/config_(\w+)/', $datain, ...) branch (an unrelated "look up an
        // invoice's config list" feature) that intercepts ANY callback_data containing it,
        // before admin.php's own handlers ever run - learned this the hard way.
        $cbConfigFirst = ($originLang !== null) ? "cfgcolbt-getfirst-{$originLang}" : 'configcolorder-config_first';
        $cbNameFirst = ($originLang !== null) ? "cfgcolbt-namefirst-{$originLang}" : 'configcolorder-name_first';
        $info = "🗂 <b>تنظیم نمایش و کپشن کانفیگ</b>\n➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "این بخش، نمایشِ صفحه‌ی کانفیگ‌های هر سرویس رو کنترل می‌کنه: ترتیب ستون‌ها، و متن/رنگ هرکدوم از دکمه‌ها.\n";
        $info .= "حالت فعلی ترتیب: <b>" . ($nameFirst ? "اول نام کانفیگ، بعد دکمه‌ی دریافت" : "اول دکمه‌ی دریافت، بعد نام کانفیگ (پیش‌فرض)") . "</b>\n";
        $info .= "➖➖➖➖➖➖➖➖➖➖\n🔹 راهنما:\n• روی متنِ هرکدوم از دکمه‌های پیش‌نمایش بزن → صفحه‌ی ویرایش همون دکمه باز می‌شه\n• اونجا هم می‌تونی متنش رو عوض کنی (✏️ ویرایش متن)، هم رنگش رو (🔵 آبی / 🟢 سبز / 🔴 قرمز)\n• برای برگردوندن یه دکمه به حالت پیش‌فرض، همونجا 🔁 ریست رو بزن\n";
        $info .= "➖➖➖➖➖➖➖➖➖➖\n👁 پیش‌نمایش زنده 👇";
        $kb = ['inline_keyboard' => []];
        $kb['inline_keyboard'][] = $nameFirst ? [$headerName, $headerConfig] : [$headerConfig, $headerName];
        $kb['inline_keyboard'][] = $nameFirst ? [$sampleName, $sampleGet] : [$sampleGet, $sampleName];
        $kb['inline_keyboard'][] = [$getAllBtn];
        $kb['inline_keyboard'][] = [['text' => 'عملیات', 'callback_data' => "cfgcoldemo-{$lang}"]];
        $kb['inline_keyboard'][] = [[
            'text' => (!$nameFirst ? '✅ ' : '') . 'دکمه‌ی دریافت، بعد نام کانفیگ',
            'callback_data' => $cbConfigFirst,
            'style' => (!$nameFirst ? 'success' : 'primary'),
        ]];
        $kb['inline_keyboard'][] = [[
            'text' => ($nameFirst ? '✅ ' : '') . 'نام کانفیگ، بعد دکمه‌ی دریافت',
            'callback_data' => $cbNameFirst,
            'style' => ($nameFirst ? 'success' : 'primary'),
        ]];
        if ($originLang !== null) {
            $kb['inline_keyboard'][] = [['text' => '✏️ ویرایش کپشن این بخش', 'callback_data' => "bt_edit|{$originLang}|users.status.getConfigHint"]];
            $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => "bt_edit|{$originLang}|{$originKey}"]];
        }
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('configdisplay_element_defs')) {
    // the 3 visible elements on the per-service config-list screen (keyboard_config() in
    // keyboard.php) that can be renamed/recolored: the real "دریافت کانفیگ" button, and the
    // 2 static header labels ("کانفیگ" / "نام کانفیگ") - hardcoded to these 3, same reasoning
    // as usertest_prompt_button_defs(): nothing else needs this shape yet
    function configdisplay_element_defs($textbotlang)
    {
        return [
            0 => ['name' => 'دکمه‌ی «دریافت کانفیگ»', 'text' => $textbotlang['keyboard']['getConfig']],
            1 => ['name' => 'ستون «کانفیگ»', 'text' => $textbotlang['keyboard']['config']],
            2 => ['name' => 'ستون «نام کانفیگ»', 'text' => $textbotlang['keyboard']['configName']],
            3 => ['name' => 'دکمه‌ی «دریافت همه کانفیگ‌ها»', 'text' => $textbotlang['keyboard']['getAllConfigs']],
        ];
    }
}
if (!function_exists('configdisplay_element_override')) {
    function configdisplay_element_override($lang, $idx)
    {
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        return (is_array($be) && isset($be[$lang]['configDisplay'][$idx]) && is_array($be[$lang]['configDisplay'][$idx]))
            ? $be[$lang]['configDisplay'][$idx]
            : [];
    }
}
if (!function_exists('configdisplay_element_current')) {
    function configdisplay_element_current($lang, $idx, $textbotlang)
    {
        $defs = configdisplay_element_defs($textbotlang);
        $d = $defs[$idx] ?? $defs[0];
        $ov = configdisplay_element_override($lang, $idx);
        $text = (isset($ov['text']) && $ov['text'] !== '') ? $ov['text'] : $d['text'];
        $style = (isset($ov['style']) && in_array($ov['style'], ['primary', 'success', 'danger'], true)) ? $ov['style'] : '';
        return ['text' => $text, 'style' => $style, 'name' => $d['name']];
    }
}
if (!function_exists('configdisplay_element_set_text')) {
    function configdisplay_element_set_text($lang, $idx, $text)
    {
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        if (!is_array($be)) {
            $be = [];
        }
        $be[$lang]['configDisplay'][$idx]['text'] = $text;
        update("setting", "button_edit", json_encode($be, JSON_UNESCAPED_UNICODE), null, null);
    }
}
if (!function_exists('configdisplay_element_set_style')) {
    function configdisplay_element_set_style($lang, $idx, $style)
    {
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        if (!is_array($be)) {
            $be = [];
        }
        $be[$lang]['configDisplay'][$idx]['style'] = $style;
        update("setting", "button_edit", json_encode($be, JSON_UNESCAPED_UNICODE), null, null);
    }
}
if (!function_exists('configdisplay_element_reset')) {
    function configdisplay_element_reset($lang, $idx)
    {
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        if (is_array($be) && isset($be[$lang]['configDisplay'][$idx])) {
            unset($be[$lang]['configDisplay'][$idx]);
            if (empty($be[$lang]['configDisplay'])) {
                unset($be[$lang]['configDisplay']);
            }
            if (empty($be[$lang])) {
                unset($be[$lang]);
            }
            update("setting", "button_edit", empty($be) ? null : json_encode($be, JSON_UNESCAPED_UNICODE), null, null);
        }
    }
}
if (!function_exists('configdisplay_element_payload')) {
    function configdisplay_element_payload($lang, $idx, $textbotlang)
    {
        $cur = configdisplay_element_current($lang, $idx, $textbotlang);
        $info = "🔘 <b>{$cur['name']}</b>\n➖➖➖➖➖➖➖➖➖➖\n👁 پیش‌نمایش زنده 👇";
        $previewBtn = ['text' => $cur['text'], 'callback_data' => 'none'];
        if ($cur['style'] !== '') {
            $previewBtn['style'] = $cur['style'];
        }
        $kb = ['inline_keyboard' => []];
        $kb['inline_keyboard'][] = [$previewBtn];
        $kb['inline_keyboard'][] = [['text' => '✏️ ویرایش متن', 'callback_data' => "cfgcoltext-{$idx}-{$lang}"]];
        $kb['inline_keyboard'][] = [
            ['text' => ($cur['style'] === 'primary' ? '✅ ' : '') . '🔵 آبی', 'callback_data' => "cfgcolelstyle-{$idx}-primary-{$lang}", 'style' => 'primary'],
            ['text' => ($cur['style'] === 'success' ? '✅ ' : '') . '🟢 سبز', 'callback_data' => "cfgcolelstyle-{$idx}-success-{$lang}", 'style' => 'success'],
            ['text' => ($cur['style'] === 'danger' ? '✅ ' : '') . '🔴 قرمز', 'callback_data' => "cfgcolelstyle-{$idx}-danger-{$lang}", 'style' => 'danger'],
        ];
        $kb['inline_keyboard'][] = [['text' => '🔁 ریست این المان', 'callback_data' => "cfgcolelrst-{$idx}-{$lang}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => "btact|cfgcol|{$lang}|users.usertest.selectUsernamePrompt"]];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('backup_settings_hub_payload')) {
    function backup_settings_hub_payload($textbotlang)
    {
        $t = $textbotlang['Admin']['BackupSettings'];
        $setting = select("setting", "*", null, null, "select");
        $dbSet = !empty($setting['backup_db_password']);
        $botSet = !empty($setting['backup_bot_password']);
        $caption = strtr($t['hubCaption'], [
            '{dbStatus}' => $dbSet ? $t['setStatus'] : $t['notSetStatus'],
            '{botStatus}' => $botSet ? $t['setStatus'] : $t['notSetStatus'],
        ]);
        $kb = ['inline_keyboard' => [
            [['text' => ($dbSet ? '🔐 ' : '🔓 ') . $t['dbPasswordBtn'], 'callback_data' => 'backupset_db', 'style' => ($dbSet ? 'success' : 'primary')]],
            [['text' => ($botSet ? '🔐 ' : '🔓 ') . $t['botPasswordBtn'], 'callback_data' => 'backupset_bot', 'style' => ($botSet ? 'success' : 'primary')]],
            [['text' => $t['closeBtn'], 'callback_data' => 'backupset_close', 'style' => 'danger']],
        ]];
        return [$caption, json_encode($kb)];
    }
}
if (!function_exists('help_resolve_lang')) {    // resolves a tutorial ("help" row) for a given viewer language: returns    // the translated name/description/media/media_type/entities if one was
    // set for that language, otherwise falls back to the Persian base row
    function help_resolve_lang($row, $lang)
    {
        $baseEntities = json_decode((string) ($row['entities_os'] ?? ''), true);
        $out = [
            'name' => (string) ($row['name_os'] ?? ''),
            'category' => (string) ($row['category'] ?? ''),
            'description' => (string) ($row['Description_os'] ?? ''),
            'media' => (string) ($row['Media_os'] ?? ''),
            'media_type' => (string) ($row['type_Media_os'] ?? ''),
            'entities' => is_array($baseEntities) ? $baseEntities : null,
        ];
        $tr = json_decode((string) ($row['translations'] ?? ''), true);
        $entry = (is_array($tr) && $lang !== 'fa' && isset($tr[$lang]) && is_array($tr[$lang])) ? $tr[$lang] : null;
        if ($entry === null) {
            return $out;
        }
        if (!empty($entry['name'])) {
            $out['name'] = (string) $entry['name'];
        }
        if (!empty($entry['category'])) {
            $out['category'] = (string) $entry['category'];
        }
        // content is all-or-nothing per language: swapping in a translated
        // caption while keeping the base entities would misalign every offset
        $hasOwnContent = (isset($entry['description']) && $entry['description'] !== '') || !empty($entry['media']);
        if ($hasOwnContent) {
            $out['description'] = (string) ($entry['description'] ?? '');
            $out['media'] = (string) ($entry['media'] ?? '');
            $out['media_type'] = (string) ($entry['media_type'] ?? '');
            $out['entities'] = is_array($entry['entities'] ?? null) ? $entry['entities'] : null;
        }
        return $out;
    }
}
if (!function_exists('help_send_content')) {
    // sends a help_resolve_lang() result as the real tutorial message: media
    // + caption when there's an attachment, plain text otherwise. entities
    // (premium emoji/formatting) win over parse_mode when present, since the
    // two are mutually exclusive on Telegram's side
    function help_send_content($chat_id, $resolved, $keyboard)
    {
        if ($resolved['media'] !== '') {
            $send = [
                'chat_id' => $chat_id,
                'caption' => $resolved['description'],
                'reply_markup' => $keyboard,
            ];
            if (!empty($resolved['entities'])) {
                $send['caption_entities'] = json_encode($resolved['entities']);
            } else {
                $send['parse_mode'] = 'HTML';
            }
            if ($resolved['media_type'] === 'video') {
                $send['video'] = $resolved['media'];
                return telegram('sendvideo', $send);
            }
            if ($resolved['media_type'] === 'document') {
                $send['document'] = $resolved['media'];
                return telegram('sendDocument', $send);
            }
            if ($resolved['media_type'] === 'photo') {
                $send['photo'] = $resolved['media'];
                return telegram('sendphoto', $send);
            }
        }
        return sendmessage($chat_id, $resolved['description'], $keyboard, 'HTML', null, $resolved['entities']);
    }
}
if (!function_exists('help_lang_has_content')) {
    function help_lang_has_content($lang)
    {
        $rows = select("help", "*", null, null, "fetchAll");
        if (!is_array($rows)) {
            return false;
        }
        if ($lang === 'fa') {
            foreach ($rows as $row) {
                if (trim((string) ($row['name_os'] ?? '')) !== '') {
                    return true;
                }
            }
            return false;
        }
        foreach ($rows as $row) {
            $tr = json_decode((string) ($row['translations'] ?? ''), true);
            if (!is_array($tr) || !isset($tr[$lang]) || !is_array($tr[$lang])) {
                continue;
            }
            $entry = $tr[$lang];
            if (trim((string) ($entry['name'] ?? '')) !== '' || trim((string) ($entry['description'] ?? '')) !== '' || trim((string) ($entry['media'] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }
}
if (!function_exists('help_layout_get')) {
    function help_layout_get()
    {
        $setting = select("setting", "*", null, null, "select");
        $data = json_decode((string) ($setting['help_layout'] ?? ''), true);
        return is_array($data) ? $data : [];
    }
}
if (!function_exists('help_layout_save')) {
    function help_layout_save($data)
    {
        update("setting", "help_layout", json_encode($data, JSON_UNESCAPED_UNICODE), null, null);
    }
}
if (!function_exists('help_layout_section')) {
    // returns the {order, width, emoji, emojiIcon, emojiSimple, color,
    // rename} block for one language + kind. emoji = plain-text emoji per
    // item; emojiIcon = premium/custom emoji id per item (mutually
    // exclusive with emoji, per item); emojiSimple = a single on/off flag
    // for the whole kind+lang that, when on, forces plain-text rendering
    // even for items that have a premium emoji set (mirrors the main-menu
    // emoji tool's "🔲 حالت ساده" toggle)
    function help_layout_section($lang, $kind)
    {
        $data = help_layout_get();
        $sec = $data[$lang][$kind] ?? [];
        return [
            'order' => is_array($sec['order'] ?? null) ? $sec['order'] : [],
            'width' => is_array($sec['width'] ?? null) ? $sec['width'] : [],
            'emoji' => is_array($sec['emoji'] ?? null) ? $sec['emoji'] : [],
            'emojiIcon' => is_array($sec['emojiIcon'] ?? null) ? $sec['emojiIcon'] : [],
            'emojiSimple' => !empty($sec['emojiSimple']),
            'color' => is_array($sec['color'] ?? null) ? $sec['color'] : [],
            'rename' => is_array($sec['rename'] ?? null) ? $sec['rename'] : [],
        ];
    }
}
if (!function_exists('help_layout_emoji_prefix')) {
    // resolves what to show for one item's emoji: a plain-text prefix to
    // prepend to its label, and/or a premium icon_custom_emoji_id to set on
    // the button - never both. "simple mode" forces the icon off (prefix
    // stays empty too, since a premium emoji has no plain-text fallback
    // glyph of its own).
    function help_layout_emoji_prefix($key, array $section)
    {
        $iconId = $section['emojiIcon'][$key] ?? '';
        if ($iconId !== '' && empty($section['emojiSimple'])) {
            return ['prefix' => '', 'icon' => $iconId];
        }
        $emojiText = $section['emoji'][$key] ?? '';
        if ($emojiText !== '') {
            return ['prefix' => $emojiText . ' ', 'icon' => ''];
        }
        return ['prefix' => '', 'icon' => ''];
    }
}
if (!function_exists('help_layout_set_section')) {
    function help_layout_set_section($lang, $kind, array $section)
    {
        $data = help_layout_get();
        if (!isset($data[$lang]) || !is_array($data[$lang])) {
            $data[$lang] = [];
        }
        $data[$lang][$kind] = $section;
        help_layout_save($data);
    }
}
if (!function_exists('help_layout_apply_order')) {
    // reorders $items (flat list of string keys) per a stored order list,
    // appending any items not yet in that order at the end (first-seen order)
    function help_layout_apply_order(array $items, array $order)
    {
        // compare by string value (PHP normalizes numeric-string array keys
        // to int, so tutorial-id items may arrive as int while the stored
        // order list, JSON-decoded, keeps its values as strings)
        $ordered = [];
        $usedIdx = [];
        foreach ($order as $key) {
            foreach ($items as $idx => $item) {
                if (isset($usedIdx[$idx])) {
                    continue;
                }
                if ((string) $item === (string) $key) {
                    $ordered[] = $item;
                    $usedIdx[$idx] = true;
                    break;
                }
            }
        }
        foreach ($items as $idx => $item) {
            if (!isset($usedIdx[$idx])) {
                $ordered[] = $item;
                $usedIdx[$idx] = true;
            }
        }
        return $ordered;
    }
}
if (!function_exists('help_layout_items')) {
    // returns [key => label]. 'tutorials': key == tutorial id (stable,
    // language-independent), label == resolved name for $lang.
    // 'categories': key == the base (fa) category string (stable identity —
    // this is also what routes end-user taps, see keyboard.php's
    // "helpctgoryـ{category}" callback_data), label == resolved per-language
    // category text. Keying categories by the base string (not the resolved
    // label) matters: if the label were the key, re-translating a category's
    // display name would silently orphan any saved order/width/emoji for it.
    function help_layout_items($lang, $kind)
    {
        if ($kind === 'panel' || $kind === 'product' || $kind === 'category') {
            return help_layout_items_commerce($lang, $kind);
        }
        if ($kind === 'gateway') {
            // only called from admin.php's btnstyle_* screens, so gateway_registry()
            // is guaranteed loaded; $lang may differ from the admin's own display
            // language, so its OWN translation file is loaded fresh rather than
            // assuming the caller's $textbotlang matches
            if (!function_exists('gateway_registry') || !function_exists('gateway_applicable_for_lang') || !function_exists('gateway_allowed_for_lang') || !function_exists('gateway_globally_on')) {
                return [];
            }
            $gwLangFile = __DIR__ . "/lang/$lang.php";
            $gwTextbotlang = is_file($gwLangFile) ? (require $gwLangFile) : [];
            $gwSection = help_layout_section($lang, 'gateway');
            $items = [];
            foreach (gateway_registry($gwTextbotlang) as $key => $label) {
                if (!gateway_applicable_for_lang($key, $lang)) {
                    continue;
                }
                if (!gateway_allowed_for_lang($key, $lang) || !gateway_globally_on($key)) {
                    continue;
                }
                $items[$key] = $gwSection['rename'][$key] ?? $label;
            }
            return $items;
        }
        $rows = select("help", "*", null, null, "fetchAll");
        if (!is_array($rows)) {
            return [];
        }
        $items = [];
        foreach ($rows as $row) {
            $view = help_resolve_lang($row, $lang);
            if ($kind === 'categories') {
                $faCat = trim((string) ($row['category'] ?? ''));
                if ($faCat === '' || $faCat === '0') {
                    continue;
                }
                if (!isset($items[$faCat])) {
                    $label = trim((string) ($view['category'] ?? ''));
                    $items[$faCat] = ($label !== '') ? $label : $faCat;
                }
            } else {
                $key = (string) $row['id'];
                $items[$key] = $view['name'];
            }
        }
        return $items;
    }
}
if (!function_exists('help_layout_items_commerce')) {
    // same [key => label] contract as help_layout_items(), for the
    // end-user-facing panel/product/category buttons (button styling: order,
    // width, emoji, color, custom rename - see the "🎨 ..." button-styling
    // hub in admin.php). key is always the item's stable, language- and
    // rename-independent identity: code_panel / product id / category id.
    // label is the real name unless a per-language rename override is set.
    function help_layout_items_commerce($lang, $kind)
    {
        $section = help_layout_section($lang, $kind);
        $items = [];
        if ($kind === 'panel') {
            $rows = select("marzban_panel", "*", null, null, "fetchAll");
            if (!is_array($rows)) {
                return [];
            }
            foreach ($rows as $row) {
                $key = (string) $row['code_panel'];
                $items[$key] = $section['rename'][$key] ?? $row['name_panel'];
            }
        } elseif ($kind === 'product') {
            $rows = select("product", "*", null, null, "fetchAll");
            if (!is_array($rows)) {
                return [];
            }
            foreach ($rows as $row) {
                $key = (string) $row['id'];
                $items[$key] = $section['rename'][$key] ?? $row['name_product'];
            }
        } else {
            $rows = select("category", "*", null, null, "fetchAll");
            if (!is_array($rows)) {
                return [];
            }
            foreach ($rows as $row) {
                $key = (string) $row['id'];
                $items[$key] = $section['rename'][$key] ?? $row['remark'];
            }
        }
        return $items;
    }
}
if (!function_exists('help_layout_chunk_rows')) {
    // groups an ordered list of prebuilt inline-keyboard button arrays into
    // rows, pairing adjacent "half"-width items two-per-row and keeping
    // "full"-width items (the default) on their own row
    function help_layout_chunk_rows(array $orderedKeys, array $buttonsByKey, array $widthMap)
    {
        $rows = [];
        $pending = null;
        foreach ($orderedKeys as $key) {
            if (!isset($buttonsByKey[$key])) {
                continue;
            }
            $width = $widthMap[$key] ?? 'full';
            if ($width === 'half') {
                if ($pending === null) {
                    $pending = $buttonsByKey[$key];
                } else {
                    $rows[] = [$pending, $buttonsByKey[$key]];
                    $pending = null;
                }
            } else {
                if ($pending !== null) {
                    $rows[] = [$pending];
                    $pending = null;
                }
                $rows[] = [$buttonsByKey[$key]];
            }
        }
        if ($pending !== null) {
            $rows[] = [$pending];
        }
        return $rows;
    }
}

if (!function_exists('bt_media_lookup')) {
    // reads a per-language sticker/reaction value, falling back to a shared
    // '_default' (or a legacy flat string, from before this became per-language)
    function bt_media_lookup($map, $key, $lang)
    {
        if (!is_array($map) || !isset($map[$key])) {
            return '';
        }
        $entry = $map[$key];
        if (is_array($entry)) {
            return (string) ($entry[$lang] ?? $entry['_default'] ?? '');
        }
        return (string) $entry;
    }
}
if (!function_exists('bt_media_set')) {
    function bt_media_set(&$map, $key, $lang, $value)
    {
        if (isset($map[$key]) && !is_array($map[$key])) {
            $map[$key] = ['_default' => $map[$key]];
        }
        if (!isset($map[$key]) || !is_array($map[$key])) {
            $map[$key] = [];
        }
        $map[$key][$lang] = $value;
    }
}
if (!function_exists('bt_media_unset')) {
    function bt_media_unset(&$map, $key, $lang)
    {
        if (isset($map[$key]) && is_array($map[$key])) {
            unset($map[$key][$lang]);
            if (empty($map[$key])) {
                unset($map[$key]);
            }
        } else {
            unset($map[$key]);
        }
    }
}
if (!function_exists('lang_timezone')) {
    // an approximate "local" timezone per bot language, used only for display
    function lang_timezone($lang)
    {
        $map = [
            'fa' => 'Asia/Tehran',
            'en' => 'UTC',
            'ru' => 'Europe/Moscow',
            'zh' => 'Asia/Shanghai',
            'tk' => 'Asia/Ashgabat',
        ];
        return $map[$lang] ?? 'UTC';
    }
}
if (!function_exists('format_datetime')) {
    // language-aware date/time: Persian users see the Jalali calendar on Tehran
    // time; every other language sees the Gregorian calendar on that language's
    // approximate local time. Format tokens (Y/m/d H:i:s ...) match jdate()'s.
    function format_datetime($format, $timestamp = null, $lang = 'fa')
    {
        if ($timestamp === null || $timestamp === '') {
            $ts = time();
        } elseif (is_numeric($timestamp)) {
            $ts = (int) $timestamp;
        } else {
            $ts = strtotime($timestamp);
        }
        $tz = lang_timezone($lang);
        if ($lang === 'fa') {
            return jdate($format, $ts, '', $tz);
        }
        $prevTz = date_default_timezone_get();
        date_default_timezone_set($tz);
        $out = date($format, $ts);
        date_default_timezone_set($prevTz);
        return $out;
    }
}
function generateAuthStr($length = 10)
{
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    return substr(str_shuffle(str_repeat($characters, ceil($length / strlen($characters)))), 0, $length);
}
function createqrcode($contents)
{
    $builder = new Builder(
        writer: new PngWriter(),
        writerOptions: [],
        data: $contents,
        encoding: new Encoding('UTF-8'),
        errorCorrectionLevel: ErrorCorrectionLevel::High,
        size: 500,
        margin: 10,
    );

    $result = $builder->build();
    return $result;
}
function sanitize_recursive(array $data): array
{
    $sanitized_data = [];
    foreach ($data as $key => $value) {
        $sanitized_key = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
        if (is_array($value)) {
            $sanitized_data[$sanitized_key] = sanitize_recursive($value);
        } elseif (is_string($value)) {
            $sanitized_data[$sanitized_key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        } elseif (is_int($value)) {
            $sanitized_data[$sanitized_key] = filter_var($value, FILTER_SANITIZE_NUMBER_INT);
        } elseif (is_float($value)) {
            $sanitized_data[$sanitized_key] = filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        } elseif (is_bool($value) || is_null($value)) {
            $sanitized_data[$sanitized_key] = $value;
        } else {
            $sanitized_data[$sanitized_key] = $value;
        }
    }
    return $sanitized_data;
}

function check_active_btn($keyboard, $text_var)
{
    $trace_keyboard = json_decode($keyboard, true)['keyboard'];
    $status = false;
    foreach ($trace_keyboard as $key => $callback_set) {
        foreach ($callback_set as $keyboard_key => $keyboard) {
            if ($keyboard['text'] == $text_var) {
                $status = true;
                break;
            }
        }
    }
    return $status;
}
function deleteFolder($folderPath)
{
    if (!is_dir($folderPath))
        return false;

    $files = array_diff(scandir($folderPath), ['.', '..']);

    foreach ($files as $file) {
        $filePath = $folderPath . DIRECTORY_SEPARATOR . $file;
        if (is_dir($filePath)) {
            deleteFolder($filePath);
        } else {
            unlink($filePath);
        }
    }

    return rmdir($folderPath);
}
function isBase64($string)
{
    if (base64_encode(base64_decode($string, true)) === $string) {
        return true;
    }
    return false;
}
function sendMessageService($panel_info, $config, $sub_link, $username_service, $reply_markup, $caption, $invoice_id, $user_id = null, $image = 'images.jpg')
{
    global $setting, $from_id, $textbotlang;
    if (!check_active_btn($setting['keyboardmain'], "text_help"))
        $reply_markup = null;
    $user_id = $user_id == null ? $from_id : $user_id;
    $STATUS_SEND_MESSAGE_PHOTO = $panel_info['config'] == "onconfig" && count($config) != 1 ? false : true;
    $out_put_qrcode = "";
    if ($panel_info['type'] == "Manualsale" || $panel_info['type'] == "ibsng" || $panel_info['type'] == "mikrotik") {
    }
    if ($panel_info['sublink'] == "onsublink" && $panel_info['config']) {
        $out_put_qrcode = $sub_link;
    } elseif ($panel_info['sublink'] == "onsublink") {
        $out_put_qrcode = $sub_link;
    } elseif ($panel_info['config'] == "onconfig") {
        $out_put_qrcode = $config[0];
    }
    if ($STATUS_SEND_MESSAGE_PHOTO) {
        if ($panel_info['type'] == "WGDashboard") {
            $urlimage = "{$panel_info['inboundid']}_{$invoice_id}.conf";
            file_put_contents($urlimage, $sub_link);
            telegram('senddocument', [
                'chat_id' => $user_id,
                'document' => new CURLFile($urlimage),
                'reply_markup' => $reply_markup,
                'caption' => $caption,
                'parse_mode' => "HTML",
            ]);
            unlink($urlimage);
        } else {
            $urlimage = "$user_id$invoice_id.png";
            $qrCode = createqrcode($out_put_qrcode);
            file_put_contents($urlimage, $qrCode->getString());
            addBackgroundImage($urlimage, $qrCode, $image);
            telegram('sendphoto', [
                'chat_id' => $user_id,
                'photo' => new CURLFile($urlimage),
                'reply_markup' => $reply_markup,
                'caption' => $caption,
                'parse_mode' => "HTML",
            ]);
            unlink($urlimage);
        }
    } else {
        sendmessage($user_id, $caption, $reply_markup, 'HTML');
    }
    if ($panel_info['config'] == "onconfig" && $setting['status_keyboard_config'] == "1") {
        if (is_array($config)) {
            sendmessage($user_id, $textbotlang['hardcoded']['getConfigHint'], keyboard_config($config, $invoice_id, false), 'HTML');
        }
    }
}
function isValidInvitationCode($setting, $fromId, $verfy_status)
{
    global $textbotlang;

    if ($setting['verifybucodeuser'] == "onverify" && $verfy_status != 1) {
        sendmessage($fromId, $textbotlang['hardcoded']['accountVerifiedSuccess'], null, 'html');
        update("user", "verify", "1", "id", $fromId);
        update("user", "cardpayment", "1", "id", $fromId);
    }
}
function createPayZarinpal($price, $order_id)
{
    global $domainhosts;
    $marchent_zarinpal = select("PaySetting", "ValuePay", "NamePay", "merchant_zarinpal", "select")['ValuePay'];
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://payment.zarinpal.com/pg/v4/payment/request.json',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Accept: application/json'
        ),
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
        "merchant_id" => $marchent_zarinpal,
        "currency" => "IRT",
        "amount" => $price,
        "callback_url" => "https://$domainhosts/payment/zarinpal.php",
        "description" => $order_id,
        "metadata" => array(
            "order_id" => $order_id
        )
    ]));
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
function createPayaqayepardakht($price, $order_id)
{
    global $domainhosts;
    $merchant_aqayepardakht = select("PaySetting", "ValuePay", "NamePay", "merchant_id_aqayepardakht", "select")['ValuePay'];
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://panel.aqayepardakht.ir/api/v2/create',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Accept: application/json'
        ),
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
        'pin' => $merchant_aqayepardakht,
        'amount' => $price,
        'callback' => $domainhosts . "/payment/aqayepardakht.php",
        'invoice_id' => $order_id,
    ]));
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
function parseConfigs($input)
{
    $lines = explode("\n", $input);
    $configs = [];

    $currentName = null;
    $currentData = [];

    foreach ($lines as $line) {
        $line = trim($line);

        if (strpos($line, '#') === 0) {
            if ($currentName && $currentData) {
                $configs[] = [
                    'name' => $currentName,
                    'config' => implode("\n", $currentData)
                ];
            }
            $currentName = trim(substr($line, 1));
            $currentData = [];
        } else {
            if ($line !== '') {
                $currentData[] = $line;
            }
        }
    }
    if ($currentName && $currentData) {
        $configs[] = [
            'name' => $currentName,
            'config' => implode("\n", $currentData)
        ];
    }

    return $configs;
}
