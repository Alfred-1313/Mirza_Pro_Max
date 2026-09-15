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
    // Two third-party pages, either of which can go down, get blocked, or
    // change shape. A zero coming out of that is not a rate, it is a failure -
    // and every caller divides by it, which on PHP 8 is a fatal rather than a
    // bad number. So a failure returns null, which every caller already reads
    // as "no rate available" and turns into a clean message for the customer.
    // ---- primary source: Nobitex ----
    // The same feed the TON and TRX gateways already price against, so every
    // crypto gateway now quotes from one place instead of three.
    //
    // It replaced bon-bast as the primary on 2026-09-07 because bon-bast began
    // answering this server with HTTP 403. That made this function return null,
    // and null took every dollar-priced gateway down with it: plisio,
    // nowpayment, Stars and پرداخت مستقیم با ترون all stopped issuing invoices.
    //
    // Tether rather than the banknote rate is also the more honest number here:
    // a customer paying in crypto converts at Tether, and the TRX price comes
    // straight back in toman instead of being multiplied through a dollar rate,
    // so there is one source of error instead of two.
    $arze_rate = [];
    $usdt = nobitex_rate_toman('usdt');
    if ($usdt > 0) {
        $arze_rate['USD'] = (int) $usdt;
        $trx = nobitex_rate_toman('trx');
        if ($trx > 0) {
            $arze_rate['TRX'] = (int) $trx;
            return $arze_rate;
        }
        // Nobitex answered for Tether but not for TRX: fall back to the old
        // two-hop calculation rather than losing the dollar rate as well
        $tron_raw = @file_get_contents('https://api.diadata.org/v1/assetQuotation/Tron/0x0000000000000000000000000000000000000000');
        $requests_tron = $tron_raw === false ? null : json_decode($tron_raw, true);
        $arze_rate['TRX'] = intval(((float) ($requests_tron['Price'] ?? 0)) * $arze_rate['USD']);
        return $arze_rate;
    }

    // ---- fallback: the original scrapers ----
    // Kept so a Nobitex outage is survivable, not because it is preferred.
    $requestsusd = null;
    $tron_raw = @file_get_contents('https://api.diadata.org/v1/assetQuotation/Tron/0x0000000000000000000000000000000000000000');
    $requests_tron = $tron_raw === false ? null : json_decode($tron_raw, true);
    $html_read = @file_get_contents("https://www.bon-bast.com/");
    if ($html_read !== false) {
        preg_match('/<span>\s*([\d,]+)\s*<\/span>/', $html_read, $matches);
        if (!empty($matches[1])) {
            $requestsusd = str_replace(',', '', $matches[1]);
        }
    }
    $arze_rate['USD'] = intval($requestsusd);
    // A zero is not a rate, it is a failure - and every caller divides by it,
    // which on PHP 8 is a fatal rather than a bad number. null is what every
    // caller already reads as "no rate available".
    if ($arze_rate['USD'] <= 0) {
        return null;
    }
    $arze_rate['TRX'] = intval(((float) ($requests_tron['Price'] ?? 0)) * $arze_rate['USD']);

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
/**
 * Same field (menu_tap_id) as menu_tap_cleanup() below, but returns the id
 * instead of deleting the message - for a ❌ بستن handler that needs to hand
 * it to close_sticker_play() so it disappears TOGETHER with the caption and
 * 🖼 استیکر دکمه بستن's own sticker after the timer, rather than the instant
 * the button is tapped (which used to make the tap message vanish first and
 * everything else catch up later).
 */
function menu_tap_capture($from_id, $user)
{
    // stored as "id" (older rows) or "id:section" (keyboard.php writes the
    // section too, so a re-opening glass tap can keep the id alive)
    $mt = explode(':', (string) ($user['menu_tap_id'] ?? ''), 2)[0];
    if (ctype_digit($mt) && intval($mt) > 0) {
        update("user", "menu_tap_id", "0", "id", $from_id);
        return intval($mt);
    }
    return 0;
}
/**
 * Removes the user's own main-menu tap message ("🔐 خرید اشتراک" and friends),
 * which the reply keyboard leaves behind in the chat. gwinvclose (the invoice
 * "give up" back button) is the only caller left that wants this deleted on
 * the spot; every ❌ بستن handler now calls menu_tap_capture() instead so the
 * tap message joins the deferred removal.
 */
function menu_tap_cleanup($from_id, $user)
{
    $mt = menu_tap_capture($from_id, $user);
    if ($mt > 0) {
        deletemessage($from_id, $mt);
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
        $Shoppinginfo = afterpay_help_kb($Balance_id['lang'] ?? 'fa', $textbotlang);
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
        // the "🚀 receipt submitted, pending review" message has done its job
        // once we get here - clean it up. A flow that never sent/tracked one
        // (receipt_msg_id stays 0) is simply a no-op, not an error.
        $rcptMsgId = intval($Payment_report['receipt_msg_id'] ?? 0);
        if ($rcptMsgId > 0) {
            deletemessage($Payment_report['id_user'], $rcptMsgId);
        }
        // top-up discount: the user paid the full amount through the gateway,
        // the bonus is added on top here - the one shared place every gateway's
        // wallet credit passes through, so no gateway integration changes.
        $topup_bonus = function_exists('topup_disc_award') ? intval(topup_disc_award($Payment_report, $Balance_id)) : 0;
        $Balance_confrim = intval($Balance_id['Balance']) + intval($Payment_report['price']) + $topup_bonus;
        update("user", "Balance", $Balance_confrim, "id", $Payment_report['id_user']);
        update("Payment_report", "payment_Status", "paid", "id_order", $Payment_report['id_order']);
        $Payment_report['price'] = number_format($Payment_report['price'], 0);
        $format_price_cart = $Payment_report['price'];
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {
            $textconfrom = sprintf($textbotlang['hardcoded']['newPaymentBalanceChargeFn'], $Balance_id['id'], $Payment_report['id_order'], $Balance_id['username'], $format_price_cart, $Balance_id['Balance'], $Payment_report['dec_not_confirmed']);
            Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
        }
        // the old "🎁 تخفیف اعمال شد!" message and the "💲 کاربر گرامی..."
        // thank-you message used to be two separate sendmessage() calls -
        // merged into one: the discount portion (when there is one) becomes a
        // customizable <blockquote> appended to the customizable base caption,
        // showing the user's up-to-date balance; a customizable تهیه اشتراک
        // button (genbtn alias 'bc') always rides along underneath.
        $bc_discountBlock = '';
        if ($topup_bonus > 0) {
            $bc_discountText = strtr(bottext_resolve_key('users.Balance.chargeSuccessDiscount'), [
                '{bonus}' => money($topup_bonus),
                '{balance}' => money($Balance_confrim),
            ]);
            $bc_discountBlock = "\n<blockquote>" . $bc_discountText . '</blockquote>';
        }
        $bc_caption = strtr(bottext_resolve_key('users.Balance.chargeSuccess'), [
            '{amount}' => $Payment_report['price'],
            '{balance}' => money($Balance_confrim),
            '{discount_block}' => $bc_discountBlock,
        ]);
        $bc_lang = (is_array($Balance_id) && !empty($Balance_id['lang'])) ? $Balance_id['lang'] : 'fa';
        $bc_defs = genbtn_defs('bc', $textbotlang);
        $bc_ov = genbtn_override($bc_lang, 'users.Balance.chargeSuccess', 0);
        $bc_kb = !empty($bc_ov['hidden']) ? null : json_encode(['inline_keyboard' => [[genbtn_render($bc_defs[0], $bc_ov, $bc_defs[0]['callback_data'])]]]);
        sendmessage($Payment_report['id_user'], $bc_caption, $bc_kb, 'HTML');
    }
    // every gateway reaches here once its payment is confirmed, so this is
    // the one place the settled invoice needs updating
    topup_paid_notify($order_id);
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
if (!function_exists('shop_feature_lang_map')) {
    // 🛒 وضعیت قابلیت‌های فروشگاه, per language. Each of its 11 toggles used to
    // be one shop-wide flag (a `setting` column or a `shopSetting` row); this is
    // the per-language override sitting on top of that, so a shop can show
    // English customers a different set of buy-flow features than Persian ones.
    //
    // Shape: {featureKey: {lang: "onX"/"offX" - the SAME literal the feature has
    // always stored}}. A language with no entry here still reads whatever the
    // old global column/row says - the whole point being that nothing changes
    // for any language until an admin explicitly overrides one, so this needed
    // no migration script and no new default to invent per feature.
    function shop_feature_lang_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['shop_feature_lang'] ?? ''), true);
        return $cache = is_array($m) ? $m : [];
    }
    // $globalValue is whatever the caller already read from the old column/row
    // for this feature - the exact fallback for every language without an
    // override, so a shop that never touches this screen keeps behaving exactly
    // as it did before this existed.
    function shop_feature_value($featureKey, $lang, $globalValue)
    {
        $m = shop_feature_lang_map();
        return $m[$featureKey][$lang] ?? $globalValue;
    }
    function shop_feature_set($featureKey, $lang, $value)
    {
        $m = shop_feature_lang_map(true);
        if (!isset($m[$featureKey]) || !is_array($m[$featureKey])) {
            $m[$featureKey] = [];
        }
        $m[$featureKey][$lang] = $value;
        update("setting", "shop_feature_lang", json_encode($m, JSON_UNESCAPED_UNICODE), null, null);
        shop_feature_lang_map(true);
    }
}
if (!function_exists('feature_setting_map')) {
    // Per-language overrides for the VALUES behind 🌐 وضعیت قابلیت‌ها (هر زبان)'s
    // ⚙️ تنظیمات sub-screens (affiliate percent, wheel prize, location limits,
    // ...) - same fallback contract as feature_value(), separate column from
    // feature_lang because that one holds the on/off flags themselves.
    function feature_setting_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['feature_lang_settings'] ?? ''), true);
        return $cache = is_array($m) ? $m : [];
    }
    // $globalValue is whatever the caller already read from the old column/row,
    // so a language nobody has overridden keeps behaving exactly as before.
    function feature_setting_value($key, $lang, $globalValue)
    {
        $m = feature_setting_map();
        $v = $m[$key][$lang] ?? null;
        return ($v === null || $v === '') ? $globalValue : $v;
    }
    function feature_setting_set($key, $lang, $value)
    {
        $m = feature_setting_map(true);
        if (!isset($m[$key]) || !is_array($m[$key])) {
            $m[$key] = [];
        }
        $m[$key][$lang] = $value;
        update("setting", "feature_lang_settings", json_encode($m, JSON_UNESCAPED_UNICODE), null, null);
        feature_setting_map(true);
    }
}
if (!function_exists('phone_prefixes_for_lang')) {
    // Each language's OWN country dial code - never Iran's for everyone, which
    // is what the fixed 989xxxxxxxxx check used to enforce on every market.
    // English is deliberately unrestricted: it is a language, not a country.
    function phone_prefix_defaults()
    {
        return ['fa' => '98', 'ru' => '7', 'zh' => '86', 'tk' => '993', 'en' => ''];
    }
    // Which dial codes a number may start with, for ONE language. Stored per
    // language (feature_lang_settings key "phone_prefix") as a comma list of
    // digits. An empty list means "any country" - that is the honest default
    // for a language with no single country behind it.
    function phone_prefixes_for_lang($lang)
    {
        $defaults = phone_prefix_defaults();
        $raw = (string) feature_setting_value('phone_prefix', $lang, $defaults[$lang] ?? '');
        $out = [];
        foreach (explode(',', $raw) as $p) {
            $p = preg_replace('/\D+/', '', (string) $p);
            // "0" is the stored sentinel for "no country restriction" - an
            // empty string cannot be used, feature_setting_value() reads that
            // as "never set" and falls back to the default
            if ($p !== '' && $p !== '0') {
                $out[] = $p;
            }
        }
        return $out;
    }
    // Telegram hands contact numbers over without a "+", so a prefix test is
    // enough - and it is the only shape that generalises past one fixed country.
    function phone_matches_lang($phone, $lang)
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return false;
        }
        $prefixes = phone_prefixes_for_lang($lang);
        if (!$prefixes) {
            // no country configured for this language: any real number passes
            return strlen($digits) >= 7;
        }
        foreach ($prefixes as $prefix) {
            if (strpos($digits, $prefix) === 0 && strlen($digits) >= strlen($prefix) + 6) {
                return true;
            }
        }
        return false;
    }
}
if (!function_exists('app_rows_for_lang')) {
    // app-download rows visible to one language, using the same lang-column
    // convention as the marzban_panel query in keyboard.php: a row with no
    // language (or 'all') belongs to every language, so every row that existed
    // before app.lang was added stays visible exactly as before.
    function app_rows_for_lang($lang)
    {
        global $pdo;
        $stmt = $pdo->prepare("SELECT * FROM app WHERE FIND_IN_SET(:userlang, lang) OR lang = 'all' OR lang IS NULL OR lang = ''");
        $stmt->bindValue(':userlang', (string) $lang);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
if (!function_exists('lang_tab_texts')) {
    // The text array for ONE specific language, regardless of whose request
    // this is - languagechange() can't do this because it overrides its own
    // $lang argument with the current user's account language. Same three
    // steps keyboard_list_text() (keyboard.php) already does for its language
    // tabs: load the file, fill anything missing from fa, apply the admin's
    // saved text overrides for that language. Cached per language because a
    // screen usually needs it more than once per render.
    function lang_tab_texts($lang)
    {
        static $cache = [];
        if (isset($cache[$lang])) {
            return $cache[$lang];
        }
        $file = __DIR__ . '/lang/' . $lang . '.php';
        if (!preg_match('/^[a-z]{2}$/', (string) $lang) || !file_exists($file)) {
            $lang = 'fa';
            $file = __DIR__ . '/lang/fa.php';
        }
        $texts = require $file;
        if (!is_array($texts)) {
            return $cache[$lang] = [];
        }
        if ($lang !== 'fa') {
            $fa = require __DIR__ . '/lang/fa.php';
            if (is_array($fa)) {
                $texts = bt_lang_fill_defaults($texts, $fa);
            }
        }
        bottext_apply_overrides($texts, $lang);
        return $cache[$lang] = $texts;
    }
}
if (!function_exists('feature_lang_map')) {
    // same per-language-override contract as shop_feature_lang_map(), separate
    // column so general bot-feature keys (get_number, wheelagent, ...) never
    // collide with the shop-feature-status screen's own key namespace
    function feature_lang_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['feature_lang'] ?? ''), true);
        return $cache = is_array($m) ? $m : [];
    }
    // $globalValue is whatever the caller already read from the old setting
    // column for this feature - the fallback for every language without an
    // override, so nothing changes for any language until an admin explicitly
    // overrides one.
    function feature_value($featureKey, $lang, $globalValue)
    {
        $m = feature_lang_map();
        return $m[$featureKey][$lang] ?? $globalValue;
    }
    function feature_set($featureKey, $lang, $value)
    {
        $m = feature_lang_map(true);
        if (!isset($m[$featureKey]) || !is_array($m[$featureKey])) {
            $m[$featureKey] = [];
        }
        $m[$featureKey][$lang] = $value;
        update("setting", "feature_lang", json_encode($m, JSON_UNESCAPED_UNICODE), null, null);
        feature_lang_map(true);
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
if (!function_exists('card_invoice_btnstyle_default_color')) {
    // Out-of-the-box colours for the invoice buttons, used whenever the admin
    // hasn't picked one. They used to fall back to no style at all, which
    // Telegram renders as a plain white button - the copy-card and
    // send-receipt buttons are the two the customer is meant to act on, so
    // they read as blue/green instead. Shared by the invoice renderer AND the
    // colour picker, so what the admin previews is what the customer sees.
    function card_invoice_btnstyle_default_color($which)
    {
        $defaults = [
            'copyCard' => 'primary',
            'paidReceipt' => 'success',
            'reissue' => 'danger',
        ];
        // copyCard2, copyCard3, ... are the second and later cards; they share
        // the first card's blue rather than falling through to a white button
        if (card_invoice_copy_index($which) !== null) {
            return 'primary';
        }
        return $defaults[$which] ?? '';
    }
}
if (!function_exists('card_invoice_copy_key')) {
    // The style key for the copy button of card #$i (1-based). Card 1 keeps the
    // original 'copyCard' key so an admin's existing colour/name survives the
    // move to per-card styling untouched.
    function card_invoice_copy_key($i)
    {
        return $i <= 1 ? 'copyCard' : ('copyCard' . intval($i));
    }
}
if (!function_exists('card_invoice_copy_index')) {
    // 1-based card number for a copy-button key, or null if it is not one
    function card_invoice_copy_index($which)
    {
        if ($which === 'copyCard') {
            return 1;
        }
        return preg_match('/^copyCard([2-9]\d*)$/', (string) $which, $m) ? intval($m[1]) : null;
    }
}
if (!function_exists('card_invoice_copy_perrow')) {
    // How many copy buttons share a row. Stored under the reserved '_layout'
    // slot of the same per-language style map, so it needs no new setting.
    function card_invoice_copy_perrow($lang)
    {
        $n = intval(card_invoice_btnstyle_for($lang, '_layout')['perRow'] ?? 0);
        return ($n >= 1 && $n <= 3) ? $n : 1;
    }
}
if (!function_exists('card_invoice_copy_rows')) {
    // Chunks the copy buttons into keyboard rows per the admin's 📐 چیدمان
    function card_invoice_copy_rows($lang, array $btns)
    {
        if (empty($btns)) {
            return [];
        }
        return array_chunk($btns, max(1, card_invoice_copy_perrow($lang)));
    }
}
if (!function_exists('card_invoice_card_order')) {
    // Display order of the cards, as a list of positions into the stored card
    // list. Kept in the reserved '_layout' slot rather than by reordering the
    // cards themselves, so 📐 چیدمان never rewrites the admin's card data - and
    // a card added or removed later just falls back to natural order.
    function card_invoice_card_order($lang, $total)
    {
        $raw = card_invoice_btnstyle_for($lang, '_layout')['order'] ?? null;
        $order = [];
        if (is_array($raw)) {
            foreach ($raw as $i) {
                $i = intval($i);
                if ($i >= 0 && $i < $total && !in_array($i, $order, true)) {
                    $order[] = $i;
                }
            }
        }
        for ($i = 0; $i < $total; $i++) {
            if (!in_array($i, $order, true)) {
                $order[] = $i;
            }
        }
        return $order;
    }
}
if (!function_exists('card_invoice_ordered_cards')) {
    // The cards in the order the invoice should show them. Used for BOTH the
    // caption and the buttons, so the "name | number" lines and the copy rows
    // can never disagree about which card is first.
    function card_invoice_ordered_cards($lang)
    {
        $cards = array_values(gw_cards_for_lang($lang));
        $out = [];
        foreach (card_invoice_card_order($lang, count($cards)) as $i) {
            $out[] = $cards[$i];
        }
        return $out;
    }
}
if (!function_exists('card_invoice_layout_swap')) {
    // Swaps two display slots. Both the card order AND the two slots' styles
    // move, so the whole button the admin sees - card, colour and custom name -
    // travels together, which is what the 📐 screen shows happening.
    function card_invoice_layout_swap($lang, $a, $b)
    {
        $total = count(gw_cards_for_lang($lang));
        $a = intval($a);
        $b = intval($b);
        if ($a === $b || $a < 0 || $b < 0 || $a >= $total || $b >= $total) {
            return;
        }
        $order = card_invoice_card_order($lang, $total);
        $tmp = $order[$a];
        $order[$a] = $order[$b];
        $order[$b] = $tmp;
        $lay = card_invoice_btnstyle_for($lang, '_layout');
        $lay['order'] = $order;
        card_invoice_btnstyle_set($lang, '_layout', $lay);

        $keyA = card_invoice_copy_key($a + 1);
        $keyB = card_invoice_copy_key($b + 1);
        $styleA = card_invoice_btnstyle_for($lang, $keyA);
        $styleB = card_invoice_btnstyle_for($lang, $keyB);
        card_invoice_btnstyle_set($lang, $keyA, $styleB);
        card_invoice_btnstyle_set($lang, $keyB, $styleA);
    }
}
if (!function_exists('card_invoice_copy_label')) {
    // Default label for card #$i's copy button. With a single card it stays
    // exactly what it always was; from two cards on it gains an ordinal, since
    // otherwise every card renders the same button text.
    function card_invoice_copy_label($i, $total, $textbotlang)
    {
        $base = $textbotlang['keyboard']['copyCardNumber'];
        if ($total < 2) {
            return $base;
        }
        $ords = $textbotlang['keyboard']['cardOrdinals'] ?? [];
        $ord = $ords[$i - 1] ?? (string) $i;
        return $base . ' ' . $ord;
    }
}
if (!function_exists('card_invoice_btnstyle_color')) {
    // The colour a given invoice button actually renders with: the admin's
    // pick if they made one, otherwise this button's built-in default.
    function card_invoice_btnstyle_color($lang, $which)
    {
        $color = (string) (card_invoice_btnstyle_for($lang, $which)['color'] ?? '');
        return in_array($color, ['success', 'danger', 'primary'], true)
            ? $color
            : card_invoice_btnstyle_default_color($which);
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
        // the reserved '_layout' slot carries no label/colour, just how many
        // copy buttons share a row - without this it would be sanitised away
        $perRow = intval($style['perRow'] ?? 0);
        if ($perRow >= 1 && $perRow <= 3) {
            $clean['perRow'] = $perRow;
        }
        if (isset($style['order']) && is_array($style['order'])) {
            $clean['order'] = array_values(array_map('intval', $style['order']));
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
    // {name_card2} / {card_number2} - the admin addresses one specific card, so
    // they can lay the cards out by hand (one per line, blank line between,
    // whatever they want) instead of the template repeating a block for them.
    // A line naming a card the shop does not have is removed whole: a shop that
    // drops from two cards to one must not leave a stray " | " behind.
    function card_invoice_indexed_render($template, array $cards)
    {
        if (!preg_match('/\{(?:name_card|card_number)[1-9][0-9]*\}/', $template)) {
            return $template;
        }
        $out = [];
        foreach (explode("\n", $template) as $line) {
            if (!preg_match_all('/\{(name_card|card_number)([1-9][0-9]*)\}/', $line, $mm, PREG_SET_ORDER)) {
                $out[] = $line;
                continue;
            }
            $repl = [];
            $missing = false;
            foreach ($mm as $m) {
                $c = $cards[(int) $m[2] - 1] ?? null;
                if ($c === null) {
                    $missing = true;
                    break;
                }
                $name = trim((string) ($c['name'] ?? ''));
                $repl[$m[0]] = $m[1] === 'name_card'
                    ? ($name !== '' ? $name : '—')
                    : (string) ($c['number'] ?? '');
            }
            if ($missing) {
                continue;
            }
            $out[] = strtr($line, $repl);
        }
        return implode("\n", $out);
    }
    function card_invoice_render($template, array $cards, array $otherReplacements)
    {
        $cards = array_values($cards);
        // the numbered form is resolved first; whatever is left can still use
        // the older repeating {name_card}/{card_number} block below
        $template = card_invoice_indexed_render($template, $cards);
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
                ['field' => 'cardInvoiceExpireMinutes', 'type' => 'number', 'label' => 'invoiceExpireLabel'],
            ],
            'plisio' => [
                ['field' => 'apinowpayment', 'type' => 'text', 'label' => 'apiKeyLabel', 'scope' => 'global'],
                ['field' => 'plisioInvoiceExpireMinutes', 'type' => 'number', 'label' => 'invoiceExpireLabel'],
            ],
            'nowpayment' => [
                ['field' => 'marchent_tronseller', 'type' => 'text', 'label' => 'apiKeyLabel', 'scope' => 'global'],
                ['field' => 'nowpaymentInvoiceExpireMinutes', 'type' => 'number', 'label' => 'invoiceExpireLabel'],
            ],
            'digitaltron' => [
                ['field' => 'walletaddress', 'type' => 'text', 'label' => 'walletLabel'],
                ['field' => 'digitaltronInvoiceExpireMinutes', 'type' => 'number', 'label' => 'invoiceExpireLabel'],
            ],
            'ton' => [
                ['field' => 'walletaddresston', 'type' => 'text', 'label' => 'tonWalletLabel'],
                ['field' => 'tonInvoiceExpireMinutes', 'type' => 'number', 'label' => 'invoiceExpireLabel'],
            ],
            'trx' => [
                ['field' => 'walletaddresstrx', 'type' => 'text', 'label' => 'trxWalletLabel'],
                ['field' => 'trxInvoiceExpireMinutes', 'type' => 'number', 'label' => 'invoiceExpireLabel'],
            ],
            'usdtbep' => [
                ['field' => 'walletaddressusdtbep', 'type' => 'text', 'label' => 'usdtbepWalletLabel'],
                ['field' => 'usdtbepInvoiceExpireMinutes', 'type' => 'number', 'label' => 'invoiceExpireLabel'],
            ],
            'iranpay1' => [
                ['field' => 'marchent_floypay', 'type' => 'text', 'label' => 'merchantLabel', 'scope' => 'global'],
            ],
            'iranpay2' => [
                ['field' => 'apiternado', 'type' => 'text', 'label' => 'apiKeyLabel', 'scope' => 'global'],
                ['field' => 'walletaddress', 'type' => 'text', 'label' => 'walletLabel'],
                ['field' => 'urlpaymenttron', 'type' => 'text', 'label' => 'payUrlLabel', 'scope' => 'global'],
            ],
            'iranpay3' => [
                ['field' => 'apiiranpay', 'type' => 'text', 'label' => 'apiKeyLabel', 'scope' => 'global'],
            ],
            'aqayepardakht' => [
                ['field' => 'merchant_id_aqayepardakht', 'type' => 'text', 'label' => 'merchantLabel', 'scope' => 'global'],
            ],
            'zarinpal' => [
                ['field' => 'merchant_zarinpal', 'type' => 'text', 'label' => 'merchantLabel', 'scope' => 'global'],
            ],
            'paymentnotverify' => [],
            'startelegrams' => [
                ['field' => 'starInvoiceExpireMinutes', 'type' => 'number', 'label' => 'invoiceExpireLabel'],
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
if (!function_exists('card_legacy_toggle_fields')) {
    // The 3 legacy card-to-card ON/OFF switches that became per-language
    // overrides (see card_legacy_settings_payload() in admin.php). Each still
    // stores its historical on/off literal string, unchanged, so every
    // existing read site (keyboard.php, croncard.php) keeps working exactly
    // as before once routed through pay_value() instead of a flat global read.
    function card_legacy_toggle_fields()
    {
        return [
            'Cartstatuspv' => ['on' => 'oncardpv', 'off' => 'offcardpv', 'label' => '💳 پرداخت مستقیم در پیوی'],
            'checkpaycartfirst' => ['on' => 'onpayverify', 'off' => 'offpayverify', 'label' => '🔒 نمایش کارت پس از اولین پرداخت'],
            'autoconfirmcart' => ['on' => 'onauto', 'off' => 'offauto', 'label' => '🤖 تایید رسید بدون بررسی'],
        ];
    }
}
if (!function_exists('card_legacy_has_any_override')) {
    // Drives the green "this language is customised" state on the main
    // screen's entry button - deliberately scoped to only the 5 per-language
    // fields (not the 4 global ones below them), since a global change isn't
    // "this language's" customisation and would wrongly turn every language's
    // entry button green at once.
    function card_legacy_has_any_override($lang)
    {
        foreach (array_keys(card_legacy_toggle_fields()) as $f) {
            if (gw_pay_override_has($f, $lang)) {
                return true;
            }
        }
        return gw_pay_override_has('timeauto_not_verify', $lang) || gw_pay_override_has('helpcart', $lang);
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
            'ton' => $textbotlang['textbot']['tonPayment'],
            'trx' => $textbotlang['textbot']['trxPayment'],
            'usdtbep' => $textbotlang['textbot']['usdtbepPayment'],
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
if (!function_exists('gateway_groups')) {
    // Gateways that belong together get one collapsible row on the 💳 hub
    // instead of a row each. Only families with more than one member are worth
    // grouping - a "group" holding a single gateway would just add a tap.
    // Anything not listed here keeps its own direct row, exactly as before.
    function gateway_groups()
    {
        return [
            // processor-settled: the gateway confirms the payment itself, so
            // nothing here ever waits on an admin
            // TON is paid into the shop's own wallet, but cronbot/ton.php
            // settles it off the public chain with no admin in the loop -
            // which is what this family means
            'online' => ['plisio', 'nowpayment', 'startelegrams', 'ton', 'trx', 'usdtbep'],
            // paid straight to the bot's own wallet, so an admin has to confirm
            // each one by hand - a different workflow, hence its own group
            'offline' => ['digitaltron'],
            // rial processors - every one of these is fa-only (see
            // gateway_fa_only_keys), so the group simply never appears on the
            // other language tabs
            'rial' => ['iranpay1', 'iranpay2', 'iranpay3', 'aqayepardakht', 'zarinpal', 'paymentnotverify'],
        ];
    }
}
if (!function_exists('gateway_disc_groups')) {
    // The same families, but as CATEGORIES rather than as a layout rule.
    //
    // gateway_groups() decides what collapses behind a submenu, and card-to-card
    // is deliberately absent from it: two of its readers (💎 مالی → 💳 درگاه‌های
    // پرداخت and the top-up gateway list) collapse a family with no "more than
    // one member" guard, so giving card a family there would hide the shop's
    // single most-used gateway behind an extra tap.
    //
    // For 🏦 بسته‌های شارژ and 🎁 تخفیف شارژ, though, card IS a category like any
    // other - so it gets one here, and only here. Order is display order.
    function gateway_disc_groups()
    {
        $g = gateway_groups();
        return [
            'card' => ['card'],
            'rial' => $g['rial'] ?? [],
            'online' => $g['online'] ?? [],
            'offline' => $g['offline'] ?? [],
        ];
    }
}
if (!function_exists('gateway_disc_group_of')) {
    function gateway_disc_group_of($key)
    {
        foreach (gateway_disc_groups() as $group => $members) {
            if (in_array($key, $members, true)) {
                return $group;
            }
        }
        return null;
    }
}
if (!function_exists('gateway_disc_group_members')) {
    // only the gateways of this category a real customer of this language would
    // see at checkout right now - the same effective-status rule the rest of
    // this screen family uses
    function gateway_disc_group_members($group, $lang, $textbotlang)
    {
        $live = topup_disc_enabled_gateways($lang, $textbotlang);
        $out = [];
        foreach (gateway_disc_groups()[$group] ?? [] as $key) {
            if (isset($live[$key])) {
                $out[$key] = $live[$key];
            }
        }
        return $out;
    }
}
if (!function_exists('gateway_group_label')) {
    function gateway_group_label($group, $textbotlang)
    {
        return $textbotlang['Admin']['GatewayLang']['groups'][$group]
            ?? ($textbotlang['Admin']['GatewayLang']['groups']['online'] ?? $group);
    }
}
if (!function_exists('gateway_group_of')) {
    // the group a gateway belongs to, or null when it stands on its own
    function gateway_group_of($key)
    {
        foreach (gateway_groups() as $group => $members) {
            if (in_array($key, $members, true)) {
                return $group;
            }
        }
        return null;
    }
}
if (!function_exists('gateway_group_members')) {
    // members of a group that make sense for this language at all
    function gateway_group_members($group, $lang)
    {
        $out = [];
        foreach (gateway_groups()[$group] ?? [] as $key) {
            if (gateway_applicable_for_lang($key, $lang)) {
                $out[] = $key;
            }
        }
        return $out;
    }
}
if (!function_exists('gateway_all_keys')) {
    // Stable identifiers for the payment buttons, taken from their callback_data
    // (card-to-card is keyed by name because it can render as a url button).
    function gateway_all_keys()
    {
        return ['card', 'plisio', 'nowpayment', 'ton', 'trx', 'usdtbep', 'digitaltron', 'iranpay1', 'iranpay2',
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

if (!function_exists('sms_forward_toggle')) {
    // Flips setting.smsForwardEnabled. Turning it ON also force-enables
    // cardRandomAmount (every invoice needs a unique amount for a future SMS
    // match to be unambiguous) and makes sure a webhook secret exists (the
    // URL built from it is what the admin pastes into their phone's SMS
    // forwarder app). Turning it back OFF deliberately leaves cardRandomAmount
    // and the secret as-is, since the admin may still want unique amounts on
    // their own merit and re-enabling later shouldn't invalidate an already-
    // configured phone app. Returns the PREVIOUS (pre-toggle) state so the
    // caller knows which way it just flipped without a second query.
    function sms_forward_toggle()
    {
        $wasOn = ((select("setting", "smsForwardEnabled", null, null, "select")['smsForwardEnabled'] ?? '0') === '1');
        if ($wasOn) {
            update("setting", "smsForwardEnabled", '0', null, null);
        } else {
            update("setting", "smsForwardEnabled", '1', null, null);
            update("setting", "cardRandomAmount", '1', null, null);
            // The blind auto-confirms are mutually exclusive with SMS Forward:
            // this feature confirms a payment because the bank actually said so,
            // while those two confirm on a timer whether or not any money
            // arrived. The admin screen refuses to switch them on while SMS
            // Forward is active, so also clear anything already on (global AND
            // every per-language override) - otherwise enabling SMS Forward on
            // top of an already-running timer would leave both live.
            pay_global_set('autoconfirmcart', 'offauto');
            pay_global_set('timeauto_not_verify', '0');
            $m = gw_lang_settings_map();
            foreach (array_keys($m) as $l) {
                unset($m[$l]['pay']['autoconfirmcart'], $m[$l]['pay']['timeauto_not_verify']);
                if (isset($m[$l]['pay']) && empty($m[$l]['pay'])) {
                    unset($m[$l]['pay']);
                }
            }
            gw_lang_settings_save($m);
            sms_forward_ensure_secret();
        }
        return $wasOn;
    }
}
if (!function_exists('sms_forward_ensure_secret')) {
    // Generates setting.smsForwardSecret once, if it doesn't already exist.
    // This secret is the ENTIRE trust boundary for the webhook endpoint (see
    // cronbot/sms_webhook.php) - anyone who knows it can trigger a payment
    // match, so it's long and random, never derived from anything guessable.
    function sms_forward_ensure_secret()
    {
        $current = (string) (select("setting", "smsForwardSecret", null, null, "select")['smsForwardSecret'] ?? '');
        if ($current !== '') {
            return $current;
        }
        $fresh = bin2hex(random_bytes(24));
        update("setting", "smsForwardSecret", $fresh, null, null);
        return $fresh;
    }
}
if (!function_exists('sms_forward_regenerate_secret')) {
    // Invalidates the old webhook URL (whatever's already pasted into the
    // phone app stops working) and issues a fresh one - for when the admin
    // suspects the old URL leaked, or is resetting up on a new phone.
    function sms_forward_regenerate_secret()
    {
        $fresh = bin2hex(random_bytes(24));
        update("setting", "smsForwardSecret", $fresh, null, null);
        return $fresh;
    }
}
if (!function_exists('sms_forward_webhook_url')) {
    function sms_forward_webhook_url()
    {
        global $domainhosts;
        $secret = sms_forward_ensure_secret();
        return "https://{$domainhosts}/cronbot/sms_webhook.php?secret={$secret}";
    }
}
if (!function_exists('sms_forward_extract_amounts')) {
    // Pulls every plausible numeric amount out of a forwarded bank SMS,
    // regardless of digit script (ASCII / Persian / Arabic-Indic) or
    // thousands-separator style (",", Arabic comma "،", Arabic thousands
    // separator "٬"). Deliberately does no keyword/context filtering
    // ("واریز" vs "برداشت" etc.) - the random-salted exact-amount match this
    // feeds into is already an unambiguous signal on its own (see the SMS
    // Forward plan). Returns a de-duplicated array of ints, order preserved.
    function sms_forward_extract_amounts($text)
    {
        $normalized = strtr((string) $text, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        preg_match_all('/\d[\d,،٬]*\d|\d/', $normalized, $m);
        $amounts = [];
        foreach ($m[0] as $raw) {
            $clean = str_replace([',', '،', '٬'], '', $raw);
            if ($clean === '') {
                continue;
            }
            $amounts[] = (int) $clean;
        }
        return array_values(array_unique($amounts));
    }
}
if (!function_exists('sms_forward_extract_text_from_request')) {
    // Pulls the forwarded SMS text out of an inbound webhook request,
    // tolerating whatever shape the admin's phone-side forwarder app happens
    // to send it in - these apps are configured by the admin with a free-text
    // body/URL template, so the exact wrapping isn't something we control.
    // Tries, in order: a JSON body with a recognizable field name, a JSON
    // body with no recognizable field (concatenates every string value found,
    // so the amount-scanning regex still has something to work with), a
    // form-encoded ($postArr/$getArr) field with a recognizable name, then
    // finally the raw body itself (covers an app that just POSTs the plain
    // message text with no wrapping at all).
    function sms_forward_extract_text_from_request($rawBody, $contentType, array $postArr, array $getArr)
    {
        $fieldNames = ['text', 'message', 'msg', 'body', 'sms', 'content'];
        $rawBody = (string) $rawBody;
        $isJson = stripos((string) $contentType, 'json') !== false
            || (strlen(trim($rawBody)) > 0 && in_array(trim($rawBody)[0], ['{', '['], true));
        if ($isJson) {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                foreach ($fieldNames as $name) {
                    foreach ($decoded as $k => $v) {
                        if (is_string($k) && strcasecmp($k, $name) === 0 && is_string($v) && $v !== '') {
                            return $v;
                        }
                    }
                }
                $flat = [];
                array_walk_recursive($decoded, function ($v) use (&$flat) {
                    if (is_string($v)) {
                        $flat[] = $v;
                    }
                });
                if (!empty($flat)) {
                    return implode(' ', $flat);
                }
            }
        }
        foreach ($fieldNames as $name) {
            foreach ($postArr as $k => $v) {
                if (is_string($k) && strcasecmp($k, $name) === 0 && is_string($v) && $v !== '') {
                    return $v;
                }
            }
        }
        foreach ($fieldNames as $name) {
            foreach ($getArr as $k => $v) {
                if (is_string($k) && strcasecmp($k, $name) === 0 && is_string($v) && $v !== '') {
                    return $v;
                }
            }
        }
        return $rawBody;
    }
}
if (!function_exists('payment_notify_admins_auto_confirmed')) {
    // Tells every admin that an invoice confirmed itself, so a receipt the
    // user may already have sent (which is sitting in the admin's chat with
    // live تایید/رد buttons) is visibly settled and nobody taps confirm on a
    // payment that is already credited. Shared by the SMS-forward webhook and
    // croncard's timer-based auto-confirm - both run in a cron context where
    // DirectPayment()'s own Editmessagetext() calls silently no-op, so this is
    // the only signal the admin gets.
    function payment_notify_admins_auto_confirmed(array $Payment_report, $reasonText)
    {
        global $textbotlang;
        $tpl = $textbotlang['hardcoded']['autoConfirmedAdminNotice'] ?? '';
        if (trim((string) $tpl) === '') {
            return; // not translated for this bot's language - nothing to send
        }
        $notice = sprintf(
            $tpl,
            $Payment_report['id_order'],
            $Payment_report['id_user'],
            number_format(intval($Payment_report['price'])),
            $reasonText
        );
        foreach ((array) select("admin", "id_admin", null, null, "FETCH_COLUMN") as $id_admin) {
            $adminRow = select("admin", "*", "id_admin", $id_admin, "select");
            if (is_array($adminRow) && ($adminRow['rule'] ?? '') === 'support') {
                continue; // same exclusion the receipt notification itself uses
            }
            sendmessage($id_admin, $notice, null, 'HTML');
        }
    }
}
if (!function_exists('sms_forward_find_pending_match')) {
    // Looks for exactly one pending card-to-card invoice whose price (in
    // rial) equals $rialAmount. 'Unpaid' is included alongside 'waiting' on
    // purpose - per the approved plan, a matched SMS confirms the payment
    // immediately with no receipt photo required, so a customer who never
    // gets around to sending one is still covered. 'ambiguous' (more than
    // one row happens to share the exact same salted amount right now) is
    // reported separately so the caller can leave it for manual review
    // instead of guessing which invoice the SMS was actually for.
    function sms_forward_find_pending_match($rialAmount)
    {
        global $pdo;
        $stmt = $pdo->prepare("SELECT * FROM Payment_report WHERE Payment_Method = 'cart to cart' AND payment_Status IN ('Unpaid','waiting')");
        $stmt->execute();
        $matches = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (intval($row['price']) * 10 === $rialAmount) {
                $matches[] = $row;
            }
        }
        if (count($matches) === 1) {
            return ['status' => 'matched', 'row' => $matches[0]];
        }
        if (count($matches) > 1) {
            return ['status' => 'ambiguous', 'rows' => $matches];
        }
        return ['status' => 'none'];
    }
}
if (!function_exists('sms_forward_claim_order')) {
    // Atomically flips one order from Unpaid/waiting to paid, returning
    // whether THIS call is the one that made the change. Guards against a
    // race with croncard.php's own time-based auto-confirm (or a manual
    // admin tap) landing in the same instant - only the caller that actually
    // moved the row should go on to call DirectPayment().
    function sms_forward_claim_order($orderId)
    {
        global $pdo;
        $stmt = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'paid' WHERE id_order = :o AND payment_Status IN ('Unpaid','waiting')");
        $stmt->execute([':o' => $orderId]);
        return $stmt->rowCount() === 1;
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
    function card_invoice_build($from_id, $lang, $amount, $idInvoice, $textbotlang, array $setting, $applyRandomAmount = true)
    {
        global $pdo;
        // shows every card configured for this language (falls back to the
        // shared card_number table when this language has none of its own),
        // not just one random pick - card_invoice_render() below repeats the
        // {card_number}/{name_card} block once per card. Shared by the
        // original amount-pick flow AND the expired-invoice "ساخت فاکتور
        // جدید" reissue flow - pure builder, no message send/edit/delete of
        // its own, so both callers stay in charge of their own delivery.
        // display order comes from 📐 چیدمان, and is applied here so the caption's
        // "name | number" lines and the copy buttons below always agree
        $cardList = card_invoice_ordered_cards($lang);
        if (empty($cardList)) {
            return null;
        }
        // "🎲 مبلغ رندوم برای هر فاکتور" (🏬 تنظیمات فروشگاه -> 💎 مالی ->
        // کارت به کارت): adds a small, unpredictable toman amount on top of
        // the real price so two invoices almost never land on the exact same
        // payable amount - lays the groundwork for a future automatic
        // SMS-receipt-matching feature. $applyRandomAmount=false is for the
        // "ساخت فاکتور جدید" reissue call below, which passes an amount
        // ALREADY read back from a prior Payment_report row (already final,
        // possibly already carrying an earlier random addition) - applying
        // this again there would silently inflate the price on every reissue.
        if ($applyRandomAmount && ($setting['cardRandomAmount'] ?? '0') === '1') {
            $amount = $amount + random_int(100, 999);
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
        // the discount this exact amount earns, stated on the invoice itself
        $textcart .= topup_disc_caption_block($from_id, $lang, 'card', $textbotlang, $amount, true);
        // shown whenever the feature is currently on - even on a reissued
        // invoice, whose amount may already carry an earlier random addition
        // from when it was first created - so the exact-amount warning always
        // matches whether THIS caption's number is actually round or not.
        // Rial (not Toman, and not $valueprice) because that is the unit
        // typed into most bank apps/card readers' amount field, and printed
        // WITHOUT thousands separators so it can be copied straight into that
        // field - a pasted "502,850" is rejected by most banking apps.
        if (($setting['cardRandomAmount'] ?? '0') === '1') {
            $priceRial = $amount * 10;
            $textcart .= "\n\n<blockquote><b>" . strtr($textbotlang['textbot']['cardRandomAmountNotice'], [
                '{price_rial}' => $priceRial,
            ]) . "</b></blockquote>";
        }
        $dateacc = date('Y/m/d H:i:s');
        $randomString = bin2hex(random_bytes(5));
        $stmt = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice) VALUES (?,?,?,?,?,?,?)");
        $payment_Status = "Unpaid";
        $Payment_Method = "cart to cart";
        $stmt->execute([$from_id, $randomString, $dateacc, $amount, $payment_Status, $Payment_Method, $idInvoice]);
        if (feature_value('statuscopycart', $lang, $setting['statuscopycart']) == "1") {
            // one style entry per card (copyCard, copyCard2, ...) so each row can
            // carry its own colour and name - a single shared entry rendered
            // every card with the identical label the moment a card had no name
            $total = count($cardList);
            $copyBtns = [];
            foreach (array_values($cardList) as $idx => $c) {
                $which = card_invoice_copy_key($idx + 1);
                $style = card_invoice_btnstyle_for($lang, $which);
                $label = trim((string) ($style['label'] ?? '')) !== ''
                    ? $style['label']
                    : card_invoice_copy_label($idx + 1, $total, $textbotlang);
                $copyBtn = ['text' => $label, 'copy_text' => ['text' => (string) ($c['number'] ?? '')]];
                $color = card_invoice_btnstyle_color($lang, $which);
                if ($color !== '') {
                    $copyBtn['style'] = $color;
                }
                $copyBtns[] = $copyBtn;
            }
            $copyRows = card_invoice_copy_rows($lang, $copyBtns);
            $receiptStyle = card_invoice_btnstyle_for($lang, 'paidReceipt');
            $receiptBtn = topup_styled_button($textbotlang['keyboard']['paidSendReceipt'], $receiptStyle, "sendresidcart-" . $randomString, card_invoice_btnstyle_default_color('paidReceipt'));
            $sendresidcart = json_encode(['inline_keyboard' => array_merge($copyRows, [[$receiptBtn]])]);
        } else {
            $receiptStyle = card_invoice_btnstyle_for($lang, 'paidReceipt');
            $receiptBtn = topup_styled_button($textbotlang['keyboard']['paidSendReceipt'], $receiptStyle, "sendresidcart-" . $randomString, card_invoice_btnstyle_default_color('paidReceipt'));
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
        // strictly below: a dollar exactly is the minimum, not the first
        // amount over it - the message names $1 as what is required
        if ($usdprice < 1) {
            return ['error' => 'toolow', 'usd' => $usd];
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
        $expireMinutes = topup_expire_minutes($lang, 'plisio');
        $captionTemplate = plisio_invoice_caption_for($lang, $textbotlang['users']['Balance']['cryptoInstruction']);
        $textnowpayments = strtr($captionTemplate, [
            '{order}' => $randomString,
            '{price}' => number_format($amount, 0),
            '{usd}' => number_format($usd),
            '{minutes}' => $expireMinutes,
        ]);
        $textnowpayments .= topup_disc_caption_block($from_id, $lang, 'plisio', $textbotlang, $amount, true);
        $payBtn = topup_styled_button($textbotlang['users']['Balance']['payments'], topup_invoice_btnstyle_for($lang, 'plisio', 'pay'), '', topup_invoice_btnstyle_default_color('pay'));
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
        [$mainbalance, $maxbalance] = topup_checkout_limits($user['lang'] ?? 'fa', 'card');
        if (topup_amount_out_of_range($user['Processing_value'], $mainbalance, $maxbalance)) {
            topup_range_notice($from_id, $user['lang'] ?? 'fa', 'card', $mainbalance, $maxbalance, $textbotlang);
            return;
        }
        $invoice = "{$user['Processing_value_tow']}|{$user['Processing_value_one']}";
        $built = card_invoice_build($from_id, $user['lang'] ?? 'fa', $user['Processing_value'], $invoice, $textbotlang, $setting);
        if ($built === null) {
            sendmessage($from_id, $textbotlang['users']['Balance']['noActiveCard'], null, 'HTML');
            return;
        }
        topup_amount_prompt_clear($from_id);
        deletemessage($from_id, $message_id);
        $gethelp = pay_value("helpcart", $user['lang'] ?? null, '2');
        if ($gethelp != 2) {
            $data = json_decode($gethelp, true);
            if ($data['type'] == "text") {
                sendmessage($from_id, $data['text'], null, 'HTML');
            } elseif ($data['type'] == "photo") {
                // HTML like the text variant, so tags and premium emoji render
                sendphoto($from_id, $data['photoid'], $data['text'], 'HTML');
            } elseif ($data['type'] == "video") {
                sendvideo($from_id, $data['videoid'], $data['text'], 'HTML');
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
        [$mainbalanceplisio, $maxbalanceplisio] = topup_checkout_limits($user['lang'] ?? 'fa', 'plisio');
        if (topup_amount_out_of_range($user['Processing_value'], $mainbalanceplisio, $maxbalanceplisio)) {
            topup_range_notice($from_id, $user['lang'] ?? 'fa', 'plisio', $mainbalanceplisio, $maxbalanceplisio, $textbotlang);
            return;
        }
        deletemessage($from_id, $message_id);
        topup_linkmsg_show($from_id, $user['lang'] ?? 'fa', 'plisio', $textbotlang['users']['Balance']['linkpayments']);
        $invoice = "{$user['Processing_value_tow']}|{$user['Processing_value_one']}";
        $built = plisio_invoice_build($from_id, $user['lang'] ?? 'fa', $user['Processing_value'], $invoice, $textbotlang, $setting);
        if ($built['error'] === 'rate') {
            topup_linkmsg_drop($from_id);
            sendmessage($from_id, $textbotlang['users']['Balance']['errorLinkPayment'], $keyboard, 'HTML');
            step('home', $from_id);
            return;
        }
        if ($built['error'] === 'toolow') {
            topup_linkmsg_drop($from_id);
            topup_range_notice($from_id, $user['lang'] ?? 'fa', 'plisio', $mainbalanceplisio, $maxbalanceplisio, $textbotlang);
            return;
        }
        if ($built['error'] === 'api') {
            topup_linkmsg_drop($from_id);
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
        topup_linkmsg_help($from_id, 'helpplisio');
        topup_track_invoice_message($built['randomString'], topup_linkmsg_finish($from_id, $built['text'], $built['keyboard']));
    }
}
if (!function_exists('plisio_expire_notify')) {
    // shared by cronbot/payment_expire.php's time-based check AND
    // cronbot/plisio.php's Plisio-API-status check, so whichever one
    // actually catches a given row first, the user sees the identical
    // edit-in-place + reissue-button treatment (not the old plain text)
    function plisio_expire_notify($id_user, $id_order, $price, $message_id, $payer_lang)
    {
        // one implementation for every gateway that words its own expiry -
        // see topup_expire_notify(); this stays as the name the two plisio
        // crons already call
        topup_expire_notify('plisio', $id_user, $id_order, $price, $message_id, $payer_lang);
    }
}
if (!function_exists('topup_amount_prompt_clear')) {
    // The "#️⃣ مبلغ دلخواه" prompt and any min/max notice under it have done
    // their job once a gateway commits to building an invoice - leaving them
    // above it just asks for an amount that has already been given.
    //
    // Called at the moment a gateway commits, never when one refuses the
    // amount: a rejected amount has to keep the prompt to try again on.
    function topup_amount_prompt_clear($chat_id)
    {
        $u = select("user", "*", "id", $chat_id, "select");
        foreach (['topup_custom_msg_id', 'topup_range_msg_id'] as $col) {
            $mid = (int) ($u[$col] ?? 0);
            if ($mid > 0) {
                deletemessage($chat_id, $mid);
                update("user", $col, "0", "id", $chat_id);
            }
        }
    }
}
if (!function_exists('topup_linkmsg_show')) {
    // ---- the "درحال ساخت لینک پرداخت..." progress message ----
    // Every redirect gateway shows it while it talks to the payment provider,
    // and it used to stay in the chat forever with the invoice piling up under
    // it. These four functions give it a lifecycle instead, shared by all of
    // them so no gateway can drift.
    //
    // It is sent WITHOUT the main reply keyboard on purpose: Telegram refuses
    // to edit a message that carries one ("message can't be edited"), and
    // turning this message into the invoice is the whole point. An inline
    // keyboard never replaces the reply keyboard anyway, so nothing is lost.
    function topup_linkmsg_show($chat_id, $lang, $gatewayKey, $default)
    {
        topup_amount_prompt_clear($chat_id);
        $res = sendmessage($chat_id, topup_linkmsg_for($lang, $gatewayKey, $default), null, 'HTML');
        $GLOBALS['topup_linkmsg_mid'] = (int) ($res['result']['message_id'] ?? 0);
        $GLOBALS['topup_linkmsg_stale'] = false;
        return $GLOBALS['topup_linkmsg_mid'];
    }
    // The per-gateway "راهنمای پرداخت" extra (helpplisio, helpzarinpal, ...).
    // Whatever it sends lands BELOW the progress message, so it also marks that
    // message stale: editing it into the invoice afterwards would leave the
    // invoice sitting above the help instead of last, where the pay button
    // belongs.
    function topup_linkmsg_help($chat_id, $payName)
    {
        $raw = select("PaySetting", "ValuePay", "NamePay", $payName, "select")['ValuePay'] ?? '2';
        if (intval($raw) == 2) {
            return false;
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            return false;
        }
        $type = (string) ($data['type'] ?? '');
        if ($type === 'text') {
            sendmessage($chat_id, $data['text'], null, 'HTML');
        } elseif ($type === 'photo') {
            sendphoto($chat_id, $data['photoid'], null);
        } elseif ($type === 'video') {
            sendvideo($chat_id, $data['videoid'], null);
        } else {
            return false;
        }
        $GLOBALS['topup_linkmsg_stale'] = true;
        return true;
    }
    // Puts the finished invoice where the progress message was: edits it in
    // place when nothing was sent in between (the usual case - the help extra
    // is off by default), and otherwise takes it away and sends the invoice
    // fresh so it stays the last thing in the chat. Returns the message id the
    // invoice ended up on, for Payment_report.message_id.
    function topup_linkmsg_finish($chat_id, $text, $keyboard)
    {
        $mid = (int) ($GLOBALS['topup_linkmsg_mid'] ?? 0);
        $stale = !empty($GLOBALS['topup_linkmsg_stale']);
        $GLOBALS['topup_linkmsg_mid'] = 0;
        $GLOBALS['topup_linkmsg_stale'] = false;
        if ($mid > 0 && !$stale) {
            $res = Editmessagetext($chat_id, $mid, $text, $keyboard, 'HTML');
            if (is_array($res) && !empty($res['ok'])) {
                return $mid;
            }
            // the edit was refused for some reason - drop it and send the
            // invoice fresh rather than losing it
        }
        if ($mid > 0) {
            deletemessage($chat_id, $mid);
        }
        $res = sendmessage($chat_id, $text, $keyboard, 'HTML');
        return (int) ($res['result']['message_id'] ?? 0);
    }
    // The link could not be built. Take the progress message away before the
    // error lands, so nobody is left staring at "درحال ساخت لینک پرداخت...".
    function topup_linkmsg_drop($chat_id)
    {
        $mid = (int) ($GLOBALS['topup_linkmsg_mid'] ?? 0);
        if ($mid > 0) {
            deletemessage($chat_id, $mid);
        }
        $GLOBALS['topup_linkmsg_mid'] = 0;
        $GLOBALS['topup_linkmsg_stale'] = false;
    }
    // updatePaymentMessageId() takes a raw sendmessage response; when the
    // invoice was edited into an existing message there is no response to pass,
    // only the id it landed on.
    function topup_track_invoice_message($orderId, $messageId)
    {
        if ((int) $messageId > 0) {
            update("Payment_report", "message_id", (int) $messageId, "id_order", $orderId);
        }
    }
}
if (!function_exists('premium_emoji_html_from_entities')) {
    // A premium (custom) emoji does not arrive in the text - only its fallback
    // character does, with a custom_emoji entity alongside it - so it has to be
    // folded back in as a <tg-emoji> tag or the saved text silently loses it.
    // Same algorithm as the 🎨 text editor in admin.php: every entity, UTF-16
    // offsets, last to first so the earlier offsets stay valid.
    function premium_emoji_html_from_entities($text, $entities)
    {
        $text = (string) $text;
        $ents = [];
        foreach ((is_array($entities) ? $entities : []) as $ent) {
            if (($ent['type'] ?? '') === 'custom_emoji' && !empty($ent['custom_emoji_id']) && isset($ent['offset'], $ent['length'])) {
                $ents[] = $ent;
            }
        }
        if (empty($ents)) {
            return trim($text);
        }
        usort($ents, function ($a, $b) {
            return intval($b['offset']) <=> intval($a['offset']);
        });
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        foreach ($ents as $ent) {
            $bo = intval($ent['offset']) * 2;
            $bl = intval($ent['length']) * 2;
            $char = substr($utf16, $bo, $bl);
            if ($char === '' || $char === false) {
                continue;
            }
            $tag = '<tg-emoji emoji-id="' . htmlspecialchars((string) $ent['custom_emoji_id'], ENT_QUOTES) . '">' . mb_convert_encoding($char, 'UTF-8', 'UTF-16LE') . '</tg-emoji>';
            $utf16 = substr($utf16, 0, $bo) . mb_convert_encoding($tag, 'UTF-16LE', 'UTF-8') . substr($utf16, $bo + $bl);
        }
        return trim(mb_convert_encoding($utf16, 'UTF-8', 'UTF-16LE'));
    }
}
if (!function_exists('topup_caption_text_from_update')) {
    // The text an admin just sent to a caption/message prompt, with every premium
    // emoji kept wherever it sits (it used to keep only one at offset 0). Entity
    // offsets refer to the raw message, so that is what gets converted.
    function topup_caption_text_from_update($text, $update)
    {
        $msg = $update['message'] ?? [];
        if (!empty($msg['entities'])) {
            return premium_emoji_html_from_entities($msg['text'] ?? $text, $msg['entities']);
        }
        if (!empty($msg['caption_entities'])) {
            return premium_emoji_html_from_entities($msg['caption'] ?? $text, $msg['caption_entities']);
        }
        return trim((string) $text);
    }
}
if (!function_exists('topup_range_caption_map')) {
    // ---- the "❌ حداقل مبلغ واریزی ..." notice, worded per gateway ----
    // {lang: {gatewayKey: text}}, same shape and contract as
    // topup_linkmsg_map(). Every gateway falls back to the shared translation
    // until an admin gives that one its own wording.
    function topup_range_caption_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['topup_range_captions'] ?? ''), true);
        $cache = is_array($m) ? $m : [];
        return $cache;
    }
    function topup_range_caption_for($lang, $gatewayKey, $default)
    {
        $v = trim((string) (topup_range_caption_map()[$lang][$gatewayKey] ?? ''));
        return $v !== '' ? $v : $default;
    }
    function topup_range_caption_has_override($lang, $gatewayKey)
    {
        return trim((string) (topup_range_caption_map()[$lang][$gatewayKey] ?? '')) !== '';
    }
    function topup_range_caption_set($lang, $gatewayKey, $text)
    {
        $m = topup_range_caption_map(true);
        $text = trim((string) $text);
        if ($text === '') {
            unset($m[$lang][$gatewayKey]);
            if (empty($m[$lang])) {
                unset($m[$lang]);
            }
        } else {
            $m[$lang][$gatewayKey] = $text;
        }
        update("setting", "topup_range_captions", empty($m) ? '{}' : json_encode($m, JSON_UNESCAPED_UNICODE), null, null);
        topup_range_caption_map(true);
    }
}
if (!function_exists('topup_range_notice')) {
    // Shows the min/max notice and nothing else: the amount the customer typed
    // is taken away, and so is the notice already on screen, so a run of wrong
    // amounts leaves exactly one message behind instead of a wall of them. The
    // ❌ button under it ends the top-up session the same way the shared
    // main-menu close does.
    // Shared by every "that amount will not do" message - the min/max refusal
    // and the crypto gateways' $1 floor. Exactly one is ever on screen: the
    // previous one and the number the customer typed both go first. It also
    // puts the customer back on the screen they came from: the step is moved to
    // 'get_step_payment' before a gateway ever sees the amount, so without this
    // a refusal would strand them on a step where typing does nothing.
    function topup_amount_notice($chat_id, $text, $textbotlang, $typedMessageId = 0)
    {
        $typedMessageId = (int) $typedMessageId > 0
            ? (int) $typedMessageId
            : (int) ($GLOBALS['topup_typed_message_id'] ?? 0);
        if ($typedMessageId > 0) {
            deletemessage($chat_id, $typedMessageId);
        }
        $prev = (int) (select("user", "*", "id", $chat_id, "select")['topup_range_msg_id'] ?? 0);
        if ($prev > 0) {
            deletemessage($chat_id, $prev);
        }
        $back = (string) ($GLOBALS['topup_amount_origin_step'] ?? '');
        if ($back !== '') {
            step($back, $chat_id);
        }
        $kb = json_encode(['inline_keyboard' => [[[
            'text' => $textbotlang['bottext']['btn_close'] ?? '❌ بستن',
            'callback_data' => 'topup_range_close',
            'style' => 'danger',
        ]]]]);
        $res = sendmessage($chat_id, $text, $kb, 'HTML');
        update("user", "topup_range_msg_id", (string) (int) ($res['result']['message_id'] ?? 0), "id", $chat_id);
    }
    // The one wording a refused amount gets, wherever it was refused - the
    // custom-amount step, the gateway's own check, or its dollar floor. They
    // used to disagree, so the same amount could be turned down twice with
    // two different numbers.
    function topup_range_text($lang, $gatewayKey, $min, $max, $textbotlang)
    {
        $fmt = function ($v) {
            return is_numeric($v) ? number_format((float) $v, 0) : (string) $v;
        };
        // a package is held to the dollar floor only, so its refusal has no
        // maximum of its own - quote the one the amount screen shows, not a blank
        if ($min === null || $max === null) {
            [$effMin, $effMax] = topup_effective_limits($lang, $gatewayKey);
            $min = $min ?? $effMin;
            $max = $max ?? $effMax;
        }
        $rate = topup_has_usd_floor($gatewayKey) ? topup_usd_rate() : 0;
        $usd = function ($v) use ($rate) {
            return ($rate > 0 && is_numeric($v)) ? rtrim(rtrim(number_format((float) $v / $rate, 2), '0'), '.') : '—';
        };
        return strtr(
            topup_range_caption_for($lang, $gatewayKey, topup_range_default($gatewayKey, $textbotlang)),
            ['{mainbalance}' => $fmt($min), '{maxbalance}' => $fmt($max), '{maxusd}' => $usd($max), '{minusd}' => $usd($min)]
        );
    }
    function topup_range_notice($chat_id, $lang, $gatewayKey, $min, $max, $textbotlang, $typedMessageId = 0)
    {
        topup_amount_notice($chat_id, topup_range_text($lang, $gatewayKey, $min, $max, $textbotlang), $textbotlang, $typedMessageId);
    }
}
if (!function_exists('topup_invoice_caption_for')) {
    // ---- one API for every gateway's invoice texts and buttons ----
    // Card-to-card and Plisio already had their own stores, with data in them
    // and their own admin flows; rather than migrate that, these forward to
    // them and use a shared per-gateway store for everyone else. Callers - the
    // gateway screens, the previews, the checkout, the expiry cron - only ever
    // see this one API, so a gateway added later needs no special case.
    function topup_invoice_caption_for($lang, $key, $default)
    {
        if ($key === 'card') {
            return card_invoice_caption_for($lang, $default);
        }
        if ($key === 'plisio') {
            return plisio_invoice_caption_for($lang, $default);
        }
        $v = trim((string) (topup_gwstore_map('topup_invoice_captions')[$lang][$key] ?? ''));
        return $v !== '' ? $v : $default;
    }
    function topup_invoice_caption_has_override($lang, $key)
    {
        if ($key === 'card') {
            return trim((string) (card_invoice_caption_get()[$lang] ?? '')) !== '';
        }
        if ($key === 'plisio') {
            return trim((string) (plisio_invoice_caption_get()[$lang] ?? '')) !== '';
        }
        return trim((string) (topup_gwstore_map('topup_invoice_captions')[$lang][$key] ?? '')) !== '';
    }
    function topup_invoice_caption_set($lang, $key, $text)
    {
        if ($key === 'card') {
            card_invoice_caption_set($lang, $text);
            return;
        }
        if ($key === 'plisio') {
            plisio_invoice_caption_set($lang, $text);
            return;
        }
        topup_gwstore_set('topup_invoice_captions', $lang, $key, $text);
    }
    function topup_invoice_expired_caption_for($lang, $key, $default)
    {
        if ($key === 'card') {
            return card_invoice_expired_caption_for($lang, $default);
        }
        if ($key === 'plisio') {
            return plisio_invoice_expired_caption_for($lang, $default);
        }
        $v = trim((string) (topup_gwstore_map('topup_invoice_expired_captions')[$lang][$key] ?? ''));
        return $v !== '' ? $v : $default;
    }
    function topup_invoice_expired_caption_has_override($lang, $key)
    {
        if ($key === 'card') {
            return trim((string) (card_invoice_expired_caption_get()[$lang] ?? '')) !== '';
        }
        if ($key === 'plisio') {
            return trim((string) (plisio_invoice_expired_caption_get()[$lang] ?? '')) !== '';
        }
        return trim((string) (topup_gwstore_map('topup_invoice_expired_captions')[$lang][$key] ?? '')) !== '';
    }
    function topup_invoice_expired_caption_set($lang, $key, $text)
    {
        if ($key === 'card') {
            card_invoice_expired_caption_set($lang, $text);
            return;
        }
        if ($key === 'plisio') {
            plisio_invoice_expired_caption_set($lang, $text);
            return;
        }
        topup_gwstore_set('topup_invoice_expired_captions', $lang, $key, $text);
    }
}
if (!function_exists('nobitex_rates_toman')) {
    // ---- prices, from Nobitex ----
    // The bot's old rate source (bon-bast) answers 403 from this server, and
    // Nobitex covers everything in one call - including TON, which it lists
    // under the token's original name, GRAM.
    //
    // Everything here comes back in RIALS; a toman is ten of them. Getting that
    // wrong is a silent factor-of-ten, so the division happens once, here.
    function nobitex_rates_toman($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 12]]);
        $raw = @file_get_contents('https://apiv2.nobitex.ir/market/stats?srcCurrency=gram,usdt,trx&dstCurrency=rls', false, $ctx);
        $j = $raw === false ? null : json_decode($raw, true);
        $out = [];
        foreach ((array) ($j['stats'] ?? []) as $pair => $s) {
            $rial = (float) ($s['latest'] ?? 0);
            if ($rial > 0) {
                $out[explode('-', $pair)[0]] = $rial / 10;
            }
        }
        $cache = $out;
        return $out;
    }
    // 0 means "no price right now" - every caller has to treat that as a
    // failure rather than dividing by it
    function nobitex_rate_toman($symbol)
    {
        $v = (float) (nobitex_rates_toman()[$symbol] ?? 0);
        return $v > 0 ? $v : 0.0;
    }
    function topup_ton_rate_toman()
    {
        return nobitex_rate_toman('gram');
    }
    // the wallet the customer is asked to pay into, per language
    function topup_ton_address($lang)
    {
        return trim((string) pay_value('walletaddresston', $lang, ''));
    }
}
if (!function_exists('topup_trx_address')) {
    // ---- TRX, settled off the TRON chain ----
    // TON could name the invoice in the transfer's comment. TRON cannot: of the
    // last nineteen real transfers into this shop's own wallet, not one carried
    // a note - wallets simply do not send them. So the amount IS the name: every
    // invoice is given a figure no other open invoice has, down to the last
    // decimal, and an incoming transfer of exactly that much identifies exactly
    // one invoice.
    //
    // The customer can also just hand over the transaction hash, which settles
    // it outright - see trx_verify_hash().
    function topup_trx_address($lang)
    {
        return trim((string) pay_value('walletaddresstrx', $lang, ''));
    }
    function topup_trx_rate_toman()
    {
        return nobitex_rate_toman('trx');
    }
    // A figure in sun (a millionth of a TRX) that no other open invoice is
    // waiting for. Rounded UP to the nearest 0.01 TRX before the unique tail is
    // added, so the shop is never asked for less than the invoice is worth.
    function trx_unique_sun($base)
    {
        global $pdo;
        $floor = (int) (ceil($base / 10000) * 10000);
        $open = $pdo->query("SELECT dec_not_confirmed FROM Payment_report WHERE Payment_Method = 'TRX' AND payment_Status = 'Unpaid'")
            ->fetchAll(PDO::FETCH_COLUMN);
        $taken = array_flip(array_map('intval', (array) $open));
        for ($i = 0; $i < 60; $i++) {
            $candidate = $floor + random_int(1, 9999);
            if (!isset($taken[$candidate])) {
                return $candidate;
            }
        }
        // sixty collisions means the shop has thousands of open TRX invoices at
        // once; step past the block rather than hand back a duplicate
        return $floor + 10000 + random_int(1, 9999);
    }
    function trx_sun_to_text($sun)
    {
        return rtrim(rtrim(number_format($sun / 1000000, 6, '.', ''), '0'), '.');
    }
}
if (!function_exists('trx_invoice_build')) {
    // Same contract as the other builders. Never execute this in a test: it
    // reads the live Nobitex price. Source-verify only.
    function trx_invoice_build($from_id, $lang, $amount, $idInvoice, $textbotlang, array $setting)
    {
        global $pdo;
        $addr = topup_trx_address($lang);
        if ($addr === '') {
            return ['error' => 'noaddress'];
        }
        $rate = topup_trx_rate_toman();
        if ($rate <= 0) {
            return ['error' => 'rate'];
        }
        $sun = trx_unique_sun((int) round(($amount / $rate) * 1000000));
        if ($sun < 1) {
            return ['error' => 'toolow'];
        }
        $trxText = trx_sun_to_text($sun);
        $randomString = bin2hex(random_bytes(5));
        $dateacc = date('Y/m/d H:i:s');
        // dec_not_confirmed holds the exact figure the watcher waits for - the
        // same column plisio uses for its txn id
        $stmt = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice,dec_not_confirmed) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$from_id, $randomString, $dateacc, $amount, "Unpaid", "TRX", $idInvoice, $sun]);
        $b = $textbotlang['users']['Balance'];
        $text = strtr(topup_invoice_caption_for($lang, 'trx', $b['trxInvoiceCaption']), [
            '{order}' => $randomString,
            '{trx}' => $trxText,
            '{price}' => number_format($amount, 0),
            '{rate}' => number_format($rate, 0),
            '{minutes}' => topup_expire_minutes($lang, 'trx'),
            '{address}' => $addr,
            // which chain the money has to travel on. One place to say it, so
            // the day a USDT-on-Tron gateway sits beside this one the two
            // cannot end up naming the same network differently.
            '{network}' => $b['trxNetworkLabel'],
        ]);
        $text .= topup_disc_caption_block($from_id, $lang, 'trx', $textbotlang, $amount, true);
        $copy = function ($which, $label, $value) use ($lang) {
            $btn = topup_styled_button($label, topup_invoice_btnstyle_for($lang, 'trx', $which), '', topup_invoice_btnstyle_default_color($which, 'trx'));
            unset($btn['callback_data']);
            $btn['copy_text'] = ['text' => $value];
            return $btn;
        };
        $plain = function ($which, $label, $cb) use ($lang) {
            return topup_styled_button($label, topup_invoice_btnstyle_for($lang, 'trx', $which), $cb, topup_invoice_btnstyle_default_color($which, 'trx'));
        };
        $made = [
            'copyamt' => $copy('copyamt', $b['trxCopyAmountBtn'], $trxText),
            'copyaddr' => $copy('copyaddr', $b['trxCopyAddressBtn'], $addr),
            'check' => $plain('check', $b['trxCheckBtn'], "trxcheck:{$randomString}"),
            'backmethod' => $plain('backmethod', $b['tonBackMethodBtn'], 'topup_back_methods'),
            'back' => $plain('back', $b['tonBackBtn'], 'gwinvclose'),
        ];
        if (topup_invoice_layout_touched($lang, 'trx')) {
            $rows = [];
            $ordered = [];
            foreach (topup_invoice_ordered_keys($lang, 'trx') as $w) {
                $ordered[] = $made[$w];
            }
            foreach (array_chunk($ordered, max(1, topup_invoice_layout_perrow($lang, 'trx'))) as $chunk) {
                $rows[] = $chunk;
            }
        } else {
            $rows = [
                [$made['copyamt'], $made['copyaddr']],
                [$made['check']],
                [$made['backmethod']],
                [$made['back']],
            ];
        }
        return [
            'error' => null,
            'text' => $text,
            'keyboard' => json_encode(['inline_keyboard' => $rows]),
            'randomString' => $randomString,
        ];
    }
}
if (!function_exists('usdtbep_rpc')) {
    // ---- USDT on BNB Smart Chain (BEP20) ----
    //
    // Same shape as the TRX gateway above: one wallet, and the invoice is named
    // by its amount because a token transfer carries no note either.
    //
    // Two things are NOT like TRX and both are load-bearing:
    //
    //   1. BEP20 USDT has 18 decimals, so the on-chain figure for anything over
    //      ~9.2 USDT is larger than PHP_INT_MAX. Every amount here is therefore
    //      kept in MICRO-USDT (6 decimals, exactly like TRX's sun) and the
    //      on-chain value is divided down with bcmath before it is ever cast to
    //      int. Never work in wei.
    //   2. BSC's public dataseed nodes refuse eth_getLogs outright, and
    //      Etherscan's free tier does not cover this chain at all. publicnode
    //      does serve it, keylessly, which is why it is first in the list.
    function usdtbep_endpoints()
    {
        return [
            'https://bsc-rpc.publicnode.com',
            'https://bsc.meowrpc.com',
            'https://bsc-dataseed.binance.org',
        ];
    }
    function usdtbep_contract()
    {
        return '0x55d398326f99059ff775485246999027b3197955';
    }
    // keccak256("Transfer(address,address,uint256)")
    function usdtbep_transfer_topic()
    {
        return '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    }
    // 18 on-chain decimals, 12 of which are dropped to reach micro-USDT
    function usdtbep_wei_per_micro()
    {
        return '1000000000000';
    }
    // One JSON-RPC call, tried against each endpoint in turn. Returns the
    // decoded 'result', or null when every endpoint failed - which callers must
    // treat as "could not look", never as "nothing arrived".
    function usdtbep_rpc($method, array $params)
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        foreach (usdtbep_endpoints() as $url) {
            $raw = @file_get_contents($url, false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $body,
                    'timeout' => 20,
                    'ignore_errors' => true,
                ],
            ]));
            if ($raw === false) {
                continue;
            }
            $j = json_decode($raw, true);
            if (is_array($j) && array_key_exists('result', $j) && !isset($j['error'])) {
                return $j['result'];
            }
        }
        return null;
    }
    // hex string -> decimal string. bcmath, because these values do not fit an
    // int (see the note above).
    function usdtbep_hex_to_dec($hex)
    {
        $hex = ltrim(strtolower((string) $hex), 'x');
        $hex = ltrim(preg_replace('/^0x/', '', $hex), '0');
        if ($hex === '' || !ctype_xdigit($hex)) {
            return '0';
        }
        $dec = '0';
        foreach (str_split($hex) as $c) {
            $dec = bcadd(bcmul($dec, '16'), (string) hexdec($c));
        }
        return $dec;
    }
    // the shop's own BSC wallet for this language
    function topup_usdtbep_address($lang)
    {
        return trim((string) pay_value('walletaddressusdtbep', $lang, ''));
    }
    function usdtbep_address_valid($addr)
    {
        return preg_match('/^0x[a-fA-F0-9]{40}$/', (string) $addr) === 1;
    }
    // a wallet as a 32-byte topic, which is how eth_getLogs matches a recipient
    function usdtbep_address_topic($addr)
    {
        return '0x' . str_pad(strtolower(substr((string) $addr, 2)), 64, '0', STR_PAD_LEFT);
    }
    // Nobitex already quotes Tether in the same call that fetches TRX's price
    // (see nobitex_rates_toman), so this costs nothing extra.
    function topup_usdtbep_rate_toman()
    {
        return nobitex_rate_toman('usdt');
    }
    // A figure in micro-USDT no other open invoice is waiting for. Rounded UP to
    // the nearest 0.001 USDT before the unique tail - finer than TRX's 0.01,
    // because a hundredth of a Tether is worth far more than a hundredth of a
    // TRX and the customer should not be asked for meaningful extra.
    function usdtbep_unique_micro($base)
    {
        global $pdo;
        $floor = (int) (ceil($base / 1000) * 1000);
        $open = $pdo->query("SELECT dec_not_confirmed FROM Payment_report WHERE Payment_Method = 'USDT-BEP20' AND payment_Status = 'Unpaid'")
            ->fetchAll(PDO::FETCH_COLUMN);
        $taken = array_flip(array_map('intval', (array) $open));
        for ($i = 0; $i < 60; $i++) {
            $candidate = $floor + random_int(1, 999);
            if (!isset($taken[$candidate])) {
                return $candidate;
            }
        }
        // sixty collisions means thousands of open invoices at once; step past
        // the block rather than hand back a duplicate
        return $floor + 1000 + random_int(1, 999);
    }
    function usdtbep_micro_to_text($micro)
    {
        return rtrim(rtrim(number_format($micro / 1000000, 6, '.', ''), '0'), '.');
    }
}
if (!function_exists('usdtbep_incoming_transfers')) {
    // Incoming USDT transfers into the shop's wallet, as [micro => timestamp_ms].
    // null means the lookup failed, which is not the same as nothing arrived.
    //
    // $sinceTs is a unix timestamp; the block window is derived from it because
    // eth_getLogs speaks blocks, not time. BSC produces a block every ~0.45s, so
    // the span is capped at 6000 blocks (~45 minutes) - comfortably longer than
    // any invoice lives, and short enough that the node will serve it.
    function usdtbep_incoming_transfers($address, $sinceTs = 0, $maxSpan = 6000)
    {
        if (!usdtbep_address_valid($address)) {
            return null;
        }
        $headHex = usdtbep_rpc('eth_blockNumber', []);
        if (!is_string($headHex)) {
            return null;
        }
        $head = (int) usdtbep_hex_to_dec($headHex);
        if ($head <= 0) {
            return null;
        }
        $span = (int) $maxSpan;
        if ($sinceTs > 0) {
            // 0.45s a block, plus a margin, then clamped
            $need = (int) ceil((time() - $sinceTs) / 0.45) + 400;
            $span = max(200, min($span, $need));
        }
        $from = max(0, $head - $span);
        $logs = usdtbep_rpc('eth_getLogs', [[
            'fromBlock' => '0x' . dechex($from),
            'toBlock' => '0x' . dechex($head),
            'address' => usdtbep_contract(),
            'topics' => [usdtbep_transfer_topic(), null, usdtbep_address_topic($address)],
        ]]);
        if (!is_array($logs)) {
            return null;
        }
        // A transfer's time comes from its block, and each block costs a call.
        // eth_getLogs answers in ascending block order, so walking it BACKWARDS
        // resolves the newest blocks first - which are the only ones a freshly
        // issued invoice can match anyway - and the cap keeps a wallet with a
        // lot of traffic from turning one tap into a hundred round trips.
        // Anything past the cap is left at 0, which fails closed: never settled
        // by mistake, and the exact-amount rule still has to agree first.
        $maxBlockLookups = 30;
        $blockTimes = [];
        $out = [];
        foreach (array_reverse($logs) as $log) {
            if (!is_array($log) || !empty($log['removed'])) {
                continue;
            }
            $micro = (int) bcdiv(usdtbep_hex_to_dec($log['data'] ?? '0x0'), usdtbep_wei_per_micro(), 0);
            if ($micro < 1) {
                continue;
            }
            $bh = (string) ($log['blockNumber'] ?? '');
            if (!array_key_exists($bh, $blockTimes)) {
                if (count($blockTimes) >= $maxBlockLookups) {
                    $blockTimes[$bh] = 0;
                } else {
                    $blk = usdtbep_rpc('eth_getBlockByNumber', [$bh, false]);
                    $blockTimes[$bh] = is_array($blk) && isset($blk['timestamp'])
                        ? ((int) usdtbep_hex_to_dec($blk['timestamp'])) * 1000
                        : 0;
                }
            }
            $ts = $blockTimes[$bh];
            if (!isset($out[$micro]) || $ts > $out[$micro]) {
                $out[$micro] = $ts;
            }
        }
        return $out;
    }
    // Same rule as TRX: the amount is the invoice's name, so it has to match
    // exactly, and the transfer has to be newer than the invoice.
    function usdtbep_payment_settled(array $row, $incoming)
    {
        if (!is_array($incoming)) {
            return false;
        }
        $want = (int) $row['dec_not_confirmed'];
        if ($want < 1 || !isset($incoming[$want])) {
            return false;
        }
        $issuedMs = strtotime((string) $row['time']) * 1000;
        // a minute of slack for clock differences between this box and the chain
        return $issuedMs <= 0 || $incoming[$want] >= ($issuedMs - 60000);
    }
    // The other way in: the customer hands over the transaction hash. True only
    // for a successful transaction carrying a USDT Transfer of exactly this
    // invoice's figure into this shop's wallet.
    function usdtbep_verify_hash(array $row, $hash, $address)
    {
        $hash = strtolower(trim((string) $hash));
        if (!preg_match('/^0x[a-f0-9]{64}$/', $hash) || !usdtbep_address_valid($address)) {
            return false;
        }
        $rc = usdtbep_rpc('eth_getTransactionReceipt', [$hash]);
        if (!is_array($rc) || ($rc['status'] ?? '') !== '0x1' || !is_array($rc['logs'] ?? null)) {
            return false;
        }
        $want = (int) $row['dec_not_confirmed'];
        $mine = strtolower((string) $address);
        foreach ($rc['logs'] as $log) {
            if (strtolower((string) ($log['address'] ?? '')) !== usdtbep_contract()) {
                continue;
            }
            if (strtolower((string) ($log['topics'][0] ?? '')) !== usdtbep_transfer_topic()) {
                continue;
            }
            $to = '0x' . substr(strtolower((string) ($log['topics'][2] ?? '')), 26);
            if ($to !== $mine) {
                continue;
            }
            $micro = (int) bcdiv(usdtbep_hex_to_dec($log['data'] ?? '0x0'), usdtbep_wei_per_micro(), 0);
            if ($micro === $want) {
                return true;
            }
        }
        return false;
    }
}
if (!function_exists('usdtbep_invoice_build')) {
    // Same contract as the other builders. Never execute this in a test: it
    // reads the live Nobitex price. Source-verify only.
    function usdtbep_invoice_build($from_id, $lang, $amount, $idInvoice, $textbotlang, array $setting)
    {
        global $pdo;
        $addr = topup_usdtbep_address($lang);
        if (!usdtbep_address_valid($addr)) {
            return ['error' => 'noaddress'];
        }
        $rate = topup_usdtbep_rate_toman();
        if ($rate <= 0) {
            return ['error' => 'rate'];
        }
        $micro = usdtbep_unique_micro((int) round(($amount / $rate) * 1000000));
        if ($micro < 1) {
            return ['error' => 'toolow'];
        }
        $amountText = usdtbep_micro_to_text($micro);
        $randomString = bin2hex(random_bytes(5));
        $dateacc = date('Y/m/d H:i:s');
        // dec_not_confirmed holds the exact figure the watcher waits for - the
        // same column plisio uses for its txn id
        $stmt = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice,dec_not_confirmed) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$from_id, $randomString, $dateacc, $amount, "Unpaid", "USDT-BEP20", $idInvoice, $micro]);
        $b = $textbotlang['users']['Balance'];
        $text = strtr(topup_invoice_caption_for($lang, 'usdtbep', $b['usdtbepInvoiceCaption']), [
            '{order}' => $randomString,
            '{usdt}' => $amountText,
            '{price}' => number_format($amount, 0),
            '{rate}' => number_format($rate, 0),
            '{minutes}' => topup_expire_minutes($lang, 'usdtbep'),
            '{address}' => $addr,
            '{network}' => $b['usdtbepNetworkLabel'],
        ]);
        $text .= topup_disc_caption_block($from_id, $lang, 'usdtbep', $textbotlang, $amount, true);
        $copy = function ($which, $label, $value) use ($lang) {
            $btn = topup_styled_button($label, topup_invoice_btnstyle_for($lang, 'usdtbep', $which), '', topup_invoice_btnstyle_default_color($which, 'usdtbep'));
            unset($btn['callback_data']);
            $btn['copy_text'] = ['text' => $value];
            return $btn;
        };
        $plain = function ($which, $label, $cb) use ($lang) {
            return topup_styled_button($label, topup_invoice_btnstyle_for($lang, 'usdtbep', $which), $cb, topup_invoice_btnstyle_default_color($which, 'usdtbep'));
        };
        $made = [
            'copyamt' => $copy('copyamt', $b['usdtbepCopyAmountBtn'], $amountText),
            'copyaddr' => $copy('copyaddr', $b['usdtbepCopyAddressBtn'], $addr),
            'check' => $plain('check', $b['usdtbepCheckBtn'], "usdtbepcheck:{$randomString}"),
            'backmethod' => $plain('backmethod', $b['tonBackMethodBtn'], 'topup_back_methods'),
            'back' => $plain('back', $b['tonBackBtn'], 'gwinvclose'),
        ];
        if (topup_invoice_layout_touched($lang, 'usdtbep')) {
            $rows = [];
            $ordered = [];
            foreach (topup_invoice_ordered_keys($lang, 'usdtbep') as $w) {
                $ordered[] = $made[$w];
            }
            foreach (array_chunk($ordered, max(1, topup_invoice_layout_perrow($lang, 'usdtbep'))) as $chunk) {
                $rows[] = $chunk;
            }
        } else {
            $rows = [
                [$made['copyamt'], $made['copyaddr']],
                [$made['check']],
                [$made['backmethod']],
                [$made['back']],
            ];
        }
        return [
            'error' => null,
            'text' => $text,
            'keyboard' => json_encode(['inline_keyboard' => $rows]),
            'randomString' => $randomString,
        ];
    }
}
if (!function_exists('trx_incoming_transfers')) {
    // Plain TRX transfers into the shop's wallet, as [sun => timestamp_ms].
    // Token transfers are skipped - the wallet is a magnet for TRC10 spam with
    // round amounts - and so is the dust that address-poisoning bots send.
    // null means the lookup failed, which is not the same as nothing arrived.
    function trx_incoming_transfers($address, $sinceMs = 0, $limit = 100)
    {
        $url = 'https://api.trongrid.io/v1/accounts/' . rawurlencode($address)
            . '/transactions?only_to=true&limit=' . (int) $limit;
        if ($sinceMs > 0) {
            $url .= '&min_timestamp=' . (int) $sinceMs;
        }
        $raw = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 20]]));
        $j = $raw === false ? null : json_decode($raw, true);
        if (!is_array($j) || !isset($j['data']) || !is_array($j['data'])) {
            return null;
        }
        $out = [];
        foreach ($j['data'] as $tx) {
            $c = $tx['raw_data']['contract'][0] ?? [];
            if (($c['type'] ?? '') !== 'TransferContract') {
                continue;   // a token, not TRX
            }
            if (($tx['ret'][0]['contractRet'] ?? '') !== 'SUCCESS') {
                continue;
            }
            $sun = (int) ($c['parameter']['value']['amount'] ?? 0);
            if ($sun < 10000) {
                continue;   // dust: address-poisoning spam, never a payment
            }
            $ts = (int) ($tx['block_timestamp'] ?? 0);
            if (!isset($out[$sun]) || $ts > $out[$sun]) {
                $out[$sun] = $ts;
            }
        }
        return $out;
    }
}
if (!function_exists('trx_payment_settled')) {
    // The amount is the invoice's name, so it has to match exactly - a
    // tolerance would make two invoices answer to the same transfer. The
    // transfer also has to be newer than the invoice, or an older payment of a
    // coincidentally equal amount would settle it.
    function trx_payment_settled(array $row, $incoming)
    {
        if (!is_array($incoming)) {
            return false;
        }
        $want = (int) $row['dec_not_confirmed'];
        if ($want < 1 || !isset($incoming[$want])) {
            return false;
        }
        $issuedMs = strtotime((string) $row['time']) * 1000;
        // a minute of slack for clock differences between this box and the chain
        return $issuedMs <= 0 || $incoming[$want] >= ($issuedMs - 60000);
    }
}
if (!function_exists('trx_verify_hash')) {
    // The other way in: the customer hands over the transaction hash and the
    // chain answers directly. Returns true only for a successful plain TRX
    // transfer of exactly this invoice's figure.
    //
    // Nothing needs to remember which hashes have been used: the figure is
    // unique per open invoice, so a hash that settles one cannot settle another.
    function trx_verify_hash(array $row, $hash, $address)
    {
        $hash = strtolower(trim((string) $hash));
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return false;
        }
        $raw = @file_get_contents('https://api.trongrid.io/wallet/gettransactionbyid', false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode(['value' => $hash]),
                'timeout' => 20,
            ],
        ]));
        $j = $raw === false ? null : json_decode($raw, true);
        if (!is_array($j) || ($j['ret'][0]['contractRet'] ?? '') !== 'SUCCESS') {
            return false;
        }
        $c = $j['raw_data']['contract'][0] ?? [];
        if (($c['type'] ?? '') !== 'TransferContract') {
            return false;
        }
        $v = $c['parameter']['value'] ?? [];
        if ((int) ($v['amount'] ?? 0) !== (int) $row['dec_not_confirmed']) {
            return false;
        }
        // the destination comes back hex-encoded; compare on the base58 form the
        // admin actually configured
        $to = strtolower((string) ($v['to_address'] ?? ''));
        return $to !== '' && $to === strtolower(trx_base58_to_hex($address));
    }
    // TRON addresses are base58check over a 21-byte payload that starts 0x41.
    // Decoding it here avoids depending on a library for one comparison.
    function trx_base58_to_hex($base58)
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $num = '0';
        $len = strlen((string) $base58);
        for ($i = 0; $i < $len; $i++) {
            $p = strpos($alphabet, $base58[$i]);
            if ($p === false) {
                return '';
            }
            $num = bcadd(bcmul($num, '58'), (string) $p);
        }
        $hex = '';
        while (bccomp($num, '0') > 0) {
            $hex = dechex((int) bcmod($num, '16')) . $hex;
            $num = bcdiv($num, '16', 0);
        }
        // leading '1's in base58 are leading zero bytes
        for ($i = 0; $i < $len && $base58[$i] === '1'; $i++) {
            $hex = '00' . $hex;
        }
        if (strlen($hex) % 2 === 1) {
            $hex = '0' . $hex;
        }
        // drop the 4-byte checksum
        return strlen($hex) > 8 ? substr($hex, 0, -8) : '';
    }
}
if (!function_exists('ton_invoice_build')) {
    // Same contract as the other builders: a pure builder that returns the
    // invoice text, keyboard and order id. No Telegram calls of its own.
    //
    // TON is paid straight into the shop's own wallet, so there is no processor
    // to ask "was this paid". The order id travels as the transfer's comment
    // instead, and cronbot/ton.php matches it back. That comment is the whole
    // mechanism - a transfer without it cannot be attributed to anyone.
    //
    // Never execute this in a test: it reads the live Nobitex price. Source-
    // verify only, the same rule the other builders carry.
    function ton_invoice_build($from_id, $lang, $amount, $idInvoice, $textbotlang, array $setting)
    {
        global $pdo;
        $addr = topup_ton_address($lang);
        if ($addr === '') {
            return ['error' => 'noaddress'];
        }
        $rate = topup_ton_rate_toman();
        if ($rate <= 0) {
            return ['error' => 'rate'];
        }
        $ton = $amount / $rate;
        $nano = (int) round($ton * 1000000000);
        if ($nano < 1) {
            return ['error' => 'toolow', 'usd' => nobitex_rate_toman('usdt')];
        }
        // TON's smallest unit is a nanoton; quote the exact figure the customer
        // has to send, with no trailing zeros to mistype
        $tonText = rtrim(rtrim(number_format($nano / 1000000000, 9, '.', ''), '0'), '.');
        // the memo and the order id are the same string on purpose - see
        // topup_memo_config(); the watcher matches the comment against id_order
        $randomString = topup_memo_build($lang, 'ton');
        $dateacc = date('Y/m/d H:i:s');
        // dec_not_confirmed carries the exact nanoton figure the watcher has to
        // see arrive - the same column plisio uses for its txn id
        $stmt = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice,dec_not_confirmed) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$from_id, $randomString, $dateacc, $amount, "Unpaid", "TON", $idInvoice, $nano]);
        $minutes = topup_expire_minutes($lang, 'ton');
        $b = $textbotlang['users']['Balance'];
        $text = strtr(topup_invoice_caption_for($lang, 'ton', $b['tonInvoiceCaption']), [
            '{order}' => $randomString,
            '{ton}' => $tonText,
            '{price}' => number_format($amount, 0),
            '{rate}' => number_format($rate, 0),
            '{minutes}' => $minutes,
            '{address}' => $addr,
            '{memo}' => $randomString,
        ]);
        $text .= topup_disc_caption_block($from_id, $lang, 'ton', $textbotlang, $amount, true);
        // The wallet link pre-fills all three values, which is the path that
        // cannot go wrong. The copy buttons are for anyone paying from a wallet
        // the link does not open.
        $openBtn = topup_styled_button($b['tonOpenWalletBtn'], topup_invoice_btnstyle_for($lang, 'ton', 'pay'), '', topup_invoice_btnstyle_default_color('pay', 'ton'));
        $openBtn['url'] = "https://app.tonkeeper.com/transfer/{$addr}?amount={$nano}&text=" . rawurlencode($randomString);
        $copy = function ($which, $label, $value) use ($lang) {
            $b = topup_styled_button($label, topup_invoice_btnstyle_for($lang, 'ton', $which), '', topup_invoice_btnstyle_default_color($which, 'ton'));
            unset($b['callback_data']);
            $b['copy_text'] = ['text' => $value];
            return $b;
        };
        // one colour for the whole keyboard, and a way back out of it - the
        // customer who opens this and changes their mind should not have to
        // start the whole top-up again
        $plain = function ($which, $label, $cb) use ($lang) {
            return topup_styled_button($label, topup_invoice_btnstyle_for($lang, 'ton', $which), $cb, topup_invoice_btnstyle_default_color($which, 'ton'));
        };
        $made = [
            'pay' => $openBtn,
            'copymemo' => $copy('copymemo', $b['tonCopyMemoBtn'], $randomString),
            'copyamt' => $copy('copyamt', $b['tonCopyAmountBtn'], $tonText),
            'copyaddr' => $copy('copyaddr', $b['tonCopyAddressBtn'], $addr),
            'check' => $plain('check', $b['tonCheckBtn'], "toncheck:{$randomString}"),
            'backmethod' => $plain('backmethod', $b['tonBackMethodBtn'], 'topup_back_methods'),
            // this one gives up on the invoice entirely: the message goes and
            // the main menu comes back, keyboard and all
            'back' => $plain('back', $b['tonBackBtn'], 'gwinvclose'),
        ];
        if (topup_invoice_layout_touched($lang, 'ton')) {
            // the admin arranged it themselves, so their order and row width win
            $rows = [];
            $ordered = [];
            foreach (topup_invoice_ordered_keys($lang, 'ton') as $w) {
                $ordered[] = $made[$w];
            }
            foreach (array_chunk($ordered, max(1, topup_invoice_layout_perrow($lang, 'ton'))) as $chunk) {
                $rows[] = $chunk;
            }
        } else {
            // the arrangement it ships with: the three copy buttons share a
            // row because they are one job, everything else stands alone
            $rows = [
                [$made['pay']],
                [$made['copymemo'], $made['copyamt'], $made['copyaddr']],
                [$made['check']],
                [$made['backmethod']],
                [$made['back']],
            ];
        }
        return [
            'error' => null,
            'text' => $text,
            'keyboard' => json_encode(['inline_keyboard' => $rows]),
            'randomString' => $randomString,
        ];
    }
}
if (!function_exists('ton_incoming_transfers')) {
    // Incoming transfers to the shop's wallet, newest first, as
    // [comment => nanotons]. toncenter needs no key; a failure returns null so
    // the caller can tell "nothing arrived" from "could not look".
    function ton_incoming_transfers($address, $limit = 50)
    {
        // archival=true, not false: the keyless lite servers behind the plain
        // endpoint prune old blocks, so as soon as the wallet's last transaction
        // aged out they answered every call with
        //   HTTP 500 LITE_SERVER_UNKNOWN ... lt not in db
        // which this function turned into null - and the watcher reads null as
        // "could not look" and leaves every invoice alone. An archival node has
        // the whole chain, and answers 200.
        $url = 'https://toncenter.com/api/v2/getTransactions?address=' . rawurlencode($address)
            . '&limit=' . (int) $limit . '&archival=true';
        $ctx = stream_context_create(['http' => ['timeout' => 15]]);
        $raw = @file_get_contents($url, false, $ctx);
        $j = $raw === false ? null : json_decode($raw, true);
        if (!is_array($j) || empty($j['ok'])) {
            return null;
        }
        $out = [];
        foreach ((array) ($j['result'] ?? []) as $tx) {
            $in = $tx['in_msg'] ?? null;
            if (!is_array($in) || trim((string) ($in['source'] ?? '')) === '') {
                continue;   // outgoing, or a message with no sender
            }
            $comment = trim((string) ($in['message'] ?? ''));
            if ($comment === '') {
                continue;   // nothing to attribute it to
            }
            $value = (int) ($in['value'] ?? 0);
            // if the same comment arrives twice, the larger transfer wins
            if (!isset($out[$comment]) || $value > $out[$comment]) {
                $out[$comment] = $value;
            }
        }
        return $out;
    }
}
if (!function_exists('ton_payment_settled')) {
    // Has this order's transfer arrived? The customer may round up, and a
    // wallet may take a fee off the top, so anything within a small tolerance
    // under the asked-for figure counts - being strict here would reject
    // honest payments and strand the money in the shop's own wallet.
    function ton_payment_settled(array $row, $incoming)
    {
        if (!is_array($incoming)) {
            return false;
        }
        $comment = (string) $row['id_order'];
        if (!isset($incoming[$comment])) {
            return false;
        }
        $want = (int) $row['dec_not_confirmed'];
        if ($want < 1) {
            return false;
        }
        // 2% under, or 0.01 TON, whichever is larger
        $slack = max((int) round($want * 0.02), 10000000);
        return $incoming[$comment] >= ($want - $slack);
    }
}
if (!function_exists('topup_usd_rate')) {
    // rate_arze() makes two blocking HTTP calls, and the floor below is read
    // on admin screens as well as on the customer's path - so it is fetched
    // at most once per request.
    function topup_usd_rate()
    {
        static $rate = null;
        if ($rate !== null) {
            return $rate;
        }
        $r = rate_arze();
        $rate = (is_array($r) && !empty($r['USD'])) ? (float) $r['USD'] : 0.0;
        return $rate;
    }
    // "Does this gateway's wording have to explain the one-dollar rule?"
    //
    // The two processors that actually refuse a sub-dollar invoice, plus USDT:
    // Tether IS the dollar, so a one-dollar floor is the natural unit there and
    // the customer is better told the toman figure it works out to than left to
    // discover that 5,000 toman buys 0.02 of a coin.
    //
    // The rest of the online family has no dollar in the middle to floor
    // against: TON and TRX are paid straight into the shop's own wallet and
    // priced in toman, and Telegram Stars is invoiced in Telegram's own
    // currency - so quoting a dollar at their customers only ever confused them.
    function topup_has_usd_floor($key)
    {
        return $key === 'plisio' || $key === 'nowpayment' || $key === 'usdtbep';
    }
    // The smallest amount this gateway can actually put on an invoice, in
    // toman, or null when it has none - and null too when the rate cannot be
    // fetched, in which case nothing new is enforced and the old limits stand.
    //
    // This is the number the prompt shows, the number the refusal quotes, and
    // the number the invoice builder holds the customer to. They have to be the
    // same number or the customer is refused twice with two different figures.
    function topup_usd_floor_toman($lang, $key)
    {
        $rate = topup_usd_rate();
        if ($rate <= 0) {
            return null;
        }
        if (topup_has_usd_floor($key)) {
            return (int) ceil($rate);
        }
        // Telegram invoices Stars in whole stars, so one star is the real floor
        // here - not the dollar the code used to borrow from Plisio. Same
        // conversion star_invoice_build() itself uses.
        if ($key === 'startelegrams') {
            return (int) ceil($rate * 0.016);
        }
        return null;
    }
    // Payment_report and PaySetting each name the gateways differently; this
    // is the suffix its minbalance/maxbalance rows are stored under.
    function topup_gateway_paysetting_suffix($key)
    {
        return [
            'card' => 'cart',
            'plisio' => 'plisio',
            'nowpayment' => 'nowpayment',
            'startelegrams' => 'star',
            'ton' => 'ton',
            'trx' => 'trx',
            'usdtbep' => 'usdtbep',
            'digitaltron' => 'digitaltron',
            'zarinpal' => 'zarinpal',
            'aqayepardakht' => 'aqayepardakht',
            'iranpay1' => 'iranpay1',
            'iranpay2' => 'iranpay2',
            'iranpay3' => 'iranpay',
        ][$key] ?? null;
    }
    // What a gateway's limits are before anyone sets one. Without a default an
    // unset ceiling reads as zero and refuses every amount, and an unset floor
    // lets a one-toman top-up through.
    //
    // Every gateway now shares the same pair rather than only the ones with a
    // dollar floor: a gateway that was never configured used to end up with no
    // ceiling at all, which is not a deliberate "unlimited" - it is a gap.
    // A gateway that HAS its own number keeps it; these only fill the blank.
    function topup_default_max($key)
    {
        return 1000000;
    }
    function topup_default_min($key)
    {
        return 100;
    }
    function topup_effective_gateway_max($lang, $key, $configured)
    {
        $default = topup_default_max($key);
        if ($default === null) {
            return $configured;
        }
        return ($configured === null || $configured === '' || (float) $configured <= 0) ? $default : $configured;
    }
    // the floor the gateway itself enforces, for the same reason
    function topup_gateway_min($lang, $key)
    {
        $suffix = topup_gateway_paysetting_suffix($key);
        $raw = $suffix === null ? null : pay_value("minbalance{$suffix}", $lang, '');
        $v = topup_effective_gateway_min($lang, $key, $raw);
        return ($v === null || $v === '' || (float) $v <= 0) ? null : $v;
    }
    // the ceiling the gateway itself enforces, so the custom-amount step can
    // quote the same number the gateway will hold the customer to
    function topup_gateway_max($lang, $key)
    {
        $suffix = topup_gateway_paysetting_suffix($key);
        $raw = $suffix === null ? null : pay_value("maxbalance{$suffix}", $lang, '');
        $v = topup_effective_gateway_max($lang, $key, $raw);
        return ($v === null || $v === '' || (float) $v <= 0) ? null : $v;
    }
    // The limits the customer is actually held to on the custom-amount step.
    //
    // Order matters. The configured minimum is resolved FIRST, and only then is
    // the gateway's hard floor applied on top - a floor is a lower bound, not a
    // replacement. Applying it first (as this did) meant a gateway with no
    // entry in the top-up store never consulted the minimum the admin had set
    // in 💎 مالی at all: Telegram Stars, floored at one star, quoted 3,636 to
    // customers while its own screen said 20,000.
    function topup_effective_limits($lang, $key)
    {
        [$min, $max] = topup_minmax_for($lang, $key);
        // the gateway keeps its own range in 💎 مالی; without folding it in here
        // the refusal would quote a maximum of zero and then the gateway would
        // hold the customer to a different number anyway
        if ($min === null) {
            $min = topup_gateway_min($lang, $key);
        }
        if ($max === null) {
            $max = topup_gateway_max($lang, $key);
        }
        $floor = topup_usd_floor_toman($lang, $key);
        if ($floor !== null && ($min === null || (float) $min < $floor)) {
            $min = $floor;
        }
        return [$min, $max];
    }
    // ...and the same floor applied to the gateway's own minimum, so the two
    // checks a customer passes through can never disagree and refuse them
    // twice with two different numbers.
    function topup_effective_gateway_min($lang, $key, $configured)
    {
        // nothing configured: fall back to the shared floor rather than leaving
        // it blank, which let a one-toman top-up through
        if ($configured === null || $configured === '' || (float) $configured <= 0) {
            $configured = topup_default_min($key);
        }
        $floor = topup_usd_floor_toman($lang, $key);
        return ($floor !== null && (float) $configured < $floor) ? $floor : $configured;
    }
    // The defaults differ for a gateway with a floor: its wording has to name
    // the dollar as well as the toman, or the number looks arbitrary.
    function topup_range_default($key, $textbotlang)
    {
        $b = $textbotlang['users']['Balance'];
        return topup_has_usd_floor($key) ? $b['depositRangeOnline'] : $b['depositRange'];
    }
    function topup_custom_caption_default($key, $textbotlang)
    {
        $b = $textbotlang['users']['Balance'];
        return topup_has_usd_floor($key) ? $b['customAmountPromptTitleOnline'] : $b['customAmountPromptTitle'];
    }
    // "لطفاً فقط عدد وارد کنید" - per gateway, like every other text here
    function topup_notnumber_caption_for($lang, $key, $default)
    {
        $v = trim((string) (topup_gwstore_map('topup_notnumber_captions')[$lang][$key] ?? ''));
        return $v !== '' ? $v : $default;
    }
    function topup_notnumber_caption_has_override($lang, $key)
    {
        return trim((string) (topup_gwstore_map('topup_notnumber_captions')[$lang][$key] ?? '')) !== '';
    }
    function topup_notnumber_caption_set($lang, $key, $text)
    {
        topup_gwstore_set('topup_notnumber_captions', $lang, $key, $text);
    }
    // it replaces itself and takes the offending message with it, exactly
    // like the two amount refusals
    function topup_notnumber_notice($chat_id, $lang, $key, $textbotlang, $typedMessageId = 0)
    {
        topup_amount_notice($chat_id, topup_notnumber_caption_for($lang, $key, $textbotlang['users']['Balance']['errorprice']), $textbotlang, $typedMessageId);
    }
}
if (!function_exists('topup_checkout_limits')) {
    // The range a gateway's checkout holds an amount to. An amount typed on the
    // custom-amount step was already checked against that step's own range
    // (topup_effective_limits, which prefers «حداقل و حداکثر مبلغ دلخواه»), so it
    // is held to the very same numbers here - checkouts used to re-check it
    // against the raw 💎 مالی value instead, refusing it a second time with other
    // figures, and refusing everything when that value was empty.
    //
    // A package button is the admin's own amount, and the min/max screen tells
    // the admin packages are always selectable - so only the gateway's dollar
    // floor applies. Holding packages to the 💎 مالی range refused every package
    // over its 1,000,000 default. The method list keeps the gateway's own range.
    function topup_checkout_limits($lang, $key)
    {
        $origin = (string) ($GLOBALS['topup_amount_origin_step'] ?? '');
        if (strpos($origin, 'topup_pkg:') === 0) {
            return [topup_usd_floor_toman($lang, $key), null];
        }
        if (strpos($origin, 'topup_custom:') === 0) {
            return topup_effective_limits($lang, $key);
        }
        return [topup_gateway_min($lang, $key), topup_gateway_max($lang, $key)];
    }
    // a null bound is no bound; compared as numbers, never as strings
    function topup_amount_out_of_range($amount, $min, $max)
    {
        return ($min !== null && (float) $amount < (float) $min) || ($max !== null && (float) $amount > (float) $max);
    }
}
if (!function_exists('topup_minusd_caption_for')) {
    // the $1 floor message and the alert an already-paid invoice answers
    // with - per gateway, same contract as every other caption here
    function topup_minusd_caption_for($lang, $key, $default)
    {
        $v = trim((string) (topup_gwstore_map('topup_minusd_captions')[$lang][$key] ?? ''));
        return $v !== '' ? $v : $default;
    }
    function topup_minusd_caption_has_override($lang, $key)
    {
        return trim((string) (topup_gwstore_map('topup_minusd_captions')[$lang][$key] ?? '')) !== '';
    }
    function topup_minusd_caption_set($lang, $key, $text)
    {
        topup_gwstore_set('topup_minusd_captions', $lang, $key, $text);
    }
    function topup_paid_alert_for($lang, $key, $default)
    {
        // a popup is plain text: a <tg-emoji> saved here before showed up as code
        $v = trim(strip_tags((string) (topup_gwstore_map('topup_paid_alerts')[$lang][$key] ?? '')));
        return $v !== '' ? $v : $default;
    }
    function topup_paid_alert_has_override($lang, $key)
    {
        return trim((string) (topup_gwstore_map('topup_paid_alerts')[$lang][$key] ?? '')) !== '';
    }
    function topup_paid_alert_set($lang, $key, $text)
    {
        topup_gwstore_set('topup_paid_alerts', $lang, $key, $text);
    }
}
if (!function_exists('topup_hashbad_caption_for')) {
    // what a rejected hash is answered with, quoted on top of the prompt
    function topup_hashbad_caption_for($lang, $key, $default)
    {
        $v = trim((string) (topup_gwstore_map('topup_hashbad_captions')[$lang][$key] ?? ''));
        return $v !== '' ? $v : $default;
    }
    function topup_hashbad_caption_has_override($lang, $key)
    {
        return trim((string) (topup_gwstore_map('topup_hashbad_captions')[$lang][$key] ?? '')) !== '';
    }
    function topup_hashbad_caption_set($lang, $key, $text)
    {
        topup_gwstore_set('topup_hashbad_captions', $lang, $key, $text);
    }
}
if (!function_exists('topup_askhash_caption_for')) {
    // the prompt that asks a TRX customer for their transaction hash
    function topup_askhash_caption_for($lang, $key, $default)
    {
        $v = trim((string) (topup_gwstore_map('topup_askhash_captions')[$lang][$key] ?? ''));
        return $v !== '' ? $v : $default;
    }
    function topup_askhash_caption_has_override($lang, $key)
    {
        return trim((string) (topup_gwstore_map('topup_askhash_captions')[$lang][$key] ?? '')) !== '';
    }
    function topup_askhash_caption_set($lang, $key, $text)
    {
        topup_gwstore_set('topup_askhash_captions', $lang, $key, $text);
    }
}
if (!function_exists('topup_notseen_caption_for')) {
    // TON's own two: what "ثبت پرداخت" answers with when the transfer is not
    // on the chain yet, and what a customer sees if the gateway is on but has
    // no wallet behind it. Same contract as every other per-gateway text.
    function topup_notseen_caption_for($lang, $key, $default)
    {
        // shown as a popup (plain text) - see topup_paid_alert_for()
        $v = trim(strip_tags((string) (topup_gwstore_map('topup_notseen_captions')[$lang][$key] ?? '')));
        return $v !== '' ? $v : $default;
    }
    function topup_notseen_caption_has_override($lang, $key)
    {
        return trim((string) (topup_gwstore_map('topup_notseen_captions')[$lang][$key] ?? '')) !== '';
    }
    function topup_notseen_caption_set($lang, $key, $text)
    {
        topup_gwstore_set('topup_notseen_captions', $lang, $key, $text);
    }
    function topup_noaddress_caption_for($lang, $key, $default)
    {
        $v = trim((string) (topup_gwstore_map('topup_noaddress_captions')[$lang][$key] ?? ''));
        return $v !== '' ? $v : $default;
    }
    function topup_noaddress_caption_has_override($lang, $key)
    {
        return trim((string) (topup_gwstore_map('topup_noaddress_captions')[$lang][$key] ?? '')) !== '';
    }
    function topup_noaddress_caption_set($lang, $key, $text)
    {
        topup_gwstore_set('topup_noaddress_captions', $lang, $key, $text);
    }
}
if (!function_exists('topup_gwstore_map')) {
    // The shared {lang: {gatewayKey: text}} store behind the generic half of
    // the API above. One column per kind, cached per column.
    function topup_gwstore_map($column, $fresh = false)
    {
        static $cache = [];
        if (isset($cache[$column]) && !$fresh) {
            return $cache[$column];
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting[$column] ?? ''), true);
        $cache[$column] = is_array($m) ? $m : [];
        return $cache[$column];
    }
    function topup_gwstore_set($column, $lang, $key, $text)
    {
        $m = topup_gwstore_map($column, true);
        $text = trim((string) $text);
        if ($text === '') {
            unset($m[$lang][$key]);
            if (empty($m[$lang])) {
                unset($m[$lang]);
            }
        } else {
            $m[$lang][$key] = $text;
        }
        update("setting", $column, empty($m) ? '{}' : json_encode($m, JSON_UNESCAPED_UNICODE), null, null);
        topup_gwstore_map($column, true);
    }
}
if (!function_exists('svc_bytes_text')) {
    // ---- the service screen's own formatting ----
    // Bytes the way a customer reads them. GB below a terabyte, because that is
    // the unit every plan in this shop is sold in.
    function svc_bytes_text($bytes)
    {
        $b = (float) max(0, (int) $bytes);
        if ($b >= 1024 ** 4) {
            return number_format($b / 1024 ** 4, 2) . ' TB';
        }
        if ($b >= 1024 ** 3) {
            return number_format($b / 1024 ** 3, 2) . ' GB';
        }
        if ($b >= 1024 ** 2) {
            return number_format($b / 1024 ** 2, 1) . ' MB';
        }
        if ($b >= 1024) {
            return number_format($b / 1024, 0) . ' KB';
        }
        return ((int) $b) . ' B';
    }
    // The 20-cell bar above the usage line. An unlimited plan has no fraction to
    // draw, so it is shown full rather than empty - empty would read as "you
    // have nothing", which is the opposite of what unlimited means.
    function svc_usage_bar($used, $limit, $cells = 20)
    {
        $limit = (int) $limit;
        if ($limit <= 0) {
            return ['bar' => str_repeat('█', $cells), 'percent' => null, 'icon' => '🔋'];
        }
        $ratio = min(max(((float) $used) / $limit, 0), 1);
        $filled = (int) round($ratio * $cells);
        return [
            'bar' => str_repeat('█', $filled) . str_repeat('░', $cells - $filled),
            'percent' => round($ratio * 100, 1),
            'icon' => $ratio >= 0.9 ? '🪫' : ($ratio >= 0.5 ? '⚠️' : '🔋'),
        ];
    }
    // One location's row: a name, a proportional bar, its share, its bytes. The
    // share is of the total that was actually attributed to locations, not of
    // the plan - a plan can be unlimited, but the split still adds up to 100%.
    // $maxRows limits how many locations are drawn (heaviest first, since the
    // caller sorted them). The ones left out are summed into a single trailing
    // line, so the customer still sees that they exist.
    function svc_location_block(array $nodes, $textbotlang, $cells = 18, $maxRows = null)
    {
        $nodes = array_filter($nodes, fn($b) => (int) $b > 0);
        if (empty($nodes)) {
            return '';
        }
        // the share is always of the REAL total, not of the rows that survived
        // the trim - otherwise hiding the small locations would silently inflate
        // the big ones to 100%
        $total = array_sum($nodes);
        $hidden = 0;
        $hiddenBytes = 0;
        if ($maxRows !== null && $maxRows >= 0 && count($nodes) > $maxRows) {
            $keep = array_slice($nodes, 0, (int) $maxRows, true);
            $hiddenBytes = $total - array_sum($keep);
            $hidden = count($nodes) - count($keep);
            $nodes = $keep;
        }
        $lines = [];
        foreach ($nodes as $name => $bytes) {
            $ratio = $total > 0 ? $bytes / $total : 0;
            $filled = (int) round($ratio * $cells);
            // at least one cell for a location that carried anything at all,
            // so a small-but-real share is visible rather than a blank row
            if ($filled < 1) {
                $filled = 1;
            }
            // name / bar / share / bytes, each on its own line - the shape the
            // shop asked for, matching the screen they showed
            $lines[] = '● <b>' . htmlspecialchars((string) $name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</b>\n"
                . str_repeat('▰', $filled) . str_repeat('▱', max($cells - $filled, 0)) . "\n"
                . number_format($ratio * 100, 1) . "%\n"
                . svc_bytes_text($bytes);
        }
        if ($hidden > 0) {
            $b = $textbotlang['users']['status'] ?? [];
            $lines[] = strtr($b['svcLocationMore'] ?? '➕ و {n} لوکیشن دیگر ({volume})', [
                '{n}' => $hidden,
                '{volume}' => svc_bytes_text($hiddenBytes),
            ]);
        }
        return implode("\n\n", $lines);
    }
    // Persian tabs get a Jalali date, every other language a Gregorian one -
    // a Chinese or Russian customer reading "۱۴۰۶/۰۵/۰۵" learns nothing.
    function svc_expire_text($expire_ts, $lang, $textbotlang)
    {
        $b = $textbotlang['users']['status'] ?? [];
        if (empty($expire_ts)) {
            return $b['svcNoExpire'] ?? 'بدون انقضا ♾️';
        }
        $ts = (int) $expire_ts;
        if ($lang === 'fa') {
            return jdate('Y/m/d', $ts);
        }
        return date('Y/m/d', $ts);
    }
    // "6 مرداد 1405" for fa, "2026-07-28" elsewhere - used by the last-online
    // block, which shows a date and a clock time on separate lines.
    function svc_date_text($ts, $lang)
    {
        $ts = (int) $ts;
        if ($ts <= 0) {
            return '';
        }
        return $lang === 'fa' ? jdate('j F Y', $ts) : date('Y-m-d', $ts);
    }
    // How long ago, in words. Anything under two minutes is "just now", which is
    // what the screenshot shows for an active connection.
    function svc_ago_text($ts, $textbotlang)
    {
        $b = $textbotlang['users']['status'] ?? [];
        $diff = time() - (int) $ts;
        if ($diff < 120) {
            return $b['svcAgoNow'] ?? 'همین الان';
        }
        if ($diff < 3600) {
            return strtr($b['svcAgoMinutes'] ?? '{n} دقیقه پیش', ['{n}' => (int) ($diff / 60)]);
        }
        if ($diff < 86400) {
            return strtr($b['svcAgoHours'] ?? '{n} ساعت پیش', ['{n}' => (int) ($diff / 3600)]);
        }
        return strtr($b['svcAgoDays'] ?? '{n} روز پیش', ['{n}' => (int) ($diff / 86400)]);
    }
}
if (!function_exists('usage_report_keyboard')) {
    // The گزارش مصرف menu, built through statusbtn_render() so the colour, the
    // emoji, the name and the hide switch an admin sets in 🎨 شخصی‌سازی actually
    // reach it - the same treatment the service screen's own buttons get.
    // Hiding every report row would leave a screen with no way out, so the back
    // button is always drawn even if it was hidden.
    function usage_report_keyboard($lang, $textbotlang)
    {
        $rows = [];
        foreach ([
            'ur_yesterday' => 'usagerep|usage_1',
            'ur_2days' => 'usagerep|usage_2',
            'ur_10days' => 'usagerep|usage_10',
            'ur_all' => 'usagerep|usage_all',
        ] as $key => $cb) {
            if (statusbtn_is_hidden($lang, $key)) {
                continue;
            }
            $b = statusbtn_render($lang, $key, $textbotlang);
            $b['callback_data'] = $cb;
            $rows[] = [$b];
        }
        $back = statusbtn_render($lang, 'ur_back', $textbotlang);
        $back['callback_data'] = 'productcheckdata';
        $rows[] = [$back];
        return json_encode(['inline_keyboard' => $rows]);
    }
}
if (!function_exists('svc_send_status_screen')) {
    // The service screen is a QR photo now, and Telegram cannot turn a text
    // message into a photo by editing it. So: try to edit the caption first -
    // that is the cheap path, and it is the one taken every time the customer
    // taps "بارگذاری مجدد" on a screen that is already a photo, leaving the
    // message where it is in the chat. Only when that fails (the message is
    // still text, i.e. the customer just arrived from the service list) does it
    // fall back to deleting and re-sending.
    //
    // A panel with no scannable subscription link (WGDashboard hands out a
    // config file, ibsng/mikrotik have none at all) keeps the old plain-text
    // screen rather than being given a QR of nothing.
    function svc_send_status_screen($chat_id, $message_id, $caption, $keyboard, $subscription_url, $force_new = false)
    {
        $url = (string) $subscription_url;
        $qrable = $url !== '' && strpos($url, 'http') === 0;
        if (!$qrable) {
            if ($force_new) {
                deletemessage($chat_id, $message_id);
                sendmessage($chat_id, $caption, $keyboard, 'html');
            } else {
                Editmessagetext($chat_id, $message_id, $caption, $keyboard);
            }
            return;
        }
        if (!$force_new && $message_id) {
            $res = telegram('editMessageCaption', [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'caption' => $caption,
                'reply_markup' => $keyboard,
                'parse_mode' => 'HTML',
            ]);
            if (!empty($res['ok'])) {
                return;   // it was already a photo - nothing moved
            }
            // "message is not modified" means the screen is already showing
            // exactly this; re-sending it would be a pointless jump
            if (strpos((string) ($res['description'] ?? ''), 'not modified') !== false) {
                return;
            }
        }
        if ($message_id) {
            deletemessage($chat_id, $message_id);
        }
        $tmp = sys_get_temp_dir() . '/svcqr_' . $chat_id . '_' . bin2hex(random_bytes(3)) . '.png';
        $ok = false;
        try {
            $qrCode = createqrcode($url);
            file_put_contents($tmp, $qrCode->getString());
            addBackgroundImage($tmp, $qrCode, __DIR__ . '/images.jpg');
            $ok = is_file($tmp) && filesize($tmp) > 0;
        } catch (Throwable $e) {
            $ok = false;
        }
        if (!$ok) {
            // a QR that could not be drawn must not cost the customer the whole
            // screen - fall back to the text version
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            sendmessage($chat_id, $caption, $keyboard, 'html');
            return;
        }
        telegram('sendphoto', [
            'chat_id' => $chat_id,
            'photo' => new CURLFile($tmp),
            'reply_markup' => $keyboard,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ]);
        @unlink($tmp);
    }
}
if (!function_exists('svc_daily_usage')) {
    // ---- day-by-day history, for the گزارش مصرف screen ----
    // This comes from the SUBSCRIPTION link rather than the admin API: the
    // panel's /sub/<token>/usage answers with {"usages":[{"date","used_traffic"}],
    // "node_usages":[...]} and needs no admin credentials. The admin endpoint
    // used by panel_user_usage() returns the per-node split only.
    //
    // Returns ['supported'=>bool,'daily'=>[Y-m-d => bytes],'error'=>string|null].
    // A panel that cannot answer is 'supported' => false, which is what makes
    // the گزارش مصرف button explain itself instead of opening an empty screen.
    function svc_daily_usage($subscription_url, $panel_type = null)
    {
        if ($panel_type !== null && !panel_usage_supported($panel_type)) {
            return ['supported' => false, 'daily' => [], 'error' => null];
        }
        $url = rtrim((string) $subscription_url, '/');
        if ($url === '' || strpos($url, 'http') !== 0) {
            return ['supported' => false, 'daily' => [], 'error' => 'no subscription url'];
        }
        $raw = @file_get_contents($url . '/usage', false, stream_context_create([
            'http' => ['timeout' => 15, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]));
        $j = $raw === false ? null : json_decode($raw, true);
        if (!is_array($j) || !isset($j['usages'])) {
            return ['supported' => false, 'daily' => [], 'error' => 'unreadable'];
        }
        $daily = [];
        foreach ((array) $j['usages'] as $row) {
            if (!is_array($row) || empty($row['date'])) {
                continue;
            }
            $daily[(string) $row['date']] = (int) ($row['used_traffic'] ?? 0);
        }
        ksort($daily);
        return ['supported' => true, 'daily' => $daily, 'error' => null];
    }
}
if (!function_exists('svc_status_blocks')) {
    // The pieces the service caption is assembled from. Kept here rather than in
    // index.php so the same blocks can be reused by anything else that shows a
    // service (the alert screens, a future admin preview) without copying them.
    function svc_status_blocks(array $DataUserOut, array $usage, $lang, $textbotlang)
    {
        $b = $textbotlang['users']['status'] ?? [];
        $limit = (int) ($DataUserOut['data_limit'] ?? 0);
        $used = (int) ($DataUserOut['used_traffic'] ?? 0);
        $bar = svc_usage_bar($used, $limit);

        // "20.48 GB مصرف شده از نامحدود ♾️" / "… از 50 GB (41%)"
        $unlimited = $b['svcUnlimited'] ?? 'نامحدود ♾️';
        if ($limit > 0) {
            $usageLine = strtr($b['svcUsageOf'] ?? '🎛 {used} مصرف شده از {total} ({percent}%)', [
                '{used}' => svc_bytes_text($used),
                '{total}' => svc_bytes_text($limit),
                '{percent}' => (string) $bar['percent'],
            ]);
        } else {
            $usageLine = strtr($b['svcUsageOfUnlimited'] ?? '🎛 {used} مصرف شده از {total}', [
                '{used}' => svc_bytes_text($used),
                '{total}' => $unlimited,
            ]);
        }

        // just the rows: the heading is a normal line of the admin's own
        // template now, so they can reword, restyle or move the whole section -
        // svc_apply_location() takes that heading away with the rows when a
        // panel has nothing to report
        $locBlock = '';
        if (!empty($usage['supported'])) {
            $locBlock = svc_location_block((array) ($usage['nodes'] ?? []), $textbotlang);
        }

        // last online: a date line and a clock line, or a single "never" line
        $onlineRaw = $DataUserOut['online_at'] ?? null;
        $ts = 0;
        if (!empty($onlineRaw)) {
            $ts = is_numeric($onlineRaw) ? (int) $onlineRaw : (int) strtotime(str_replace('/', '-', (string) $onlineRaw));
        }
        if ($ts > 0) {
            $onlineBlock = strtr($b['svcOnlineBlock'] ?? "تاریخ ← {date}\nساعت ← {time} ({ago})", [
                '{date}' => svc_date_text($ts, $lang),
                '{time}' => date('H:i', $ts),
                '{ago}' => svc_ago_text($ts, $textbotlang),
            ]);
        } else {
            $onlineBlock = $b['svcNeverOnline'] ?? 'متصل نشده';
        }

        return [
            'usage_bar' => $bar['icon'] . ' <code>' . $bar['bar'] . '</code>',
            'usage_line' => $usageLine,
            'location_block' => $locBlock,
            'online_block' => $onlineBlock,
            'total_text' => $limit > 0 ? svc_bytes_text($limit) : $unlimited,
            'used_text' => svc_bytes_text($used),
        ];
    }
}
if (!function_exists('svc_apply_location')) {
    // {location_block} is the location section's own on/off switch inside the
    // caption. The heading above it is ordinary template text, so the admin can
    // reword it, restyle it or move the whole section - which means that when a
    // panel reports nothing, the heading has to go with it or it would sit
    // there over an empty space.
    //
    // So: {location_block} ALONE on its line is a whole section. Empty, it takes
    // its own line and the unbroken run of lines above it (its heading) away,
    // and the blank lines that met around the hole collapse into one. With
    // rows, they arrive one blank line under the heading.
    //
    // {location_block} sharing a line with other text is left as it was: the
    // token is simply replaced (with nothing when empty) and the line stays -
    // that is what the older templates do, and they must keep working.
    function svc_apply_location($template, $block)
    {
        $out = [];
        foreach (explode("\n", $template) as $line) {
            if (strpos($line, '{location_block}') === false) {
                $out[] = $line;
                continue;
            }
            if (trim(str_replace('{location_block}', '', $line)) !== '') {
                $out[] = str_replace('{location_block}', $block === '' ? '' : "\n" . $block, $line);
                continue;
            }
            if ($block !== '') {
                $out[] = "\n" . $block;
                continue;
            }
            while (!empty($out) && trim((string) end($out)) !== '') {
                array_pop($out);
            }
        }
        return preg_replace("/\n{3,}/", "\n\n", implode("\n", $out));
    }
}
if (!function_exists('svc_build_caption')) {
    // Assembles the service caption and makes sure it FITS.
    //
    // A photo caption is capped at 1024 characters by Telegram, and a location
    // row costs about 49 of them. Measured against this shop's own panel: seven
    // locations already take 773, so a shop with thirteen nodes would push the
    // caption over the cap - and Telegram does not truncate, it refuses the
    // whole message. The service screen would simply stop appearing.
    //
    // So rather than freezing a row count, this drops the lightest locations
    // one at a time until the finished caption fits, and says how many were
    // left out. A shop with few nodes loses nothing; a shop with many keeps the
    // ones that actually carry its traffic.
    function svc_build_caption($template, array $vars, array $nodes, $textbotlang, $limit = 1024)
    {
        $nodes = array_filter($nodes, fn($b) => (int) $b > 0);
        $rows = count($nodes);
        for ($try = $rows; $try >= 0; $try--) {
            $block = $try > 0 ? svc_location_block($nodes, $textbotlang, 18, $try) : '';
            $caption = strtr(svc_apply_location($template, $block), $vars);
            if (mb_strlen($caption) <= $limit) {
                return $caption;
            }
        }
        // Even with no locations at all it does not fit: the admin has written a
        // caption longer than Telegram allows. Hand back the shortest version we
        // can build and let the caller deal with it - silently dropping their
        // text would be worse than a visible failure.
        return strtr(svc_apply_location($template, ''), $vars);
    }
}
if (!function_exists('svc_usage_report_text')) {
    // The four views behind گزارش مصرف, matching the report the shop already
    // had in its own tooling: one named day, or the whole history.
    function svc_usage_report_text($mode, array $daily, $lang, $textbotlang)
    {
        $b = $textbotlang['users']['status'] ?? [];
        $dayOffsets = ['usage_1' => 1, 'usage_2' => 2, 'usage_10' => 10];
        if (isset($dayOffsets[$mode])) {
            $date = date('Y-m-d', strtotime("-{$dayOffsets[$mode]} day"));
            $bytes = (int) ($daily[$date] ?? 0);
            $shown = $lang === 'fa' ? jdate('Y/m/d', strtotime($date)) : $date;
            if ($bytes <= 0) {
                return strtr($b['svcReportEmpty'] ?? '💭 در تاریخ {date} هیچ مصرفی ثبت نشده است.', ['{date}' => $shown]);
            }
            return strtr($b['svcReportOneDay'] ?? "<blockquote><b>📊 مصرف {date}</b></blockquote>\n\n💾 مجموع مصرف: <b>{amount}</b>", [
                '{date}' => $shown,
                '{amount}' => svc_bytes_text($bytes),
            ]);
        }
        // the whole history, heaviest day scaled to a full bar
        $active = array_filter($daily, fn($v) => (int) $v > 0);
        if (empty($active)) {
            return $b['svcReportNothingYet'] ?? '💭 هنوز هیچ مصرفی برای این سرویس ثبت نشده است.';
        }
        $max = max($active);
        $out = "<blockquote><b>" . ($b['svcReportAllTitle'] ?? '📊 گزارش کل مصرف') . "</b></blockquote>\n\n";
        $out .= strtr($b['svcReportSummary'] ?? "🟢 روزهای فعال: <b>{days}</b>\n💾 مجموع مصرف: <b>{total}</b>", [
            '{days}' => count($active),
            '{total}' => svc_bytes_text(array_sum($active)),
        ]) . "\n\n";
        // newest first: the day a customer cares about most is today
        foreach (array_reverse($active, true) as $date => $bytes) {
            $filled = max(1, (int) round(($bytes / $max) * 10));
            $shown = $lang === 'fa' ? jdate('Y/m/d', strtotime($date)) : $date;
            $out .= "• {$shown}\n<code>" . str_repeat('▰', $filled) . str_repeat('▱', 10 - $filled) . '</code> '
                . svc_bytes_text($bytes) . "\n\n";
        }
        return rtrim($out);
    }
}
if (!function_exists('topup_memo_config')) {
    // ---- what an invoice's memo (comment / tag) is made of ----
    //
    // The memo IS the order id: ton_payment_settled() looks the incoming
    // transfer up by the exact comment string, so whatever is built here is
    // what gets stored in Payment_report.id_order and what the watcher then
    // matches on. Keeping them one and the same is what makes changing this
    // setting safe for invoices that are already open - each one carries its
    // own memo in the row, so it keeps matching under the old shape.
    //
    // Only two parts are offered, and the omissions are deliberate:
    //   - an optional shop prefix, so a customer scanning their wallet history
    //     can tell what the payment was for
    //   - a random part, which is never optional; it is the only thing making
    //     one invoice distinguishable from another
    // The customer's Telegram id and username are NOT available as parts. A
    // TON comment is written to a public chain and stays there for good, so
    // putting either one in it would publish, permanently and for anybody to
    // read off the shop's own wallet, which Telegram account paid this shop
    // what and when. See topup_memo_locked_reason().
    function topup_memo_defaults()
    {
        return ['prefix' => '', 'len' => 10];
    }
    function topup_memo_limits()
    {
        // 12 + 16 = 28 characters at most, and "toncheck:" + 28 is well inside
        // the 64-byte callback_data budget the check button has to fit in
        return ['prefixMax' => 12, 'lenMin' => 6, 'lenMax' => 16];
    }
    function topup_memo_config($lang, $key)
    {
        $m = topup_gwstore_map('topup_memo_config');
        $raw = $m[$lang][$key] ?? null;
        $cfg = topup_memo_defaults();
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (is_array($raw)) {
            if (isset($raw['prefix'])) {
                $cfg['prefix'] = (string) $raw['prefix'];
            }
            if (isset($raw['len'])) {
                $cfg['len'] = (int) $raw['len'];
            }
        }
        $lim = topup_memo_limits();
        $cfg['prefix'] = topup_memo_clean_prefix($cfg['prefix']);
        $cfg['len'] = max($lim['lenMin'], min($lim['lenMax'], (int) $cfg['len']));
        return $cfg;
    }
    // Letters and digits only. The memo travels through a URL query string, a
    // wallet's comment field and finally a callback_data regex - a separator
    // or a non-ASCII character would survive some of those and not others.
    function topup_memo_clean_prefix($prefix)
    {
        $p = preg_replace('/[^A-Za-z0-9]/', '', (string) $prefix);
        return substr((string) $p, 0, topup_memo_limits()['prefixMax']);
    }
    function topup_memo_set($lang, $key, $field, $value)
    {
        $cfg = topup_memo_config($lang, $key);
        $cfg[$field] = $field === 'len' ? (int) $value : topup_memo_clean_prefix($value);
        $def = topup_memo_defaults();
        // back to the factory shape means no stored row at all, so a reset
        // leaves the store as clean as a fresh install
        if ($cfg['prefix'] === $def['prefix'] && (int) $cfg['len'] === (int) $def['len']) {
            topup_gwstore_set('topup_memo_config', $lang, $key, '');
            return;
        }
        topup_gwstore_set('topup_memo_config', $lang, $key, json_encode($cfg));
    }
    function topup_memo_reset($lang, $key)
    {
        topup_gwstore_set('topup_memo_config', $lang, $key, '');
    }
    function topup_memo_is_custom($lang, $key)
    {
        $m = topup_gwstore_map('topup_memo_config');
        return isset($m[$lang][$key]);
    }
    // The random half is drawn from random_bytes, not rand(): a guessable memo
    // would let somebody watching the chain work out another customer's memo
    // before they paid it.
    //
    // It is also checked against the orders already issued. At the default 10
    // characters a clash is not worth thinking about, but the admin may shorten
    // it to 6 - and two invoices sharing a memo is not a cosmetic problem: the
    // watcher would settle whichever row it read first, crediting the wrong
    // customer. Cheap to rule out, so it is ruled out.
    function topup_memo_build($lang, $key)
    {
        global $pdo;
        $cfg = topup_memo_config($lang, $key);
        $memo = '';
        for ($try = 0; $try < 6; $try++) {
            $hex = bin2hex(random_bytes((int) ceil($cfg['len'] / 2)));
            $memo = $cfg['prefix'] . substr($hex, 0, (int) $cfg['len']);
            $taken = $pdo->prepare("SELECT 1 FROM Payment_report WHERE id_order = ? LIMIT 1");
            $taken->execute([$memo]);
            if ($taken->fetchColumn() === false) {
                return $memo;
            }
        }
        // six clashes in a row means the random part is far too short for how
        // many orders this shop has - fall back to the full-length default
        // rather than handing back a memo already in use
        return $cfg['prefix'] . bin2hex(random_bytes(8));
    }
    // Shown when an admin taps one of the two parts that are not on offer.
    // answerCallbackQuery truncates past 200 characters, so the short form is
    // the one that has to fit; the settings screen's own caption carries the
    // longer version where there is room for it.
    function topup_memo_locked_reason()
    {
        return "⛔️ به دلایل امنیتی در دسترس نیست.\n\n"
            . "کامنت تراکنش روی بلاکچین TON برای همیشه عمومی می‌ماند. با آیدی یا یوزرنیم "
            . "داخلش، هر کسی از روی کیف پول شما می‌فهمد کدام حساب تلگرام چه مبلغی پرداخت کرده.";
    }
}
if (!function_exists('topup_invoice_layout_get')) {
    // ---- the arrangement of an invoice's own buttons ----
    // Stored beside the per-button styles, under a name no real button can
    // have, so the styling handlers (which only match [a-z]+) can never write
    // to it by accident.
    function topup_invoice_layout_get($lang, $key)
    {
        $v = topup_invoice_btnstyle_for($lang, $key, '_layout');
        return is_array($v) ? $v : [];
    }
    function topup_invoice_layout_set($lang, $key, array $layout)
    {
        topup_invoice_btnstyle_set($lang, $key, '_layout', $layout);
    }
    // 0 means "nothing has been set" - the builder then uses the arrangement it
    // ships with, which is shaped to the invoice rather than to a single number.
    function topup_invoice_layout_perrow($lang, $key)
    {
        $n = (int) (topup_invoice_layout_get($lang, $key)['perRow'] ?? 0);
        return $n > 0 ? $n : 1;
    }
    function topup_invoice_layout_touched($lang, $key)
    {
        $l = topup_invoice_layout_get($lang, $key);
        return !empty($l['order']) || !empty($l['perRow']);
    }
    // The buttons that share one keyboard, in the order they are shown. Only
    // these can be rearranged; reissue and paid live on their own keyboards.
    function topup_invoice_layout_keys($key)
    {
        if ($key === 'ton') {
            return ['pay', 'copymemo', 'copyamt', 'copyaddr', 'check', 'backmethod', 'back'];
        }
        if ($key === 'trx' || $key === 'usdtbep') {
            return ['copyamt', 'copyaddr', 'check', 'backmethod', 'back'];
        }
        // card-to-card arranges its own copy buttons on its own screen, which
        // knows about cards; the shared one would only get in the way
        if ($key === 'card') {
            return [];
        }
        return ['pay'];
    }
    function topup_invoice_ordered_keys($lang, $key)
    {
        $all = topup_invoice_layout_keys($key);
        $saved = (array) (topup_invoice_layout_get($lang, $key)['order'] ?? []);
        $out = [];
        foreach ($saved as $w) {
            if (in_array($w, $all, true) && !in_array($w, $out, true)) {
                $out[] = $w;
            }
        }
        // anything added to the gateway since the admin last arranged it goes
        // on the end rather than disappearing
        foreach ($all as $w) {
            if (!in_array($w, $out, true)) {
                $out[] = $w;
            }
        }
        return $out;
    }
    // swaps two buttons' places, leaving every other setting alone
    function topup_invoice_layout_swap($lang, $key, $a, $b)
    {
        $order = topup_invoice_ordered_keys($lang, $key);
        $ia = array_search($a, $order, true);
        $ib = array_search($b, $order, true);
        if ($ia === false || $ib === false) {
            return;
        }
        [$order[$ia], $order[$ib]] = [$order[$ib], $order[$ia]];
        $l = topup_invoice_layout_get($lang, $key);
        $l['order'] = $order;
        topup_invoice_layout_set($lang, $key, $l);
    }
}
if (!function_exists('topup_invoice_btnstyle_items')) {
    // The buttons a gateway's invoice actually carries. Card-to-card's invoice
    // is built in the chat and has a row of its own (copy-card, receipt sent,
    // reissue), which is why it keeps its own screen; the online gateways all
    // show the same two, so they share one.
    function topup_invoice_btnstyle_items($key, $textbotlang, $lang = null)
    {
        $b = $textbotlang['users']['Balance'];
        if ($key === 'card' && function_exists('card_invoice_btnstyle_items')) {
            // card-to-card has one copy button per card, so its list is built
            // from the cards that language actually has
            return card_invoice_btnstyle_items($textbotlang, $lang);
        }
        if ($key === 'trx') {
            // no wallet-opening button: TRON has no deep link every wallet
            // honours, so the two values are copied instead
            return [
                'copyamt' => $b['trxCopyAmountBtn'],
                'copyaddr' => $b['trxCopyAddressBtn'],
                'check' => $b['trxCheckBtn'],
                'backmethod' => $b['tonBackMethodBtn'],
                'back' => $b['tonBackBtn'],
                'reissue' => $b['reissueInvoiceBtn'],
                'paid' => $b['paidInvoiceBtn'],
            ];
        }
        if ($key === 'usdtbep') {
            // same shape as TRX, for the same reason: no deep link every BSC
            // wallet honours, so the amount and the address are copied instead
            return [
                'copyamt' => $b['usdtbepCopyAmountBtn'],
                'copyaddr' => $b['usdtbepCopyAddressBtn'],
                'check' => $b['usdtbepCheckBtn'],
                'backmethod' => $b['tonBackMethodBtn'],
                'back' => $b['tonBackBtn'],
                'reissue' => $b['reissueInvoiceBtn'],
                'paid' => $b['paidInvoiceBtn'],
            ];
        }
        if ($key === 'ton') {
            // its invoice is paid by hand from a wallet, so it carries the
            // copy buttons and the two ways back that the redirect gateways
            // have no use for
            return [
                'pay' => $b['tonOpenWalletBtn'],
                'copymemo' => $b['tonCopyMemoBtn'],
                'copyamt' => $b['tonCopyAmountBtn'],
                'copyaddr' => $b['tonCopyAddressBtn'],
                'check' => $b['tonCheckBtn'],
                'backmethod' => $b['tonBackMethodBtn'],
                'back' => $b['tonBackBtn'],
                'reissue' => $b['reissueInvoiceBtn'],
                'paid' => $b['paidInvoiceBtn'],
            ];
        }
        return [
            'pay' => $textbotlang['users']['Balance']['payments'],
            'reissue' => $textbotlang['users']['Balance']['reissueInvoiceBtn'],
            'paid' => $textbotlang['users']['Balance']['paidInvoiceBtn'],
        ];
    }
    // What each button looks like before an admin touches it. A button with
    // no colour renders white, which reads as "not a real button" - so the
    // pay button is blue from a fresh install, not colourless.
    function topup_invoice_btnstyle_default_color($which, $key = null)
    {
        if ($key === 'card' && function_exists('card_invoice_btnstyle_default_color')) {
            // card-to-card has had its own defaults since before this existed -
            // blue copy buttons, a green receipt button, a red reissue - and the
            // styling screens have to show the customer's colours, not ours
            $c = card_invoice_btnstyle_default_color($which);
            if ($c !== '') {
                return $c;
            }
            // 'paid' is not one of card's own buttons - the shared map below
            // is what actually renders it, so it decides the colour too
        }
        if ($key === 'trx' || $key === 'usdtbep') {
            // same convention as TON: green moves the payment forward, blue
            // only copies, red abandons the invoice
            return ['check' => 'success', 'back' => 'danger', 'reissue' => 'danger', 'paid' => 'success'][$which] ?? 'primary';
        }
        if ($key === 'ton') {
            // green moves the payment forward, blue only copies, red abandons
            // the invoice - and '' is a real choice, not a missing one: the
            // wallet button leaves Telegram, and white sets it apart from the
            // coloured ones that act inside the chat
            return array_key_exists($which, $tonDefaults = [
                'pay' => '',
                'check' => 'success',
                'back' => 'danger',
                'reissue' => 'danger',
                'paid' => 'success',
            ]) ? $tonDefaults[$which] : 'primary';
        }
        return ['reissue' => 'danger', 'paid' => 'success'][$which] ?? 'primary';
    }
    function topup_invoice_btnstyle_map_of($lang, $key)
    {
        if ($key === 'plisio') {
            return plisio_invoice_btnstyle_map()[$lang] ?? [];
        }
        return topup_gwstore_btnstyle_map()[$lang][$key] ?? [];
    }
    function topup_invoice_btnstyle_for($lang, $key, $which)
    {
        if ($key === 'plisio') {
            return plisio_invoice_btnstyle_for($lang, $which);
        }
        if ($key === 'card') {
            return card_invoice_btnstyle_for($lang, $which);
        }
        return topup_gwstore_btnstyle_map()[$lang][$key][$which] ?? [];
    }
    function topup_invoice_btnstyle_set($lang, $key, $which, array $style)
    {
        if ($key === 'plisio') {
            plisio_invoice_btnstyle_set($lang, $which, $style);
            return;
        }
        $map = topup_gwstore_btnstyle_map(true);
        $map[$lang][$key][$which] = $style;
        update("setting", "topup_invoice_btnstyle", json_encode($map, JSON_UNESCAPED_UNICODE), null, null);
        topup_gwstore_btnstyle_map(true);
    }
    function topup_gwstore_btnstyle_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['topup_invoice_btnstyle'] ?? ''), true);
        $cache = is_array($m) ? $m : [];
        return $cache;
    }
}

if (!function_exists('topup_expire_minutes')) {
    // The setting each gateway keeps its invoice lifetime in. Anything not
    // listed has no invoice to expire.
    function topup_expire_field($key)
    {
        return [
            'card' => 'cardInvoiceExpireMinutes',
            'plisio' => 'plisioInvoiceExpireMinutes',
            'nowpayment' => 'nowpaymentInvoiceExpireMinutes',
            'startelegrams' => 'starInvoiceExpireMinutes',
            'ton' => 'tonInvoiceExpireMinutes',
            'trx' => 'trxInvoiceExpireMinutes',
            'usdtbep' => 'usdtbepInvoiceExpireMinutes',
            'digitaltron' => 'digitaltronInvoiceExpireMinutes',
        ][$key] ?? null;
    }
    // How long this gateway's invoice stays payable, set per language on the
    // gateway's own screen. Kept in one place so the number a caption promises
    // and the number cronbot/payment_expire.php enforces cannot drift apart.
    function topup_expire_minutes($lang, $key)
    {
        // One number for every gateway. TON and TRX used to start at 15 because
        // they quote an exact amount of coin against a moving price, but a
        // single default is what an admin can actually reason about - and any
        // gateway that wants a shorter window still has its own per-language
        // field on its settings screen.
        $default = 30;
        $field = topup_expire_field($key);
        $m = $field === null ? $default : (int) pay_value($field, $lang, $default);
        return $m > 0 ? $m : $default;
    }
}
if (!function_exists('nowpayment_invoice_build')) {
    // Same contract as plisio_invoice_build(): a pure builder that talks to the
    // payment provider and returns the invoice text, keyboard and order id -
    // no Telegram calls of its own. Shared by the checkout and by the "ساخت
    // فاکتور جدید" button an expired invoice grows.
    // Never execute this in a test: it calls the live rate and NowPayments
    // APIs. Source-verify only, the same rule the other builders carry.
    function nowpayment_invoice_build($from_id, $lang, $amount, $idInvoice, $textbotlang, array $setting)
    {
        global $pdo;
        $rates = rate_arze();
        if ($rates === null) {
            return ['error' => 'rate'];
        }
        $usd = $rates['USD'];
        $usdprice = $amount / $usd;
        // the same floor Plisio has always had: an invoice under a dollar is
        // below what the processors will settle, and a 0-star invoice is not
        // payable at all
        // strictly below: a dollar exactly is the minimum, not the first
        // amount over it - the message names $1 as what is required
        if ($usdprice < 1) {
            return ['error' => 'toolow', 'usd' => $usd];
        }
        $randomString = bin2hex(random_bytes(5));
        $pay = nowPayments('invoice', $usdprice, $randomString, 'TopUp - ' . $from_id);
        $dateacc = date('Y/m/d H:i:s');
        // the row is written before the response is checked, exactly as the
        // original inline handler did - the failure path below still reports it
        $stmt = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice,dec_not_confirmed) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$from_id, $randomString, $dateacc, $amount, "Unpaid", "nowpayment", $idInvoice, $pay['id'] ?? null]);
        if (!isset($pay['id'])) {
            return ['error' => 'api', 'apiMessage' => json_encode($pay)];
        }
        $captionTemplate = topup_invoice_caption_for($lang, 'nowpayment', $textbotlang['users']['Balance']['nowpaymentInvoiceCaption']);
        $text = strtr($captionTemplate, [
            '{order}' => $randomString,
            '{price}' => number_format($amount, 0),
            '{usd}' => number_format($usd),
            '{minutes}' => topup_expire_minutes($lang, 'nowpayment'),
        ]);
        $text .= topup_disc_caption_block($from_id, $lang, 'nowpayment', $textbotlang, $amount, true);
        $payBtn = topup_styled_button($textbotlang['users']['Balance']['payments'], topup_invoice_btnstyle_for($lang, 'nowpayment', 'pay'), '', topup_invoice_btnstyle_default_color('pay'));
        $payBtn['url'] = $pay['invoice_url'];
        return [
            'error' => null,
            'text' => $text,
            'keyboard' => json_encode(['inline_keyboard' => [[$payBtn]]]),
            'randomString' => $randomString,
        ];
    }
}
if (!function_exists('star_invoice_build')) {
    // Telegram Stars, same contract as the two builders above.
    // Never execute this in a test: it calls the live rate API and
    // createInvoiceLink. Source-verify only.
    function star_invoice_build($from_id, $lang, $amount, $idInvoice, $textbotlang, array $setting)
    {
        global $pdo;
        $rates = rate_arze(['USD', 'Ton']);
        if ($rates === null) {
            return ['error' => 'rate'];
        }
        $usd = $rates['USD'];
        $perStar = $usd * 0.016;
        if ($perStar <= 0) {
            return ['error' => 'rate'];
        }
        $starAmount = intval($amount / $perStar);
        // one whole star is Telegram's own minimum, and now the only one: the
        // extra "under a dollar" rule that used to sit here was borrowed from
        // the crypto processors, which is not what this gateway settles in. It
        // also disagreed with the minimum the amount screen quoted.
        if ($starAmount < 1) {
            return ['error' => 'toolow', 'usd' => $usd];
        }
        $randomString = bin2hex(random_bytes(5));
        $dateacc = date('Y/m/d H:i:s');
        $stmt = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$from_id, $randomString, $dateacc, $amount, "Unpaid", "Star Telegram", $idInvoice]);
        $link = telegram('createInvoiceLink', [
            'title' => "Buy for Price {$amount}",
            'description' => "Buy price",
            'payload' => $randomString,
            'currency' => "XTR",
            'prices' => json_encode([['label' => "Price", 'amount' => $starAmount]]),
        ]);
        if (empty($link['ok'])) {
            return ['error' => 'api', 'apiMessage' => json_encode($link)];
        }
        $captionTemplate = topup_invoice_caption_for($lang, 'startelegrams', $textbotlang['users']['Balance']['starInvoiceCaption']);
        $text = strtr($captionTemplate, [
            '{order}' => $randomString,
            '{stars}' => $starAmount,
            '{price}' => number_format($amount, 0),
            '{minutes}' => topup_expire_minutes($lang, 'startelegrams'),
        ]);
        $text .= topup_disc_caption_block($from_id, $lang, 'startelegrams', $textbotlang, $amount, true);
        $payBtn = topup_styled_button($textbotlang['users']['Balance']['payments'], topup_invoice_btnstyle_for($lang, 'startelegrams', 'pay'), '', topup_invoice_btnstyle_default_color('pay'));
        $payBtn['url'] = $link['result'];
        return [
            'error' => null,
            'text' => $text,
            'keyboard' => json_encode(['inline_keyboard' => [[$payBtn]]]),
            'randomString' => $randomString,
        ];
    }
}
if (!function_exists('topup_gateway_key_by_method')) {
    // Payment_report keeps each gateway under its own historical name; this
    // is the whole map back to the key the caption and button stores use.
    function topup_gateway_key_by_method($method)
    {
        $m = [
            'cart to cart' => 'card',
            'plisio' => 'plisio',
            'nowpayment' => 'nowpayment',
            'Star Telegram' => 'startelegrams',
            'TON' => 'ton',
            'TRX' => 'trx',
            'USDT-BEP20' => 'usdtbep',
            'arze digital offline' => 'digitaltron',
            'zarinpal' => 'zarinpal',
            'aqayepardakht' => 'aqayepardakht',
            'Currency Rial 1' => 'iranpay1',
            'Currency Rial 2' => 'iranpay2',
            'Currency Rial 3' => 'iranpay3',
            'paymentnotverify' => 'paymentnotverify',
        ];
        return $m[(string) $method] ?? null;
    }
}
if (!function_exists('topup_paid_notify')) {
    // The wallet is credited, but the invoice is still sitting in the chat
    // with a live payment link on it - and tapping a url button tells the bot
    // nothing, so it cannot answer. Swap it for a button that can: the
    // customer's natural second tap then gets the gateway's own alert instead
    // of reopening a payment page for an invoice that is already settled.
    function topup_paid_notify($order_id)
    {
        $row = select("Payment_report", "*", "id_order", $order_id, "select");
        if (!is_array($row) || (int) ($row['message_id'] ?? 0) < 1) {
            return;
        }
        $key = topup_gateway_key_by_method($row['Payment_Method'] ?? '');
        if ($key === null) {
            return;
        }
        $lang = (string) (select("user", "*", "id", $row['id_user'], "select")['lang'] ?? 'fa');
        $t = languagechange(null, $lang);
        $btn = topup_styled_button(
            $t['users']['Balance']['paidInvoiceBtn'],
            topup_invoice_btnstyle_for($lang, $key, 'paid'),
            "gwpaid:{$key}",
            topup_invoice_btnstyle_default_color('paid', $key)
        );
        telegram('editMessageReplyMarkup', [
            'chat_id' => $row['id_user'],
            'message_id' => (int) $row['message_id'],
            'reply_markup' => json_encode(['inline_keyboard' => [[$btn]]]),
        ]);
    }
}
if (!function_exists('topup_expire_gateway_keys')) {
    // Payment_report stores each gateway under its own historical name;
    // these are the ones that word their own expiry, mapped to the gateway
    // key the caption and button stores are indexed by. Card-to-card is not
    // here - it has its own reissue flow, with buttons no other gateway has.
    function topup_expire_gateway_keys()
    {
        return [
            'plisio' => 'plisio',
            'nowpayment' => 'nowpayment',
            'Star Telegram' => 'startelegrams',
            'TON' => 'ton',
            'TRX' => 'trx',
            'USDT-BEP20' => 'usdtbep',
        ];
    }
}
if (!function_exists('topup_expire_notify')) {
    // What an expired invoice turns into, for every gateway that has its own
    // wording: the caption an admin can edit plus a "ساخت فاکتور جدید" button.
    // Plisio has had this since it was built; the other online gateways used to
    // have their invoice silently deleted instead.
    function topup_expire_notify($key, $id_user, $id_order, $price, $message_id, $payer_lang)
    {
        // languagechange()'s $lang is a typed string, so a null here is a fatal
        // rather than a fallback - and this runs inside the expiry cron's loop,
        // where a fatal means the row never gets marked expired at all. The
        // caller is fixed, but one missed caller must not be able to stop the
        // whole cron again.
        $payer_lang = is_string($payer_lang) && $payer_lang !== '' ? $payer_lang : 'fa';
        $t = languagechange(null, $payer_lang);
        $default = $key === 'plisio'
            ? $t['users']['Balance']['plisioInvoiceExpiredCaption']
            : $t['users']['Balance']['topupInvoiceExpiredCaption'];
        $caption = strtr(topup_invoice_expired_caption_for($payer_lang, $key, $default), [
            '{price}' => number_format($price),
        ]);
        $btn = topup_styled_button(
            $t['users']['Balance']['reissueInvoiceBtn'],
            topup_invoice_btnstyle_for($payer_lang, $key, 'reissue'),
            "gwreissue:{$key}:{$id_order}",
            'danger'
        );
        Editmessagetext($id_user, $message_id, $caption, json_encode(['inline_keyboard' => [[$btn]]]), 'HTML');
    }
}

if (!function_exists('topup_slot_defs')) {
    // The four buttons of the top-up amount flow that an admin can restyle, each
    // with its own storage slot. 'back' and 'backcustom' are BOTH "بازگشت به روش
    // پرداخت" but on different screens - they used to share the 'back' slot, so
    // recolouring one silently recoloured the other.
    function topup_slot_defs($textbotlang)
    {
        $b = $textbotlang['users']['Balance'];
        return [
            'custom' => ['label' => $b['customAmountBtn'], 'color' => 'primary', 'screen' => 'amount', 'pair' => 'back'],
            'back' => ['label' => $b['backToMethodBtn'], 'color' => 'danger', 'screen' => 'amount', 'pair' => 'custom'],
            'backcustom' => ['label' => $b['backToMethodBtn'], 'color' => 'danger', 'screen' => 'custom', 'pair' => 'backpkg'],
            'backpkg' => ['label' => $b['backToPrevMenuBtn'] ?? '🔙 بازگشت به منوی قبلی', 'color' => 'danger', 'screen' => 'custom', 'pair' => 'backcustom'],
        ];
    }
}
if (!function_exists('topup_slot_label')) {
    // the text this button actually shows the customer: the admin's own name if
    // they set one, otherwise the slot's built-in label
    function topup_slot_label($lang, $key, $slot, $textbotlang)
    {
        $style = topup_btnstyle_for($lang, $key, $slot);
        $label = trim((string) ($style['label'] ?? ''));
        if ($label !== '') {
            return $label;
        }
        return topup_slot_defs($textbotlang)[$slot]['label'] ?? $slot;
    }
}
if (!function_exists('topup_slot_color')) {
    function topup_slot_color($lang, $key, $slot, $textbotlang)
    {
        $c = (string) (topup_btnstyle_for($lang, $key, $slot)['color'] ?? '');
        return in_array($c, ['primary', 'success', 'danger'], true)
            ? $c
            : (topup_slot_defs($textbotlang)[$slot]['color'] ?? '');
    }
}
if (!function_exists('topup_custom_screen_swapped')) {
    // the same left/right swap the amount screen has, for the pair on the
    // "#️⃣ مبلغ دلخواه" screen (بازگشت به روش پرداخت / بازگشت به منوی قبلی)
    function topup_custom_screen_swapped($lang, $gatewayKey)
    {
        $m = topup_btnstyle_map();
        return !empty($m[$lang][$gatewayKey]['_customScreenSwapped']);
    }
    function topup_custom_screen_toggle($lang, $gatewayKey)
    {
        $m = topup_btnstyle_map();
        if (!empty($m[$lang][$gatewayKey]['_customScreenSwapped'])) {
            unset($m[$lang][$gatewayKey]['_customScreenSwapped']);
        } else {
            $m[$lang][$gatewayKey]['_customScreenSwapped'] = true;
        }
        topup_btnstyle_save($m);
    }
}
if (!function_exists('topup_slot_swapped')) {
    // which swap flag governs a slot's row
    function topup_slot_swapped($lang, $key, $slot, $textbotlang)
    {
        $screen = topup_slot_defs($textbotlang)[$slot]['screen'] ?? 'amount';
        return $screen === 'custom'
            ? topup_custom_screen_swapped($lang, $key)
            : topup_custom_back_swapped($lang, $key);
    }
    function topup_slot_swap_toggle($lang, $key, $slot, $textbotlang)
    {
        $screen = topup_slot_defs($textbotlang)[$slot]['screen'] ?? 'amount';
        if ($screen === 'custom') {
            topup_custom_screen_toggle($lang, $key);
        } else {
            topup_custom_back_toggle($lang, $key);
        }
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
if (!function_exists('topup_linkmsg_map')) {
    // "درحال ساخت لینک پرداخت..." per gateway. It used to be one shared string
    // for all nine redirect gateways, so wording it for one silently reworded
    // the rest; each now keeps its own text, falling back to the shared default.
    function topup_linkmsg_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['topup_linkmsgs'] ?? ''), true);
        $cache = is_array($m) ? $m : [];
        return $cache;
    }
}
if (!function_exists('topup_linkmsg_for')) {
    function topup_linkmsg_for($lang, $gatewayKey, $default)
    {
        $m = topup_linkmsg_map();
        $v = trim((string) ($m[$lang][$gatewayKey] ?? ''));
        return $v !== '' ? $v : $default;
    }
}
if (!function_exists('topup_linkmsg_has_override')) {
    function topup_linkmsg_has_override($lang, $gatewayKey)
    {
        $m = topup_linkmsg_map();
        return trim((string) ($m[$lang][$gatewayKey] ?? '')) !== '';
    }
}
if (!function_exists('topup_linkmsg_set')) {
    function topup_linkmsg_set($lang, $gatewayKey, $text)
    {
        $m = topup_linkmsg_map();
        $text = trim((string) $text);
        if ($text === '') {
            unset($m[$lang][$gatewayKey]);
            if (empty($m[$lang])) {
                unset($m[$lang]);
            }
        } else {
            $m[$lang][$gatewayKey] = $text;
        }
        update("setting", "topup_linkmsgs", empty($m) ? '{}' : json_encode($m, JSON_UNESCAPED_UNICODE), null, null);
        topup_linkmsg_map(true);
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
            // blue unless the admin picked something else: with no style at all
            // Telegram draws a plain white button, which read as disabled next
            // to the coloured ones around it
            $color = $section['color'][$key] ?? '';
            $btn['style'] = in_array($color, ['primary', 'success', 'danger'], true) ? $color : 'primary';
            $buttons[$key] = $btn;
            $order[] = $key;
        }
        if (empty($buttons)) {
            return array_values($rows);
        }
        $ordered = help_layout_visible(help_layout_apply_order($order, $section['order']), $section);
        $out = help_layout_chunk_rows($ordered, $buttons, $section['width']);
        foreach ($extraRows as $row) {
            $out[] = $row;
        }
        return $out;
    }
}if (!function_exists('topup_group_methods_on')) {
    // ---- grouping the payment methods the customer sees ----
    // The admin side has shown the gateways in families for a while; the
    // customer's list is the one place that stayed flat, and with eleven live it
    // reads as a wall. This collapses it to one row per family.
    //
    // Only families with more than one live member are collapsed: a "group"
    // holding a single gateway would cost a tap and give nothing back, so a
    // language with one gateway is left exactly as it was.
    function topup_group_methods_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['topup_group_methods'] ?? ''), true);
        $cache = is_array($m) ? $m : [];
        return $cache;
    }
    function topup_group_methods_on($lang)
    {
        return (string) (topup_group_methods_map()[$lang] ?? '') === '1';
    }
    function topup_group_methods_set($lang, $on)
    {
        $m = topup_group_methods_map(true);
        if ($on) {
            $m[$lang] = '1';
        } else {
            unset($m[$lang]);
        }
        update("setting", "topup_group_methods", empty($m) ? '{}' : json_encode($m, JSON_UNESCAPED_UNICODE), null, null);
        topup_group_methods_map(true);
    }
    // per-family button styling, the same shape every other style store here uses
    function topup_group_btnstyle_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['topup_group_btnstyle'] ?? ''), true);
        $cache = is_array($m) ? $m : [];
        return $cache;
    }
    function topup_group_btnstyle_for($lang, $group)
    {
        return topup_group_btnstyle_map()[$lang][$group] ?? [];
    }
    function topup_group_btnstyle_set($lang, $group, array $style)
    {
        $m = topup_group_btnstyle_map(true);
        $m[$lang][$group] = $style;
        update("setting", "topup_group_btnstyle", json_encode($m, JSON_UNESCAPED_UNICODE), null, null);
        topup_group_btnstyle_map(true);
    }
    // the caption a family's own screen carries; {group} becomes its name
    function topup_group_caption_for($lang, $default)
    {
        $v = trim((string) (topup_gwstore_map('topup_group_captions')[$lang]['all'] ?? ''));
        return $v !== '' ? $v : $default;
    }
    function topup_group_caption_has_override($lang)
    {
        return trim((string) (topup_gwstore_map('topup_group_captions')[$lang]['all'] ?? '')) !== '';
    }
    function topup_group_caption_set($lang, $text)
    {
        topup_gwstore_set('topup_group_captions', $lang, 'all', $text);
    }
    // Which gateways this rendered keyboard actually offers - read off the
    // buttons rather than the config, so it reflects what the customer sees
    // after the per-language filtering has already run.
    function topup_group_live_keys($rows, $cartToCartText = null)
    {
        $out = [];
        foreach ((array) $rows as $row) {
            foreach ((array) $row as $btn) {
                $k = gateway_button_key($btn, $cartToCartText);
                if ($k !== null) {
                    $out[] = $k;
                }
            }
        }
        return $out;
    }
    // Which families get collapsed into one button on the customer's method
    // screen. A family with a single live member is collapsed too: the offline
    // family has only ever had one gateway, so the old "more than one member"
    // rule left پرداخت مستقیم ترون sitting loose next to the categories, as if
    // it belonged to none of them.
    //
    // Card-to-card is deliberately not a family here (see gateway_disc_groups):
    // burying the most-used gateway behind a tap costs every customer a step.
    function topup_group_collapsible($rows, $cartToCartText = null)
    {
        $live = topup_group_live_keys($rows, $cartToCartText);
        $out = [];
        foreach (gateway_groups() as $group => $members) {
            if (count(array_intersect($members, $live)) > 0) {
                $out[] = $group;
            }
        }
        return $out;
    }
    function topup_group_button($lang, $group, $textbotlang)
    {
        return topup_styled_button(
            gateway_group_label($group, $textbotlang),
            topup_group_btnstyle_for($lang, $group),
            "topupmgrp:{$group}",
            'primary'
        );
    }
    // The method screen with each collapsible family replaced by one button.
    // The button takes the place of that family's first member, so whatever
    // order and row width the admin set in 📐 چیدمان still decides the layout.
    function topup_group_method_rows($rows, $lang, $textbotlang, $cartToCartText = null)
    {
        $collapse = topup_group_collapsible($rows, $cartToCartText);
        if (empty($collapse)) {
            return $rows;
        }
        $out = [];
        $done = [];
        foreach ((array) $rows as $row) {
            $keep = [];
            foreach ((array) $row as $btn) {
                $k = gateway_button_key($btn, $cartToCartText);
                $g = $k === null ? null : gateway_group_of($k);
                if ($g !== null && in_array($g, $collapse, true)) {
                    if (isset($done[$g])) {
                        continue;
                    }
                    $done[$g] = true;
                    $keep[] = topup_group_button($lang, $g, $textbotlang);
                    continue;
                }
                $keep[] = $btn;
            }
            if (!empty($keep)) {
                $out[] = $keep;
            }
        }
        return $out;
    }
    // One family's own screen: its gateways exactly as they were arranged on the
    // flat list - same styling, same rows - plus a way back. Taking them from
    // the already-styled keyboard is what keeps 📐 چیدمان working inside a family
    // instead of the grouping quietly flattening it.
    function topup_group_screen_rows($rows, $group, $lang, $textbotlang, $cartToCartText = null)
    {
        $members = gateway_groups()[$group] ?? [];
        $out = [];
        foreach ((array) $rows as $row) {
            $keep = [];
            foreach ((array) $row as $btn) {
                $k = gateway_button_key($btn, $cartToCartText);
                if ($k !== null && in_array($k, $members, true)) {
                    $keep[] = $btn;
                }
            }
            if (!empty($keep)) {
                $out[] = $keep;
            }
        }
        $out[] = [[
            'text' => $textbotlang['users']['Balance']['groupBackBtn'],
            'callback_data' => 'topup_back_methods',
            'style' => 'danger',
        ]];
        return $out;
    }
}
if (!function_exists('topup_method_keyboard')) {
    // $step_payment already carries a shared "❌ بستن لیست" row used by ~9
    // other flows in this bot - the method-first top-up screen doesn't want
    // it (its own بازگشت/close buttons cover that), so this strips just that
    // trailing row for THIS display without touching the shared variable
    // every other flow still relies on unmodified.
    function topup_method_keyboard($stepPaymentJson, $lang = null)
    {
        $kb = json_decode($stepPaymentJson, true);
        if (!is_array($kb['inline_keyboard'] ?? null)) {
            return $stepPaymentJson;
        }
        $rows = $kb['inline_keyboard'];
        $last = end($rows);
        if (is_array($last) && count($last) === 1 && ($last[0]['callback_data'] ?? '') === 'colselist') {
            // the shared 'colselist' row belongs to ~9 other flows, so it cannot
            // stay - but the screen still deserves a way out, with the sticker
            // cleanup the shared one never did
            global $textbotlang;
            array_pop($rows);
            // 🚫 مخفی کردن این دکمه (🎨 شخصی‌سازی) drops the row entirely -
            // the shared 'colselist' row is popped either way, so turning this
            // off leaves the screen with no trailing row rather than a broken one
            if (!bt_button_hidden($lang ?? 'fa', 'bottext.btnCloseTopup')) {
                $rows[] = [bt_button(
                    $lang ?? 'fa',
                    'bottext.btnCloseTopup',
                    $textbotlang['bottext']['btnCloseTopup'] ?? ($textbotlang['bottext']['btn_close'] ?? '❌ بستن'),
                    // ':tp' tells the shared mmclose handler which of the six
                    // sections' own 🖼 استیکر دکمه بستن setting to use
                    'mmclose:tp'
                )];
            }
        }
        // 🗂 دسته‌بندی درگاه‌ها, off unless this language turned it on. Done last
        // so the close row above is already in place and stays where it is.
        if ($lang !== null && topup_group_methods_on($lang)) {
            global $textbotlang;
            $rows = topup_group_method_rows($rows, $lang, $textbotlang, $textbotlang['textbot']['cartToCart'] ?? null);
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
if (!function_exists('gateway_datain')) {
    // The inverse of gateway_button_key(): the callback a gateway's checkout is
    // keyed on. Card-to-card answers to the legacy 'cart_to_offline' rather than
    // its own key; every other gateway is keyed on the key itself.
    function gateway_datain($key)
    {
        return $key === 'card' ? 'cart_to_offline' : (string) $key;
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
if (!function_exists('money_input_steps')) {
    // Every step on which the bot is waiting for an AMOUNT - admin side and
    // customer side both. A message typed on one of these is read through
    // money_normalize() first, so "100,000" and "۱۰۰٬۰۰۰" mean exactly what
    // "100000" means, the same way convertPersianNumbersToEnglish() already
    // rewrites every incoming message in botapi.php.
    //
    // Only amounts belong here. Steps that ask for days, volume, a count or a
    // percentage are deliberately absent: a separator means nothing there, and
    // rewriting their input would only hide a typo.
    // Steps whose names are built at runtime (topup_custom:, gwfld:, the top-up
    // package editors) already run money_valid()/money_normalize() themselves.
    function money_input_steps()
    {
        return [
            // customer: the legacy "مبلغ واریز" prompt
            'getprice',
            // admin: wallet adjustments
            'add_Balance_all', 'addbalanceusercurrent', 'addbalanceuser',
            'addbalancemanual', 'get_price_Negative', 'getpricebackremove',
            // admin: product and plan prices
            'get_price', 'change_price', 'setpricechangelocation',
            'getaddpricepeoduct', 'getkampricepeoduct',
            'getpricef', 'getpricnn', 'getpricnn2',
            'getpriceftime', 'getpricnntime', 'getpricnn2time',
            'GetPriceExtra', 'GetPricecustomvo', 'GetPricetimeextra', 'GetPriceExtratime',
            // admin: discount and gift codes
            'get_price_code', 'get_price_codesell', 'getvaluegift',
            // admin: deposit limits, per gateway
            'minbalance', 'maxbalance', 'minbalancebulk',
            'getmaincart', 'getmaxcart',
            'getmainplisio', 'getmaxplisio',
            'getmaindigitaltron', 'getmaxdigitaltron',
            'getmainiranpay1', 'getmaaxiranpay1',
            'getmainiranpay2', 'getmaaxiranpay2',
            'getmainaqayepardakht', 'getmaaxaqayepardakht',
            'getmainaqzarinpal', 'getmaaxzarinpal',
            'minbalanceiranpay', 'maxbalanceiranpay',
            'getmainaqstar', 'maxbalancestar',
            'getmainaqnowpayment', 'maxbalancenowpayment',
            // admin: other prices
            'getpricereqagent', 'getpricewheel',
            'getpricevolumesrc', 'getpricetimesrc',
        ];
    }
}
if (!function_exists('money_step_text')) {
    // The rewrite itself. Anything that is not a plain amount is handed back
    // untouched, so a step's own "مبلغ نامعتبر است" still fires on real typos
    // instead of being swallowed here.
    function money_step_text($step, $text)
    {
        if (!is_string($text) || trim($text) === '') {
            return $text;
        }
        if (!in_array((string) $step, money_input_steps(), true)) {
            return $text;
        }
        // digits and separators only: a message with a letter in it is a typo
        // (or a menu label), not an amount someone spaced out
        if (!preg_match('/^[0-9۰-۹٠-٩,،.٫\s\x{00A0}]+$/u', trim($text))) {
            return $text;
        }
        $n = money_normalize($text);
        return $n === null ? $text : $n;
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
    // table_schema = DATABASE() matters: without it this matched a table of the
    // same name in ANY database on the server, so the column check (which IS
    // scoped) then said "missing" and the ALTER ran against a table this
    // database does not have. That threw, and because table.php calls this ~219
    // times in a row with no guard, every migration after it silently never
    // ran - an update could leave a bot half-upgraded.
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :tableName");
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
    // one failing column must not abort the whole migration run behind it
    try {
        $pdo->prepare("ALTER TABLE $tableName ADD $fieldName $datatype")->execute();
        if ($defaultValue != null) {
            $stmt = $pdo->prepare("UPDATE $tableName SET $fieldName= ?");
            $stmt->bindParam(1, $defaultValue);
            $stmt->execute();
        }
        echo "The $fieldName field was added ✅";
    } catch (Exception $e) {
        error_log("addFieldToTable({$tableName}.{$fieldName}) failed: " . $e->getMessage());
        echo "Could not add $fieldName to $tableName ⚠️";
    }
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

if (!function_exists('pruneUnlockedBotCrons')) {
    // Removes the old, unguarded shape of the bot's own cron lines: a line that
    // calls one of $scripts and does NOT go through flock. Anything else in the
    // crontab - other tools, an admin's own entries, our flock lines - is left
    // exactly as it is.
    //
    // This exists because addCronIfNotExists() can only ever ADD. When the
    // schedule here changed, the old lines stayed behind and both sets ran.
    function pruneUnlockedBotCrons(array $scripts)
    {
        $scripts = array_values(array_filter($scripts));
        if (empty($scripts) || !isShellExecAvailable()) {
            return false;
        }
        $crontabBinary = getCrontabBinary();
        if ($crontabBinary === null) {
            return false;
        }
        $existing = trim((string) runShellCommand(sprintf('%s -l 2>/dev/null', escapeshellarg($crontabBinary))));
        if ($existing === '') {
            return true;
        }
        $lines = preg_split('/\r?\n/', $existing);
        $kept = [];
        $removed = 0;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            $isOurs = false;
            foreach ($scripts as $s) {
                if ($trimmed !== '' && strpos($trimmed, '#') !== 0
                    && strpos($trimmed, 'cronbot/' . $s) !== false
                    && strpos($trimmed, 'flock') === false) {
                    $isOurs = true;
                    break;
                }
            }
            if ($isOurs) {
                $removed++;
                continue;
            }
            $kept[] = $line;
        }
        if ($removed === 0) {
            return true;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'cron');
        if ($tmp === false) {
            return false;
        }
        file_put_contents($tmp, implode(PHP_EOL, $kept) . PHP_EOL);
        runShellCommand(sprintf('%s %s', escapeshellarg($crontabBinary), escapeshellarg($tmp)));
        unlink($tmp);
        error_log("pruneUnlockedBotCrons: removed $removed unguarded cron line(s)");
        return true;
    }
}
function activecron()
{
    global $domainhosts;

    // Every line is wrapped in `flock -n` and carries a `--max-time`, and the
    // heavy jobs are spread out. Both matter, and both were learned the hard
    // way on 2026-09-06: the plain one-minute versions of these lines needed
    // more than 60 seconds of work every 60 seconds on a one-core box, so they
    // stacked - 21 concurrent croncard runs, load past 180, and Telegram
    // timing out on the webhook.
    //
    // These strings are the source of truth. addCronIfNotExists() compares
    // lines EXACTLY, so if this list ever drifts from what is installed, the
    // difference is silently added alongside rather than replacing - which is
    // precisely how the box ended up running two competing sets of the same
    // jobs. Change the schedule here, never only in the crontab.
    $cronCommands = [
        // --- money path: still every minute ---
        "*/1 * * * * flock -n /tmp/mz_croncard.lock curl -s --max-time 55 https://$domainhosts/cronbot/croncard.php > /dev/null 2>&1",
        "*/1 * * * * flock -n /tmp/mz_iranpay1.lock curl -s --max-time 55 https://$domainhosts/cronbot/iranpay1.php > /dev/null 2>&1",
        "*/1 * * * * flock -n /tmp/mz_sendmessage.lock curl -s --max-time 55 https://$domainhosts/cronbot/sendmessage.php > /dev/null 2>&1",
        // TON, TRX and USDT-BEP20 are settled by reading the chain, so nothing
        // credits a customer until these run - they are the whole gateway, not
        // an extra
        "*/2 * * * * flock -n /tmp/mz_ton.lock curl -s --max-time 110 https://$domainhosts/cronbot/ton.php > /dev/null 2>&1",
        "*/2 * * * * flock -n /tmp/mz_trx.lock curl -s --max-time 110 https://$domainhosts/cronbot/trx.php > /dev/null 2>&1",
        "*/2 * * * * flock -n /tmp/mz_usdtbep.lock curl -s --max-time 110 https://$domainhosts/cronbot/usdtbep.php > /dev/null 2>&1",
        "*/3 * * * * flock -n /tmp/mz_plisio.lock curl -s --max-time 170 https://$domainhosts/cronbot/plisio.php > /dev/null 2>&1",
        "*/5 * * * * flock -n /tmp/mz_payment_expire.lock curl -s --max-time 280 https://$domainhosts/cronbot/payment_expire.php > /dev/null 2>&1",
        // --- stretched: these were the load ---
        "*/5 * * * * flock -n /tmp/mz_notifications.lock curl -s --max-time 280 https://$domainhosts/cronbot/NoticationsService.php > /dev/null 2>&1",
        "*/2 * * * * flock -n /tmp/mz_activeconfig.lock curl -s --max-time 110 https://$domainhosts/cronbot/activeconfig.php > /dev/null 2>&1",
        "*/2 * * * * flock -n /tmp/mz_disableconfig.lock curl -s --max-time 110 https://$domainhosts/cronbot/disableconfig.php > /dev/null 2>&1",
        "*/5 * * * * flock -n /tmp/mz_gift.lock curl -s --max-time 280 https://$domainhosts/cronbot/gift.php > /dev/null 2>&1",
        "*/10 * * * * flock -n /tmp/mz_configtest.lock curl -s --max-time 550 https://$domainhosts/cronbot/configtest.php > /dev/null 2>&1",
        // --- quarter-hourly, on different minutes so they never fire together ---
        "3,18,33,48 * * * * flock -n /tmp/mz_statusday.lock curl -s --max-time 600 https://$domainhosts/cronbot/statusday.php > /dev/null 2>&1",
        "7,22,37,52 * * * * flock -n /tmp/mz_on_hold.lock curl -s --max-time 600 https://$domainhosts/cronbot/on_hold.php > /dev/null 2>&1",
        "11,26,41,56 * * * * flock -n /tmp/mz_uptime_node.lock curl -s --max-time 600 https://$domainhosts/cronbot/uptime_node.php > /dev/null 2>&1",
        "14,29,44,59 * * * * flock -n /tmp/mz_uptime_panel.lock curl -s --max-time 600 https://$domainhosts/cronbot/uptime_panel.php > /dev/null 2>&1",
        "9,39 * * * * flock -n /tmp/mz_expireagent.lock curl -s --max-time 1700 https://$domainhosts/cronbot/expireagent.php > /dev/null 2>&1",
    ];

    // Drop the pre-flock shape of these same jobs before adding ours. Without
    // this the two sets run side by side, each holding its own lock and neither
    // aware of the other - which is exactly what re-broke the box after the
    // first fix. Deliberately narrow: only lines that call one of OUR cron
    // scripts AND have no flock are removed, so anything an admin added by hand
    // is left alone.
    // [A-Za-z0-9_]+ and not [A-Za-z_]+: iranpay1.php has a digit in it, and a
    // letters-only pattern silently dropped it from the list - so the one job
    // whose old line most needed pruning would have been the one left behind
    pruneUnlockedBotCrons(array_map(static function ($line) {
        preg_match('#cronbot/([A-Za-z0-9_]+\.php)#', $line, $m);
        return $m[1] ?? '';
    }, $cronCommands));

    addCronIfNotExists($cronCommands);

    // backupbot.php's schedule is admin-configurable (🗄 تنظیمات بکاپ), so it's
    // managed separately from the fixed list above - addCronIfNotExists() can
    // only ever add a missing line, never replace a stale one, which would
    // otherwise leave two competing schedules active side by side after the
    // admin changes it
    $setting = select("setting", "*", null, null, "select");
    updateBackupCronSchedule($setting['backup_interval_hours'] ?? '5');
}
if (!function_exists('updateBackupCronSchedule')) {
    function updateBackupCronSchedule($intervalHours)
    {
        global $domainhosts;
        $intervalHours = (int) $intervalHours;
        if ($intervalHours < 1 || $intervalHours > 24) {
            $intervalHours = 5;
        }
        if (!isShellExecAvailable()) {
            return false;
        }
        $crontabBinary = getCrontabBinary();
        if ($crontabBinary === null) {
            return false;
        }
        $existingCronJobs = runShellCommand(sprintf('%s -l 2>/dev/null', escapeshellarg($crontabBinary)));
        $existingCronJobs = trim((string) $existingCronJobs);
        $cronLines = $existingCronJobs === '' ? [] : preg_split('/\r?\n/', $existingCronJobs);
        $cronLines = array_values(array_filter(array_map('trim', $cronLines), static function ($line) {
            return $line !== '' && strpos($line, '#') !== 0;
        }));

        // guarded like every other job: a database dump is the heaviest thing
        // this bot does, and two of them at once is what a slow disk turns into
        $targetLine = "0 */$intervalHours * * * flock -n /tmp/mz_backupbot.lock curl -s --max-time 3000 https://$domainhosts/cronbot/backupbot.php > /dev/null 2>&1";
        $backupLines = array_values(array_filter($cronLines, static function ($line) {
            return strpos($line, 'cronbot/backupbot.php') !== false;
        }));
        if ($backupLines === [$targetLine]) {
            return true;
        }

        $cronLines = array_values(array_filter($cronLines, static function ($line) {
            return strpos($line, 'cronbot/backupbot.php') === false;
        }));
        $cronLines[] = $targetLine;
        $cronLines = array_values(array_unique($cronLines));
        $cronContent = implode(PHP_EOL, $cronLines) . PHP_EOL;

        $temporaryFile = tempnam(sys_get_temp_dir(), 'cron');
        if ($temporaryFile === false) {
            return false;
        }
        if (file_put_contents($temporaryFile, $cronContent) === false) {
            unlink($temporaryFile);
            return false;
        }
        runShellCommand(sprintf('%s %s', escapeshellarg($crontabBinary), escapeshellarg($temporaryFile)));
        unlink($temporaryFile);
        return true;
    }
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
if (!function_exists('bottext_all_item_keys')) {
    // every key the message manager can reach: the registered items plus the
    // three rows rendered outside bottext.items (the unknown-message row and the
    // two sub-items that are only reachable from another item's own menu)
    function bottext_all_item_keys($textbotlang)
    {
        $keys = [];
        foreach (($textbotlang['bottext']['items'] ?? []) as $it) {
            if (!empty($it['key'])) {
                $keys[] = $it['key'];
            }
        }
        foreach (['users.unknownMsg', 'textbot.afterText', 'users.status.getConfigHint', 'users.sell.confirmButtons'] as $extra) {
            $keys[] = $extra;
        }
        return array_values(array_unique($keys));
    }
}
if (!function_exists('sell_sticker_capture')) {
    // Same field (bt_sticker_id) as sell_sticker_retire() below, but for a
    // caller that needs the id WITHOUT deleting the message yet - the ❌ بستن
    // handlers, which hand it to close_sticker_play() to remove together with
    // its own sticker after 🖼 استیکر دکمه بستن's timer. The DB is cleared
    // either way, so a second call never returns the same id twice.
    function sell_sticker_capture($chat_id)
    {
        $sr_user = select("user", "*", "id", $chat_id, "select");
        $sr_old = (int) ($sr_user['bt_sticker_id'] ?? 0);
        if ($sr_old > 0) {
            update("user", "bt_sticker_id", "0", "id", $chat_id);
        }
        return $sr_old;
    }
}
if (!function_exists('sell_sticker_retire')) {
    // Takes away the sticker the current purchase screen put up. sell_screen()
    // calls it on every step; the paths that LEAVE the flow (a product is
    // chosen, or the user cancels) call it directly, so the last screen's
    // sticker does not end up sitting above an unrelated invoice.
    function sell_sticker_retire($chat_id)
    {
        $sr_old = sell_sticker_capture($chat_id);
        if ($sr_old > 0) {
            deletemessage($chat_id, $sr_old);
        }
    }
}
if (!function_exists('sell_screen')) {
    // One choke point for the three screens of 🔐 خرید اشتراک (panel → category
    // → product). What decides how to move between them is whether the screen
    // being opened has a sticker OF ITS OWN:
    //
    //   no sticker of its own -> edit the open message in place and leave any
    //                            sticker already on screen exactly where it is.
    //                            So a sticker set only on the panel step stays
    //                            up across category and product, and a flow with
    //                            no stickers at all never deletes or re-sends
    //                            anything - it is one screen changing.
    //   has its own sticker   -> a sticker cannot live inside a text message and
    //                            a new one would land BELOW the caption, so the
    //                            screen is REPLACED: the previous sticker and
    //                            caption go, then the new sticker is sent with
    //                            the new caption underneath it.
    //
    // The sticker that is up is therefore only ever taken away by a screen that
    // brings its own, or by sell_sticker_retire() when the flow is left - which
    // is what stops stickers from piling up down the chat. Pass $message_id = 0
    // when there is no open bot message to replace (the reply keyboard tap,
    // where $message_id is the user's own message).
    function sell_screen($chat_id, $message_id, $text, $keyboard)
    {
        $ss_mid = (int) $message_id;
        $ss_extras = function_exists('bottext_extras_for_text') ? bottext_extras_for_text($text) : null;
        $ss_hasSticker = is_array($ss_extras) && ($ss_extras['sticker'] ?? '') !== '';
        if (!$ss_hasSticker) {
            // $ss_mid === 0 means the flow is being ENTERED fresh rather than
            // navigated, so a sticker left behind by an abandoned run is an
            // orphan and goes; mid-flow the sticker on screen is kept.
            if ($ss_mid > 0) {
                Editmessagetext($chat_id, $ss_mid, $text, $keyboard, 'HTML');
            } else {
                sell_sticker_retire($chat_id);
                sendmessage($chat_id, $text, $keyboard, 'HTML');
            }
            return;
        }
        // this screen brings its own sticker, so the previous pair makes way
        sell_sticker_retire($chat_id);
        if ($ss_mid > 0) {
            deletemessage($chat_id, $ss_mid);
        }
        // sendmessage() fires the sticker BEFORE the text, so the sticker lands
        // above its own caption rather than under it
        $ss_res = sendmessage($chat_id, $text, $keyboard, 'HTML');
        $ss_new = (int) ($ss_res['_sticker_message_id'] ?? 0);
        update("user", "bt_sticker_id", $ss_new > 0 ? (string) $ss_new : "0", "id", $chat_id);
    }
}
if (!function_exists('statusbtn_defs')) {
    // the "وضعیت سرویس" detail screen's 13 possible buttons, keyed by the
    // exact same string keys index.php's own $keyboarddate array already
    // uses - this is deliberate: it means the ~20 unset($keyboarddate[...])
    // conditions that decide WHICH of these actually show for a given
    // panel/settings combo never need to know this system exists at all.
    // 'changestatus' is special: its real default text toggles between
    // "روشن کردن"/"خاموش کردن" depending on the service's live state - the
    // 'text' here is just a representative label for this admin UI's own
    // preview; the real render call always passes the live value in as a
    // fallback (see statusbtn_render()'s $fallbackText param).
    function statusbtn_defs($textbotlang)
    {
        return [
            'updateinfo' => ['name' => '♻️ بروزرسانی اطلاعات', 'text' => $textbotlang['keyboard']['refreshInfo'], 'style' => 'primary'],
            'linksub' => ['name' => '🔗 لینک اشتراک', 'text' => $textbotlang['users']['status']['linksub'], 'style' => 'primary'],
            'config' => ['name' => '🔰 دریافت کانفیگ', 'text' => $textbotlang['users']['status']['config'], 'style' => 'primary'],
            'extend' => ['name' => '💊 تمدید سرویس', 'text' => $textbotlang['users']['extend']['title'], 'style' => 'success'],
            'changelink' => ['name' => '⚙️ تغییر لینک', 'text' => $textbotlang['users']['changeLink']['btnTitle'], 'style' => 'primary'],
            'removeservice' => ['name' => '❌ بازگشت وجه', 'text' => $textbotlang['users']['status']['removeservice'], 'style' => 'danger'],
            'changenameconfig' => ['name' => '📝 تغییر یادداشت', 'text' => $textbotlang['users']['status']['btnEditNote'], 'style' => 'primary'],
            'Extra_volume' => ['name' => '➕ خرید حجم اضافه', 'text' => $textbotlang['users']['extraVolume']['sellextra'], 'style' => 'success'],
            'Extra_time' => ['name' => '⏳ خرید زمان اضافه', 'text' => $textbotlang['users']['extraTime']['title'], 'style' => 'success'],
            'changestatus' => ['name' => '💡 روشن/خاموش کردن اکانت', 'text' => $textbotlang['users']['status']['btnTurnOn'], 'style' => 'primary'],
            'transfor' => ['name' => '🚚 انتقال سرویس', 'text' => $textbotlang['users']['transfer']['title'], 'style' => 'primary'],
            'change-location' => ['name' => '🌐 تغییر لوکیشن', 'text' => $textbotlang['users']['changeLocation']['title'], 'style' => 'primary'],
            'ekhtelal' => ['name' => '⚠️ ارسال گزارش اختلال', 'text' => $textbotlang['keyboard']['sendDisruptionReport'], 'style' => 'danger'],
            // registered here rather than hardcoded onto the screen so it gets
            // the same reorder / rename / recolour / hide treatment every other
            // status button already has
            'usagereport' => ['name' => '📊 گزارش مصرف', 'text' => $textbotlang['users']['status']['svcUsageReportBtn'] ?? '📊 گزارش مصرف', 'style' => 'primary'],
            // The five buttons INSIDE گزارش مصرف. They live in this same
            // registry so the admin edits them with the tools they already
            // know (colour / emoji / name / hide) instead of a parallel system
            // - but they are on a different screen, so statusbtn_layout_payload()
            // leaves them out of the drag-to-reorder list where they would mean
            // nothing.
            'ur_yesterday' => ['name' => '📊 گزارش مصرف › مصرف دیروز', 'text' => $textbotlang['users']['status']['svcReportBtnYesterday'] ?? '📅 مصرف دیروز', 'style' => 'primary'],
            'ur_2days' => ['name' => '📊 گزارش مصرف › ۲ روز پیش', 'text' => $textbotlang['users']['status']['svcReportBtn2'] ?? '📅 مصرف ۲ روز پیش', 'style' => 'primary'],
            'ur_10days' => ['name' => '📊 گزارش مصرف › ۱۰ روز پیش', 'text' => $textbotlang['users']['status']['svcReportBtn10'] ?? '📅 مصرف ۱۰ روز پیش', 'style' => 'primary'],
            'ur_all' => ['name' => '📊 گزارش مصرف › کل مصرف‌ها', 'text' => $textbotlang['users']['status']['svcReportBtnAll'] ?? '📈 کل مصرف‌ها', 'style' => 'success'],
            'ur_back' => ['name' => '📊 گزارش مصرف › دکمه بازگشت', 'text' => $textbotlang['users']['status']['svcBackToInfo'] ?? '🔙 بازگشت به اطلاعات سرویس', 'style' => 'danger'],
        ];
    }
    // the keys that actually sit on the service screen itself - the گزارش مصرف
    // sub-screen's buttons are registered above for editing, but reordering them
    // against the service screen's own buttons would be meaningless
    function statusbtn_screen_keys($textbotlang)
    {
        return array_values(array_filter(
            array_keys(statusbtn_defs($textbotlang)),
            fn($k) => strpos($k, 'ur_') !== 0
        ));
    }
}
if (!function_exists('statusbtn_override')) {
    function statusbtn_override($lang, $key)
    {
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        return (is_array($be) && isset($be[$lang]['users.status.infoFull'][$key]) && is_array($be[$lang]['users.status.infoFull'][$key]))
            ? $be[$lang]['users.status.infoFull'][$key]
            : [];
    }
}
if (!function_exists('statusbtn_current')) {
    function statusbtn_current($lang, $key, $textbotlang)
    {
        $defs = statusbtn_defs($textbotlang);
        $d = $defs[$key] ?? ['name' => $key, 'text' => $key, 'style' => 'primary'];
        $ov = statusbtn_override($lang, $key);
        $text = (isset($ov['text']) && $ov['text'] !== '') ? $ov['text'] : $d['text'];
        $style = (isset($ov['style']) && in_array($ov['style'], ['primary', 'success', 'danger'], true)) ? $ov['style'] : $d['style'];
        return ['text' => $text, 'style' => $style, 'name' => $d['name']];
    }
}
if (!function_exists('statusbtn_render')) {
    // $fallbackText: the caller's own live-computed default (only actually
    // differs from statusbtn_defs() for 'changestatus', whose real default
    // depends on the service's current on/off state) - an explicit text
    // override still wins over it either way
    function statusbtn_render($lang, $key, $textbotlang, $fallbackText = null)
    {
        $defs = statusbtn_defs($textbotlang);
        $d = $defs[$key] ?? ['name' => $key, 'text' => $fallbackText ?? $key, 'style' => 'primary'];
        if ($fallbackText !== null) {
            $d['text'] = $fallbackText;
        }
        $ov = statusbtn_override($lang, $key);
        $text = (isset($ov['text']) && $ov['text'] !== '') ? $ov['text'] : $d['text'];
        $style = (isset($ov['style']) && in_array($ov['style'], ['primary', 'success', 'danger'], true)) ? $ov['style'] : $d['style'];
        $pos = (isset($ov['pos']) && $ov['pos'] === 'left') ? 'left' : 'right';
        $btn = ['text' => $text, 'style' => $style];
        if (!empty($ov['simple'])) {
            $btn['text'] = strip_leading_emoji($text);
        } elseif (!empty($ov['emojiIcon'])) {
            $btn['text'] = strip_leading_emoji($text);
            $btn['icon_custom_emoji_id'] = $ov['emojiIcon'];
        } else {
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
        return $btn;
    }
}
if (!function_exists('statusbtn_set_field')) {
    function statusbtn_set_field($lang, $key, $field, $value)
    {
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        if (!is_array($be)) {
            $be = [];
        }
        $be[$lang]['users.status.infoFull'][$key][$field] = $value;
        if ($field === 'emoji') {
            unset($be[$lang]['users.status.infoFull'][$key]['emojiIcon']);
        } elseif ($field === 'emojiIcon') {
            unset($be[$lang]['users.status.infoFull'][$key]['emoji']);
        }
        update("setting", "button_edit", json_encode($be, JSON_UNESCAPED_UNICODE), null, null);
    }
}
if (!function_exists('statusbtn_reset')) {
    function statusbtn_reset($lang, $key)
    {
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        if (is_array($be) && isset($be[$lang]['users.status.infoFull'][$key])) {
            unset($be[$lang]['users.status.infoFull'][$key]);
            if (empty($be[$lang]['users.status.infoFull'])) {
                unset($be[$lang]['users.status.infoFull']);
            }
            if (empty($be[$lang])) {
                unset($be[$lang]);
            }
            update("setting", "button_edit", empty($be) ? null : json_encode($be, JSON_UNESCAPED_UNICODE), null, null);
        }
    }
}
if (!function_exists('statusbtn_reset_all')) {
    function statusbtn_reset_all($lang)
    {
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        if (is_array($be) && isset($be[$lang]['users.status.infoFull'])) {
            unset($be[$lang]['users.status.infoFull']);
            if (empty($be[$lang])) {
                unset($be[$lang]);
            }
            update("setting", "button_edit", empty($be) ? null : json_encode($be, JSON_UNESCAPED_UNICODE), null, null);
        }
    }
}
if (!function_exists('statusbtn_list_payload')) {
    function statusbtn_list_payload($lang, $textbotlang)
    {
        $info = "🔘 <b>دکمه‌های صفحه‌ی وضعیت سرویس</b>\n➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "این دکمه‌ها زیر پیام «وضعیت سرویس» نشون داده می‌شن (وقتی کاربر داخل «🛍 سرویس‌های من» روی یکی از سرویس‌هاش می‌زنه).\n";
        $info .= "ℹ️ کدوم‌هاشون واقعاً نشون داده بشن به نوع پنل و تنظیمات فروشگاه بستگی داره - همیشه همه‌شون با هم نیستن.\n";
        $info .= "➖➖➖➖➖➖➖➖➖➖\nکدوم بخش رو می‌خوای تنظیم کنی؟ 👇";
        $kb = ['inline_keyboard' => []];
        $kb['inline_keyboard'][] = [['text' => '🎨 رنگ‌بندی دکمه‌ها', 'callback_data' => "statusbtn|cat|{$lang}|color", 'style' => 'primary']];
        $kb['inline_keyboard'][] = [['text' => '🎭 ایموجی دکمه‌ها', 'callback_data' => "statusbtn|cat|{$lang}|emoji", 'style' => 'primary']];
        $kb['inline_keyboard'][] = [['text' => '📐 چیدمان دکمه‌ها', 'callback_data' => "statusbtn|cat|{$lang}|layout", 'style' => 'primary']];
        $kb['inline_keyboard'][] = [['text' => '✏️ نام و نمایش دکمه‌ها', 'callback_data' => "statusbtn|cat|{$lang}|text", 'style' => 'primary']];
        $kb['inline_keyboard'][] = [['text' => '✏️ ویرایش کپشن پیام وضعیت سرویس', 'callback_data' => "bt_edit|{$lang}|users.status.infoFull", 'style' => 'primary']];
        $kb['inline_keyboard'][] = [['text' => '🔁 ریست همه‌ی دکمه‌ها به پیش‌فرض', 'callback_data' => "statusbtn|rstall|{$lang}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => "bt_edit|{$lang}|users.status.infoFull", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '❌ بستن', 'callback_data' => 'bt_close', 'style' => 'danger']];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('statusbtn_cat_payload')) {
    // one live-preview list per category, exactly like color_editor_payload/
    // emoji_sticker_editor_payload: every row already reflects every OTHER
    // category's overrides too (honest live preview), tapping a row acts
    // immediately - cycle in place for color, prompt for emoji/text
    function statusbtn_cat_payload($lang, $cat, $textbotlang)
    {
        $defs = statusbtn_defs($textbotlang);
        $kb = ['inline_keyboard' => []];
        foreach ($defs as $key => $d) {
            $rendered = statusbtn_render($lang, $key, $textbotlang);
            $hiddenMark = statusbtn_is_hidden($lang, $key) ? '🚫 ' : '';
            if ($cat === 'color') {
                $kb['inline_keyboard'][] = [['text' => $hiddenMark . $rendered['text'], 'callback_data' => "statusbtn|colorcyc|{$lang}|{$key}", 'style' => $rendered['style']]];
            } elseif ($cat === 'emoji') {
                $ov = statusbtn_override($lang, $key);
                $pos = (isset($ov['pos']) && $ov['pos'] === 'left') ? 'left' : 'right';
                $mainBtn = ['text' => $hiddenMark . $rendered['text'], 'callback_data' => "statusbtn|emoji|{$lang}|{$key}", 'style' => $rendered['style']];
                if (isset($rendered['icon_custom_emoji_id'])) {
                    $mainBtn['icon_custom_emoji_id'] = $rendered['icon_custom_emoji_id'];
                }
                $posBtn = ['text' => ($pos === 'left') ? '⬅️' : '➡️', 'callback_data' => "statusbtn|postoggle|{$lang}|{$key}"];
                $kb['inline_keyboard'][] = [$mainBtn, $posBtn];
            } else {
                $hidden = statusbtn_is_hidden($lang, $key);
                $mainBtn = ['text' => $hiddenMark . $rendered['text'], 'callback_data' => "statusbtn|text|{$lang}|{$key}", 'style' => $rendered['style']];
                $hideBtn = ['text' => $hidden ? '👁' : '🚫', 'callback_data' => "statusbtn|hidetog|{$lang}|{$key}", 'style' => $hidden ? 'danger' : 'primary'];
                $kb['inline_keyboard'][] = [$mainBtn, $hideBtn];
            }
        }
        $captions = [
            'color' => "🎨 <b>رنگ‌بندی دکمه‌های صفحه‌ی وضعیت</b>\n\nاین لیست، پیش‌نمایش زنده‌ست 👁\nروی هر دکمه بزن تا رنگش عوض بشه 👇\n🔵 آبی ← 🟢 سبز ← 🔴 قرمز ← ⚪️ پیش‌فرض\n🚫 = دکمه‌ی پنهان‌شده",
            'emoji' => "🎭 <b>ایموجی دکمه‌های صفحه‌ی وضعیت</b>\n\nروی هر دکمه بزن و ایموجی جدیدش رو بفرست 👇\n📍 دکمه‌ی کوچیک کنارش (⬅️/➡️) جای ایموجی رو (چپ یا راست متن) عوض می‌کنه\n💎 ایموجی پریمیوم همیشه سمت راست میاد (محدودیت خود تلگرامه)\n🚫 = دکمه‌ی پنهان‌شده",
            'text' => "✏️ <b>نام و نمایش دکمه‌های صفحه‌ی وضعیت</b>\n\n🔹 روی متن دکمه بزن تا متنش رو عوض کنی\n🔹 دکمه‌ی کوچیک کنارش، اون دکمه رو کلاً از دید کاربر پنهان یا آشکار می‌کنه\n🚫 = دکمه‌ی پنهان‌شده (کاربر اصلاً نمی‌بینتش) | 👁 = دوباره آشکارش می‌کنه",
        ];
        $info = $captions[$cat] ?? $captions['color'];
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت به منوی قبلی', 'callback_data' => "statusbtn|list|{$lang}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '❌ بستن', 'callback_data' => 'bt_close', 'style' => 'danger']];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('statusbtn_cycle_style')) {
    // mirrors btncolor-'s exact 4-state cycle (none/primary/success/danger)
    // - "none" clears just the style field, falling back to that button's
    // own hardcoded default, same granular per-field unset the rest of this
    // family already uses
    function statusbtn_cycle_style($lang, $key)
    {
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        if (!is_array($be)) {
            $be = [];
        }
        $order = ['', 'primary', 'success', 'danger'];
        $cur = $be[$lang]['users.status.infoFull'][$key]['style'] ?? '';
        if (!in_array($cur, $order, true)) {
            $cur = '';
        }
        $idx = array_search($cur, $order, true);
        $next = $order[($idx + 1) % count($order)];
        if ($next === '') {
            unset($be[$lang]['users.status.infoFull'][$key]['style']);
            if (empty($be[$lang]['users.status.infoFull'][$key])) {
                unset($be[$lang]['users.status.infoFull'][$key]);
            }
            if (empty($be[$lang]['users.status.infoFull'])) {
                unset($be[$lang]['users.status.infoFull']);
            }
            if (empty($be[$lang])) {
                unset($be[$lang]);
            }
        } else {
            $be[$lang]['users.status.infoFull'][$key]['style'] = $next;
        }
        update("setting", "button_edit", empty($be) ? null : json_encode($be, JSON_UNESCAPED_UNICODE), null, null);
    }
}
if (!function_exists('statusbtn_toggle_pos')) {
    function statusbtn_toggle_pos($lang, $key)
    {
        $ov = statusbtn_override($lang, $key);
        $cur = (isset($ov['pos']) && $ov['pos'] === 'left') ? 'left' : 'right';
        statusbtn_set_field($lang, $key, 'pos', ($cur === 'left') ? 'right' : 'left');
    }
}
if (!function_exists('statusbtn_is_hidden')) {
    function statusbtn_is_hidden($lang, $key)
    {
        $ov = statusbtn_override($lang, $key);
        return !empty($ov['hidden']);
    }
}
if (!function_exists('statusbtn_toggle_hidden')) {
    function statusbtn_toggle_hidden($lang, $key)
    {
        statusbtn_set_field($lang, $key, 'hidden', !statusbtn_is_hidden($lang, $key));
    }
}
if (!function_exists('statusbtn_order')) {
    // the order lives at $be[$lang]['users.status.infoFull']['__order'] - a
    // sibling of the per-key override entries, using a reserved key none of
    // the 13 real $keyboarddate keys can ever collide with (they're all
    // plain identifiers, never double-underscore-prefixed)
    function statusbtn_order($lang)
    {
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        return (is_array($be) && isset($be[$lang]['users.status.infoFull']['__order']) && is_array($be[$lang]['users.status.infoFull']['__order']))
            ? $be[$lang]['users.status.infoFull']['__order']
            : [];
    }
}
if (!function_exists('statusbtn_set_order')) {
    function statusbtn_set_order($lang, array $order)
    {
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        if (!is_array($be)) {
            $be = [];
        }
        $be[$lang]['users.status.infoFull']['__order'] = array_values($order);
        update("setting", "button_edit", json_encode($be, JSON_UNESCAPED_UNICODE), null, null);
    }
}
if (!function_exists('statusbtn_reset_order')) {
    function statusbtn_reset_order($lang)
    {
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        if (is_array($be) && isset($be[$lang]['users.status.infoFull']['__order'])) {
            unset($be[$lang]['users.status.infoFull']['__order']);
            if (empty($be[$lang]['users.status.infoFull'])) {
                unset($be[$lang]['users.status.infoFull']);
            }
            if (empty($be[$lang])) {
                unset($be[$lang]);
            }
            update("setting", "button_edit", empty($be) ? null : json_encode($be, JSON_UNESCAPED_UNICODE), null, null);
        }
    }
}
if (!function_exists('statusbtn_apply_order')) {
    // saved order first (dropping any stale key no longer in $allKeys), then
    // anything not yet mentioned appended in its original definition order -
    // so a future 14th status button just shows up at the end instead of
    // vanishing until the admin re-visits چیدمان
    function statusbtn_apply_order(array $allKeys, array $savedOrder)
    {
        $ordered = [];
        foreach ($savedOrder as $k) {
            if (in_array($k, $allKeys, true) && !in_array($k, $ordered, true)) {
                $ordered[] = $k;
            }
        }
        foreach ($allKeys as $k) {
            if (!in_array($k, $ordered, true)) {
                $ordered[] = $k;
            }
        }
        return $ordered;
    }
}
if (!function_exists('statusbtn_ordered_keys')) {
    function statusbtn_ordered_keys($lang, $textbotlang)
    {
        return statusbtn_apply_order(array_keys(statusbtn_defs($textbotlang)), statusbtn_order($lang));
    }
}
if (!function_exists('statusbtn_layout_payload')) {
    // pick-then-swap, index-addressed exactly like help_lay_pick/help_lay_swap:
    // first tap marks an item selected (🔵), second tap on any OTHER item
    // swaps their positions in the saved order and clears the selection
    function statusbtn_layout_payload($lang, $textbotlang, $selectedIdx = null)
    {
        // only the service screen's own buttons can be reordered here - the
        // گزارش مصرف sub-screen's rows are edited elsewhere in this same tool
        $orderedKeys = array_values(array_filter(
            statusbtn_ordered_keys($lang, $textbotlang),
            fn($k) => strpos($k, 'ur_') !== 0
        ));
        $kb = ['inline_keyboard' => []];
        foreach ($orderedKeys as $idx => $key) {
            $rendered = statusbtn_render($lang, $key, $textbotlang);
            $label = (statusbtn_is_hidden($lang, $key) ? '🚫 ' : '') . $rendered['text'];
            $isSel = ($selectedIdx !== null && (int) $selectedIdx === $idx);
            if ($isSel) {
                $btn = ['text' => '🔵 ' . $label, 'callback_data' => "statusbtn|laycancel|{$lang}"];
            } elseif ($selectedIdx !== null) {
                $btn = ['text' => $label, 'callback_data' => "statusbtn|layswap|{$lang}|{$selectedIdx}|{$idx}"];
            } else {
                $btn = ['text' => $label, 'callback_data' => "statusbtn|laypick|{$lang}|{$idx}"];
            }
            $btn['style'] = $rendered['style'];
            $kb['inline_keyboard'][] = [$btn];
        }
        $kb['inline_keyboard'][] = [['text' => '🔁 ریست چیدمان', 'callback_data' => "statusbtn|layreset|{$lang}"]];
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت به منوی قبلی', 'callback_data' => "statusbtn|list|{$lang}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '❌ بستن', 'callback_data' => 'bt_close', 'style' => 'danger']];
        $info = "📐 <b>چیدمان دکمه‌های صفحه‌ی وضعیت</b>\n\nروی یه دکمه بزن تا انتخاب بشه 🔵، بعد روی دکمه‌ی مقصد بزن تا جاشون با هم عوض بشه 👇\nرنگ و ایموجی دکمه‌ها همراهشون منتقل می‌شن\n🚫 = دکمه‌ی پنهان‌شده";
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('statusbtn_detail_payload')) {
    function statusbtn_detail_payload($lang, $key, $textbotlang)
    {
        $defs = statusbtn_defs($textbotlang);
        if (!isset($defs[$key])) {
            return statusbtn_list_payload($lang, $textbotlang);
        }
        $d = $defs[$key];
        $cur = statusbtn_current($lang, $key, $textbotlang);
        $ov = statusbtn_override($lang, $key);
        $curPos = (isset($ov['pos']) && $ov['pos'] === 'left') ? 'left' : 'right';
        $curSimple = !empty($ov['simple']);
        $hasEmoji = !empty($ov['emoji']) || !empty($ov['emojiIcon']);
        $previewBtn = statusbtn_render($lang, $key, $textbotlang);
        $previewBtn['callback_data'] = 'none';
        $info = "🔘 <b>ویرایش {$d['name']}</b>\n➖➖➖➖➖➖➖➖➖➖\n";
        if ($key === 'changestatus') {
            $info .= "ℹ️ این دکمه بین «روشن کردن» و «خاموش کردن» جابه‌جا می‌شه (بسته به وضعیت فعلی سرویس). اگه متن سفارشی بذاری، همون متن به‌جای هر دو حالت نشون داده می‌شه.\n";
        }
        $info .= "👁 پیش‌نمایش زنده 👇";
        $kb = ['inline_keyboard' => []];
        $kb['inline_keyboard'][] = [$previewBtn];
        $kb['inline_keyboard'][] = [['text' => '✏️ ویرایش متن', 'callback_data' => "statusbtn|text|{$lang}|{$key}", 'style' => 'primary']];
        $kb['inline_keyboard'][] = [
            ['text' => ($cur['style'] === 'primary' ? '✅ ' : '') . '🔵 آبی', 'callback_data' => "statusbtn|style|{$lang}|{$key}|primary", 'style' => 'primary'],
            ['text' => ($cur['style'] === 'success' ? '✅ ' : '') . '🟢 سبز', 'callback_data' => "statusbtn|style|{$lang}|{$key}|success", 'style' => 'success'],
            ['text' => ($cur['style'] === 'danger' ? '✅ ' : '') . '🔴 قرمز', 'callback_data' => "statusbtn|style|{$lang}|{$key}|danger", 'style' => 'danger'],
        ];
        $kb['inline_keyboard'][] = [['text' => ($hasEmoji ? '✅ ' : '') . '💎 ایموجی دکمه', 'callback_data' => "statusbtn|emoji|{$lang}|{$key}", 'style' => 'primary']];
        $kb['inline_keyboard'][] = [['text' => ($curSimple ? '✅ ' : '') . '🎭 حالت ساده (بدون ایموجی)', 'callback_data' => "statusbtn|simple|{$lang}|{$key}", 'style' => 'primary']];
        $kb['inline_keyboard'][] = [
            ['text' => ($curPos === 'right' ? '✅ ' : '') . '➡️ راست', 'callback_data' => "statusbtn|pos|{$lang}|{$key}|right", 'style' => 'primary'],
            ['text' => ($curPos === 'left' ? '✅ ' : '') . '⬅️ چپ', 'callback_data' => "statusbtn|pos|{$lang}|{$key}|left", 'style' => 'primary'],
        ];
        $kb['inline_keyboard'][] = [['text' => '🔁 ریست این دکمه', 'callback_data' => "statusbtn|rst|{$lang}|{$key}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => "statusbtn|list|{$lang}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '❌ بستن', 'callback_data' => 'bt_close', 'style' => 'danger']];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('bottext_key_touched')) {
    function bottext_key_touched($key, $lang)
    {
        $setting = select("setting", "*", null, null, "select");
        $te = json_decode((string) ($setting['text_edit'] ?? ''), true);
        $layout = json_decode((string) ($setting['keyboardmain'] ?? ''), true);
        $st = (is_array($layout) && isset($layout['text_stickers']) && is_array($layout['text_stickers'])) ? $layout['text_stickers'] : [];
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        $textCustom = is_array($te) && isset($te[$lang]) && bottext_dotted_isset($te[$lang], $key);
        $stickerCustom = bt_media_lookup($st, $key, $lang) !== '';
        $btnCustom = is_array($be) && !empty($be[$lang][$key]);
        return $textCustom || $stickerCustom || $btnCustom;
    }
}
if (!function_exists('config_delivery_cfgcol_touched')) {
    function config_delivery_cfgcol_touched($lang)
    {
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        return !empty($be[$lang]['configDisplay']) || (string) ($setting['configColOrder'] ?? '') !== '';
    }
}
if (!function_exists('config_delivery_default_mode')) {
    // Mode '1' = deliver the full service message only.
    // Mode '2' = that same message, PLUS a second message carrying the
    //            config-picker buttons (useful for multi-config services).
    // Every panel starts on mode 1, for both purchase and usertest, including
    // panels added later - the admin asked for that explicitly, so it is a
    // hardcoded default rather than something inherited from the legacy
    // bot-wide toggle.
    function config_delivery_default_mode()
    {
        return '1';
    }
}
if (!function_exists('config_delivery_map')) {
    // shape: {"purchase": {"<code_panel>": "1"|"2"}, "usertest": {...}}
    // Only NON-default values are stored, so an untouched install keeps this
    // column NULL and every panel simply answers with the default.
    function config_delivery_map()
    {
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['configDeliveryMode'] ?? ''), true);
        return is_array($m) ? $m : [];
    }
}
if (!function_exists('config_delivery_map_save')) {
    function config_delivery_map_save(array $map)
    {
        update("setting", "configDeliveryMode", empty($map) ? null : json_encode($map, JSON_UNESCAPED_UNICODE), null, null);
    }
}
if (!function_exists('config_delivery_mode')) {
    function config_delivery_mode($kind, $codePanel)
    {
        $map = config_delivery_map();
        $v = ($codePanel === null) ? null : ($map[$kind][$codePanel] ?? null);
        return in_array($v, ['1', '2'], true) ? $v : config_delivery_default_mode();
    }
}
if (!function_exists('config_delivery_set_mode')) {
    function config_delivery_set_mode($kind, $codePanel, $mode)
    {
        if (!in_array($mode, ['1', '2'], true)) {
            return;
        }
        $map = config_delivery_map();
        if ($mode === config_delivery_default_mode()) {
            // storing only deviations keeps "has the admin touched this?"
            // answerable without a separate flag
            unset($map[$kind][$codePanel]);
            if (empty($map[$kind])) {
                unset($map[$kind]);
            }
        } else {
            $map[$kind][$codePanel] = $mode;
        }
        config_delivery_map_save($map);
    }
}
if (!function_exists('config_delivery_qr_on')) {
    // 📷 QR with the delivered service - on unless the admin switched it off
    // for this panel and kind. Like the modes, only the deviation is stored.
    function config_delivery_qr_on($kind, $codePanel)
    {
        $map = config_delivery_map();
        return $codePanel === null || empty($map['qroff'][$kind][$codePanel]);
    }
    function config_delivery_set_qr($kind, $codePanel, $on)
    {
        $map = config_delivery_map();
        if ($on) {
            unset($map['qroff'][$kind][$codePanel]);
            if (isset($map['qroff'][$kind]) && empty($map['qroff'][$kind])) {
                unset($map['qroff'][$kind]);
            }
            if (isset($map['qroff']) && empty($map['qroff'])) {
                unset($map['qroff']);
            }
        } else {
            $map['qroff'][$kind][$codePanel] = true;
        }
        config_delivery_map_save($map);
    }
}
if (!function_exists('config_delivery_panel_reset')) {
    // $kind = null resets BOTH kinds for this panel (kept for completeness);
    // a specific kind resets ONLY that one - used by the kind-scoped screens
    // so resetting purchase never silently also resets usertest, or vice versa
    function config_delivery_panel_reset($codePanel, $kind = null)
    {
        $map = config_delivery_map();
        $kinds = ($kind === null) ? ['purchase', 'usertest'] : [$kind];
        foreach ($kinds as $k) {
            unset($map[$k][$codePanel], $map['qroff'][$k][$codePanel]);
            if (isset($map[$k]) && empty($map[$k])) {
                unset($map[$k]);
            }
            if (isset($map['qroff'][$k]) && empty($map['qroff'][$k])) {
                unset($map['qroff'][$k]);
            }
        }
        if (isset($map['qroff']) && empty($map['qroff'])) {
            unset($map['qroff']);
        }
        config_delivery_map_save($map);
    }
}
if (!function_exists('config_delivery_touched')) {
    function config_delivery_touched($kind = null)
    {
        $map = config_delivery_map();
        if ($kind !== null) {
            return !empty($map[$kind]);
        }
        return !empty($map['purchase']) || !empty($map['usertest']);
    }
}
if (!function_exists('config_delivery_mode_fa')) {
    function config_delivery_mode_fa($mode)
    {
        return $mode === '1' ? '۱' : '۲';
    }
}
if (!function_exists('config_delivery_back_cb')) {
    // 'u' = opened from 🔑 تنظیم اکانت تست, anything else = from the
    // 🛒 پیام‌های مراحل خرید group. Threaded through every sub-screen so a
    // round-trip cannot lose track of where the admin actually came from.
    function config_delivery_back_cb($lang, $origin)
    {
        return ($origin === 'u')
            ? "bt_edit|{$lang}|users.usertest.selectUsernamePrompt"
            : "bt_group|{$lang}|buyflow";
    }
}
if (!function_exists('config_delivery_panels_payload')) {
    function config_delivery_panels_payload($lang, $origin = 'b')
    {
        $kind = ($origin === 'u') ? 'usertest' : 'purchase';
        $kindLabel = ($kind === 'usertest') ? 'اکانت تست' : 'خرید';
        $panels = select("marzban_panel", "*", null, null, "fetchAll");
        if (!is_array($panels)) {
            $panels = [];
        }
        $info = "📌 <b>نحوه‌ی نمایش کانفیگ (هنگام {$kindLabel})</b>\n➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "تعیین می‌کنه وقتی کاربر " . ($kind === 'usertest' ? 'اکانت تست می‌گیره' : 'سرویس می‌خره') . "، بعد از تحویل چی ببینه.\n";
        $info .= "برای هر پنل جداست.\n";
        $info .= "➖➖➖➖➖➖➖➖➖➖\n";
        $info .= empty($panels) ? "⚠️ هنوز هیچ پنلی اضافه نشده." : "👇 اول پنل رو انتخاب کن:";
        $kb = ['inline_keyboard' => []];
        $map = config_delivery_map();
        foreach ($panels as $p) {
            $code = $p['code_panel'];
            $m = config_delivery_mode($kind, $code);
            $touched = isset($map[$kind][$code]);
            $label = "🖥 {$p['name_panel']}  •  حالت " . config_delivery_mode_fa($m);
            $kb['inline_keyboard'][] = [['text' => $label, 'callback_data' => "cfgdeliv|p|{$lang}|{$code}|{$origin}", 'style' => 'primary']];
        }
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => config_delivery_back_cb($lang, $origin), 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '❌ بستن', 'callback_data' => 'bt_close', 'style' => 'danger']];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('config_delivery_panel_payload')) {
    function config_delivery_panel_payload($lang, $codePanel, $origin = 'b')
    {
        $kind = ($origin === 'u') ? 'usertest' : 'purchase';
        $kindLabel = ($kind === 'usertest') ? 'اکانت تست' : 'خرید';
        $panel = select("marzban_panel", "*", "code_panel", $codePanel, "select");
        $name = is_array($panel) ? ($panel['name_panel'] ?? $codePanel) : $codePanel;
        $cur = config_delivery_mode($kind, $codePanel);
        $info = "🖥 <b>پنل: " . htmlspecialchars((string) $name, ENT_QUOTES) . "</b>\n";
        $info .= "📌 نحوه‌ی نمایش کانفیگ (هنگام {$kindLabel})\n➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "بعد از اینکه سرویس تحویل داده شد، کاربر چی ببینه؟\n\n";
        $info .= "🔹 <b>حالت ۱ — فقط پیام کامل</b>\n";
        $info .= "مشخصات سرویس (نام، لوکیشن، مدت، حجم) و لینک اتصال، توی یک پیام.\n\n";
        $info .= "🔹 <b>حالت ۲ — پیام کامل + صفحه‌ی کانفیگ</b>\n";
        $info .= "همون پیام بالا، بعلاوه‌ی یه پیام جدا با دکمه‌های انتخاب کانفیگ.\n";
        $info .= "مناسب سرویس‌هایی که چند تا کانفیگ دارن.\n";
        $info .= "➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "الان: <b>حالت " . config_delivery_mode_fa($cur) . "</b>";
        $qrOn = config_delivery_qr_on($kind, $codePanel);
        $info .= "\n\n📷 <b>QR کد همراه پیام:</b> " . ($qrOn ? "روشن ✅" : "خاموش ❌") . "\n";
        $info .= "QR لینک اشتراک رو نشون می‌ده. اگه لینک اشتراک این پنل خاموش باشه، فقط وقتی سرویس یک کانفیگ داره QR فرستاده می‌شه.";
        $kb = ['inline_keyboard' => []];
        $kb['inline_keyboard'][] = [
            ['text' => ($cur === '1' ? '✅ ' : '') . 'حالت ۱ — فقط پیام کامل', 'callback_data' => "cfgdeliv|set|{$lang}|{$codePanel}|{$kind}|1|{$origin}", 'style' => $cur === '1' ? 'success' : 'primary'],
        ];
        $kb['inline_keyboard'][] = [
            ['text' => ($cur === '2' ? '✅ ' : '') . 'حالت ۲ — + صفحه‌ی کانفیگ', 'callback_data' => "cfgdeliv|set|{$lang}|{$codePanel}|{$kind}|2|{$origin}", 'style' => $cur === '2' ? 'success' : 'primary'],
        ];
        $kb['inline_keyboard'][] = [
            ['text' => $qrOn ? '📷 ارسال QR کد: روشن ✅' : '📷 ارسال QR کد: خاموش ❌', 'callback_data' => "cfgdeliv|qr|{$lang}|{$codePanel}|{$kind}|{$origin}", 'style' => $qrOn ? 'success' : 'danger'],
        ];
        $kb['inline_keyboard'][] = [['text' => bt_section_meta('cfgdeliv_edit')['label'], 'callback_data' => 'bt_sep|cfgdeliv_edit']];
        if ($kind === 'purchase') {
            $kb['inline_keyboard'][] = [['text' => '📝 پیام کامل (حالت ۱)', 'callback_data' => "cfgdeliv|msg|{$lang}|{$codePanel}|{$origin}|ap", 'style' => 'primary']];
            $kb['inline_keyboard'][] = [['text' => '📝 کپشن صفحه‌ی کانفیگ (حالت ۲)', 'callback_data' => "cfgdeliv|msg|{$lang}|{$codePanel}|{$origin}|cb", 'style' => 'primary']];
        } else {
            $kb['inline_keyboard'][] = [['text' => '📝 پیام کامل (حالت ۱)', 'callback_data' => "cfgdeliv|msg|{$lang}|{$codePanel}|{$origin}|at", 'style' => 'primary']];
            $kb['inline_keyboard'][] = [['text' => '📝 کپشن صفحه‌ی کانفیگ (حالت ۲)', 'callback_data' => "cfgdeliv|msg|{$lang}|{$codePanel}|{$origin}|ct", 'style' => 'primary']];
        }
        $kb['inline_keyboard'][] = [['text' => '🎨 دکمه‌ها و ترتیب کانفیگ‌ها (مشترک بین خرید و تست)', 'callback_data' => "cfgdeliv|cfgcol|{$lang}|{$codePanel}|{$origin}", 'style' => 'primary']];
        $kb['inline_keyboard'][] = [['text' => '🔁 ریست حالت این پنل به پیش‌فرض', 'callback_data' => "cfgdeliv|rst|{$lang}|{$codePanel}|{$kind}|{$origin}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت به لیست پنل‌ها', 'callback_data' => "cfgdeliv|list|{$lang}|{$origin}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '❌ بستن', 'callback_data' => 'bt_close', 'style' => 'danger']];
        return [$info, json_encode($kb)];
    }
}if (!function_exists('bottext_reset_keys')) {
    // The single place that knows every store a bot-message item can be
    // customized in. The per-item reset, the 🛒 group reset and the whole-list
    // reset all go through here, so they cannot drift apart again.
    //
    // Each of them used to clear only the text and the sticker, and did the
    // sticker bluntly - unset($map[$key]) drops EVERY language's sticker, not
    // just the one being reset. None of them touched setting.button_edit, so a
    // "reset to default" left per-button labels/colours (and the usertest item's
    // config-column settings) exactly where they were.
    //
    // Everything here is scoped to $lang. configColOrder is the one genuinely
    // global setting, so it is cleared only when its owning item is reset.
    // $parts limits WHICH stores get cleared: 'text' = the message text,
    // 'media' = its sticker/reaction, 'buttons' = its custom buttons (and the
    // config-column settings the usertest item owns). Defaults to all three,
    // so the per-item and per-group resets that call this are unchanged.
    function bottext_reset_keys(array $keys, $lang, array $parts = ['text', 'media', 'buttons'])
    {
        $doText = in_array('text', $parts, true);
        $doMedia = in_array('media', $parts, true);
        $doButtons = in_array('buttons', $parts, true);
        if (!$doText && !$doMedia && !$doButtons) {
            return;
        }
        $setting = select("setting", "*", null, null, "select");
        $map = json_decode((string) ($setting['text_edit'] ?? ''), true);
        if (!is_array($map)) {
            $map = [];
        }
        $layout = json_decode((string) ($setting['keyboardmain'] ?? ''), true);
        if (!is_array($layout)) {
            $layout = [];
        }
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        if (!is_array($be)) {
            $be = [];
        }
        $clearColOrder = false;
        $clearColOrderBuy = false;
        foreach ($keys as $key) {
            if ($doText && isset($map[$lang]) && is_array($map[$lang])) {
                bottext_dotted_unset($map[$lang], $key);
            }
            if ($doMedia && isset($layout['text_stickers']) && is_array($layout['text_stickers'])) {
                bt_media_unset($layout['text_stickers'], $key, $lang);
            }
            if ($doMedia && isset($layout['text_reactions']) && is_array($layout['text_reactions'])) {
                bt_media_unset($layout['text_reactions'], $key, $lang);
            }
            if ($doButtons) {
                unset($be[$lang][$key]);
                if ($key === 'users.usertest.selectUsernamePrompt') {
                    // this item also owns the config-column display settings
                    unset($be[$lang]['configDisplay']);
                    $clearColOrder = true;
                }
                if ($key === 'users.status.getConfigHintBuy') {
                    // buy-only sibling of the config-column settings above
                    unset($be[$lang]['configDisplayBuy']);
                    $clearColOrderBuy = true;
                }
            }
        }
        if (isset($map[$lang]) && empty($map[$lang])) {
            unset($map[$lang]);
        }
        if (isset($be[$lang]) && empty($be[$lang])) {
            unset($be[$lang]);
        }
        if ($doText) {
            update("setting", "text_edit", empty($map) ? null : json_encode($map, JSON_UNESCAPED_UNICODE), null, null);
        }
        if ($doButtons) {
            update("setting", "button_edit", empty($be) ? null : json_encode($be, JSON_UNESCAPED_UNICODE), null, null);
        }
        // keyboardmain carries the whole main menu - only ever write it back if
        // it still looks like a real layout, never a decode failure
        if ($doMedia && !empty($layout) && isset($layout['keyboard'])) {
            update("setting", "keyboardmain", json_encode($layout, JSON_UNESCAPED_UNICODE), null, null);
        }
        if ($clearColOrder) {
            update("setting", "configColOrder", null, null, null);
        }
        if ($clearColOrderBuy) {
            update("setting", "configColOrderBuy", null, null, null);
        }
    }
}
if (!function_exists('mainmenu_appearance_reset')) {
    // Clears every per-button appearance override the main-menu button screens
    // can set (colour for both keyboard modes, tap sticker, hidden flag,
    // renamed label, emoji in all three of its forms), plus the two global
    // emoji switches.
    //
    // Deliberately preserved: the row/column STRUCTURE of $layout['keyboard']
    // and each button's 'text' key. Those are the menu's identity, not
    // customizations - dropping them would leave the bot with no main menu at
    // all. Also preserved: text_stickers / text_reactions, which live in the
    // same blob but belong to the message manager and are reset separately by
    // bottext_reset_keys().
    // $parts: any of 'sticker', 'color', 'emoji', 'visibility'. Defaults to all
    // four, so callers that want a full wipe need not spell it out.
    // table.php's factory keyboardmain ships these keys with "hidden":true, so
    // "reset to default" means restoring THAT - not making every button visible,
    // which is what both reset paths used to do.
    function mainmenu_default_hidden_keys()
    {
        return ['text_extend', 'text_wheel_luck', 'text_Tariff_list', 'text_support', 'text_change_language', 'text_affiliates'];
    }
    function mainmenu_appearance_reset(array $parts = ['sticker', 'color', 'emoji', 'visibility'])
    {
        $mmFields = [];
        if (in_array('sticker', $parts, true)) {
            $mmFields[] = 'sticker';
        }
        if (in_array('color', $parts, true)) {
            $mmFields[] = 'style';
            $mmFields[] = 'style_reply';
        }
        if (in_array('emoji', $parts, true)) {
            $mmFields[] = 'emoji';
            $mmFields[] = 'emoji_pos';
            $mmFields[] = 'icon_emoji';
        }
        if (in_array('visibility', $parts, true)) {
            $mmFields[] = 'hidden';
            $mmFields[] = 'custom_text';
        }
        if (empty($mmFields)) {
            return false;
        }
        $setting = select("setting", "*", null, null, "select");
        $layout = json_decode((string) ($setting['keyboardmain'] ?? ''), true);
        // never write back a decode failure - that would wipe the whole menu
        if (!is_array($layout) || !isset($layout['keyboard']) || !is_array($layout['keyboard'])) {
            return false;
        }
        foreach ($layout['keyboard'] as $mm_r => $mm_row) {
            if (!is_array($mm_row)) {
                continue;
            }
            foreach ($mm_row as $mm_c => $mm_btn) {
                if (!is_array($mm_btn)) {
                    continue;
                }
                foreach ($mmFields as $mm_f) {
                    unset($layout['keyboard'][$mm_r][$mm_c][$mm_f]);
                }
            }
        }
        if (in_array('visibility', $parts, true)) {
            $mm_def_hidden = mainmenu_default_hidden_keys();
            foreach ($layout['keyboard'] as $mm_r => $mm_row) {
                if (!is_array($mm_row)) {
                    continue;
                }
                foreach ($mm_row as $mm_c => $mm_btn) {
                    if (is_array($mm_btn) && isset($mm_btn['text']) && in_array($mm_btn['text'], $mm_def_hidden, true)) {
                        $layout['keyboard'][$mm_r][$mm_c]['hidden'] = true;
                    }
                }
            }
        }
        if (in_array('emoji', $parts, true)) {
            unset($layout['simple_emoji'], $layout['emoji_pos_global']);
        }
        update("setting", "keyboardmain", json_encode($layout, JSON_UNESCAPED_UNICODE), null, null);
        return true;
    }
}
if (!function_exists('bt_reset_sections')) {
    // The 🔁 reset picker's catalogue. Each entry is one selectable category:
    //   bit    - its slot in the stateless mask carried in callback_data
    //   label  - the row text
    //   sep    - a white divider label rendered ABOVE this row, or ''
    // Order here is the order shown on screen.
    function bt_reset_sections()
    {
        return [
            'msg_text' => ['bit' => 1, 'label' => '📝 متن پیام‌ها', 'sep' => ''],
            'msg_media' => ['bit' => 2, 'label' => '🖼 استیکر و ری‌اکشن پیام‌ها', 'sep' => ''],
            'msg_buttons' => ['bit' => 4, 'label' => '🔘 دکمه‌های داخل پیام‌ها', 'sep' => ''],
            'warnings' => ['bit' => 8, 'label' => '🔋 هشدارهای مصرف بسته', 'sep' => ''],
            'mm_sticker' => ['bit' => 16, 'label' => '✨ استیکر دکمه‌های منو', 'sep' => '⬇️ دکمه‌های منوی اصلی (پایین صفحه‌ی کاربر)'],
            'mm_color' => ['bit' => 32, 'label' => '🎨 رنگ دکمه‌های منو', 'sep' => ''],
            'mm_emoji' => ['bit' => 64, 'label' => '😀 ایموجی دکمه‌های منو', 'sep' => ''],
            'mm_visibility' => ['bit' => 128, 'label' => '👁 نام و مخفی‌بودن دکمه‌ها', 'sep' => ''],
        ];
    }
}
if (!function_exists('bt_reset_counts')) {
    // How many things are ACTUALLY customized right now in each category, so
    // the picker can tell the admin exactly what a reset would destroy instead
    // of making them guess.
    function bt_reset_counts($lang, $textbotlang)
    {
        $c = ['msg_text' => 0, 'msg_media' => 0, 'msg_buttons' => 0, 'warnings' => 0,
              'mm_sticker' => 0, 'mm_color' => 0, 'mm_emoji' => 0, 'mm_visibility' => 0];
        $setting = select("setting", "*", null, null, "select");
        $te = json_decode((string) ($setting['text_edit'] ?? ''), true);
        $layout = json_decode((string) ($setting['keyboardmain'] ?? ''), true);
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        $st = (is_array($layout) && isset($layout['text_stickers']) && is_array($layout['text_stickers'])) ? $layout['text_stickers'] : [];
        $re = (is_array($layout) && isset($layout['text_reactions']) && is_array($layout['text_reactions'])) ? $layout['text_reactions'] : [];
        foreach (bottext_all_item_keys($textbotlang) as $k) {
            if (is_array($te) && isset($te[$lang]) && bottext_dotted_isset($te[$lang], $k)) {
                $c['msg_text']++;
            }
            if (bt_media_lookup($st, $k, $lang) !== '' || bt_media_lookup($re, $k, $lang) !== '') {
                $c['msg_media']++;
            }
            if (is_array($be) && !empty($be[$lang][$k])) {
                $c['msg_buttons']++;
            }
        }
        if (is_array($be) && !empty($be[$lang]['configDisplay'])) {
            $c['msg_buttons']++;
        }
        if ((string) ($setting['configColOrder'] ?? '') !== '') {
            $c['msg_buttons']++;
        }
        foreach (volumepct_tiers_map() as $tier) {
            if (!is_array($tier)) {
                continue;
            }
            $touched = isset($tier['text'][$lang]) || isset($tier['btnLabel'][$lang]) || isset($tier['sticker'][$lang])
                || isset($tier['style']) || isset($tier['emoji']) || isset($tier['emojiIcon'])
                || isset($tier['pos']) || isset($tier['simple']);
            if ($touched) {
                $c['warnings']++;
            }
        }
        if (is_array($layout) && isset($layout['keyboard']) && is_array($layout['keyboard'])) {
            foreach ($layout['keyboard'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($row as $btn) {
                    if (!is_array($btn)) {
                        continue;
                    }
                    if (isset($btn['sticker'])) {
                        $c['mm_sticker']++;
                    }
                    if (isset($btn['style']) || isset($btn['style_reply'])) {
                        $c['mm_color']++;
                    }
                    if (isset($btn['emoji']) || isset($btn['emoji_pos']) || isset($btn['icon_emoji'])) {
                        $c['mm_emoji']++;
                    }
                    // only a DIFFERENCE from the factory default counts: some
                    // keys ship hidden (mainmenu_default_hidden_keys()), so a
                    // plain "isset" here reported them as customized forever
                    // and made every reset claim it had undone something
                    $mm_def_hidden = in_array($btn['text'] ?? '', mainmenu_default_hidden_keys(), true);
                    if (!empty($btn['hidden']) !== $mm_def_hidden || isset($btn['custom_text'])) {
                        $c['mm_visibility']++;
                    }
                }
            }
            if (isset($layout['simple_emoji']) || isset($layout['emoji_pos_global'])) {
                $c['mm_emoji']++;
            }
        }
        return $c;
    }
}
if (!function_exists('bt_reset_picker_payload')) {
    // The confirmation screen for 🔁 ریست همه به پیش‌فرض. The selection lives
    // entirely in the callback_data as a bitmask - no step, no DB state, so it
    // cannot go stale or leak between admins.
    function bt_reset_picker_payload($lang, $mask, $textbotlang)
    {
        $sections = bt_reset_sections();
        $counts = bt_reset_counts($lang, $textbotlang);
        $total = 0;
        $selectedCount = 0;
        foreach ($sections as $name => $s) {
            $total += $counts[$name];
            if ($mask & $s['bit']) {
                $selectedCount += $counts[$name];
            }
        }
        $info = "🔁 <b>ریست به پیش‌فرض</b>\n➖➖➖➖➖➖➖➖➖➖\n";
        if ($total === 0) {
            $info .= "✨ هیچ تنظیم سفارشی‌ای پیدا نشد - همه‌چیز همین الان روی حالت پیش‌فرضه.\n";
        } else {
            $info .= "انتخاب کن کدوم بخش‌ها به حالت پیش‌فرض برگردن.\n";
            $info .= "فقط ردیف‌هایی که ✅ دارن پاک می‌شن.\n\n";
            $info .= "عدد جلوی هر ردیف = تعداد موردی که همین الان سفارشی شده.\n";
            $info .= "⚠️ این کار برگشت‌ناپذیره.\n";
        }
        $info .= "➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "📊 مجموع سفارشی‌شده: <b>{$total}</b> مورد\n";
        $info .= "🗑 با انتخاب فعلی پاک می‌شه: <b>{$selectedCount}</b> مورد";
        $kb = ['inline_keyboard' => []];
        foreach ($sections as $name => $s) {
            if ($s['sep'] !== '') {
                $kb['inline_keyboard'][] = [['text' => $s['sep'], 'callback_data' => 'bt_rstsep']];
            }
            $on = ($mask & $s['bit']) ? true : false;
            $n = $counts[$name];
            $kb['inline_keyboard'][] = [[
                'text' => ($on ? '✅ ' : '❌ ') . $s['label'] . ' (' . $n . ')',
                'callback_data' => "bt_rsttog|{$lang}|{$mask}|{$s['bit']}",
                'style' => 'primary',
            ]];
        }
        $kb['inline_keyboard'][] = [
            ['text' => '✅ انتخاب همه', 'callback_data' => "bt_rstsel|{$lang}|all", 'style' => 'primary'],
            ['text' => '❌ هیچ‌کدام', 'callback_data' => "bt_rstsel|{$lang}|none", 'style' => 'primary'],
        ];
        $kb['inline_keyboard'][] = [['text' => "🗑 ریست موارد انتخاب‌شده ({$selectedCount})", 'callback_data' => "bt_rstgo|{$lang}|{$mask}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '🔙 انصراف و بازگشت', 'callback_data' => "btact|back|{$lang}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '❌ بستن', 'callback_data' => 'bt_close', 'style' => 'danger']];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('bt_reset_apply_mask')) {
    // Runs exactly the categories the mask selects and returns a human summary
    // of what was cleared, so the admin sees the result instead of a bare "done".
    function bt_reset_apply_mask($lang, $mask, $textbotlang)
    {
        $sections = bt_reset_sections();
        $before = bt_reset_counts($lang, $textbotlang);
        $done = [];
        $parts = [];
        if ($mask & $sections['msg_text']['bit']) {
            $parts[] = 'text';
        }
        if ($mask & $sections['msg_media']['bit']) {
            $parts[] = 'media';
        }
        if ($mask & $sections['msg_buttons']['bit']) {
            $parts[] = 'buttons';
        }
        if (!empty($parts)) {
            bottext_reset_keys(bottext_all_item_keys($textbotlang), $lang, $parts);
        }
        if ($mask & $sections['warnings']['bit']) {
            volumepct_tiers_reset_all_style($lang, null);
        }
        $mmParts = [];
        if ($mask & $sections['mm_sticker']['bit']) {
            $mmParts[] = 'sticker';
        }
        if ($mask & $sections['mm_color']['bit']) {
            $mmParts[] = 'color';
        }
        if ($mask & $sections['mm_emoji']['bit']) {
            $mmParts[] = 'emoji';
        }
        if ($mask & $sections['mm_visibility']['bit']) {
            $mmParts[] = 'visibility';
        }
        if (!empty($mmParts)) {
            mainmenu_appearance_reset($mmParts);
        }
        foreach ($sections as $name => $s) {
            if (($mask & $s['bit']) && $before[$name] > 0) {
                $done[] = "• {$s['label']} ({$before[$name]} مورد)";
            }
        }
        return $done;
    }
}
if (!function_exists('mainmenu_sticker_reset_all')) {
    // the ✨ استیکر پریمیوم دکمه‌ها screen's own reset - stickers only, so it
    // stays scoped exactly like the emoji screen's existing reset button
    function mainmenu_sticker_reset_all()
    {
        $setting = select("setting", "*", null, null, "select");
        $layout = json_decode((string) ($setting['keyboardmain'] ?? ''), true);
        if (!is_array($layout) || !isset($layout['keyboard']) || !is_array($layout['keyboard'])) {
            return false;
        }
        foreach ($layout['keyboard'] as $mm_r => $mm_row) {
            if (!is_array($mm_row)) {
                continue;
            }
            foreach ($mm_row as $mm_c => $mm_btn) {
                if (is_array($mm_btn)) {
                    unset($layout['keyboard'][$mm_r][$mm_c]['sticker']);
                }
            }
        }
        update("setting", "keyboardmain", json_encode($layout, JSON_UNESCAPED_UNICODE), null, null);
        return true;
    }
}
if (!function_exists('strip_leading_emoji')) {
    // Also defined (guarded) in admin.php for the main-menu button screens;
    // duplicated here so channel_button_text() below can use it from the
    // end-user request path (index.php), which never loads admin.php.
    function strip_leading_emoji($s)
    {
        return trim(preg_replace('/^[\x{203C}\x{2049}\x{2139}\x{2194}-\x{2199}\x{21A9}-\x{21AA}\x{231A}-\x{231B}\x{23E9}-\x{23EC}\x{23F0}\x{23F3}\x{24C2}\x{25AA}-\x{25AB}\x{25B6}\x{25C0}\x{25FB}-\x{25FE}\x{2600}-\x{27BF}\x{2934}-\x{2935}\x{2B05}-\x{2B07}\x{2B1B}-\x{2B1C}\x{2B50}\x{2B55}\x{3030}\x{303D}\x{3297}\x{3299}\x{FE0E}\x{FE0F}\x{200D}\x{2764}\x{20E3}\x{1F000}-\x{1FAFF}\x{1F1E6}-\x{1F1FF}\s]*/u', '', (string) $s));
    }
}
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
if (!function_exists('channels_effective_order')) {
    // Applies the admin's custom channel-button order on top of the raw
    // `channels` rows: stored order first (skipping any id no longer
    // present), then any channel NOT yet in that list appended at the end in
    // natural id order - so a channel added after the order was last saved
    // is never silently dropped from the join-gate message.
    function channels_effective_order()
    {
        $rows = select("channels", "*", null, null, "fetchAll");
        if (!is_array($rows)) {
            return [];
        }
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $setting = select("setting", "*", null, null, "select");
        $order = json_decode((string) ($setting['channelButtonsOrder'] ?? ''), true);
        $ordered = [];
        if (is_array($order)) {
            foreach ($order as $oid) {
                $oid = (int) $oid;
                if (isset($byId[$oid])) {
                    $ordered[] = $byId[$oid];
                    unset($byId[$oid]);
                }
            }
        }
        foreach ($byId as $r) {
            $ordered[] = $r;
        }
        return $ordered;
    }
}
if (!function_exists('channel_button_style')) {
    function channel_button_style($row)
    {
        $s = $row['style'] ?? '';
        return in_array($s, ['primary', 'success', 'danger'], true) ? $s : '';
    }
}
if (!function_exists('channel_button_name')) {
    function channel_button_name($row)
    {
        $t = trim((string) ($row['custom_text'] ?? ''));
        return $t !== '' ? $t : (string) $row['remark'];
    }
}
if (!function_exists('channel_button_text')) {
    // Builds the exact button label the real join-gate message shows,
    // applying the emoji override (text or premium) and its per-item
    // left/right position - mirrors the main-menu emoji preview logic
    // (emoji_sticker_editor_payload) but per-row instead of one global
    // switch. Returns [label, iconCustomEmojiId] - the caller sets
    // icon_custom_emoji_id on the button only when the second value isn't ''.
    function channel_button_text($row, $forAdminPreview = false)
    {
        $name = channel_button_name($row);
        $iconEmoji = (string) ($row['icon_emoji'] ?? '');
        $emoji = (string) ($row['emoji'] ?? '');
        $pos = ($row['emoji_pos'] ?? '') === 'left' ? 'left' : 'right';
        $text = $name;
        $iconId = '';
        if ($iconEmoji !== '') {
            $text = strip_leading_emoji($name);
            $iconId = $iconEmoji;
        } elseif ($emoji !== '') {
            $text = ($pos === 'left') ? ($name . ' ' . $emoji) : ($emoji . ' ' . $name);
        }
        if ($forAdminPreview && !empty($row['hidden'])) {
            $text = '🚫 ' . $text;
        }
        return [$text, $iconId];
    }
}
if (!function_exists('channel_buttons_any_customized')) {
    function channel_buttons_any_customized()
    {
        $rows = select("channels", "*", null, null, "fetchAll");
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (($r['style'] ?? '') !== '' || ($r['custom_text'] ?? '') !== '' || ($r['emoji'] ?? '') !== ''
                    || ($r['icon_emoji'] ?? '') !== '' || !empty($r['hidden'])) {
                    return true;
                }
            }
        }
        $setting = select("setting", "*", null, null, "select");
        return (string) ($setting['channelButtonsOrder'] ?? '') !== '';
    }
}
if (!function_exists('channel_buttons_reset')) {
    // $parts: any of 'color', 'emoji', 'rename', 'visibility', 'layout'.
    // Mirrors mainmenu_appearance_reset()'s shape/semantics, applied to the
    // channels table + its order setting. Resetting clears the override
    // columns (NULL) rather than writing the default back over them, so a
    // channel added later never inherits a stale "default" value.
    function channel_buttons_reset(array $parts)
    {
        global $pdo;
        $fields = [];
        if (in_array('color', $parts, true)) {
            $fields[] = 'style';
        }
        if (in_array('emoji', $parts, true)) {
            $fields[] = 'emoji';
            $fields[] = 'icon_emoji';
            $fields[] = 'emoji_pos';
        }
        if (in_array('rename', $parts, true)) {
            $fields[] = 'custom_text';
        }
        if (in_array('visibility', $parts, true)) {
            $fields[] = 'hidden';
        }
        if (!empty($fields)) {
            $setClause = implode(', ', array_map(function ($f) { return "`$f` = NULL"; }, $fields));
            $pdo->exec("UPDATE channels SET $setClause");
        }
        if (in_array('layout', $parts, true)) {
            update("setting", "channelButtonsOrder", null, null, null);
        }
    }
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
if (!function_exists('bt_section_meta')) {
    // white, non-navigating divider rows inside a bottext group list. Tapping
    // one answers the callback with an alert (no message edit) explaining what
    // the items below it are for - Telegram inline keyboards have no real
    // section-header concept, this is the standard workaround.
    function bt_section_meta($section = null)
    {
        $meta = [
            'panel' => [
                'label' => '🛍 مراحل انتخاب خرید',
                'alert' => 'این بخش کپشن‌ها و دکمه‌های مراحل انتخاب پنل، دسته‌بندی و محصول رو مدیریت می‌کنه.',
            ],
            'balance' => [
                'label' => '👤 نام سرویس و موجودی',
                'alert' => 'این بخش پیام‌های نام‌گذاری سرویس و موجودی ناکافی رو مدیریت می‌کنه.',
            ],
            'confirm' => [
                'label' => '🧾 تأیید خرید',
                'alert' => 'کپشن صفحه‌ی تأیید نهایی خرید. دو حالت داره: خرید عادی و خرید عمده - هر کدوم کپشن خودشو داره، ولی دکمه‌های تایید/انصرافشون مشترکه.',
            ],
            'btnstyle' => [
                'label' => '🎨 ظاهر دکمه‌های انتخاب',
                'alert' => 'این بخش رنگ، ترتیب، عرض و ایموجی دکمه‌های پنل، دسته‌بندی و محصول رو مدیریت می‌کنه - نه متن پیام‌ها رو.',
            ],
            'usertest_related' => [
                'label' => '📦 سایر پیام‌ها و دکمه‌های اکانت تست',
                'alert' => 'این دکمه‌ها تو رو به پیام‌ها و دکمه‌های دیگه‌ی مسیر اکانت تست می‌برن (لیست پنل‌ها، پایان اعتبار، نمایش کانفیگ) - نه همین پیامی که الان داری تنظیمش می‌کنی. هیچ‌کدومشون توی لیست اصلی ردیف جدا ندارن؛ راه ورودشون فقط همینجاست.',
            ],
            'usertest_current' => [
                'label' => '⬆️ دکمه‌های بالا برای پیام‌های دیگه بود',
                'alert' => 'از اینجا به بعد دوباره برگشتیم به تنظیمات همین پیام (درخواست نام کاربری اکانت تست).',
            ],
            'processing' => [
                'label' => '⏳ پیام‌های در حال پردازش',
                'alert' => 'این بخش پیام‌های موقتی‌ای رو مدیریت می‌کنه که وقتی کاربر منتظره نشون داده می‌شن (در حال ساخت لینک پرداخت، در حال ساخت سرویس).',
            ],
            'cfgdeliv_link' => [
                'label' => '📌 نحوه‌ی نمایش کانفیگ',
                'alert' => 'این بخش تعیین می‌کنه که آیا یک پیام جداگونه با دکمه‌ی «دریافت کانفیگ» هم بعد از پیام اصلی تحویل سرویس فرستاده بشه یا نه - نه متن پیام‌ها رو.',
            ],
            'cfgdeliv_panels' => [
                'label' => '🖥 اختصاصی‌سازی هر پنل',
                'alert' => 'مقدار سراسری بالا پیش‌فرض همه‌ی پنل‌هاست. با لمس هر پنل می‌تونی فقط همون پنل رو جدا از بقیه تنظیم کنی.',
            ],
            'cfgdeliv_buy' => [
                'label' => '🛒 وقتی کاربر سرویس می‌خره',
                'alert' => 'این تنظیم فقط روی سرویس‌هایی اثر داره که کاربر می‌خره - اکانت تست تنظیم جدا خودشو داره.',
            ],
            'cfgdeliv_test' => [
                'label' => '🎁 وقتی کاربر اکانت تست می‌گیره',
                'alert' => 'این تنظیم فقط روی اکانت تست اثر داره - خرید تنظیم جدا خودشو داره.',
            ],
            'cfgdeliv_edit' => [
                'label' => '✏️ ویرایش متن و دکمه‌ها',
                'alert' => 'برخلاف حالت‌های بالا (که برای هر پنل جدان)، متن و دکمه‌های این پیام‌ها بین همه‌ی پنل‌ها مشترکه - یه بار ویرایش کنی، همه‌جا اعمال می‌شه.',
            ],
            'balance_topup' => [
                'label' => '💳 شارژ کیف پول (کارت‌به‌کارت)',
                'alert' => 'این بخش پیام «رسید ارسال شد» و پیام تایید نهاییِ شارژ کیف پول (و دکمه‌ی تهیه اشتراک زیرش) رو مدیریت می‌کنه.',
            ],
            'myservices_related' => [
                'label' => '📊 پیام‌های دیگه‌ی مرتبط',
                'alert' => 'این دکمه تو رو به پیام دیگه‌ای می‌بره که تو مسیر سرویس‌های من فرستاده می‌شه - نه همین پیامی که الان داری تنظیمش می‌کنی.',
            ],
            'cfgcol_actions' => [
                'label' => '⚙️ تنظیم ترتیب ستون‌ها',
                'alert' => 'با دکمه‌های پایین مشخص کن اول دکمه‌ی «دریافت کانفیگ» نشون داده بشه یا اول نام کانفیگ - دکمه‌های بالاتر (پیش‌نمایش) خودشون قابل لمس‌ان و به صفحه‌ی ویرایش همون آیتم می‌رن.',
            ],
            'genbtn_actions' => [
                'label' => '⚙️ عملیات',
                'alert' => 'ابزارهای بالا (چیدمان، رنگ‌بندی، ایموجی، نام سفارشی) هرکدوم همه‌ی دکمه‌های این بخش رو نشون می‌دن. دکمه‌های پایین (ریست/بازگشت/بستن) کار واقعی انجام می‌دن.',
            ],
            'genbtn_visibility' => [
                'label' => '👁 نمایش و پنهان کردن',
                'alert' => 'با دکمه‌ی پایین می‌تونی این دکمه رو کلاً از جلوی چشم کاربر برداری. پنهان که بشه، اصلاً ساخته نمی‌شه - نه اینکه غیرفعال بشه.',
            ],
            'genbtn_detail_actions' => [
                'label' => '⚙️ عملیات',
                'alert' => 'دکمه‌ی 🔁 ریست، این دکمه رو کامل به حالت پیش‌فرض برمی‌گردونه (متن، رنگ، ایموجی، جای دکمه، و اگه استیکر بستن داره اونم). 🔙 بازگشت و ❌ بستن هم فقط از این صفحه خارج می‌شن و چیزی رو تغییر نمی‌دن.',
            ],
            'genbtn_closesticker' => [
                'label' => '🖼 استیکر دکمه بستن',
                'alert' => 'وقتی کاربر روی ❌ بستنِ همین بخش می‌زنه، این استیکر فرستاده می‌شه و بعد از مدت تعیین‌شده، صفحه و استیکرِ خودِ صفحه و این استیکر با هم پاک می‌شن. این تنظیم فقط مال همین بخشه و دکمه‌ی 🔁 ریست این دکمه، اینو هم به پیش‌فرض برمی‌گردونه.',
            ],
            'myservices_status' => [
                'label' => '📊 وضعیت سرویس (فعال/غیرفعال)',
                'alert' => 'این بخش پیام‌هایی که وضعیت فعال یا غیرفعال بودن سرویس کاربر رو نشون می‌دن مدیریت می‌کنه.',
            ],
            'myservices_usagereport' => [
                'label' => '📊 گزارش مصرف',
                'alert' => "این بخش دو تا متن داره:\n• Alert وقتی پنل سرویس نتونه مصرف تفکیک‌شده بده (ربات موقع زدن دکمه از خود پنل می‌پرسه، پس فقط جایی میاد که واقعاً جوابی نیست).\n• خط پایان لیست لوکیشن‌ها. کپشن عکس سقف ۱۰۲۴ کاراکتر داره و هر لوکیشن حدود ۴۹ تا می‌گیره، پس اگه نودها زیاد باشن ربات سنگین‌ترین‌ها رو نگه می‌داره و بقیه رو توی این خط جمع می‌زنه. {n} = تعداد، {volume} = مجموع حجمشون.",
            ],
            'myservices_panelerror' => [
                'label' => '⚠️ خطای ارتباط با پنل',
                'alert' => 'این پیام وقتی نشون داده می‌شه که کاربر روی سرویسش می‌زنه ولی ربات نمی‌تونه از پنل استعلام بگیره - پنل خاموشه، آدرسش عوض شده، یا توکنش دیگه معتبر نیست. متنش رو طوری بنویس که کاربر بدونه تقصیر خودش نیست و بعداً دوباره امتحان کنه.',
            ],
            'myservices_extend' => [
                'label' => '♻️ تمدید سرویس',
                'alert' => 'این بخش فاکتور تمدید سرویس و پیام هشدار موجودی ناکافیِ همون فاکتور رو مدیریت می‌کنه.',
            ],
            'myservices_changelink' => [
                'label' => '🔗 تغییر لینک اتصال',
                'alert' => 'این بخش کپشن و دکمه‌های صفحه‌ی هشدار «تغییر لینک اتصال» رو مدیریت می‌کنه.',
            ],
            'myservices_configbuy' => [
                'label' => '📥 دریافت کانفیگ (خرید سرویس)',
                'alert' => 'این بخش کپشن و چیدمان دکمه‌های صفحه‌ی «دریافت کانفیگ» رو - فقط برای سرویس‌های خریداری‌شده، جدا از اکانت تست - مدیریت می‌کنه.',
            ],
            'myservices_refresh' => [
                'label' => '🔄 بروزرسانی اطلاعات',
                'alert' => 'این بخش پیام Alert کوچیکی که بعد از لمس «بروزرسانی اطلاعات» نشون داده می‌شه رو مدیریت می‌کنه.',
            ],
            'myservices_transfer' => [
                'label' => '🚚 انتقال سرویس به کاربر دیگر',
                'alert' => 'این بخش کپشن صفحه‌ی شروع «انتقال سرویس به کاربر دیگر» رو مدیریت می‌کنه.',
            ],
            'myservices_linksub' => [
                'label' => '🔗 لینک اشتراک',
                'alert' => 'این بخش کپشن دکمه‌ی «🔗 لینک اشتراک» رو مدیریت می‌کنه - یکی برای حالت QR (بیشتر پنل‌ها)، یکی برای حالت فایل (پنل‌های WireGuard).',
            ],
            'topupdisc_bulk' => [
                'label' => '⚡️ اعمال همگانی',
                'alert' => 'دکمه‌ی زیر یه دسته نیست، بالاتر از همه‌ی دسته‌هاست: هم تخفیف خودکار داره هم کد تخفیف، و روی هر درگاه فعال این زبان کار می‌کنه. با روشن کردنش، تخفیف دسته‌ها و تخفیف تک‌تک درگاه‌ها خاموش می‌شن تا فقط همین یکی اعمال بشه.',
            ],
            'langsw_when' => [
                'label' => '⏱ کِی نشون داده بشه',
                'alert' => 'منوی انتخاب زبان یا خودکار به کاربر نشون داده می‌شه یا اصلاً نه. اگه روشنش کنی، انتخاب کن که فقط بار اولی که کاربر وارد ربات می‌شه بیاد، یا هر بار که /start می‌زنه.',
            ],
            'langsw_langs' => [
                'label' => '🌍 زبان‌های فروشگاه',
                'alert' => 'زبان‌هایی که ربات پشتیبانی می‌کنه. هم توی منوی انتخاب زبان همین‌ها نشون داده می‌شن، هم اگه قانون پایین صفحه روشن باشه، فقط همین‌ها می‌تونن از ربات استفاده کنن. حداقل یکی باید روشن بمونه.',
            ],
            'langsw_look' => [
                'label' => '🎨 ظاهر صفحه‌ی انتخاب زبان',
                'alert' => 'متن بالای صفحه‌ی انتخاب زبان و ظاهر خود دکمه‌ها (چیدمان، رنگ، ایموجی، تغییر نام) - همون چهار ابزاری که بقیه‌ی دکمه‌های ربات هم دارن.',
            ],
            'langsw_access' => [
                'label' => '🚦 چه کسانی سرویس بگیرن',
                'alert' => 'با روشن کردن این گزینه، هر کاربری که زبانش جزو زبان‌های فروشگاه نباشه یه پیام رد می‌گیره و هیچ بخشی از ربات براش کار نمی‌کنه. مدیرها هیچ‌وقت بسته نمی‌شن تا کسی خودشو بیرون ربات جا نذاره.',
            ],
            'topupdisc_code' => [
                'label' => '🎟 کد تخفیف',
                'alert' => 'این سه پیام، مسیر وارد کردن کد تخفیف‌ان: صفحه‌ای که ازش کد می‌خواد، جوابش وقتی کد غلط باشه، و جوابش وقتی کد درست باشه.',
            ],
            'topupdisc_show' => [
                'label' => '📣 اعلام تخفیف در روش پرداخت',
                'alert' => 'این دو بلوک، بالای لیست روش‌های پرداخت به کاربر نشون داده می‌شن تا بدونه چه تخفیفی براش فعاله. پیام مستقل نیستن - داخل همون کپشن نوشته می‌شن.',
            ],
            'topupdisc_line_one' => [
                'label' => '✏️ جمله‌ی تخفیف — وقتی روی یک درگاه ست شده',
                'alert' => 'این دو جمله وقتی به کاربر نشون داده می‌شن که تخفیف روی یک درگاهِ تکی ست شده باشه (یا کد تخفیفی که مال همون درگاهه). مهم نیست کدوم درگاه - همه‌ی درگاه‌ها از همین دو جمله استفاده می‌کنن، پس اینجا اسم درگاه نمی‌بینی. یکی برای تخفیف درصدیه، یکی برای تخفیف با مبلغ ثابت.',
            ],
            'topupdisc_line_all' => [
                'label' => '✏️ جمله‌ی تخفیف — وقتی همگانی ست شده',
                'alert' => 'این دو جمله وقتی نشون داده می‌شن که تخفیف از بخش «اعمال همگانی» ست شده باشه، یعنی روی همه‌ی درگاه‌ها. چون دسته‌ای در کار نیست، متغیر {group} اینجا معنی نداره و توش استفاده نمی‌شه.',
            ],
            'topupdisc_line_group' => [
                'label' => '✏️ جمله‌ی تخفیف — وقتی روی یک دسته ست شده',
                'alert' => 'این دو جمله وقتی نشون داده می‌شن که تخفیف روی کل یه دسته ست شده باشه (کارت به کارت / ریالی / آنلاین ارزی / آفلاین ارزی). اسم اون دسته خودش با {group} داخل جمله نوشته می‌شه، پس یه متن برای هر چهار دسته کافیه.',
            ],
            'topupdisc_line_invoice' => [
                'label' => '🧾 جمله‌ی تخفیف — داخل خودِ فاکتور',
                'alert' => 'این دو جمله داخل خودِ فاکتور پرداخت (هر درگاهی) نشون داده می‌شن، بعد از اینکه کاربر مبلغ رو انتخاب کرده. دقیقاً می‌گن برای همین مبلغ چقدر به موجودی اضافه می‌شه: {bonus} مبلغ هدیه، {amount} مبلغ فاکتور، {value} درصد تخفیف.',
            ],
            'topupdisc_members' => [
                'label' => '💳 درگاه‌های این دسته',
                'alert' => 'دکمه‌ی بالا تخفیف کل این دسته‌ست. دکمه‌های زیر هر درگاه رو جدا تنظیم می‌کنن (تخفیف خودکار و کدهای تخفیف مخصوص همون درگاه). اگه هر دو ست باشن، روی هم سوار نمی‌شن — هرکدوم به کاربر بیشتر بده همون اعمال می‌شه.',
            ],
            'topupdisc_auto_actions' => [
                'label' => '⚙️ عملیات',
                'alert' => 'دکمه‌های بالاتر تنظیمات این تخفیفن. دو دکمه‌ی پایین فوری اجرا می‌شن: یکی فقط سهمیه‌ی استفاده‌شده‌ی کاربرها رو صفر می‌کنه (تنظیمات دست‌نخورده می‌مونه)، یکی همه‌چیز (تنظیمات) رو به پیش‌فرض برمی‌گردونه.',
            ],
            'card_legacy_perlang' => [
                'label' => '🌍 مخصوص همین زبان',
                'alert' => 'این تنظیمات فقط روی همین زبون اثر می‌ذارن - هر زبون می‌تونه مقدار جدای خودش رو داشته باشه؛ تا وقتی برای یه زبون تنظیم نکنید، همون مقدار سراسری (پیش‌فرض) رو ازش استفاده می‌کنه.',
            ],
            'card_legacy_global' => [
                'label' => '🌐 سراسری (همه زبان‌ها)',
                'alert' => 'این‌ها بین همه‌ی زبون‌ها مشترکن - تغییرشون روی کل ربات اثر می‌ذاره، نه فقط یه زبون خاص.',
            ],
            'home_general' => [
                'label' => '💬 پیام‌های عمومی ربات',
                'alert' => 'این بخش پیام‌های مستقل ربات (خوش‌آمدگویی، سؤالات متداول، تعرفه‌ها، قوانین) رو مدیریت می‌کنه - مربوط به هیچ مرحله‌ی خاصی نیستن.',
            ],
            'home_purchase' => [
                'label' => '🛍 پیام‌ها و بخش‌های خرید',
                'alert' => 'این بخش پیام‌های مرتبط با خرید و دو زیرمنوی کامل «مراحل خرید» و «سرویس‌های من» رو مدیریت می‌کنه.',
            ],
            'home_topup' => [
                'label' => '💰 افزایش موجودی',
                'alert' => 'این بخش پیام‌های مسیر «💰 افزایش موجودی» رو مدیریت می‌کنه - از زدن مبلغ تا شارژ شدن کیف پول. جدا از خرید سرویسه.',
            ],
            'topup_flow' => [
                'label' => '⏳ در جریان پرداخت',
                'alert' => 'پیام‌هایی که کاربر بین ثبت مبلغ تا رسیدن رسیدش می‌بینه.',
            ],
            'topup_done' => [
                'label' => '✅ بعد از تایید شارژ',
                'alert' => 'پیام‌هایی که بعد از تایید پرداخت و شارژ شدن کیف پول برای کاربر می‌ره.',
            ],
            'topup_card' => [
                'label' => '💳 کپشن و دکمه‌های کارت به کارت',
                'alert' => 'کپشن صفحه‌ی بسته‌ها، متن مبلغ دلخواه، متن فاکتور و فاکتور منقضی، و ظاهر دکمه‌های پرداخت - همه مخصوص کارت‌به‌کارت. قبلاً توی 🏬 تنظیمات فروشگاه بودن و از اینجا منتقل شدن.',
            ],
            'home_usertest' => [
                'label' => '🔑 اکانت تست',
                'alert' => 'همه‌ی پیام‌ها و دکمه‌های مسیر اکانت تست از داخل همین یه دکمه تنظیم می‌شن: درخواست یوزرنیم، دکمه‌ی بستن لیست پنل‌ها، پیام پایان اعتبار و نحوه‌ی نمایش کانفیگ.',
            ],
            'home_account' => [
                'label' => '👤 حساب کاربری',
                'alert' => 'این بخش کپشن صفحه‌ی «👤 حساب کاربری» و دکمه‌ی بستنش رو مدیریت می‌کنه.',
            ],
            'home_help' => [
                'label' => '📚 آموزش',
                'alert' => 'این بخش پیام‌های بخش «📚 آموزش» و ظاهر دکمه‌های دسته‌بندی و آموزش‌ها رو مدیریت می‌کنه.',
            ],
            'account_main' => [
                'label' => '👤 صفحه‌ی حساب کاربری',
                'alert' => 'کپشن صفحه‌ی حساب کاربری و دکمه‌ی ❌ بستنِ زیرش - همون صفحه‌ای که موجودی و تعداد سرویس کاربر رو نشون می‌ده.',
            ],
            'help_screens' => [
                'label' => '📝 متن صفحه‌های آموزش',
                'alert' => 'کپشن دو صفحه‌ی بخش آموزش (لیست دسته‌بندی‌ها و لیست آموزش‌ها) و پیامی که وقتی آموزش خاموشه نشون داده می‌شه.',
            ],
            'help_buttons' => [
                'label' => '🔘 دکمه‌های بخش آموزش',
                'alert' => 'دکمه‌ی ❌ بستنِ لیست دسته‌بندی آموزش‌ها - متن و رنگش از اینجا تنظیم می‌شه.',
            ],
            'help_defaults' => [
                'label' => '🔌 وضعیت بخش آموزش',
                'alert' => 'روشن یا خاموش بودن کل بخش آموزش برای کاربر. خاموش که باشه دکمه‌ی آموزش از منوی کاربر برداشته می‌شه؛ آموزش‌ها و دسته‌بندی‌ها و تنظیمات نمایششون دست‌نخورده سر جاشون می‌مونن.',
            ],
            'help_cats' => [
                'label' => '🗂 دسته‌بندی‌ها',
                'alert' => 'ساخت و مدیریت دسته‌ها. هر آموزش می‌تونه توی یکی از این‌ها باشه یا بدون دسته بمونه.',
            ],
            'help_items' => [
                'label' => '📚 آموزش‌ها',
                'alert' => 'خودِ آموزش‌ها - افزودن، ویرایش محتوا، و جابه‌جایی بین دسته‌ها.',
            ],
            'help_manage' => [
                'label' => '🗂 مدیریت محتوای آموزش‌ها',
                'alert' => 'افزودن، ویرایش، حذف و ترجمه‌ی خود آموزش‌ها - نه متن پیام‌ها یا ظاهر دکمه‌ها.',
            ],
            'help_style' => [
                'label' => '🎨 ظاهر دکمه‌های آموزش',
                'alert' => 'ترتیب، عرض، رنگ، ایموجی و نمایش/مخفی‌بودن دکمه‌های دسته‌بندی و آموزش‌ها - نه متن پیام‌ها.',
            ],
            'home_admin' => [
                'label' => '🛡 دسترسی ادمین',
                'alert' => 'محدودیت‌هایی که روی خود ادمین‌ها اعمال می‌شه: تعداد اکانت تست و پرداخت هزینه‌ی خرید. روی کاربرها اثری نداره.',
            ],
            'home_features' => [
                'label' => '🎯 قابلیت‌های ربات',
                'alert' => 'پیام‌ها و دکمه‌های قابلیت‌هایی که از «🌐 وضعیت قابلیت‌ها (هر زبان)» روشن/خاموش می‌شن: احراز شماره و قوانین، گردونه شانس، و زیرمجموعه‌گیری.',
            ],
            'verify_flow' => [
                'label' => '📞 احراز شماره و قوانین',
                'alert' => 'پیام‌های درخواست و تایید شماره تماس، پیام رد شدن شماره‌ی کشور غیرمجاز، و دکمه‌های «ارسال شماره» و «پذیرش قوانین».',
            ],
            'wheel_flow' => [
                'label' => '🎲 گردونه شانس',
                'alert' => 'پیام‌های گردونه شانس: برنده شدن، نبردن، شرکت تکراری در ۲۴ ساعت، و خطای دریافت نتیجه.',
            ],
            'referral_flow' => [
                'label' => '🎁 زیرمجموعه‌گیری',
                'alert' => 'پیام‌های زیرمجموعه‌گیری: صفحه‌ی اصلی، هدیه عضویت، پورسانت خرید، و دکمه‌های «دریافت هدیه» و «اشتراک لینک».',
            ],
            'home_other' => [
                'label' => '💬 سایر پیام‌ها',
                'alert' => 'پیام‌هایی که هنوز به هیچ بخشی تعلق ندارن و جای مشخصی براشون تعریف نشده. اگه اینجا چیزی دیدی که فکر می‌کنی باید توی یکی از بخش‌های بالا باشه، بگو تا منتقلش کنم.',
            ],
            'home_tools' => [
                'label' => '⚙️ ابزارها و تنظیمات جانبی',
                'alert' => 'این بخش ابزارهای جانبی (ظاهر دکمه‌های منوی اصلی، تنظیمات تغییر زبان) رو مدیریت می‌کنه - نه متن پیام‌ها رو.',
            ],
        ];
        if ($section === null) {
            return $meta;
        }
        return $meta[$section] ?? ['label' => (string) $section, 'alert' => '📌 این یک برچسب بخشه.'];
    }
}
if (!function_exists('genbtn_alias_map')) {
    // Telegram's callback_data is capped at 64 bytes, and the full dotted item
    // keys this system otherwise uses (e.g. "users.sell.selectUsernamePrompt")
    // eat most of that budget on their own - short aliases keep every gbtn|
    // callback comfortably under the limit. 'cf' is the confirm/cancel row
    // shared by all three "تأیید خرید" screens (normal, discounted, custom-
    // volume) - they render byte-identical buttons in the live code today, so
    // one shared override avoids three copies of the same edit.
    function genbtn_alias_map()
    {
        return [
            'su' => 'users.sell.selectUsernamePrompt',
            'cf' => 'users.sell.confirmButtons',
            // unlike 'cf', this key is not shared - it is the SAME key as the
            // caption item itself, so resetting/green-state checking the
            // caption already covers its one button for free
            'ns' => 'users.sell.service_not_available',
            'te' => 'textbot.testExpired',
            // same "own-key" trick as 'ns' - service_sell's one static button
            // (the list's close button; the per-service rows above it are a
            // live listing, not something a label override could apply to)
            'sc' => 'users.sell.service_sell',
            // same trick again - the one button under the wallet top-up
            // success message
            'bc' => 'users.Balance.chargeSuccess',
            // own-key trick again - the renewal invoice's confirm/back pair
            // (the افزایش موجودی button between them is the SHARED
            // balancebtn widget, not part of this alias - one place to style
            // it bot-wide instead of a per-screen copy)
            'rn' => 'users.extend.invoiceCreated',
            'cl' => 'users.changeLink.warnchange',
            // own-key trick again - the 🎁 code-entry screen owns both its
            // "کد تخفیف دارم" row on the method screen and its own back button
            'td' => 'users.Balance.topupDiscPrompt',
            // the two purchase-flow back buttons and five ❌ بستن buttons - each
            // one already IS a bottext.items key (bt_btnitem_keys()), so unlike
            // every alias above there is no "owning caption" to borrow: the key
            // is the button itself.
            'rc' => 'users.sell.backToPreviousBtn',
            'rp' => 'users.sell.backToPanelListBtn',
            'bu' => 'bottext.btnCloseBuy',
            'tp' => 'bottext.btnCloseTopup',
            'ac' => 'bottext.btnCloseAccount',
            'ts' => 'bottext.btnCloseTest',
            'he' => 'bottext.btnCloseHelp',
            // own-key trick again - the tutorial list inside a category owns the
            // one button under it, the "back to the category list" row
            'hb' => 'users.help.listCaption',
            // and the category caption owns the back button of the screen that
            // shows one tutorial's own content
            'hv' => 'users.help.categoryCaption',
            // own-key trick again - the one 📚 button under the post-purchase
            // message (afterPay and its manual/WGDashboard/ibsng variants)
            'ab' => 'textbot.afterPay',
            // and its twin under the test-account message
            'ut' => 'textbot.afterText',
            // the other four bt_btnitem_keys() buttons - listed there all along
            // but never here, so their style screen opened empty and its 🔙
            // fell through to an unrelated screen
            'sp' => 'keyboard.sendPhoneNumber',
            'ar' => 'keyboard.acceptRules',
            'mg' => 'keyboard.receiveMembershipGift',
            'sl' => 'keyboard.shareLink',
        ];
    }
}
if (!function_exists('genbtn_alias_to_key')) {
    function genbtn_alias_to_key($alias)
    {
        $m = genbtn_alias_map();
        return $m[$alias] ?? null;
    }
    // the reverse lookup - bt_btnitem_keys() items reach their own alias by
    // key, not the other way around
    function genbtn_key_to_alias($key)
    {
        $alias = array_search($key, genbtn_alias_map(), true);
        return $alias !== false ? $alias : '';
    }
    // Which buttons get the 👁/🚫 toggle: every close/back button plus the
    // optional extras a screen still works without. Confirm/pay/cancel buttons
    // (cf, rn, cl and su's cancel) stay out - hiding one strands the customer.
    function genbtn_hideable($alias, $idx)
    {
        // their render sites (keyboard.php) never read 'hidden', and hiding
        // «پذیرش قوانین» would strand a customer on the rules screen
        if ($alias === 'sp' || $alias === 'ar') {
            return false;
        }
        $key = genbtn_alias_to_key($alias);
        if ($key !== null && function_exists('bt_btnitem_keys') && in_array($key, bt_btnitem_keys(), true)) {
            return true;
        }
        $extras = ['ab' => [0], 'ut' => [0], 'bc' => [0], 'ns' => [0], 'te' => [0], 'sc' => [0], 'hb' => [0], 'hv' => [0], 'td' => [0, 1], 'su' => [1]];
        return in_array((int) $idx, $extras[$alias] ?? [], true);
    }
}
if (!function_exists('genbtn_text_only')) {
    // buttons that can show nothing but their label: a reply-keyboard
    // request_contact button and a URL button - colour, emoji, simple mode and
    // position are not offered for them (they would silently do nothing)
    function genbtn_text_only($alias)
    {
        return $alias === 'sp' || $alias === 'sl';
    }
}
if (!function_exists('genbtn_defs')) {
    // idx => ['name' => admin-facing label, 'text' => default button text,
    // 'style' => default colour, 'callback_data' => the REAL action for this
    // button - only meaningful for 'su', whose action never varies by screen;
    // 'cf' varies per call site, so its live callback_data is supplied by the
    // caller of genbtn_render() instead, and 'none' here is only for previews]
    function genbtn_defs($alias, $textbotlang)
    {
        if ($alias === 'su') {
            return [
                0 => ['name' => '🔴 دکمه انصراف', 'text' => $textbotlang['keyboard']['cancelUsernameBtn'], 'style' => 'danger', 'callback_data' => 'ucancel'],
                1 => ['name' => '🟢 دکمه استفاده از پیش‌فرض', 'text' => $textbotlang['keyboard']['useDefaultUsernameBtn'], 'style' => 'success', 'callback_data' => 'usedefaultname'],
            ];
        }
        if ($alias === 'cf') {
            return [
                0 => ['name' => '🔴 دکمه انصراف', 'text' => $textbotlang['keyboard']['backToPlansBtn'], 'style' => 'danger', 'callback_data' => 'none'],
                1 => ['name' => '🟢 دکمه تأیید و پرداخت', 'text' => $textbotlang['keyboard']['payAndGetService'], 'style' => 'success', 'callback_data' => 'none'],
            ];
        }
        if ($alias === 'ns') {
            return [
                0 => ['name' => '🔵 دکمه تهیه اشتراک', 'text' => $textbotlang['users']['sell']['buySubscriptionBtn'], 'style' => 'primary', 'callback_data' => 'buyfresh'],
            ];
        }
        if ($alias === 'te') {
            return [
                0 => ['name' => '🔵 دکمه خرید سرویس', 'text' => $textbotlang['keyboard']['buyService'], 'style' => 'primary', 'callback_data' => 'buy'],
            ];
        }
        if ($alias === 'sc') {
            return [
                0 => ['name' => '🔴 دکمه بستن', 'text' => $textbotlang['bottext']['btn_close'], 'style' => 'danger', 'callback_data' => 'servclose'],
            ];
        }
        if ($alias === 'td') {
            // idx 0 lives on the 💰 افزایش موجودی method screen, idx 1 on the
            // code prompt this opens - both hang off the prompt's own caption
            // key (the own-key trick), since that is the one item the pair
            // belongs to in 🎨 شخصی‌سازی پیام‌های ربات
            return [
                0 => ['name' => '🔵 دکمه «کد تخفیف دارم»', 'text' => $textbotlang['users']['Balance']['topupDiscHaveCodeBtn'], 'style' => 'primary', 'callback_data' => 'topup_disc_enter'],
                1 => ['name' => '🔴 دکمه بازگشت به منوی قبل', 'text' => $textbotlang['users']['Balance']['topupDiscBackBtn'], 'style' => 'danger', 'callback_data' => 'topup_disc_cancel'],
            ];
        }
        if ($alias === 'bc') {
            return [
                0 => ['name' => '🔵 دکمه تهیه اشتراک', 'text' => $textbotlang['users']['sell']['buySubscriptionBtn'], 'style' => 'primary', 'callback_data' => 'buyfresh'],
            ];
        }
        if ($alias === 'rn') {
            return [
                0 => ['name' => '🔵 دکمه تایید تمدید', 'text' => $textbotlang['users']['extend']['confirm'], 'style' => 'primary', 'callback_data' => 'none'],
                1 => ['name' => '🔴 دکمه بازگشت به منوی قبل', 'text' => '🔙 بازگشت به منوی قبل', 'style' => 'danger', 'callback_data' => 'none'],
                // shown only on the payment-method screen reached by tapping
                // افزایش موجودی from the invoice (appended below the live
                // gateway list, not part of extend_invoice_kb's own 2-button
                // layout) - kept as idx 2 of the SAME alias since it is still
                // the renewal invoice's own flow, own-key trick still applies.
                // Tapping it re-renders the exact same invoice (rn_reshow_).
                2 => ['name' => '🔴 دکمه بازگشت (از صفحه‌ی پرداخت)', 'text' => '🔙 بازگشت به منوی قبلی', 'style' => 'danger', 'callback_data' => 'none'],
            ];
        }
        if ($alias === 'cl') {
            return [
                0 => ['name' => '🔵 دکمه تایید تغییر لینک', 'text' => $textbotlang['users']['changeLink']['confirm'], 'style' => 'primary', 'callback_data' => 'none'],
                1 => ['name' => '🔴 دکمه بازگشت به منوی قبل', 'text' => '🔙 بازگشت به منوی قبل', 'style' => 'danger', 'callback_data' => 'none'],
            ];
        }
        // the seven bt_btnitem_keys() buttons - 'callback_data' is 'none' like
        // 'cf'/'rn'/'cl' above, because each one is rendered from more than one
        // call site with its own real callback (bt_button() supplies that at
        // render time); this copy is only ever shown on the inert preview row.
        if ($alias === 'rc') {
            return [0 => ['name' => '🔙 بازگشت به لیست دسته‌بندی (زیر لیست محصولات)', 'text' => $textbotlang['users']['sell']['backToPreviousBtn'], 'style' => 'danger', 'callback_data' => 'none']];
        }
        if ($alias === 'rp') {
            return [0 => ['name' => '🔙 بازگشت به لیست پنل‌ها (زیر لیست دسته‌بندی)', 'text' => $textbotlang['users']['sell']['backToPanelListBtn'], 'style' => 'danger', 'callback_data' => 'none']];
        }
        if ($alias === 'bu') {
            return [0 => ['name' => '❌ دکمه بستن (خرید اشتراک)', 'text' => $textbotlang['bottext']['btnCloseBuy'], 'style' => 'danger', 'callback_data' => 'none']];
        }
        if ($alias === 'tp') {
            return [0 => ['name' => '❌ دکمه بستن (افزایش موجودی)', 'text' => $textbotlang['bottext']['btnCloseTopup'], 'style' => 'danger', 'callback_data' => 'none']];
        }
        if ($alias === 'ac') {
            return [0 => ['name' => '❌ دکمه بستن (حساب کاربری)', 'text' => $textbotlang['bottext']['btnCloseAccount'], 'style' => 'danger', 'callback_data' => 'none']];
        }
        if ($alias === 'ts') {
            return [0 => ['name' => '❌ دکمه بستن (اکانت تست)', 'text' => $textbotlang['bottext']['btnCloseTest'], 'style' => 'danger', 'callback_data' => 'none']];
        }
        if ($alias === 'he') {
            return [0 => ['name' => '❌ دکمه بستن (آموزش)', 'text' => $textbotlang['bottext']['btnCloseHelp'], 'style' => 'danger', 'callback_data' => 'none']];
        }
        if ($alias === 'hb') {
            return [0 => ['name' => '🔙 دکمه بازگشت به دسته‌بندی آموزش', 'text' => $textbotlang['users']['help']['backToCategoriesBtn'], 'style' => 'danger', 'callback_data' => 'helpbtns']];
        }
        if ($alias === 'hv') {
            return [0 => ['name' => '🔙 دکمه بازگشت (زیر محتوای آموزش)', 'text' => $textbotlang['users']['help']['backToCategoryListBtn'], 'style' => 'danger', 'callback_data' => 'helpbtns']];
        }
        if ($alias === 'ab' || $alias === 'ut') {
            // no default style: the button has always gone out unstyled.
            // trim(): the lang string ends in a space, and split_leading_emoji()
            // counts that space as emoji length ("📚 م مشاهده...").
            // 'hidden' => true: ships switched off, shown from its own screen
            return [0 => ['name' => '📚 دکمه مشاهده آموزش', 'text' => trim($textbotlang['users']['help']['btninlinebuy']), 'style' => '', 'callback_data' => 'helpbtn', 'hidden' => true]];
        }
        // defaults are exactly what keyboard.php / index.php render today; the
        // real callback (or request_contact / url) is supplied at each call site.
        // 'note' is shown on the style screen when a button cannot show every tool
        if ($alias === 'sp') {
            return [0 => ['name' => '📞 دکمه ارسال شماره تماس', 'text' => $textbotlang['keyboard']['sendPhoneNumber'], 'style' => '', 'callback_data' => 'none', 'note' => 'این دکمه روی کیبورد پایین صفحه میاد و تلگرام اونجا رنگ و ایموجی نمی‌پذیره - فقط متنش قابل تغییره.']];
        }
        if ($alias === 'ar') {
            return [0 => ['name' => '🟢 دکمه پذیرش قوانین', 'text' => $textbotlang['keyboard']['acceptRules'], 'style' => 'success', 'callback_data' => 'none', 'note' => 'رنگ و ایموجی فقط وقتی دیده می‌شن که دکمه‌های شیشه‌ای روشن باشه؛ با کیبورد پایین صفحه فقط متن نشون داده می‌شه.']];
        }
        if ($alias === 'mg') {
            return [0 => ['name' => '🟢 دکمه دریافت هدیه عضویت', 'text' => $textbotlang['keyboard']['receiveMembershipGift'], 'style' => 'success', 'callback_data' => 'none']];
        }
        if ($alias === 'sl') {
            return [0 => ['name' => '🔗 دکمه اشتراک‌گذاری لینک', 'text' => $textbotlang['keyboard']['shareLink'], 'style' => '', 'callback_data' => 'none', 'note' => 'این دکمه لینکه و تلگرام برای دکمه‌ی لینک رنگ و ایموجی نمی‌پذیره - فقط متنش قابل تغییره.']];
        }
        return [];
    }
}
if (!function_exists('genbtn_override')) {
    function genbtn_override($lang, $key, $idx)
    {
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        return (is_array($be) && isset($be[$lang][$key][$idx]) && is_array($be[$lang][$key][$idx]))
            ? $be[$lang][$key][$idx]
            : [];
    }
}
if (!function_exists('genbtn_set_text')) {
    function genbtn_set_text($lang, $key, $idx, $text)
    {
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        if (!is_array($be)) {
            $be = [];
        }
        if ($text === null || $text === '') {
            // ✏️ نام سفارشی "0": back to the default text
            unset($be[$lang][$key][$idx]['text']);
            if (empty($be[$lang][$key][$idx])) {
                unset($be[$lang][$key][$idx]);
            }
        } else {
            $be[$lang][$key][$idx]['text'] = $text;
        }
        update("setting", "button_edit", empty($be) ? null : json_encode($be, JSON_UNESCAPED_UNICODE), null, null);
    }
}
if (!function_exists('genbtn_set_style')) {
    // one combined setter, mirroring volumepct_tier_set_style()'s shape:
    // pass null to leave a field untouched, '' to clear an emoji/icon field
    function genbtn_set_style($lang, $key, $idx, $style = null, $emoji = null, $emojiIcon = null, $pos = null, $simple = null, $hidden = null)
    {
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        if (!is_array($be)) {
            $be = [];
        }
        if ($style !== null && in_array($style, ['primary', 'success', 'danger'], true)) {
            $be[$lang][$key][$idx]['style'] = $style;
        } elseif ($style === '') {
            // ⚪ پیش‌فرض: back to the button's own colour
            unset($be[$lang][$key][$idx]['style']);
        }
        if ($emoji !== null) {
            if ($emoji === '') {
                unset($be[$lang][$key][$idx]['emoji']);
            } else {
                $be[$lang][$key][$idx]['emoji'] = $emoji;
            }
            unset($be[$lang][$key][$idx]['emojiIcon']);
        }
        if ($emojiIcon !== null) {
            if ($emojiIcon === '') {
                unset($be[$lang][$key][$idx]['emojiIcon']);
            } else {
                $be[$lang][$key][$idx]['emojiIcon'] = $emojiIcon;
            }
            unset($be[$lang][$key][$idx]['emoji']);
        }
        // right side and simple mode off are what an unset button already
        // does, so they are not stored (a group only turns green for a change)
        if ($pos === 'left') {
            $be[$lang][$key][$idx]['pos'] = 'left';
        } elseif ($pos === 'right') {
            unset($be[$lang][$key][$idx]['pos']);
        }
        if ($simple !== null) {
            if ($simple) {
                $be[$lang][$key][$idx]['simple'] = true;
            } else {
                unset($be[$lang][$key][$idx]['simple']);
            }
        }
        if ($hidden !== null) {
            if ($hidden === 'shown') {
                // for a button that ships hidden: "no flag" would mean hidden
                $be[$lang][$key][$idx]['hidden'] = false;
            } elseif ($hidden) {
                $be[$lang][$key][$idx]['hidden'] = true;
            } else {
                unset($be[$lang][$key][$idx]['hidden']);
            }
        }
        if (empty($be[$lang][$key][$idx])) {
            unset($be[$lang][$key][$idx]);
        }
        update("setting", "button_edit", empty($be) ? null : json_encode($be, JSON_UNESCAPED_UNICODE), null, null);
    }
}
if (!function_exists('genbtn_reset')) {
    function genbtn_reset($lang, $key, $idx)
    {
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        if (is_array($be) && isset($be[$lang][$key][$idx])) {
            unset($be[$lang][$key][$idx]);
            if (empty($be[$lang][$key])) {
                unset($be[$lang][$key]);
            }
            if (empty($be[$lang])) {
                unset($be[$lang]);
            }
            update("setting", "button_edit", empty($be) ? null : json_encode($be, JSON_UNESCAPED_UNICODE), null, null);
        }
    }
}
if (!function_exists('genbtn_reset_all')) {
    function genbtn_reset_all($lang, $key)
    {
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        if (is_array($be) && isset($be[$lang][$key])) {
            unset($be[$lang][$key]);
            if (empty($be[$lang])) {
                unset($be[$lang]);
            }
            update("setting", "button_edit", empty($be) ? null : json_encode($be, JSON_UNESCAPED_UNICODE), null, null);
        }
    }
}
if (!function_exists('genbtn_current')) {
    // merged text/style only - what the admin's plain-text preview/list rows show
    function genbtn_current(array $def, array $ov)
    {
        $text = (isset($ov['text']) && $ov['text'] !== '') ? $ov['text'] : $def['text'];
        $style = (isset($ov['style']) && in_array($ov['style'], ['primary', 'success', 'danger'], true)) ? $ov['style'] : $def['style'];
        return [$text, $style];
    }
}
if (!function_exists('genbtn_is_hidden')) {
    // an explicit override wins either way; with none, a def may ship hidden
    // ('ab'/'ut') - which is why "shown" is stored as hidden => false there
    function genbtn_is_hidden(array $def, array $ov)
    {
        return array_key_exists('hidden', $ov) ? !empty($ov['hidden']) : !empty($def['hidden']);
    }
}
if (!function_exists('genbtn_render')) {
    // the button as it is actually sent to a real user - same emoji-baking
    // algorithm as volumepct_tier_kb(), generalized to any def/override pair
    function genbtn_render(array $def, array $ov, $callback_data)
    {
        list($text, $style) = genbtn_current($def, $ov);
        $pos = (isset($ov['pos']) && $ov['pos'] === 'left') ? 'left' : 'right';
        $btn = ['text' => $text, 'callback_data' => $callback_data];
        if ($style !== '') {
            $btn['style'] = $style;
        }
        if (!empty($ov['simple'])) {
            $btn['text'] = strip_leading_emoji($text);
        } elseif (!empty($ov['emojiIcon'])) {
            $btn['text'] = strip_leading_emoji($text);
            $btn['icon_custom_emoji_id'] = $ov['emojiIcon'];
        } else {
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
        return $btn;
    }
}
if (!function_exists('myservices_close_btn')) {
    function myservices_close_btn($lang, $textbotlang)
    {
        $defs = genbtn_defs('sc', $textbotlang);
        $ov = genbtn_override($lang, 'users.sell.service_sell', 0);
        return genbtn_render($defs[0], $ov, $defs[0]['callback_data']);
    }
}
if (!function_exists('afterpay_help_kb')) {
    // the keyboard under the post-purchase message - genbtn alias 'ab'.
    // JSON, or null when the admin hid the button (no keyboard at all)
    function afterpay_help_kb($lang, $textbotlang)
    {
        $defs = genbtn_defs('ab', $textbotlang);
        $ov = genbtn_override($lang, 'textbot.afterPay', 0);
        if (genbtn_is_hidden($defs[0], $ov)) {
            return null;
        }
        return json_encode(['inline_keyboard' => [[genbtn_render($defs[0], $ov, $defs[0]['callback_data'])]]]);
    }
}
if (!function_exists('usertest_help_kb')) {
    // the same 📚 button under the test-account message - genbtn alias 'ut'
    function usertest_help_kb($lang, $textbotlang)
    {
        $defs = genbtn_defs('ut', $textbotlang);
        $ov = genbtn_override($lang, 'textbot.afterText', 0);
        if (genbtn_is_hidden($defs[0], $ov)) {
            return null;
        }
        return json_encode(['inline_keyboard' => [[genbtn_render($defs[0], $ov, $defs[0]['callback_data'])]]]);
    }
}
if (!function_exists('sell_selectUsername_kb')) {
    // renders the buy flow's OWN copy of the cancel/use-default keyboard - the
    // usertest flow keeps using its separate usertest_selectUsername_kb(), so
    // overrides on one never leak into the other
    function sell_selectUsername_kb($lang, $textbotlang)
    {
        $defs = genbtn_defs('su', $textbotlang);
        $buttons = [];
        foreach ($defs as $idx => $d) {
            $ov = genbtn_override($lang, 'users.sell.selectUsernamePrompt', $idx);
            if (!empty($ov['hidden']) && genbtn_hideable('su', $idx)) {
                continue;
            }
            $buttons[$idx] = genbtn_render($d, $ov, $d['callback_data']);
        }
        // order and width from 📐 چیدمان; with none saved, the same one row as before
        return json_encode(['inline_keyboard' => genbtn_group_rows('su', $lang, $buttons, $textbotlang)]);
    }
}
if (!function_exists('sell_confirm_kb')) {
    // the shared confirm/cancel row for every "تأیید خرید" screen - the only
    // thing that differs per call site is which callback actually confirms
    // the purchase, so that is the one thing the caller supplies
    function sell_confirm_kb($lang, $textbotlang, $confirmCallback, $cancelCallback = 'backuser')
    {
        $defs = genbtn_defs('cf', $textbotlang);
        $cbs = [0 => $cancelCallback, 1 => $confirmCallback];
        $buttons = [];
        foreach ($defs as $idx => $d) {
            $ov = genbtn_override($lang, 'users.sell.confirmButtons', $idx);
            $buttons[$idx] = genbtn_render($d, $ov, $cbs[$idx]);
        }
        // order and width from 📐 چیدمان; with none saved, the same one row as before
        return json_encode(['inline_keyboard' => genbtn_group_rows('cf', $lang, $buttons, $textbotlang)]);
    }
}
if (!function_exists('sell_noservice_kb')) {
    // the "🛍 سرویس‌های من" empty-state keyboard - a single button, still
    // routed through the same generalized system for text/colour/emoji
    function sell_noservice_kb($lang, $textbotlang)
    {
        $defs = genbtn_defs('ns', $textbotlang);
        $ov = genbtn_override($lang, 'users.sell.service_not_available', 0);
        if (!empty($ov['hidden'])) {
            return null;
        }
        return json_encode(['inline_keyboard' => [[genbtn_render($defs[0], $ov, $defs[0]['callback_data'])]]]);
    }
}
if (!function_exists('test_expired_kb')) {
    function test_expired_kb($lang, $textbotlang)
    {
        $defs = genbtn_defs('te', $textbotlang);
        $ov = genbtn_override($lang, 'textbot.testExpired', 0);
        if (!empty($ov['hidden'])) {
            return null;
        }
        return json_encode(['inline_keyboard' => [[genbtn_render($defs[0], $ov, $defs[0]['callback_data'])]]]);
    }
}
if (!function_exists('extend_invoice_kb')) {
    // the renewal invoice's keyboard: تایید تمدید + افزایش موجودی on one row,
    // بازگشت به منوی قبل on its own row below. The confirm/back pair is
    // customizable via the 'rn' genbtn alias (own-key trick against
    // users.extend.invoiceCreated, its one caption item); افزایش موجودی
    // reuses the SHARED balancebtn widget's text/style/emoji so admins style
    // it once, bot-wide - but its callback_data is overridden to
    // $topupCallback (never the shared 'Add_Balance') because Add_Balance's
    // own dispatcher unconditionally zeroes Processing_value, which would
    // destroy this invoice's cached id_invoice/code_product/price/etc.
    function extend_invoice_kb($lang, $textbotlang, $confirmCallback, $backCallback, $topupCallback)
    {
        $defs = genbtn_defs('rn', $textbotlang);
        $confirmOv = genbtn_override($lang, 'users.extend.invoiceCreated', 0);
        $backOv = genbtn_override($lang, 'users.extend.invoiceCreated', 1);
        $confirmBtn = genbtn_render($defs[0], $confirmOv, $confirmCallback);
        $backBtn = genbtn_render($defs[1], $backOv, $backCallback);
        $balanceBtn = balancebtn_button($lang, $textbotlang);
        $balanceBtn['callback_data'] = $topupCallback;
        return json_encode(['inline_keyboard' => [[$confirmBtn, $balanceBtn], [$backBtn]]]);
    }
}
if (!function_exists('render_extend_invoice_from_product')) {
    // shared tail of "show the renewal invoice": applies the user's
    // pricediscount percentage to $product['price_product'] (always the RAW
    // catalog/synthesized price - callers must never pre-discount it, or the
    // discount applies twice), builds the invoiceCreated caption, and the
    // keyboard. Used both when a product/duration is first picked and by the
    // rn_reshow_ dispatcher (re-rendering the same invoice after a detour to
    // the payment-method screen).
    function render_extend_invoice_from_product($user, $textbotlang, $nameloc, $product)
    {
        if (intval($user['pricediscount']) != 0) {
            $result = ($product['price_product'] * $user['pricediscount']) / 100;
            $pricelastextend = number_format(round($product['price_product'] - $result, 0));
        } else {
            $pricelastextend = $product['price_product'];
        }
        $textextend = sprintf($textbotlang['users']['extend']['invoiceCreated'], $nameloc['username'], $product['name_product'], $pricelastextend, $product['Service_time'], $product['Volume_constraint'], $product['note'], $user['Balance']);
        $keyboardextend = extend_invoice_kb($user['lang'] ?? 'fa', $textbotlang, "confirmserivce", "product_" . $nameloc['id_invoice'], "rn_topup_" . $nameloc['id_invoice']);
        return [$textextend, $keyboardextend];
    }
}
if (!function_exists('notify_test_expired')) {
    // Shared by cronbot/configtest.php (the normal path) AND every place in
    // index.php that silently disables a stale test invoice while a user is
    // just browsing 🛍 سرویس‌های من (see e.g. the "user not found" branches) -
    // both paths race to be the one that flips Status to 'disabled', and
    // whichever wins used to leave the loser's notification code dead. This
    // makes the notification fire wherever the disable actually happens.
    function notify_test_expired($invoiceRow, $textbotlang)
    {
        $user = select("user", "*", "id", $invoiceRow['id_user'], "select");
        if (!$user || intval($user['status_cron'] ?? 0) == 0) {
            return;
        }
        $Response = test_expired_kb($user['lang'] ?? 'fa', $textbotlang);
        $textexpire = str_replace('{username}', $invoiceRow['username'], $textbotlang['textbot']['testExpired']);
        $textexpire = strtr($textexpire, bottext_user_placeholders($user, $invoiceRow['id_user']));
        sendmessage($invoiceRow['id_user'], $textexpire, $Response, 'HTML');
    }
}
if (!function_exists('genbtn_list_payload')) {
    // The per-group button list and the per-button detail screen were replaced
    // by one hub per group (genbtn_hub_payload, 001). Both stay as thin
    // wrappers, so a caller that still asks for them lands on that hub.
    function genbtn_list_payload($alias, $lang, $textbotlang, $origin = '')
    {
        return genbtn_hub_payload($alias, $lang, $textbotlang, $origin);
    }
}
if (!function_exists('genbtn_detail_payload')) {
    // The close_sticker key this screen owns, or '' when this button doesn't
    // close anything (most of them navigate or confirm instead). 'sc' is the
    // one alias whose genbtn key is the caption it lives on rather than a key
    // close_sticker knows, so it is translated here.
    function genbtn_close_sticker_key($alias, $key, $idx)
    {
        if ($idx !== 0 || !function_exists('close_sticker_keys')) {
            return '';
        }
        $csKey = ($alias === 'sc') ? 'servclose' : $key;
        return in_array($csKey, close_sticker_keys(), true) ? $csKey : '';
    }
    // $csNote: the "🖼 استیکر دکمه بستن" change that just happened, shown inside
    // the hub's own caption quote
    function genbtn_detail_payload($alias, $lang, $idx, $textbotlang, $origin = '', $csNote = '')
    {
        return genbtn_hub_payload($alias, $lang, $textbotlang, $origin, $csNote);
    }
}
if (!function_exists('genbtn_hub_payload')) {
    // ---- one hub per button group, like «🎨 ظاهر دکمه‌های پنل» (001) ----
    // 📐 چیدمان / 🎨 رنگ‌بندی / 🎭 ایموجی / ✏️ نام سفارشی, each showing the whole
    // group and acting on one tap. The store (button_edit) and the customer
    // renderer (genbtn_render) are the same as before, so nothing saved earlier
    // looks any different.

    function genbtn_group_title($alias, $textbotlang)
    {
        $titles = ['su' => '🔘 دکمه‌های نام‌گذاری سرویس', 'cf' => '🔘 دکمه‌های تأیید خرید', 'ns' => '🔘 دکمه‌ی نداشتن سرویس فعال', 'te' => '🔘 دکمه‌ی پیام اتمام اکانت تست', 'sc' => '🔘 دکمه‌ی بستن (سرویس‌های من)', 'bc' => '🔘 دکمه‌ی تهیه اشتراک (شارژ کیف پول)', 'rn' => '🔘 دکمه‌های فاکتور تمدید سرویس', 'cl' => '🔘 دکمه‌های تغییر لینک اتصال', 'td' => '🔘 دکمه‌های کد تخفیف شارژ', 'hb' => '🔘 دکمه‌ی بازگشت به دسته‌بندی آموزش', 'hv' => '🔘 دکمه‌ی بازگشت (زیر محتوای آموزش)', 'ab' => '📚 دکمه‌ی مشاهده آموزش (پیام بعد از خرید)', 'ut' => '📚 دکمه‌ی مشاهده آموزش (اکانت تست)'];
        if (isset($titles[$alias])) {
            return $titles[$alias];
        }
        // the button-only items: their one button's own name
        $defs = genbtn_defs($alias, $textbotlang);
        return '🔘 ' . ($defs[0]['name'] ?? 'دکمه‌ها');
    }
    function genbtn_group_note($alias, $textbotlang)
    {
        $notes = [
            'su' => 'این ۲ دکمه، زیر پیام انتخاب نام سرویس (مرحله‌ی خرید) به کاربر نشون داده می‌شن.',
            'cf' => 'این ۲ دکمه، زیر صفحه‌ی تأیید نهایی خرید نشون داده می‌شن - هر سه حالت (عادی/تخفیف‌دار/حجم دلخواه) از این یکی استفاده می‌کنن، پس ویرایششون روی هر سه اثر می‌ذاره.',
            'ns' => 'این ۱ دکمه، زیر پیامِ «سرویس فعالی ندارید» (وقتی 🛍 سرویس‌های من خالیه) به کاربر نشون داده می‌شه.',
            'te' => 'این ۱ دکمه، زیر پیامِ «اکانت تست شما به پایان رسید» به کاربر نشون داده می‌شه (همون پیامی که کرون موقع تموم‌شدن اعتبار اکانت تست می‌فرسته).',
            'sc' => 'این ۱ دکمه، زیر لیست سرویس‌های فعال کاربر (وقتی 🛍 سرویس‌های من حداقل یک سرویس داره) نشون داده می‌شه.',
            'bc' => 'این ۱ دکمه، زیر پیام تایید نهاییِ شارژ کیف پول (هر روش پرداختی) نشون داده می‌شه.',
            'rn' => 'دکمه‌ی ۱ و ۲ زیر فاکتور تمدید سرویس نشون داده می‌شن (دکمه‌ی «افزایش موجودی» بینشون از تنظیمات مشترک همون دکمه میاد، جدا نیست). دکمه‌ی ۳ وقتی کاربر روی «افزایش موجودی» بزنه، زیر لیست روش‌های پرداخت میاد و با تپ روش، برمی‌گردونه به همون فاکتور تمدید.',
            'cl' => 'این ۲ دکمه، زیر پیام هشدار «تغییر لینک اتصال» (قبل از تایید نهایی) نشون داده می‌شن.',
            'td' => 'دکمه‌ی ۱ («کد تخفیف دارم») زیر لیست روش‌های پرداختِ 💰 افزایش موجودی میاد - ولی فقط وقتی که برای این زبان حداقل یک کد تخفیف شارژ ساخته باشی، وگرنه اصلاً نشون داده نمی‌شه. دکمه‌ی ۲ زیر همون صفحه‌ی وارد کردن کد میاد. اگر کاربر یه کد رو فعال کرده باشه، دکمه‌ی ۱ دیگه بهش نشون داده نمی‌شه (چون خود کپشن تخفیف فعال رو نوشته).',
            'ab' => 'این ۱ دکمه، زیر پیام «✅ سرویس با موفقیت ایجاد شد» (بعد از خرید) نشون داده می‌شه - برای همه‌ی نوع پنل‌ها، خرید چندتایی، پرداخت آنلاین و سفارشی که ادمین برای کاربر ثبت می‌کنه. پیش‌فرض مخفیه؛ برای نمایش، «👁 نمایش دادن این دکمه» رو بزن.',
            'ut' => 'این ۱ دکمه، زیر پیام «✅ سرویس با موفقیت ایجاد شد» بعد از گرفتن اکانت تست نشون داده می‌شه. پیش‌فرض مخفیه؛ برای نمایش، «👁 نمایش دادن این دکمه» رو بزن.',
        ];
        if (isset($notes[$alias])) {
            return $notes[$alias];
        }
        $defs = genbtn_defs($alias, $textbotlang);
        return (string) ($defs[0]['note'] ?? '');
    }
    // where 🔙 on a group's hub goes - the same targets the old screens used
    function genbtn_hub_back_cb($alias, $lang, $origin = '')
    {
        $key = genbtn_alias_to_key($alias);
        if ($key !== null && function_exists('bt_btnitem_keys') && in_array($key, bt_btnitem_keys(), true) && function_exists('bt_btnitem_back_cb')) {
            return bt_btnitem_back_cb($key, $lang);
        }
        // 'cf' points at textbot.preInvoice (the normal-purchase تأیید خرید)
        $backKeyMap = ['su' => 'users.sell.selectUsernamePrompt', 'cf' => 'textbot.preInvoice', 'ns' => 'users.sell.service_not_available', 'te' => 'textbot.testExpired', 'sc' => 'users.sell.service_sell', 'bc' => 'users.Balance.chargeSuccess', 'rn' => 'users.extend.invoiceCreated', 'cl' => 'users.changeLink.warnchange', 'td' => 'users.Balance.topupDiscPrompt', 'hb' => 'users.help.listCaption', 'hv' => 'users.help.categoryCaption', 'ab' => 'textbot.afterPay', 'ut' => 'textbot.afterText'];
        $backKey = ($origin === 'u') ? 'users.usertest.selectUsernamePrompt' : ($backKeyMap[$alias] ?? 'users.sell.selectUsernamePrompt');
        return "bt_edit|{$lang}|{$backKey}";
    }

    // Groups whose buttons sit together on ONE customer screen get 📐 چیدمان;
    // the value is the width all of them have today. rn (its confirm button
    // shares a row with the shared «افزایش موجودی» button) and td (two
    // different screens) have none.
    function genbtn_layout_default($alias)
    {
        return ['su' => 'half', 'cf' => 'half', 'cl' => 'full'][$alias] ?? null;
    }
    // {order: [idx...], width: {idx: half|full}} - what is stored, over the
    // defaults; a group without layout gets its buttons in index order
    function genbtn_layout_get($lang, $alias, $textbotlang)
    {
        $idxs = array_map('strval', array_keys(genbtn_defs($alias, $textbotlang)));
        $defWidth = genbtn_layout_default($alias);
        if ($defWidth === null) {
            return ['order' => $idxs, 'width' => []];
        }
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        $stored = (is_array($be) && is_array($be[$lang][genbtn_alias_to_key($alias)]['layout'] ?? null)) ? $be[$lang][genbtn_alias_to_key($alias)]['layout'] : [];
        $storedOrder = is_array($stored['order'] ?? null) ? array_map('strval', $stored['order']) : [];
        $width = [];
        foreach ($idxs as $i) {
            $w = $stored['width'][$i] ?? null;
            $width[$i] = in_array($w, ['half', 'full'], true) ? $w : $defWidth;
        }
        return ['order' => help_layout_apply_order($idxs, $storedOrder), 'width' => $width];
    }
    // a layout that equals the default is not stored at all, so the group's
    // entry only turns green for a real change
    function genbtn_layout_set($lang, $alias, array $order, array $width, $textbotlang)
    {
        $key = genbtn_alias_to_key($alias);
        $idxs = array_map('strval', array_keys(genbtn_defs($alias, $textbotlang)));
        $defWidth = genbtn_layout_default($alias);
        $order = array_values(array_map('strval', $order));
        $isDefault = ($order === $idxs);
        $w = [];
        foreach ($idxs as $i) {
            $w[$i] = in_array($width[$i] ?? null, ['half', 'full'], true) ? $width[$i] : $defWidth;
            if ($w[$i] !== $defWidth) {
                $isDefault = false;
            }
        }
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        if (!is_array($be)) {
            $be = [];
        }
        if ($isDefault) {
            unset($be[$lang][$key]['layout']);
        } else {
            $be[$lang][$key]['layout'] = ['order' => array_map('intval', $order), 'width' => (object) $w];
        }
        if (isset($be[$lang][$key]) && empty($be[$lang][$key])) {
            unset($be[$lang][$key]);
        }
        if (isset($be[$lang]) && empty($be[$lang])) {
            unset($be[$lang]);
        }
        update("setting", "button_edit", empty($be) ? null : json_encode($be, JSON_UNESCAPED_UNICODE), null, null);
    }
    // the rows a customer gets for a group's VISIBLE buttons ($buttonsByIdx
    // holds only those), in its order and width - with nothing stored these are
    // exactly the rows each keyboard had before
    function genbtn_group_rows($alias, $lang, array $buttonsByIdx, $textbotlang)
    {
        $lay = genbtn_layout_get($lang, $alias, $textbotlang);
        if (genbtn_layout_default($alias) === null) {
            $rows = [];
            foreach ($lay['order'] as $i) {
                if (isset($buttonsByIdx[$i])) {
                    $rows[] = [$buttonsByIdx[$i]];
                }
            }
            return $rows;
        }
        return help_layout_chunk_rows($lay['order'], $buttonsByIdx, $lay['width']);
    }
    function genbtn_layout_swap($lang, $alias, $i, $j, $textbotlang)
    {
        $lay = genbtn_layout_get($lang, $alias, $textbotlang);
        $a = array_search((string) $i, $lay['order'], true);
        $b = array_search((string) $j, $lay['order'], true);
        if ($a === false || $b === false || $a === $b) {
            return false;
        }
        $tmp = $lay['order'][$a];
        $lay['order'][$a] = $lay['order'][$b];
        $lay['order'][$b] = $tmp;
        genbtn_layout_set($lang, $alias, $lay['order'], $lay['width'], $textbotlang);
        return true;
    }
    // the same one-tap pair/unpair rule as 📐 چیدمان in «🎨 ظاهر دکمه‌های پنل»
    function genbtn_layout_toggle_width($lang, $alias, $i, $textbotlang)
    {
        $lay = genbtn_layout_get($lang, $alias, $textbotlang);
        $order = $lay['order'];
        $width = $lay['width'];
        $k = (string) $i;
        if (($width[$k] ?? 'full') === 'half') {
            $partner = null;
            foreach (help_layout_chunk_rows($order, array_combine($order, $order), $width) as $r) {
                if (count($r) === 2 && in_array($k, $r, true)) {
                    $partner = ($r[0] === $k) ? $r[1] : $r[0];
                    break;
                }
            }
            $width[$k] = 'full';
            if ($partner !== null) {
                $width[$partner] = 'full';
            }
        } else {
            $pos = array_search($k, $order, true);
            $partner = null;
            if ($pos !== false) {
                if (isset($order[$pos + 1]) && ($width[$order[$pos + 1]] ?? 'full') === 'full') {
                    $partner = $order[$pos + 1];
                } elseif (isset($order[$pos - 1]) && ($width[$order[$pos - 1]] ?? 'full') === 'full') {
                    $partner = $order[$pos - 1];
                }
            }
            $width[$k] = 'half';
            if ($partner !== null) {
                $width[$partner] = 'half';
            }
        }
        genbtn_layout_set($lang, $alias, $order, $width, $textbotlang);
    }

    // the group's buttons exactly as a customer sees them (text, colour, emoji,
    // order, width), 🚫 on hidden ones; $mk turns each into this tool's button
    function genbtn_tool_rows($alias, $lang, $textbotlang, callable $mk)
    {
        $key = genbtn_alias_to_key($alias);
        $btns = [];
        foreach (genbtn_defs($alias, $textbotlang) as $idx => $d) {
            $ov = genbtn_override($lang, $key, $idx);
            $b = genbtn_render($d, $ov, 'none');
            if (genbtn_is_hidden($d, $ov)) {
                $b['text'] = '🚫 ' . $b['text'];
            }
            $btns[$idx] = $mk((int) $idx, $b, $d, $ov);
        }
        return genbtn_group_rows($alias, $lang, $btns, $textbotlang);
    }
    function genbtn_tool_back_row($alias, $lang, $textbotlang, $sfx)
    {
        return [['text' => $textbotlang['Admin']['LangScope']['backToHubBtn'], 'callback_data' => "gbs|hub|{$lang}|{$alias}{$sfx}", 'style' => 'danger']];
    }

    function genbtn_hub_payload($alias, $lang, $textbotlang, $origin = '', $note = '')
    {
        $key = (string) genbtn_alias_to_key($alias);
        $sfx = ($origin === 'u') ? '|u' : '';
        $defs = genbtn_defs($alias, $textbotlang);
        $h = $textbotlang['Admin']['Help'];
        $hasLayout = genbtn_layout_default($alias) !== null;
        $csKey = genbtn_close_sticker_key($alias, $key, 0);
        $info = '<b>' . genbtn_group_title($alias, $textbotlang) . "</b>\n➖➖➖➖➖➖➖➖➖➖\n";
        $groupNote = genbtn_group_note($alias, $textbotlang);
        if ($groupNote !== '') {
            $info .= "ℹ️ {$groupNote}\n";
        }
        if ($csKey !== '') {
            $info .= close_sticker_caption_block($csKey, $note);
        } elseif ($note !== '') {
            $info .= "<blockquote>{$note}</blockquote>\n";
        }
        $info .= "\n" . strtr($h['curLangLine'], ['{lang}' => $textbotlang['bottext']['langs'][$lang] ?? $lang]);
        $kb = ['inline_keyboard' => []];
        if ($hasLayout) {
            $kb['inline_keyboard'][] = [['text' => $h['layoutBtn'], 'callback_data' => "gbs|lay|{$lang}|{$alias}{$sfx}"]];
        }
        // a button that can only show its label gets only the tool that works on it
        if (!genbtn_text_only($alias)) {
            $kb['inline_keyboard'][] = [['text' => $h['colorBtn'], 'callback_data' => "gbs|col|{$lang}|{$alias}{$sfx}"]];
            $kb['inline_keyboard'][] = [['text' => $h['emojiBtn'], 'callback_data' => "gbs|emo|{$lang}|{$alias}{$sfx}"]];
        }
        $kb['inline_keyboard'][] = [['text' => $textbotlang['Admin']['BtnStyle']['renameBtn'], 'callback_data' => "gbs|ren|{$lang}|{$alias}{$sfx}"]];
        // 👁/🚫 - inside 📐 چیدمان for a group that has one, here otherwise
        if (!$hasLayout) {
            $hideRows = [];
            foreach ($defs as $idx => $d) {
                if (!genbtn_hideable($alias, $idx)) {
                    continue;
                }
                $ov = genbtn_override($lang, $key, $idx);
                $hidden = genbtn_is_hidden($d, $ov);
                $label = count($defs) === 1
                    ? ($hidden ? $h['showBtn'] : $h['hideBtn'])
                    : sprintf($hidden ? $h['showItemBtn'] : $h['hideItemBtn'], genbtn_current($d, $ov)[0]);
                $hideRows[] = [['text' => $label, 'callback_data' => "gbs|hide|{$lang}|{$alias}|{$idx}{$sfx}", 'style' => $hidden ? 'success' : 'danger']];
            }
            if (!empty($hideRows)) {
                $kb['inline_keyboard'][] = [['text' => bt_section_meta('genbtn_visibility')['label'], 'callback_data' => 'bt_sep|genbtn_visibility']];
                foreach ($hideRows as $r) {
                    $kb['inline_keyboard'][] = $r;
                }
            }
        }
        // 🖼 استیکر دکمه بستن - only the six ❌ بستن buttons close_sticker_keys()
        // knows about
        if ($csKey !== '') {
            $sc_alias = close_sticker_key_to_alias($csKey);
            $sc_cs = close_sticker_settings($csKey);
            $kb['inline_keyboard'][] = [['text' => bt_section_meta('genbtn_closesticker')['label'], 'callback_data' => 'bt_sep|genbtn_closesticker']];
            $kb['inline_keyboard'][] = [[
                'text' => $sc_cs['enabled'] ? '✅ استیکر بستن: روشن' : '❌ استیکر بستن: خاموش',
                'callback_data' => "clst2tog:{$lang}:{$sc_alias}",
                'style' => $sc_cs['enabled'] ? 'success' : 'danger',
            ]];
            if ($sc_cs['enabled']) {
                $kb['inline_keyboard'][] = [[
                    'text' => '🖼 تغییر استیکر: ' . ($sc_cs['file_id'] === close_sticker_default_file_id() ? 'پیش‌فرض' : 'سفارشی'),
                    'callback_data' => "clst2set:{$lang}:{$sc_alias}",
                    'style' => 'primary',
                ]];
                $kb['inline_keyboard'][] = [[
                    'text' => "⏱ مدت نمایش: {$sc_cs['duration']} ثانیه",
                    'callback_data' => "clst2time:{$lang}:{$sc_alias}",
                    'style' => 'primary',
                ]];
            }
        }
        $kb['inline_keyboard'][] = [['text' => bt_section_meta('genbtn_actions')['label'], 'callback_data' => 'bt_sep|genbtn_actions']];
        $kb['inline_keyboard'][] = [['text' => '🔁 ریست همه به پیش‌فرض', 'callback_data' => "gbs|rstall|{$lang}|{$alias}{$sfx}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => $textbotlang['Admin']['LangScope']['backToHubBtn'], 'callback_data' => genbtn_hub_back_cb($alias, $lang, $origin), 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '❌ بستن', 'callback_data' => 'bt_close', 'style' => 'danger']];
        return [$info, json_encode($kb)];
    }

    // 🎨 رنگ‌بندی: ⚪ پیش‌فرض (the button's own colour) -> 🔵 -> 🟢 -> 🔴 -> ⚪
    function genbtn_color_payload($alias, $lang, $textbotlang, $origin = '')
    {
        $sfx = ($origin === 'u') ? '|u' : '';
        $marks = ['' => '⚪', 'primary' => '🔵', 'success' => '🟢', 'danger' => '🔴'];
        $rows = genbtn_tool_rows($alias, $lang, $textbotlang, function ($idx, $b, $d, $ov) use ($alias, $lang, $sfx, $marks) {
            $cur = (isset($ov['style']) && isset($marks[$ov['style']])) ? $ov['style'] : '';
            $b['text'] .= ' ' . $marks[$cur];
            $b['callback_data'] = "gbs|colp|{$lang}|{$alias}|{$idx}{$sfx}";
            return $b;
        });
        $rows[] = genbtn_tool_back_row($alias, $lang, $textbotlang, $sfx);
        return json_encode(['inline_keyboard' => $rows]);
    }
    // 🎭 ایموجی: tap a button to send its emoji; simple mode and the emoji side
    // are written to every button of the group
    function genbtn_emoji_payload($alias, $lang, $textbotlang, $origin = '')
    {
        $sfx = ($origin === 'u') ? '|u' : '';
        $key = genbtn_alias_to_key($alias);
        $h = $textbotlang['Admin']['Help'];
        $rows = genbtn_tool_rows($alias, $lang, $textbotlang, function ($idx, $b) use ($alias, $lang, $sfx) {
            $b['callback_data'] = "gbs|emop|{$lang}|{$alias}|{$idx}{$sfx}";
            return $b;
        });
        $allSimple = true;
        $allLeft = true;
        foreach (array_keys(genbtn_defs($alias, $textbotlang)) as $idx) {
            $ov = genbtn_override($lang, $key, $idx);
            if (empty($ov['simple'])) {
                $allSimple = false;
            }
            if (($ov['pos'] ?? 'right') !== 'left') {
                $allLeft = false;
            }
        }
        $state = $allSimple ? $textbotlang['Admin']['Status']['statuson'] : $textbotlang['Admin']['Status']['statusoff'];
        $rows[] = [['text' => strtr($h['simpleModeBtn'], ['{state}' => $state]), 'callback_data' => "gbs|emos|{$lang}|{$alias}{$sfx}"]];
        $rows[] = [['text' => strtr($h['emojiSideBtn'], ['{side}' => $allLeft ? $h['emojiSideLeft'] : $h['emojiSideRight']]), 'callback_data' => "gbs|emod|{$lang}|{$alias}{$sfx}"]];
        $rows[] = [['text' => $h['resetEmojiBtn'], 'callback_data' => "gbs|emor|{$lang}|{$alias}{$sfx}"]];
        $rows[] = genbtn_tool_back_row($alias, $lang, $textbotlang, $sfx);
        return json_encode(['inline_keyboard' => $rows]);
    }
    // ✏️ نام سفارشی: tap a button to send its new text
    function genbtn_rename_payload($alias, $lang, $textbotlang, $origin = '')
    {
        $sfx = ($origin === 'u') ? '|u' : '';
        $rows = genbtn_tool_rows($alias, $lang, $textbotlang, function ($idx, $b, $d, $ov) use ($alias, $lang, $sfx) {
            if (isset($ov['text']) && $ov['text'] !== '') {
                $b['text'] .= ' ✏️';
            }
            $b['callback_data'] = "gbs|renp|{$lang}|{$alias}|{$idx}{$sfx}";
            return $b;
        });
        $rows[] = [['text' => $textbotlang['Admin']['BtnStyle']['resetRenameBtn'], 'callback_data' => "gbs|renr|{$lang}|{$alias}{$sfx}"]];
        $rows[] = genbtn_tool_back_row($alias, $lang, $textbotlang, $sfx);
        return json_encode(['inline_keyboard' => $rows]);
    }
    // 📐 چیدمان: tap one, then the destination to swap them; width and hide act
    // on the picked button
    function genbtn_layout_payload($alias, $lang, $textbotlang, $origin = '', $picked = null)
    {
        $sfx = ($origin === 'u') ? '|u' : '';
        $h = $textbotlang['Admin']['Help'];
        $rows = genbtn_tool_rows($alias, $lang, $textbotlang, function ($idx, $b) use ($alias, $lang, $sfx, $picked) {
            if ($picked === null) {
                $b['callback_data'] = "gbs|layp|{$lang}|{$alias}|{$idx}{$sfx}";
            } elseif ($idx === $picked) {
                $b['text'] = '🔵 ' . $b['text'];
                $b['callback_data'] = "gbs|layc|{$lang}|{$alias}{$sfx}";
            } else {
                $b['callback_data'] = "gbs|lays|{$lang}|{$alias}|{$picked}|{$idx}{$sfx}";
            }
            return $b;
        });
        if ($picked !== null) {
            $rows[] = [['text' => $h['toggleWidthBtn'], 'callback_data' => "gbs|layw|{$lang}|{$alias}|{$picked}{$sfx}"]];
            if (genbtn_hideable($alias, $picked)) {
                $d = genbtn_defs($alias, $textbotlang)[$picked] ?? [];
                $hidden = genbtn_is_hidden($d, genbtn_override($lang, genbtn_alias_to_key($alias), $picked));
                $rows[] = [['text' => $hidden ? $h['showBtn'] : $h['hideBtn'], 'callback_data' => "gbs|hide|{$lang}|{$alias}|{$picked}{$sfx}", 'style' => $hidden ? 'success' : 'danger']];
            }
        }
        $rows[] = [['text' => $h['resetLayoutBtn'], 'callback_data' => "gbs|layr|{$lang}|{$alias}{$sfx}"]];
        $rows[] = genbtn_tool_back_row($alias, $lang, $textbotlang, $sfx);
        return json_encode(['inline_keyboard' => $rows]);
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
            if ($idx === 1 && !empty($ov['hidden'])) {
                continue;
            }
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
            $kb['inline_keyboard'][] = [['text' => (!empty($ov['hidden']) ? '🚫 ' : '') . $curText, 'callback_data' => "btact|btn|{$lang}|{$idx}", 'style' => $curStyle]];
        }
        $kb['inline_keyboard'][] = [['text' => '🔁 ریست همه به پیش‌فرض', 'callback_data' => "btact|btnsrstall|{$lang}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => "bt_edit|{$lang}|users.usertest.selectUsernamePrompt", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '❌ بستن', 'callback_data' => 'bt_close', 'style' => 'danger']];
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
        $ub_hidden = !empty($ov['hidden']);
        $kb['inline_keyboard'][] = [['text' => ($ub_hidden ? '🚫 ' : '') . $curText, 'callback_data' => 'none', 'style' => $curStyle]];
        $kb['inline_keyboard'][] = [['text' => '✏️ ویرایش متن', 'callback_data' => "btact|btntext|{$lang}|{$idx}", 'style' => 'primary']];
        $kb['inline_keyboard'][] = [
            ['text' => ($curStyle === 'primary' ? '✅ ' : '') . '🔵 آبی', 'callback_data' => "btact|btnstyle|{$lang}|{$idx}|primary", 'style' => 'primary'],
            ['text' => ($curStyle === 'success' ? '✅ ' : '') . '🟢 سبز', 'callback_data' => "btact|btnstyle|{$lang}|{$idx}|success", 'style' => 'success'],
            ['text' => ($curStyle === 'danger' ? '✅ ' : '') . '🔴 قرمز', 'callback_data' => "btact|btnstyle|{$lang}|{$idx}|danger", 'style' => 'danger'],
        ];
        if ((int) $idx === 1) {
            // cancel (idx 0) is the prompt's way out, so only "use default" can go
            $kb['inline_keyboard'][] = [[
                'text' => $ub_hidden ? '👁 نمایش دادن این دکمه' : '🚫 مخفی کردن این دکمه',
                'callback_data' => "btact|btnhide|{$lang}|1",
                'style' => $ub_hidden ? 'success' : 'danger',
            ]];
        }
        $kb['inline_keyboard'][] = [['text' => '🔁 ریست این دکمه', 'callback_data' => "btact|btnrst|{$lang}|{$idx}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => "btact|btns|{$lang}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '❌ بستن', 'callback_data' => 'bt_close', 'style' => 'danger']];
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
if (!function_exists('balancebtn_button')) {
    // the raw button array for the shared "افزایش موجودی" top-up widget,
    // applying any per-language text/color/premium-emoji override - factored
    // out of balancebtn_kb() so other screens (e.g. the renewal invoice) can
    // splice this exact button into a bigger keyboard of their own instead of
    // duplicating the override-rendering logic
    function balancebtn_button($lang, $textbotlang)
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
        return $btn;
    }
}
if (!function_exists('balancebtn_kb')) {
    // renders the "insufficient balance" message's own top-up button, applying any
    // per-language text/color/premium-emoji override - this is the keyboard actually
    // sent to users at both purchase call sites in index.php
    function balancebtn_kb($lang, $textbotlang)
    {
        return json_encode(['inline_keyboard' => [[balancebtn_button($lang, $textbotlang)]]]);
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
if (!function_exists('topup_disc_is_nav_text')) {
    // A step handler that matches on the step alone swallows the admin's own
    // navigation buttons - the exact two labels $backadmin renders are sent as
    // plain text, so "▶️ بازگشت به منوی قبل" was being answered with
    // "please send only a number". Any ask-for-input step must let these fall
    // through to the real navigation handlers instead of consuming them.
    function topup_disc_is_nav_text($text, $textbotlang)
    {
        $t = trim((string) $text);
        if ($t === '') {
            return false;
        }
        $nav = [
            $textbotlang['Admin']['backAdminBtn'] ?? null,
            $textbotlang['Admin']['backMenuBtn'] ?? null,
            $textbotlang['keyboard']['backToAdminMenu'] ?? null,
            $textbotlang['keyboard']['backToPreviousMenu'] ?? null,
            $textbotlang['keyboard']['backToPrevMenu2'] ?? null,
            $textbotlang['users']['backmenu'] ?? null,
            $textbotlang['Admin']['panelAdmin'] ?? null,
        ];
        foreach ($nav as $n) {
            if ($n !== null && $n !== '' && $t === trim((string) $n)) {
                return true;
            }
        }
        return false;
    }
}
if (!function_exists('topup_disc_usage_breakdown')) {
    // per-user itemised lines for a report: who got a discount, how many times,
    // and how much credit they received. Capped because Telegram hard-limits a
    // message at 4096 chars and a popular code can have hundreds of users.
    function topup_disc_usage_breakdown($sinceTs = 0, $code = null, $limit = 20)
    {
        global $pdo;
        $sql = "SELECT id_user, COUNT(*) uses, COALESCE(SUM(bonus),0) bonus, COALESCE(SUM(paid),0) paid
                FROM topup_discount_use WHERE used_at > :s";
        $params = [':s' => (string) intval($sinceTs)];
        if ($code !== null && $code !== '') {
            $sql .= " AND code = :c";
            $params[':c'] = $code;
        }
        $sql .= " GROUP BY id_user ORDER BY bonus DESC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return '';
        }
        $out = "➖➖➖➖➖➖➖➖➖➖\n📋 <b>ریز گزارش</b> (به تفکیک کاربر):\n";
        $n = 0;
        foreach ($rows as $r) {
            if ($n >= $limit) {
                break;
            }
            $n++;
            $out .= "{$n}. <code>{$r['id_user']}</code> · " . intval($r['uses']) . " بار · تخفیف " . money(intval($r['bonus'])) . "\n";
        }
        $rest = count($rows) - $n;
        if ($rest > 0) {
            $out .= "… و {$rest} کاربر دیگر\n";
        }
        return $out;
    }
}
if (!function_exists('topup_disc_notify_map')) {
    // Notification preferences are GLOBAL, not per gateway/language - they live
    // in their own column rather than inside topup_discounts, whose top level is
    // iterated as {lang: {gateway: ...}} and would misread a reserved key.
    function topup_disc_notify_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['topup_disc_notify'] ?? ''), true);
        $cache = is_array($m) ? $m : [];
        return $cache;
    }
}
if (!function_exists('topup_disc_notify_save')) {
    function topup_disc_notify_save(array $m)
    {
        update("setting", "topup_disc_notify", empty($m) ? '{}' : json_encode($m, JSON_UNESCAPED_UNICODE), null, null);
        topup_disc_notify_map(true);
    }
}
if (!function_exists('topup_disc_notify_get')) {
    function topup_disc_notify_get()
    {
        $m = topup_disc_notify_map();
        return [
            'perUse' => !empty($m['perUse']),
            'periodic' => !empty($m['periodic']),
            'everyHours' => max(1, intval($m['everyHours'] ?? 24)),
            'lastSentAt' => intval($m['lastSentAt'] ?? 0),
        ];
    }
}
if (!function_exists('topup_disc_notify_set')) {
    function topup_disc_notify_set(array $fields)
    {
        $m = topup_disc_notify_get();
        foreach ($fields as $k => $v) {
            if ($v !== null) {
                $m[$k] = $v;
            }
        }
        topup_disc_notify_save($m);
    }
}
if (!function_exists('topup_disc_notify_admin')) {
    // fired the moment a discount is actually awarded. Deliberately defensive:
    // this runs inside DirectPayment, which is crediting real money - a failed
    // notification must never be able to disturb the payment.
    function topup_disc_notify_admin($userId, $codeName, $lang, $gatewayKey, $paid, $bonus)
    {
        if (!function_exists('telegram')) {
            return false;
        }
        $cfg = topup_disc_notify_get();
        if (empty($cfg['perUse'])) {
            return false;
        }
        $setting = select("setting", "*", null, null, "select");
        if (empty($setting['Channel_Report'])) {
            return false;
        }
        $u = select("user", "*", "id", $userId, "select");
        $uname = (is_array($u) && !empty($u['username']) && $u['username'] !== 'none') ? ('@' . $u['username']) : '—';
        $what = ($codeName === '' || $codeName === null) ? 'تخفیف خودکار (بدون کد)' : ("کد <code>" . htmlspecialchars((string) $codeName, ENT_QUOTES) . "</code>");
        $txt = "🎁 <b>استفاده از تخفیف شارژ</b>\n➖➖➖➖➖➖➖➖➖➖\n"
            . "👤 کاربر: {$uname} (<code>{$userId}</code>)\n"
            . "🏷 {$what}\n"
            . "💳 درگاه: <code>{$gatewayKey}</code> · زبان: <code>{$lang}</code>\n"
            . "💰 پرداختی: " . money($paid) . "\n"
            . "🎁 تخفیف: " . money($bonus) . "\n"
            . "💼 مجموع واریز به کیف پول: " . money(floatval($paid) + floatval($bonus)) . "\n"
            // this notifier runs from inside topup_disc_award(), i.e. BEFORE
            // DirectPayment writes the new balance - so the after-figure has to
            // be computed, never read back from the user row
            . "💳 موجودی پس از واریز: " . money(floatval(is_array($u) ? ($u['Balance'] ?? 0) : 0) + floatval($paid) + floatval($bonus));
        telegram('sendmessage', [
            'chat_id' => $setting['Channel_Report'],
            'text' => $txt,
            'parse_mode' => 'HTML',
        ]);
        return true;
    }
}
if (!function_exists('topup_disc_periodic_report')) {
    // periodic summary, driven from the existing per-minute notification cron.
    // Reports the window since the previous report, plus all-time totals.
    function topup_disc_periodic_report($force = false)
    {
        global $pdo;
        if (!function_exists('telegram')) {
            return false;
        }
        $cfg = topup_disc_notify_get();
        if (empty($cfg['periodic']) && !$force) {
            return false;
        }
        $now = time();
        $due = $cfg['lastSentAt'] + ($cfg['everyHours'] * 3600);
        if (!$force && $cfg['lastSentAt'] > 0 && $now < $due) {
            return false;
        }
        $setting = select("setting", "*", null, null, "select");
        if (empty($setting['Channel_Report'])) {
            // still advance the clock so a missing channel does not make every
            // cron tick retry forever
            topup_disc_notify_set(['lastSentAt' => $now]);
            return false;
        }
        $since = intval($cfg['lastSentAt']);
        $st = $pdo->prepare("SELECT COUNT(*) c, COUNT(DISTINCT id_user) u, COALESCE(SUM(bonus),0) b, COALESCE(SUM(paid),0) p FROM topup_discount_use WHERE used_at > :s");
        $st->execute([':s' => (string) $since]);
        $win = $st->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'u' => 0, 'b' => 0, 'p' => 0];
        $all = $pdo->query("SELECT COUNT(*) c, COUNT(DISTINCT id_user) u, COALESCE(SUM(bonus),0) b, COALESCE(SUM(paid),0) p FROM topup_discount_use")->fetch(PDO::FETCH_ASSOC)
            ?: ['c' => 0, 'u' => 0, 'b' => 0, 'p' => 0];

        $hours = $cfg['everyHours'];
        $txt = "📊 <b>گزارش دوره‌ای تخفیف شارژ</b>\n➖➖➖➖➖➖➖➖➖➖\n";
        $txt .= "🕒 بازه: " . ($since > 0 ? "{$hours} ساعت گذشته" : "از ابتدا تا الان") . "\n\n";
        $txt .= "🔁 دفعات استفاده: " . intval($win['c']) . "\n";
        $txt .= "👥 کاربران: " . intval($win['u']) . " نفر\n";
        $txt .= "💰 مجموع پرداختی: " . money(intval($win['p'])) . "\n";
        $txt .= "🎁 مجموع تخفیف: " . money(intval($win['b'])) . "\n";
        $txt .= "➖➖➖➖➖➖➖➖➖➖\n";
        $txt .= "<b>از ابتدا:</b> " . intval($all['c']) . " استفاده · " . intval($all['u']) . " کاربر · تخفیف " . money(intval($all['b'])) . "\n";
        $txt .= topup_disc_usage_breakdown($since);

        telegram('sendmessage', [
            'chat_id' => $setting['Channel_Report'],
            'text' => $txt,
            'parse_mode' => 'HTML',
        ]);
        topup_disc_notify_set(['lastSentAt' => $now]);
        return true;
    }
}
if (!function_exists('topup_disc_notify_payload')) {
    function topup_disc_notify_payload($lang, $key, $textbotlang)
    {
        $cfg = topup_disc_notify_get();
        $info = "🔔 <b>اعلان‌های تخفیف شارژ</b>\n➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "این تنظیمات <b>عمومی</b>ه و برای همه‌ی درگاه‌ها و زبان‌ها یکسان اعمال می‌شه.\n";
        $info .= "گزارش‌ها به کانال گزارش ربات ارسال می‌شن.\n";
        $info .= "➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "🔔 اعلان هر استفاده: " . ($cfg['perUse'] ? 'روشن ✅' : 'خاموش') . "\n";
        $info .= "📊 گزارش دوره‌ای: " . ($cfg['periodic'] ? 'روشن ✅' : 'خاموش') . "\n";
        $info .= "🕒 هر " . $cfg['everyHours'] . " ساعت یک‌بار\n";
        if ($cfg['periodic'] && $cfg['lastSentAt'] > 0) {
            require_once __DIR__ . '/jdf.php';
            $info .= "آخرین گزارش: " . jdate('Y/m/d - H:i', $cfg['lastSentAt']) . "\n";
        }
        $kb = ['inline_keyboard' => []];
        $kb['inline_keyboard'][] = [[
            'text' => ($cfg['perUse'] ? '✅ ' : '') . '🔔 اعلان هر استفاده',
            'callback_data' => "tpdntog:{$lang}:{$key}:peruse",
            'style' => $cfg['perUse'] ? 'success' : 'danger',
        ]];
        $kb['inline_keyboard'][] = [[
            'text' => ($cfg['periodic'] ? '✅ ' : '') . '📊 گزارش دوره‌ای',
            'callback_data' => "tpdntog:{$lang}:{$key}:periodic",
            'style' => $cfg['periodic'] ? 'success' : 'danger',
        ]];
        $kb['inline_keyboard'][] = [['text' => '🕒 تغییر بازه (' . $cfg['everyHours'] . ' ساعت)', 'callback_data' => "tpdnevery:{$lang}:{$key}"]];
        $kb['inline_keyboard'][] = [['text' => '📤 ارسال گزارش همین الان', 'callback_data' => "tpdnnow:{$lang}:{$key}"]];
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => topup_disc_key_back_cb($lang, $key)]];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('topup_disc_method_to_gateway')) {
    // Payment_report.Payment_Method stores a human-ish literal per gateway, not
    // the canonical gateway key. Verified against every INSERT site in
    // index.php (each one sits inside its own `$datain == "<key>"` branch).
    // Anything not listed here - notably 'add balance by admin' and
    // 'low balance by admin' - is NOT a real top-up and must never earn a bonus.
    function topup_disc_method_to_gateway($method)
    {
        $map = [
            'cart to cart' => 'card',
            'plisio' => 'plisio',
            'nowpayment' => 'nowpayment',
            'aqayepardakht' => 'aqayepardakht',
            'zarinpal' => 'zarinpal',
            'Currency Rial 1' => 'iranpay1',
            'Currency Rial 2' => 'iranpay2',
            'Currency Rial 3' => 'iranpay3',
            'arze digital offline' => 'digitaltron',
            'Star Telegram' => 'startelegrams',
            'USDT-BEP20' => 'usdtbep',
            // both settle through DirectPayment() from their cronbot watchers
            // like USDT-BEP20 does; missing here, a discount shown on their
            // screens was never actually credited
            'TON' => 'ton',
            'TRX' => 'trx',
        ];
        return $map[(string) $method] ?? null;
    }
}
if (!function_exists('topup_disc_admin_restrictions_bypassed')) {
    // used to be an opt-in "exempt admins from their own per-user / new-
    // users-only discount limits, for easier testing" toggle (setting.
    // topup_disc_admin_selftest, flipped from topup_disc_hub_payload's now-
    // removed 🧪 button). Removed on explicit request: discount limits now
    // always apply to admins exactly like any other user, unconditionally.
    function topup_disc_admin_restrictions_bypassed($userId)
    {
        return false;
    }
}
if (!function_exists('topup_disc_auto_eligible')) {
    // single source of truth for "would this user still get the codeless
    // auto-discount on this gateway right now" - used both to decide the real
    // bonus at credit time (topup_disc_effective) and to decide whether to
    // even mention it in the top-up caption beforehand, so the two can never
    // drift out of sync the way they did before (caption used to ignore the
    // per-user cap entirely)
    function topup_disc_auto_eligible($userId, $lang, $gatewayKey, $auto = null)
    {
        if ($auto === null) {
            $auto = topup_disc_auto_for($lang, $gatewayKey);
        }
        if (empty($auto['enabled'])) {
            return false;
        }
        if (topup_disc_admin_restrictions_bypassed($userId)) {
            return true;
        }
        $perUser = intval($auto['limitPerUser'] ?? 1);
        if ($perUser > 0 && topup_disc_auto_user_count($userId, $lang, $gatewayKey) >= $perUser) {
            return false;
        }
        if (!empty($auto['newUserOnly']) && topup_disc_user_has_any_paid_topup($userId)) {
            return false;
        }
        return true;
    }
}
if (!function_exists('topup_disc_group_user_count')) {
    // How many codeless discounts this user has already taken anywhere in this
    // CATEGORY - the group quota is a category quota, not a per-gateway one, so
    // it counts every gateway in the group together. That deliberately includes
    // uses awarded by a gateway's own auto discount: both are logged the same
    // way (empty code), and from the customer's side they are the same thing -
    // "a codeless discount on this category".
    function topup_disc_group_user_count($userId, $lang, $group)
    {
        global $pdo;
        // 'all' covers every gateway there is - not a category in the map
        $members = ($group === 'all') ? gateway_all_keys() : (gateway_disc_groups()[$group] ?? []);
        if (empty($members)) {
            return 0;
        }
        $resetAt = intval(topup_disc_group_for($lang, $group)['resetAt'] ?? 0);
        $in = implode(',', array_fill(0, count($members), '?'));
        $sql = "SELECT COUNT(*) FROM topup_discount_use WHERE id_user = ? AND (code = '' OR code IS NULL) AND lang = ? AND gateway IN ($in)";
        $params = array_merge([$userId, $lang], array_values($members));
        if ($resetAt > 0) {
            $sql .= " AND used_at > ?";
            $params[] = (string) $resetAt;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return intval($st->fetchColumn());
    }
}
if (!function_exists('topup_disc_group_eligible')) {
    // the group twin of topup_disc_auto_eligible(), same rules, category scope
    function topup_disc_group_eligible($userId, $lang, $group, $g = null)
    {
        if ($g === null) {
            $g = topup_disc_group_live($lang, $group);
        }
        if (!is_array($g) || empty($g['enabled'])) {
            return false;
        }
        if (topup_disc_admin_restrictions_bypassed($userId)) {
            return true;
        }
        $perUser = intval($g['limitPerUser'] ?? 1);
        if ($perUser > 0 && topup_disc_group_user_count($userId, $lang, $group) >= $perUser) {
            return false;
        }
        if (!empty($g['newUserOnly']) && topup_disc_user_has_any_paid_topup($userId)) {
            return false;
        }
        return true;
    }
}
if (!function_exists('topup_disc_user_has_any_paid_topup')) {
    // true once this user has ANY previously-completed wallet top-up (any
    // gateway) - backs the "فقط کاربران جدید" (new-users-only) restriction on
    // both the auto discount and discount codes. Called from inside
    // topup_disc_award(), itself called from DirectPayment() BEFORE the
    // current row's own payment_Status flips to 'paid', so this naturally
    // excludes the payment currently being credited.
    function topup_disc_user_has_any_paid_topup($userId)
    {
        global $pdo;
        $st = $pdo->prepare("SELECT Payment_Method FROM Payment_report WHERE id_user = ? AND payment_Status = 'paid'");
        $st->execute([$userId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $method) {
            if (topup_disc_method_to_gateway($method) !== null) {
                return true;
            }
        }
        return false;
    }
}
if (!function_exists('topup_disc_auto_user_count')) {
    // mirrors topup_disc_code_user_count() for the codeless auto discount -
    // scoped to (lang, gateway) since a separate auto discount is configured
    // per gateway/language, same scope $auto itself is fetched at. Auto-sourced
    // topup_discount_use rows are logged with an empty code (see topup_disc_award).
    // Only counts uses AFTER the auto discount's own 'resetAt' marker (if set) -
    // "ریست سهمیه‌ی استفاده‌شده" advances that marker instead of deleting log
    // rows, so admin stats (topup_disc_periodic_report etc.) never lose history.
    function topup_disc_auto_user_count($userId, $lang, $gatewayKey)
    {
        global $pdo;
        $resetAt = intval(topup_disc_auto_for($lang, $gatewayKey)['resetAt'] ?? 0);
        $sql = "SELECT COUNT(*) FROM topup_discount_use WHERE id_user = :u AND (code = '' OR code IS NULL) AND lang = :l AND gateway = :g";
        $params = [':u' => $userId, ':l' => $lang, ':g' => $gatewayKey];
        if ($resetAt > 0) {
            $sql .= " AND used_at > :r";
            $params[':r'] = (string) $resetAt;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return intval($st->fetchColumn());
    }
}
if (!function_exists('topup_disc_award')) {
    // Called at the single shared moment a top-up actually credits the wallet
    // (DirectPayment). Returns the extra amount to add on top of what was paid,
    // 0 when nothing applies. Logs the use and clears the user's activated code
    // so a one-shot code cannot be silently reused.
    function topup_disc_award($paymentReport, $userRow)
    {
        $gw = topup_disc_method_to_gateway($paymentReport['Payment_Method'] ?? '');
        if ($gw === null) {
            return 0;
        }
        $userId = $paymentReport['id_user'] ?? '';
        $paid = floatval($paymentReport['price'] ?? 0);
        if ($paid <= 0) {
            return 0;
        }
        $lang = (is_array($userRow) && !empty($userRow['lang'])) ? $userRow['lang'] : 'fa';
        $eff = topup_disc_effective($userId, $lang, $gw, $paid);
        if ($eff === null || $eff['bonus'] <= 0) {
            return 0;
        }
        $bonus = intval($eff['bonus']);
        $codeName = ($eff['source'] === 'code') ? $eff['code'] : '';
        topup_disc_log_use($userId, $codeName, $lang, $gw, $paid, $bonus);
        // never let a notification failure disturb the payment being credited
        try {
            topup_disc_notify_admin($userId, $codeName, $lang, $gw, $paid, $bonus);
        } catch (\Throwable $e) {
            // swallowed on purpose - the money has already been decided
        }
        if ($eff['source'] === 'code') {
            // the activation is consumed - the user re-enters the code if it
            // still has quota left for them
            topup_disc_user_clear($userId);
            topup_disc_report_if_finished($lang, $gw, $codeName);
        }
        return $bonus;
    }
}
if (!function_exists('topup_disc_report_if_finished')) {
    // one summary report per code, the moment it stops being usable (quota
    // filled or validity elapsed) - never one message per use
    function topup_disc_report_if_finished($lang, $gatewayKey, $codeName)
    {
        if ($codeName === '') {
            return;
        }
        $codes = topup_disc_codes_for($lang, $gatewayKey);
        foreach ($codes as $i => $c) {
            if (strcasecmp((string) $c['code'], (string) $codeName) !== 0) {
                continue;
            }
            if (!empty($c['reported'])) {
                return;
            }
            $st = topup_disc_code_status($c);
            if ($st !== 'exhausted' && $st !== 'expired') {
                return;
            }
            if (topup_disc_send_report($c, $lang, $gatewayKey, $st)) {
                topup_disc_code_update($lang, $gatewayKey, $i, ['reported' => true]);
            }
            return;
        }
    }
}
if (!function_exists('topup_disc_send_report')) {
    // returns true when the report was actually delivered (or deliberately
    // skipped because no report channel is configured), false when it could not
    // be sent - the caller uses that to decide whether to mark the code as
    // reported, so a transient failure retries instead of silently vanishing
    function topup_disc_send_report(array $c, $lang, $gatewayKey, $status)
    {
        global $pdo;
        // telegram() lives in botapi.php, which is always loaded in the real bot
        // and cron paths but not in a bare CLI context
        if (!function_exists('telegram')) {
            return false;
        }
        $setting = select("setting", "*", null, null, "select");
        if (empty($setting['Channel_Report'])) {
            return true;
        }
        $uses = topup_disc_code_used_count($c['code']);
        $users = topup_disc_code_unique_users($c['code']);
        $st = $pdo->prepare("SELECT SUM(bonus) FROM topup_discount_use WHERE code = :c");
        $st->execute([':c' => $c['code']]);
        $totalBonus = intval($st->fetchColumn());
        $reason = ($status === 'expired') ? 'مدت اعتبارش تموم شد' : 'ظرفیتش پر شد';
        $txt = "🎁 <b>پایان کد تخفیف</b>\n➖➖➖➖➖➖➖➖➖➖\n"
            . "کد: <code>{$c['code']}</code>\n"
            . "درگاه: <code>{$gatewayKey}</code> · زبان: <code>{$lang}</code>\n"
            . "دلیل: {$reason}\n"
            . "👥 استفاده‌کننده‌ها: {$users} نفر\n"
            . "🔁 دفعات استفاده: {$uses}\n"
            . "💸 مجموع تخفیف داده‌شده: " . money($totalBonus) . "\n"
            . topup_disc_usage_breakdown(0, $c['code']);
        telegram('sendmessage', [
            'chat_id' => $setting['Channel_Report'],
            'text' => $txt,
            'parse_mode' => 'HTML',
        ]);
        return true;
    }
}
if (!function_exists('topup_disc_sweep_expired')) {
    // catches codes whose validity elapsed without anyone using them at the end
    // (so the "finished" report still fires) - safe to call from a cron tick
    function topup_disc_sweep_expired()
    {
        foreach (topup_disc_map() as $lang => $byGw) {
            foreach ((array) $byGw as $gw => $bucket) {
                foreach ((array) ($bucket['codes'] ?? []) as $i => $c) {
                    if (!empty($c['reported'])) {
                        continue;
                    }
                    $st = topup_disc_code_status($c);
                    if ($st === 'expired' || $st === 'exhausted') {
                        if (topup_disc_send_report($c, $lang, $gw, $st)) {
                            topup_disc_code_update($lang, $gw, $i, ['reported' => true]);
                        }
                    }
                }
            }
        }
    }
}
if (!function_exists('topup_disc_terms_line')) {
    // the human summary shown to the USER when a code is activated, and to the
    // admin in the info alert - uses left and the Jalali expiry date
    function topup_disc_terms_line(array $c, $userId = null, $gatewayKey = null, $textbotlang = null)
    {
        $perUser = intval($c['limitPerUser'] ?? 0);
        $used = ($perUser > 0 && $userId !== null) ? topup_disc_code_user_count($c['code'] ?? '', $userId) : 0;
        return topup_disc_render_block('users.Balance.topupDiscActiveBlock', $textbotlang, [
            '{title}' => topup_disc_caption_line($c, $textbotlang),
            '{gateway}' => ($gatewayKey !== null && $textbotlang !== null) ? topup_disc_gateway_label($gatewayKey, $textbotlang) : '',
            '{uses}' => topup_disc_uses_text($perUser, $used, $textbotlang),
            '{expiry}' => topup_disc_expiry_text(intval($c['expiry'] ?? 0), $textbotlang),
        ]);
    }
}
if (!function_exists('topup_disc_render_block')) {
    // Renders one of the discount blocks from its customizable template. The
    // admin's edit in 🎨 شخصی‌سازی پیام‌های ربات wins; the language file is the
    // fallback. Values are escaped here, never the template, so an admin can
    // still use <b>/<blockquote> in their own wording.
    function topup_disc_render_block($key, $textbotlang, array $vars)
    {
        $tpl = function_exists('bottext_resolve_key') ? bottext_resolve_key($key) : '';
        if (trim((string) $tpl) === '') {
            $leaf = substr($key, strrpos($key, '.') + 1);
            $tpl = (string) ($textbotlang['users']['Balance'][$leaf] ?? '');
        }
        $safe = [];
        foreach ($vars as $k => $v) {
            $safe[$k] = htmlspecialchars((string) $v, ENT_QUOTES);
        }
        return trim(strtr($tpl, $safe));
    }
}
if (!function_exists('topup_disc_uses_text')) {
    // "N بار از M بار" or "نامحدود" - one place, so the code block and the
    // codeless block can never drift apart
    function topup_disc_uses_text($perUser, $used, $textbotlang)
    {
        $bal = $textbotlang['users']['Balance'] ?? [];
        if (intval($perUser) <= 0) {
            return (string) ($bal['topupDiscUsesUnlimited'] ?? 'نامحدود');
        }
        $left = max(0, intval($perUser) - intval($used));
        return strtr((string) ($bal['topupDiscUsesLimited'] ?? '{left} بار از {total} بار'), [
            '{left}' => $left,
            '{total}' => intval($perUser),
        ]);
    }
}
if (!function_exists('topup_disc_expiry_text')) {
    function topup_disc_expiry_text($ts, $textbotlang)
    {
        $bal = $textbotlang['users']['Balance'] ?? [];
        if (intval($ts) <= 0) {
            return (string) ($bal['topupDiscExpiryNone'] ?? 'بدون محدودیت زمانی');
        }
        require_once __DIR__ . '/jdf.php';
        return jdate('Y/m/d - H:i', intval($ts));
    }
}
if (!function_exists('topup_disc_active_label')) {
    // the row shown on the 💰 افزایش موجودی method screen: either an invite to
    // enter a code, or the code the user already has active
    function topup_disc_active_label($userId, $lang)
    {
        $act = topup_disc_user_active($userId);
        if ($act === null) {
            return null;
        }
        $found = topup_disc_find_code($act['code']);
        if ($found === null || topup_disc_code_status($found['code']) !== 'active') {
            return null;
        }
        return $act['code'];
    }
}
if (!function_exists('topup_backpkg_label')) {
    // The "back to the amount list" button on the مبلغ دلخواه screen. It used to
    // be the only button there with no customisation at all, and it borrowed the
    // shared users.status.backinfo label (used ~60 other places), so it could not
    // be renamed without affecting unrelated screens - hence its own default here
    // and its own 'backpkg' entry in the existing per-gateway style storage.
    function topup_backpkg_label($lang, $key, $textbotlang)
    {
        $style = topup_btnstyle_for($lang, $key, 'backpkg');
        $label = trim((string) ($style['label'] ?? ''));
        // it returns to the amount screen this one was opened from, so it says
        // so - a bare "بازگشت" next to "بازگشت به روش پرداخت" said nothing about
        // which of the two steps back it actually takes
        return $label !== '' ? $label : '🔙 بازگشت به منوی قبلی';
    }
}
if (!function_exists('topup_backpkg_payload')) {
    function topup_backpkg_payload($lang, $key, $textbotlang)
    {
        $style = topup_btnstyle_for($lang, $key, 'backpkg');
        $label = topup_backpkg_label($lang, $key, $textbotlang);
        $color = (string) ($style['color'] ?? '');
        $custom = (trim((string) ($style['label'] ?? '')) !== '' || $color !== '');

        $info = "🔙 <b>دکمه بازگشت (صفحه مبلغ دلخواه)</b>\n➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "این دکمه کنار «بازگشت به روش پرداخت» توی صفحه‌ی مبلغ دلخواه به کاربر نشون داده می‌شه.\n";
        $info .= "➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "وضعیت: " . ($custom ? 'سفارشی ✅' : 'پیش‌فرض') . "\n";
        $info .= "➖➖➖➖➖➖➖➖➖➖\n👁 پیش‌نمایش زنده 👇";

        $preview = ['text' => $label, 'callback_data' => 'none'];
        if ($color !== '') {
            $preview['style'] = $color;
        }
        $kb = ['inline_keyboard' => []];
        $kb['inline_keyboard'][] = [$preview];
        $kb['inline_keyboard'][] = [['text' => '✏️ ویرایش نام', 'callback_data' => "tpbpn:{$lang}:{$key}"]];
        $kb['inline_keyboard'][] = [
            ['text' => ($color === 'primary' ? '✅ ' : '') . '🔵 آبی', 'callback_data' => "tpbpc:{$lang}:{$key}:primary", 'style' => 'primary'],
            ['text' => ($color === 'success' ? '✅ ' : '') . '🟢 سبز', 'callback_data' => "tpbpc:{$lang}:{$key}:success", 'style' => 'success'],
            ['text' => ($color === 'danger' ? '✅ ' : '') . '🔴 قرمز', 'callback_data' => "tpbpc:{$lang}:{$key}:danger", 'style' => 'danger'],
        ];
        $kb['inline_keyboard'][] = [['text' => '🔁 بازگشت به پیش‌فرض', 'callback_data' => "tpbpr:{$lang}:{$key}", 'style' => 'danger']];
        // reachable from 🏦 (via مبلغ دلخواه) and from 🎨 directly, so it returns
        // to whichever screen actually opened it
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => function_exists('topup_owner_screen_cb')
            ? topup_owner_screen_cb($lang, $key)
            : "topupminmax:{$lang}:{$key}"]];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('topup_disc_live_autos')) {
    // every gateway in this language whose codeless discount is currently live
    // (enabled, non-zero, not past its expiry) -> [gatewayKey => config]
    function topup_disc_live_autos($lang)
    {
        $out = [];
        $m = topup_disc_map();
        foreach ((array) ($m[$lang] ?? []) as $gw => $bucket) {
            $a = $bucket['auto'] ?? [];
            if (!is_array($a) || empty($a['enabled'])) {
                continue;
            }
            if (floatval($a['value'] ?? 0) <= 0) {
                continue;
            }
            $exp = intval($a['expiry'] ?? 0);
            if ($exp > 0 && time() >= $exp) {
                continue;
            }
            $out[$gw] = $a;
        }
        return $out;
    }
}
if (!function_exists('topup_disc_gateway_label')) {
    function topup_disc_gateway_label($gatewayKey, $textbotlang)
    {
        // a '@'-prefixed key names a scope, not a gateway - the code screens
        // pass whichever they are editing
        $scope = topup_disc_scope_of_key($gatewayKey);
        if ($scope !== null) {
            return topup_disc_scope_label($scope, $textbotlang);
        }
        $reg = function_exists('gateway_registry') ? gateway_registry($textbotlang) : [];
        $label = $reg[$gatewayKey] ?? $gatewayKey;
        return trim(strip_tags((string) $label));
    }
}
if (!function_exists('topup_disc_auto_info_text')) {
    // body of the alert behind the auto-discount button on the method screen.
    // Telegram caps show_alert at ~200 chars, so this stays deliberately terse
    // and truncates rather than being silently rejected.
    function topup_disc_auto_info_text($lang, $textbotlang)
    {
        $autos = topup_disc_live_autos($lang);
        if (empty($autos)) {
            return 'در حال حاضر تخفیف خودکاری فعال نیست.';
        }
        $lines = ['🎁 تخفیف خودکار — بدون نیاز به کد'];
        foreach ($autos as $gw => $a) {
            $val = (($a['mode'] ?? 'percent') === 'fixed') ? money($a['value']) : ('٪' . rtrim(rtrim(number_format((float) $a['value'], 2, '.', ','), '0'), '.'));
            $lines[] = '• ' . topup_disc_gateway_label($gw, $textbotlang) . ': ' . $val;
        }
        $exp = 0;
        foreach ($autos as $a) {
            $e = intval($a['expiry'] ?? 0);
            if ($e > 0 && ($exp === 0 || $e < $exp)) {
                $exp = $e;
            }
        }
        if ($exp > 0) {
            require_once __DIR__ . '/jdf.php';
            $lines[] = '⏳ تا ' . jdate('Y/m/d', $exp);
        } else {
            $lines[] = '⏳ بدون محدودیت زمانی';
        }
        $lines[] = '🔁 نامحدود برای همه کاربران';
        $txt = implode("\n", $lines);
        return (mb_strlen($txt) > 195) ? (mb_substr($txt, 0, 192) . '…') : $txt;
    }
}
if (!function_exists('topup_disc_method_caption')) {
    // the method screen's caption, plus a summary of whatever discount the user
    // would actually get - the activated code (with its terms) and every live
    // codeless discount, so nothing is only discoverable by tapping around.
    function topup_disc_method_caption($userId, $lang, $textbotlang)
    {
        $base = $textbotlang['users']['Balance']['selectPaymentGrouped'];
        $parts = [];

        $activeCode = topup_disc_active_label($userId, $lang);
        if ($activeCode !== null) {
            $found = topup_disc_find_code($activeCode);
            if ($found !== null) {
                // the code itself is deliberately NOT printed here - the whole
                // block comes from the admin-editable template, which has no
                // placeholder for it
                $parts[] = topup_disc_terms_line($found['code'], $userId, $found['gateway'], $textbotlang);
            }
        }

        $autos = topup_disc_live_autos($lang);
        $eligibleAutos = [];
        foreach ($autos as $gw => $a) {
            if (topup_disc_auto_eligible($userId, $lang, $gw, $a)) {
                $eligibleAutos[$gw] = $a;
            }
        }
        // a discount set on a whole category gets ONE line naming the category,
        // rather than the same sentence repeated for each of its gateways
        $eligibleGroups = [];
        foreach (topup_disc_scopes() as $grp) {
            if (empty(topup_disc_scope_members($grp, $lang, $textbotlang))) {
                continue;
            }
            $g = topup_disc_group_live($lang, $grp);
            if ($g !== null && topup_disc_group_eligible($userId, $lang, $grp, $g)) {
                $eligibleGroups[$grp] = $g;
            }
        }
        if (!empty($eligibleAutos) || !empty($eligibleGroups)) {
            $autoLines = [];
            $exp = 0;
            foreach ($eligibleGroups as $grp => $g) {
                $perUser = intval($g['limitPerUser'] ?? 0);
                $autoLines[] = topup_disc_render_block('users.Balance.topupDiscAutoLine', $textbotlang, [
                    '{gateway}' => topup_disc_scope_label($grp, $textbotlang),
                    '{value}' => topup_disc_admin_value_label($g),
                    '{uses}' => topup_disc_uses_text($perUser, $perUser > 0 ? topup_disc_group_user_count($userId, $lang, $grp) : 0, $textbotlang),
                ]);
                $e = intval($g['expiry'] ?? 0);
                if ($e > 0 && ($exp === 0 || $e < $exp)) {
                    $exp = $e;
                }
            }
            foreach ($eligibleAutos as $gw => $a) {
                $perUser = intval($a['limitPerUser'] ?? 0);
                $autoLines[] = topup_disc_render_block('users.Balance.topupDiscAutoLine', $textbotlang, [
                    '{gateway}' => topup_disc_gateway_label($gw, $textbotlang),
                    '{value}' => topup_disc_admin_value_label($a),
                    '{uses}' => topup_disc_uses_text($perUser, $perUser > 0 ? topup_disc_auto_user_count($userId, $lang, $gw) : 0, $textbotlang),
                ]);
                $e = intval($a['expiry'] ?? 0);
                if ($e > 0 && ($exp === 0 || $e < $exp)) {
                    $exp = $e;
                }
            }
            // {lines} is already-rendered markup, so it is substituted after the
            // escaping pass rather than through it
            $parts[] = strtr(topup_disc_render_block('users.Balance.topupDiscAutoBlock', $textbotlang, [
                '{expiry}' => topup_disc_expiry_text($exp, $textbotlang),
            ]), ['{lines}' => implode("\n", $autoLines)]);
        }

        if (empty($parts)) {
            return $base;
        }
        return $base . "\n\n<blockquote>" . implode("\n\n", $parts) . '</blockquote>';
    }
}
if (!function_exists('topup_disc_method_keyboard')) {
    // post-processes the payment-method keyboard, appending the discount-code
    // row. Never changes which gateways are listed - purely additive.
    function topup_disc_method_keyboard($kbJson, $userId, $lang)
    {
        $kb = json_decode((string) $kbJson, true);
        if (!is_array($kb) || !isset($kb['inline_keyboard'])) {
            return $kbJson;
        }
        // only offer these rows when the shop actually uses discounts, so a shop
        // that never configured any sees no clutter
        $anyCodes = false;
        foreach (topup_disc_map() as $mLang => $byGw) {
            if ((string) $mLang !== (string) $lang) {
                continue;
            }
            foreach ((array) $byGw as $bucket) {
                if (!empty($bucket['codes'])) {
                    $anyCodes = true;
                    break 2;
                }
            }
        }
        $liveAutos = topup_disc_live_autos($lang);
        if (!$anyCodes && empty($liveAutos)) {
            return $kbJson;
        }
        if (!$anyCodes) {
            return json_encode($kb);
        }
        // Once a code IS applied there is no row at all: the caption already
        // states the discount and its terms, so a "🎁 کد فعال: X" button only
        // repeated it - and it exposed the code itself, which it should not.
        if (topup_disc_active_label($userId, $lang) === null && !bt_button_hidden($lang, 'users.Balance.topupDiscPrompt')) {
            global $textbotlang;
            $defs = genbtn_defs('td', $textbotlang);
            $ov = genbtn_override($lang, 'users.Balance.topupDiscPrompt', 0);
            $row = [genbtn_render($defs[0], $ov, 'topup_disc_enter')];
            // the row belongs UNDER the payment methods but ABOVE ❌ بستن, which
            // topup_method_keyboard() always leaves as the last row
            $rows = $kb['inline_keyboard'];
            $last = end($rows);
            // 'mmclose' now carries a ':xx' section suffix (see close_sticker_keys()),
            // so this is a prefix check rather than an exact match
            $lastCb = (string) ($last[0]['callback_data'] ?? '');
            $closeAt = (is_array($last) && count($last) === 1 && (strpos($lastCb, 'mmclose') === 0 || $lastCb === 'colselist'))
                ? count($rows) - 1 : count($rows);
            array_splice($rows, $closeAt, 0, [$row]);
            $kb['inline_keyboard'] = array_values($rows);
        }
        return json_encode($kb);
    }
}
if (!function_exists('topup_disc_prompt_payload')) {
    // The 🎁 code-entry screen: the same caption + back button whether it is
    // being opened for the first time or re-shown with an error on it, so the
    // whole exchange happens inside ONE message that keeps getting edited.
    // $error is already-escaped plain text, or null for the clean prompt.
    function topup_disc_prompt_payload($lang, $textbotlang, $error = null)
    {
        $caption = bottext_resolve_key('users.Balance.topupDiscPrompt');
        if (trim((string) $caption) === '') {
            $caption = $textbotlang['users']['Balance']['topupDiscPrompt'];
        }
        if ($error !== null) {
            $tpl = bottext_resolve_key('users.Balance.topupDiscInvalid');
            if (trim((string) $tpl) === '') {
                $tpl = $textbotlang['users']['Balance']['topupDiscInvalid'];
            }
            $caption = strtr($tpl, ['{reason}' => $error]) . "\n\n" . $caption;
        }
        $defs = genbtn_defs('td', $textbotlang);
        $ov = genbtn_override($lang, 'users.Balance.topupDiscPrompt', 1);
        $kb = !empty($ov['hidden']) ? null : json_encode(['inline_keyboard' => [[genbtn_render($defs[1], $ov, 'topup_disc_cancel')]]]);
        if ($error !== null && function_exists('bottext_extras_key_hint')) {
            // the text starts with the filled-in reason, so it cannot be
            // recognised by its wording - name the key for its sticker
            bottext_extras_key_hint('users.Balance.topupDiscInvalid');
        }
        return [$caption, $kb];
    }
}
if (!function_exists('topup_disc_caption_line')) {
    // the single bold line shown to the user describing the active discount.
    // $amount is optional: when given, a percent discount can also state the
    // concrete bonus for that specific package.
    function topup_disc_caption_line(array $disc, $textbotlang, $amount = null, $forPackage = false)
    {
        $value = $disc['value'] ?? 0;
        $isFixed = (($disc['mode'] ?? 'percent') === 'fixed');
        $valueTxt = rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.');
        if ($forPackage && $amount !== null) {
            $bonus = topup_disc_bonus_of($disc, $amount);
            $key = $isFixed ? 'topupDiscPkgFixed' : 'topupDiscPkgPercent';
            $tpl = $textbotlang['hardcoded'][$key] ?? ($isFixed ? '{bonus} اضافه برای بسته {amount}' : 'تخفیف {value} درصدی برای بسته {amount}');
            return strtr($tpl, [
                '{value}' => $valueTxt,
                '{bonus}' => money($bonus),
                '{amount}' => money($amount),
            ]);
        }
        // a discount that came from a whole category says so by name, so the
        // customer reads "درگاه‌های آنلاین ارزی ٪۲۰ تخفیف" rather than a bare
        // percentage that gives no hint why it applies here and not elsewhere
        $group = (string) ($disc['group'] ?? '');
        if ($group === 'all') {
            // a discount that covers every gateway has nothing to name - saying
            // "درگاه‌های همه" reads worse than simply not naming a category
            $key = $isFixed ? 'topupDiscAllFixedCaption' : 'topupDiscAllPercentCaption';
            $tpl = $textbotlang['hardcoded'][$key]
                ?? ($isFixed ? '{value} اضافه روی هر شارژ' : 'تخفیف {value} درصدی روی همه‌ی روش‌های پرداخت');
            return strtr($tpl, ['{value}' => $isFixed ? money($value) : $valueTxt]);
        }
        if ($group !== '') {
            $key = $isFixed ? 'topupDiscGroupFixedCaption' : 'topupDiscGroupPercentCaption';
            $tpl = $textbotlang['hardcoded'][$key]
                ?? ($isFixed ? '{value} اضافه برای هر شارژ با {group}' : 'تخفیف {value} درصدی برای {group}');
            return strtr($tpl, [
                '{value}' => $isFixed ? money($value) : $valueTxt,
                // scope-aware: gateway_group_label() would answer for 'all'
                // with the online label, since it falls back to that key
                '{group}' => topup_disc_scope_label($group, $textbotlang),
            ]);
        }
        $key = $isFixed ? 'topupDiscFixedCaption' : 'topupDiscPercentCaption';
        $tpl = $textbotlang['hardcoded'][$key] ?? ($isFixed ? '{value} اضافه برای هر شارژ' : 'تخفیف {value} درصدی برای افزایش موجودی');
        return strtr($tpl, ['{value}' => $isFixed ? money($value) : $valueTxt]);
    }
}
if (!function_exists('topup_disc_caption_block')) {
    // the bold + blockquote block appended under the amount screens' own
    // caption. Returns '' when no discount applies, so call sites can just
    // concatenate unconditionally. $forPackage = true is the invoice's own
    // quote: the bonus for exactly this $amount, the same figure
    // topup_disc_award() credits once the invoice is paid.
    function topup_disc_caption_block($userId, $lang, $gatewayKey, $textbotlang, $amount = null, $forPackage = false)
    {
        $eff = topup_disc_effective($userId, $lang, $gatewayKey, $amount === null ? 100000 : $amount);
        if ($eff === null) {
            return '';
        }
        $line = topup_disc_caption_line($eff['disc'], $textbotlang, $amount, $forPackage);
        return "\n\n<blockquote><b>" . htmlspecialchars($line, ENT_QUOTES) . "</b></blockquote>";
    }
}
if (!function_exists('topup_disc_admin_status_label')) {
    function topup_disc_admin_status_label($status)
    {
        $map = [
            'active' => 'فعال',
            'disabled' => 'خاموش',
            'expired' => 'منقضی',
            'exhausted' => 'سهمیه تمام',
        ];
        return $map[$status] ?? $status;
    }
}
if (!function_exists('topup_disc_admin_value_label')) {
    function topup_disc_admin_value_label(array $d)
    {
        $v = $d['value'] ?? 0;
        $txt = rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.');
        return (($d['mode'] ?? 'percent') === 'fixed') ? (money($v)) : ('٪' . $txt);
    }
}
if (!function_exists('topup_disc_enabled_gateways')) {
    // exactly the gateways a real user of this language sees at checkout - same
    // effective-status rule 🏦 بسته‌های شارژ uses (per-language allow-list AND
    // the separate global switch, both on)
    function topup_disc_enabled_gateways($lang, $textbotlang)
    {
        $out = [];
        foreach (gateway_registry($textbotlang) as $key => $label) {
            if (!gateway_applicable_for_lang($key, $lang)) {
                continue;
            }
            if (!gateway_allowed_for_lang($key, $lang)) {
                continue;
            }
            // gateway_globally_on() lives in admin.php, which is only loaded on
            // the admin dispatch path. This function is admin-UI-only today, so
            // the guard is really a safety net: rather than fatal if it is ever
            // called from a user-facing path (the exact failure gateway_registry
            // once caused in production), it degrades to the per-language rule
            // alone. NOT fixed by moving gateway_globally_on() into function.php
            // - it is the anchor string 10 test files use to extract admin.php's
            // gateway block, and moving it would break every one of them.
            if (function_exists('gateway_globally_on') && !gateway_globally_on($key)) {
                continue;
            }
            $out[$key] = $label;
        }
        return $out;
    }
}
if (!function_exists('topup_disc_gw_summary')) {
    // one compact "what is configured here" string per gateway, so the list is
    // scannable without opening every one
    function topup_disc_gw_summary($lang, $key)
    {
        $bits = [];
        $auto = topup_disc_auto_for($lang, $key);
        $liveAuto = !empty($auto['enabled']) && floatval($auto['value'] ?? 0) > 0
            && (intval($auto['expiry'] ?? 0) === 0 || time() < intval($auto['expiry']));
        if ($liveAuto) {
            $bits[] = topup_disc_admin_value_label($auto);
        }
        $codes = topup_disc_codes_for($lang, $key);
        $activeCodes = 0;
        foreach ($codes as $c) {
            if (topup_disc_code_status($c) === 'active') {
                $activeCodes++;
            }
        }
        if ($activeCodes > 0) {
            $bits[] = "{$activeCodes} کد";
        }
        return empty($bits) ? '' : implode(' · ', $bits);
    }
}
if (!function_exists('topup_disc_gw_list_payload')) {
    // what is configured for a whole category, in one scannable string
    function topup_disc_group_summary($lang, $group)
    {
        $g = topup_disc_group_live($lang, $group);
        return $g === null ? '' : topup_disc_admin_value_label($g);
    }
    // Only the discount itself, for a button label - no code counts, no
    // gateway counts. topup_disc_gw_summary() stays the fuller string the
    // caption reports use.
    function topup_disc_gw_value_summary($lang, $key)
    {
        $auto = topup_disc_auto_for($lang, $key);
        $live = !empty($auto['enabled']) && floatval($auto['value'] ?? 0) > 0
            && (intval($auto['expiry'] ?? 0) === 0 || time() < intval($auto['expiry']));
        return $live ? topup_disc_admin_value_label($auto) : '';
    }
    // the category screen: the discount for the whole family, then its gateways
    function topup_disc_group_list_payload($lang, $group, $textbotlang)
    {
        $members = topup_disc_scope_members($group, $lang, $textbotlang);
        $groupLabel = topup_disc_scope_label($group, $textbotlang);
        $isAll = ($group === 'all');
        $g = topup_disc_group_for($lang, $group);
        $gLive = topup_disc_group_live($lang, $group);
        $scopeKey = topup_disc_scope_key($group);

        $info = "🎁 <b>{$groupLabel}</b>\n➖➖➖➖➖➖➖➖➖➖\n";
        if ($isAll) {
            $info .= "هرچی اینجا تنظیم کنی روی <b>هر " . count($members) . " درگاه فعال</b> این زبان اعمال می‌شه.\n";
            $info .= "با روشن کردنش، تخفیف دسته‌ها و تخفیف تک‌تک درگاه‌ها <b>خاموش</b> می‌شن تا همین یکی اعمال بشه.\n";
        } else {
            $info .= "تخفیف <b>گروهی</b> روی همه‌ی " . count($members) . " درگاه این دسته اعمال می‌شه.\n";
            $info .= "تخفیف <b>تک‌درگاهی</b> هم می‌تونی جدا بذاری — روی هم سوار نمی‌شن، هرکدوم به کاربر بیشتر بده همون اعمال می‌شه.\n";
        }
        $info .= "➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "🎯 تخفیف خودکار: " . ($gLive !== null ? topup_disc_admin_value_label($gLive) . ' ✅' : 'خاموش') . "\n";
        $info .= "🎟 کدهای تخفیف: " . count(topup_disc_codes_for($lang, $scopeKey)) . "\n";
        if ($gLive === null && floatval($g['value'] ?? 0) > 0) {
            $info .= "\n⚠️ <b>مقدار داره ولی فعال نیست</b> — یا خاموشه یا مدتش تموم شده.\n";
        }
        if ($gLive !== null) {
            $preview = $gLive;
            $preview['group'] = $group;
            $info .= "\n👁 چیزی که کاربر می‌بینه:\n<blockquote><b>"
                . htmlspecialchars(topup_disc_caption_line($preview, $textbotlang), ENT_QUOTES) . "</b></blockquote>";
        }

        $kb = ['inline_keyboard' => []];
        // blue throughout: the label says the discount, the caption above says
        // what is set - a green row said only "something here is configured"
        $kb['inline_keyboard'][] = [[
            'text' => ($isAll ? '🎯 تخفیف خودکار همگانی' : '🎯 تخفیف گروهی این دسته')
                . ($gLive !== null ? ' • ' . topup_disc_admin_value_label($gLive) : ''),
            'callback_data' => "dsgrpauto:{$lang}:{$group}",
            'style' => 'primary',
        ]];
        // the same code list every gateway has, attached to this scope instead:
        // one code that works on every gateway the scope covers
        foreach (topup_disc_codes_for($lang, $scopeKey) as $i => $c) {
            $st = topup_disc_code_status($c);
            $used = topup_disc_code_used_count($c['code']);
            $lim = intval($c['limitTotal'] ?? 0);
            $label = "{$c['code']} · " . topup_disc_admin_value_label($c) . ' · ' . ($lim > 0 ? "{$used}/{$lim}" : (string) $used);
            if ($st !== 'active') {
                $label .= ' · ' . topup_disc_admin_status_label($st);
            }
            $kb['inline_keyboard'][] = [[
                'text' => $label,
                'callback_data' => "tpdopen:{$lang}:{$scopeKey}:{$i}",
                'style' => 'primary',
            ]];
        }
        $kb['inline_keyboard'][] = [['text' => '➕ افزودن کد تخفیف', 'callback_data' => "tpdadd:{$lang}:{$scopeKey}", 'style' => 'primary']];
        $memberReport = [];
        if (!$isAll) {
            $kb['inline_keyboard'][] = [['text' => bt_section_meta('topupdisc_members')['label'], 'callback_data' => 'bt_sep|topupdisc_members']];
            foreach ($members as $key => $label) {
                $v = topup_disc_gw_value_summary($lang, $key);
                $kb['inline_keyboard'][] = [[
                    'text' => trim(strip_tags((string) $label)) . ($v !== '' ? " • {$v} تکی" : ''),
                    'callback_data' => topup_disc_key_back_cb($lang, $key),
                    'style' => 'primary',
                ]];
                $full = topup_disc_gw_summary($lang, $key);
                if ($full !== '') {
                    $memberReport[] = '• ' . trim(strip_tags((string) $label)) . ": <b>{$full}</b>";
                }
            }
        }
        if (!empty($memberReport)) {
            $info .= "\n\n<blockquote>💳 <b>تخفیف تکی درگاه‌ها</b>\n" . implode("\n", $memberReport) . '</blockquote>';
        }
        if ($isAll) {
            $info .= "\n\n<blockquote>💳 <b>درگاه‌هایی که شامل می‌شن</b>\n"
                . implode('، ', array_map(fn($l) => trim(strip_tags((string) $l)), $members)) . '</blockquote>';
        }
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => "dslang:{$lang}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => $textbotlang['bottext']['btn_close'] ?? '❌ بستن', 'callback_data' => 'dsclose', 'style' => 'danger']];
        return [$info, json_encode($kb)];
    }
    function topup_disc_gw_list_payload($lang, $textbotlang)
    {
        $gws = topup_disc_enabled_gateways($lang, $textbotlang);
        $langLabel = $textbotlang['bottext']['langs'][$lang] ?? $lang;
        $info = "🎁 <b>تخفیف شارژ</b> — {$langLabel}\n➖➖➖➖➖➖➖➖➖➖\n";
        if (empty($gws)) {
            $info .= "برای این زبان هیچ درگاه فعالی وجود نداره.\nاول از 💳 درگاه‌های پرداخت یکی رو فعال کن.";
        } else {
            $info .= "درگاه‌های فعال این زبان بر اساس نوعشون دسته‌بندی شدن.\n";
            $info .= "روی هر دسته بزن تا هم برای <b>کل دسته</b> تخفیف بذاری، هم برای <b>تک‌تک درگاه‌هاش</b>.\n";
            $info .= "➖➖➖➖➖➖➖➖➖➖\n👇 دسته رو انتخاب کن:";
        }
        $kb = ['inline_keyboard' => []];
        // One row per category that has at least one live gateway. Always blue,
        // never a count: the label carries only the discount itself and whether
        // it is a group one or a single-gateway one, and the quote under the
        // caption spells out what is set where.
        $report = [];
        foreach (array_keys(gateway_disc_groups()) as $group) {
            $members = gateway_disc_group_members($group, $lang, $textbotlang);
            if (empty($members)) {
                continue;
            }
            $groupLabel = trim(strip_tags((string) gateway_group_label($group, $textbotlang)));
            $bits = [];
            $gSum = topup_disc_group_summary($lang, $group);
            if ($gSum !== '') {
                $bits[] = "{$gSum} گروهی";
            }
            // the per-gateway ones: one number when they all agree, otherwise
            // just the fact that some gateway here has its own
            $ownVals = [];
            $ownNames = [];
            foreach ($members as $mk => $mLabel) {
                $v = topup_disc_gw_value_summary($lang, $mk);
                if ($v !== '') {
                    $ownVals[$v] = true;
                }
                // the report may say more than the label does - a gateway with
                // only discount codes has no value to show next to its name.
                // A one-gateway category drops the name: it would otherwise
                // read "کارت به کارت: کارت به کارت (۱ کد)".
                $full = topup_disc_gw_summary($lang, $mk);
                if ($full !== '') {
                    $ownNames[] = count($members) === 1
                        ? $full
                        : trim(strip_tags((string) $mLabel)) . " ({$full})";
                }
            }
            if (count($ownVals) === 1) {
                $bits[] = array_key_first($ownVals) . ' تکی';
            } elseif (count($ownVals) > 1) {
                $bits[] = 'تکی';
            }
            $kb['inline_keyboard'][] = [[
                'text' => $groupLabel . (empty($bits) ? '' : ' • ' . implode(' · ', $bits)),
                'callback_data' => "dsgrp:{$lang}:{$group}",
                'style' => 'primary',
            ]];
            if ($gSum !== '') {
                $report[] = "• {$groupLabel}: <b>{$gSum}</b> روی کل دسته";
            }
            if (!empty($ownNames)) {
                $report[] = "• {$groupLabel}: " . implode('، ', $ownNames);
            }
        }
        if (!empty($gws)) {
            // white divider - the row below is not another category but the
            // one that overrides all of them
            $kb['inline_keyboard'][] = [['text' => bt_section_meta('topupdisc_bulk')['label'], 'callback_data' => 'bt_sep|topupdisc_bulk']];
            $allSum = topup_disc_group_summary($lang, 'all');
            $allCodes = count(topup_disc_codes_for($lang, topup_disc_scope_key('all')));
            $allBits = [];
            if ($allSum !== '') {
                $allBits[] = "{$allSum} همگانی";
            }
            if ($allCodes > 0) {
                $allBits[] = "{$allCodes} کد";
            }
            $kb['inline_keyboard'][] = [[
                'text' => topup_disc_scope_label('all', $textbotlang) . (empty($allBits) ? '' : ' • ' . implode(' · ', $allBits)),
                'callback_data' => "dsgrp:{$lang}:all",
                'style' => 'primary',
            ]];
            if ($allSum !== '') {
                $report[] = '• ' . topup_disc_scope_label('all', $textbotlang) . ": <b>{$allSum}</b> روی همه‌ی درگاه‌ها";
            }
        }
        if (!empty($report)) {
            $info .= "\n\n<blockquote>🎯 <b>تخفیف‌های فعال</b>\n" . implode("\n", $report) . '</blockquote>';
        }
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت به بسته‌های شارژ', 'callback_data' => "topuplang:{$lang}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => $textbotlang['bottext']['btn_close'] ?? '❌ بستن', 'callback_data' => 'dsclose', 'style' => 'danger']];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('topup_disc_bulk_payload')) {
    function topup_disc_bulk_payload($lang, $textbotlang)
    {
        $gws = topup_disc_enabled_gateways($lang, $textbotlang);
        $n = count($gws);
        $langLabel = $textbotlang['bottext']['langs'][$lang] ?? $lang;
        $info = "⚡️ <b>اعمال همگانی</b> — {$langLabel}\n➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "تخفیف خودکاری که اینجا تعیین می‌کنی، یکجا روی <b>هر {$n} درگاه فعال</b> این زبان نوشته می‌شه.\n\n";
        $info .= "بعدش هر درگاه رو می‌تونی جدا عوض کنی — این فقط یه میان‌بره، لایه‌ی جداگانه‌ای روی تخفیف‌ها نیست.\n";
        $info .= "➖➖➖➖➖➖➖➖➖➖\n👇 نوع تخفیف رو انتخاب کن:";
        $kb = ['inline_keyboard' => []];
        $kb['inline_keyboard'][] = [
            ['text' => '٪ درصدی', 'callback_data' => "dsbulkm:{$lang}:percent", 'style' => 'primary'],
            ['text' => '💵 مبلغ ثابت', 'callback_data' => "dsbulkm:{$lang}:fixed", 'style' => 'primary'],
        ];
        $kb['inline_keyboard'][] = [['text' => '🗑 خاموش کردن تخفیف خودکار همه درگاه‌ها', 'callback_data' => "dsbulkoff:{$lang}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => "dslang:{$lang}", 'style' => 'danger']];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('topup_disc_bulk_apply')) {
    // writes the SAME auto discount to every enabled gateway of this language.
    // Deliberately a bulk shortcut, not a new precedence tier: each gateway
    // keeps its own independently-editable entry afterwards, so "which discount
    // applies" stays exactly the two-way code-vs-auto rule it already was.
    function topup_disc_bulk_apply($lang, $mode, $value, $days, $textbotlang)
    {
        $gws = topup_disc_enabled_gateways($lang, $textbotlang);
        $expiry = ($days > 0) ? (time() + $days * 86400) : 0;
        foreach (array_keys($gws) as $key) {
            topup_disc_auto_set($lang, $key, [
                'enabled' => true,
                'mode' => ($mode === 'fixed') ? 'fixed' : 'percent',
                'value' => $value,
                'expiry' => $expiry,
            ]);
        }
        return count($gws);
    }
}
if (!function_exists('topup_disc_bulk_off')) {
    function topup_disc_bulk_off($lang, $textbotlang)
    {
        $gws = topup_disc_enabled_gateways($lang, $textbotlang);
        $n = 0;
        foreach (array_keys($gws) as $key) {
            $a = topup_disc_auto_for($lang, $key);
            if (empty($a['enabled'])) {
                continue;
            }
            $a['enabled'] = false;
            topup_disc_auto_set($lang, $key, $a);
            $n++;
        }
        return $n;
    }
}
if (!function_exists('topup_disc_hub_payload')) {
    function topup_disc_hub_payload($lang, $key, $textbotlang)
    {
        $auto = topup_disc_auto_for($lang, $key);
        $codes = topup_disc_codes_for($lang, $key);
        $autoOn = !empty($auto['enabled']) && floatval($auto['value'] ?? 0) > 0;

        $info = "🎁 <b>تخفیف شارژ</b> — <code>{$key}</code>\n➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "تخفیف روی شارژ کیف پول: کاربر همون مبلغ رو پرداخت می‌کنه، ولی بیشتر شارژ می‌شه.\n";
        $info .= "➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "🎯 تخفیف خودکار (بدون کد): " . ($autoOn ? topup_disc_admin_value_label($auto) . ' ✅' : 'خاموش') . "\n";
        $info .= "🎟 کدهای تخفیف: " . count($codes) . "\n";
        if (!$autoOn && floatval($auto['value'] ?? 0) > 0) {
            $info .= "\n⚠️ <b>تخفیف خودکار مقدار داره ولی خاموشه</b> — روشنش کن تا اعمال بشه.\n";
        }
        if ($autoOn) {
            $info .= "\n👁 چیزی که کاربر می‌بینه:\n<blockquote><b>"
                . htmlspecialchars(topup_disc_caption_line($auto, $textbotlang), ENT_QUOTES) . "</b></blockquote>";
        }

        $kb = ['inline_keyboard' => []];
        $kb['inline_keyboard'][] = [[
            'text' => ($autoOn ? '✅ ' : '') . '🎯 تخفیف خودکار (بدون کد)',
            'callback_data' => "tpdauto:{$lang}:{$key}",
            'style' => $autoOn ? 'success' : 'primary',
        ]];
        foreach ($codes as $i => $c) {
            $st = topup_disc_code_status($c);
            $used = topup_disc_code_used_count($c['code']);
            $lim = intval($c['limitTotal'] ?? 0);
            $usedTxt = $lim > 0 ? "{$used}/{$lim}" : (string) $used;
            $label = "{$c['code']} · " . topup_disc_admin_value_label($c) . " · {$usedTxt}";
            if ($st !== 'active') {
                $label .= ' · ' . topup_disc_admin_status_label($st);
            }
            $kb['inline_keyboard'][] = [[
                'text' => $label,
                'callback_data' => "tpdopen:{$lang}:{$key}:{$i}",
                'style' => ($st === 'active') ? 'success' : 'primary',
            ]];
        }
        $kb['inline_keyboard'][] = [['text' => '➕ افزودن کد تخفیف', 'callback_data' => "tpdadd:{$lang}:{$key}", 'style' => 'primary']];
        $tpd_ncfg = topup_disc_notify_get();
        $tpd_nOn = (!empty($tpd_ncfg['perUse']) || !empty($tpd_ncfg['periodic']));
        $kb['inline_keyboard'][] = [[
            'text' => ($tpd_nOn ? '✅ ' : '') . '🔔 اعلان‌های تخفیف (عمومی)',
            'callback_data' => "tpdnotif:{$lang}:{$key}",
            'style' => $tpd_nOn ? 'success' : 'primary',
        ]];
        // back to the category this gateway was opened from, not past it to the
        // category list - one screen back, the way every other level here works
        $tpd_grp = gateway_disc_group_of($key);
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => $tpd_grp === null ? "dslang:{$lang}" : "dsgrp:{$lang}:{$tpd_grp}", 'style' => 'danger']];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('topup_disc_auto_payload')) {
    function topup_disc_auto_payload($lang, $key, $textbotlang)
    {
        require_once __DIR__ . '/jdf.php';
        $auto = topup_disc_auto_for($lang, $key);
        $on = !empty($auto['enabled']);
        $mode = ($auto['mode'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
        $val = floatval($auto['value'] ?? 0);
        $perUser = intval($auto['limitPerUser'] ?? 1);
        $newOnly = !empty($auto['newUserOnly']);

        $info = "🎯 <b>تخفیف خودکار</b> — <code>{$key}</code>\n➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "بدون نیاز به کد، برای همه‌ی کاربرهای این زبان و این درگاه.\n";
        $info .= "➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "وضعیت: " . ($on ? 'روشن ✅' : 'خاموش') . "\n";
        $info .= "نوع: " . ($mode === 'fixed' ? 'مبلغ ثابت' : 'درصدی') . "\n";
        $info .= "مقدار: " . topup_disc_admin_value_label($auto) . "\n";
        $autoExp = intval($auto['expiry'] ?? 0);
        $info .= "انقضا: " . ($autoExp > 0 ? jdate('Y/m/d - H:i', $autoExp) : 'ندارد') . "\n";
        $info .= "هر کاربر: " . ($perUser > 0 ? "{$perUser} بار" : 'نامحدود') . " (پیش‌فرض: ۱ بار)\n";
        $info .= "فقط کاربران جدید: " . ($newOnly ? 'بله' : 'خیر') . "\n";
        $resetAt = intval($auto['resetAt'] ?? 0);
        if ($resetAt > 0) {
            $info .= "👥 آخرین ریست سهمیه: " . jdate('Y/m/d - H:i', $resetAt) . " (استفاده‌های قبل از این تاریخ حساب نمی‌شن)\n";
        }
        if ($autoExp > 0 && time() >= $autoExp) {
            $info .= "\n⚠️ <b>مدت این تخفیف تموم شده</b> — تا تاریخش رو تمدید نکنی اعمال نمی‌شه.\n";
        }
        if (!$on && $val > 0) {
            $info .= "\n⚠️ <b>مقدار ست شده ولی تخفیف خاموشه</b> — تا روشنش نکنی به کاربر چیزی اضافه نمی‌شه.\n";
        }
        if ($on && $val > 0) {
            $info .= "\n👁 چیزی که کاربر می‌بینه:\n<blockquote><b>"
                . htmlspecialchars(topup_disc_caption_line($auto, $textbotlang), ENT_QUOTES) . "</b></blockquote>";
        }

        $kb = ['inline_keyboard' => []];
        $kb['inline_keyboard'][] = [[
            'text' => $on ? '✅ روشن' : '❌ خاموش',
            'callback_data' => "tpdautotog:{$lang}:{$key}",
            'style' => $on ? 'success' : 'primary',
        ]];
        $kb['inline_keyboard'][] = [
            ['text' => ($mode === 'percent' ? '✅ ' : '') . '٪ درصدی', 'callback_data' => "tpdautomode:{$lang}:{$key}:percent", 'style' => 'primary'],
            ['text' => ($mode === 'fixed' ? '✅ ' : '') . '💵 مبلغ ثابت', 'callback_data' => "tpdautomode:{$lang}:{$key}:fixed", 'style' => 'primary'],
        ];
        $kb['inline_keyboard'][] = [['text' => '✏️ تغییر مقدار (' . topup_disc_admin_value_label($auto) . ')', 'callback_data' => "tpdautoval:{$lang}:{$key}", 'style' => $val > 0 ? 'success' : 'primary']];
        $kb['inline_keyboard'][] = [['text' => '⏳ مدت اعتبار', 'callback_data' => "tpdautoexp:{$lang}:{$key}", 'style' => $autoExp > 0 ? 'success' : 'primary']];
        $kb['inline_keyboard'][] = [['text' => '👤 سهمیه هر کاربر' . ($perUser > 0 ? " ({$perUser} بار)" : ''), 'callback_data' => "tpdautolimit:{$lang}:{$key}", 'style' => $perUser > 0 ? 'success' : 'primary']];
        $kb['inline_keyboard'][] = [['text' => ($newOnly ? '✅ ' : '') . '🆕 فقط کاربران جدید', 'callback_data' => "tpdautonewonly:{$lang}:{$key}", 'style' => $newOnly ? 'success' : 'primary']];
        // white divider - everything above edits a SETTING, everything below
        // is a one-tap action with an immediate, wider effect (no confirm
        // step, matching this hub's own existing convention for such buttons)
        $kb['inline_keyboard'][] = [['text' => bt_section_meta('topupdisc_auto_actions')['label'], 'callback_data' => 'bt_sep|topupdisc_auto_actions']];
        $kb['inline_keyboard'][] = [['text' => '👥 ریست سهمیه‌ی استفاده‌شده', 'callback_data' => "tpdautoresetusage:{$lang}:{$key}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '🔄 ریست تنظیمات به پیش‌فرض', 'callback_data' => "tpdautoreset:{$lang}:{$key}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => topup_disc_key_back_cb($lang, $key), 'style' => 'danger']];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('topup_disc_group_auto_payload')) {
    // The category's own discount. Same screen as a gateway's auto discount,
    // same fields, same wording - only the scope differs, so an admin who has
    // used one already knows this one.
    function topup_disc_group_auto_payload($lang, $group, $textbotlang)
    {
        require_once __DIR__ . '/jdf.php';
        $g = topup_disc_group_for($lang, $group);
        $members = topup_disc_scope_members($group, $lang, $textbotlang);
        $groupLabel = topup_disc_scope_label($group, $textbotlang);
        $on = !empty($g['enabled']);
        $mode = ($g['mode'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
        $val = floatval($g['value'] ?? 0);
        $perUser = intval($g['limitPerUser'] ?? 1);
        $newOnly = !empty($g['newUserOnly']);
        $exp = intval($g['expiry'] ?? 0);

        $info = "🎯 <b>تخفیف گروهی</b> — {$groupLabel}\n➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "بدون نیاز به کد، روی هر " . count($members) . " درگاه این دسته یکجا.\n";
        $info .= "اگه درگاهی تخفیف خودش رو هم داشته باشه، هرکدوم برای کاربر بهتر باشه همون اعمال می‌شه — جمع نمی‌شن.\n";
        $info .= "➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "وضعیت: " . ($on ? 'روشن ✅' : 'خاموش') . "\n";
        $info .= "نوع: " . ($mode === 'fixed' ? 'مبلغ ثابت' : 'درصدی') . "\n";
        $info .= "مقدار: " . topup_disc_admin_value_label($g) . "\n";
        $info .= "انقضا: " . ($exp > 0 ? jdate('Y/m/d - H:i', $exp) : 'ندارد') . "\n";
        $info .= "هر کاربر: " . ($perUser > 0 ? "{$perUser} بار" : 'نامحدود') . " (پیش‌فرض: ۱ بار)\n";
        $info .= "فقط کاربران جدید: " . ($newOnly ? 'بله' : 'خیر') . "\n";
        $info .= "درگاه‌های این دسته: " . implode('، ', array_map(fn($l) => trim(strip_tags((string) $l)), $members)) . "\n";
        $resetAt = intval($g['resetAt'] ?? 0);
        if ($resetAt > 0) {
            $info .= "👥 آخرین ریست سهمیه: " . jdate('Y/m/d - H:i', $resetAt) . " (استفاده‌های قبل از این تاریخ حساب نمی‌شن)\n";
        }
        if ($exp > 0 && time() >= $exp) {
            $info .= "\n⚠️ <b>مدت این تخفیف تموم شده</b> — تا تاریخش رو تمدید نکنی اعمال نمی‌شه.\n";
        }
        if (!$on && $val > 0) {
            $info .= "\n⚠️ <b>مقدار ست شده ولی تخفیف خاموشه</b> — تا روشنش نکنی به کاربر چیزی اضافه نمی‌شه.\n";
        }
        if ($on && $val > 0) {
            $preview = $g;
            $preview['group'] = $group;
            $info .= "\n👁 چیزی که کاربر می‌بینه:\n<blockquote><b>"
                . htmlspecialchars(topup_disc_caption_line($preview, $textbotlang), ENT_QUOTES) . "</b></blockquote>";
        }

        $kb = ['inline_keyboard' => []];
        $kb['inline_keyboard'][] = [[
            'text' => $on ? '✅ روشن' : '❌ خاموش',
            'callback_data' => "dsgrptog:{$lang}:{$group}",
            'style' => $on ? 'success' : 'primary',
        ]];
        $kb['inline_keyboard'][] = [
            ['text' => ($mode === 'percent' ? '✅ ' : '') . '٪ درصدی', 'callback_data' => "dsgrpmode:{$lang}:{$group}:percent", 'style' => 'primary'],
            ['text' => ($mode === 'fixed' ? '✅ ' : '') . '💵 مبلغ ثابت', 'callback_data' => "dsgrpmode:{$lang}:{$group}:fixed", 'style' => 'primary'],
        ];
        $kb['inline_keyboard'][] = [['text' => '✏️ تغییر مقدار (' . topup_disc_admin_value_label($g) . ')', 'callback_data' => "dsgrpval:{$lang}:{$group}", 'style' => $val > 0 ? 'success' : 'primary']];
        $kb['inline_keyboard'][] = [['text' => '⏳ مدت اعتبار', 'callback_data' => "dsgrpexp:{$lang}:{$group}", 'style' => $exp > 0 ? 'success' : 'primary']];
        $kb['inline_keyboard'][] = [['text' => '👤 سهمیه هر کاربر' . ($perUser > 0 ? " ({$perUser} بار)" : ''), 'callback_data' => "dsgrplimit:{$lang}:{$group}", 'style' => $perUser > 0 ? 'success' : 'primary']];
        $kb['inline_keyboard'][] = [['text' => ($newOnly ? '✅ ' : '') . '🆕 فقط کاربران جدید', 'callback_data' => "dsgrpnewonly:{$lang}:{$group}", 'style' => $newOnly ? 'success' : 'primary']];
        $kb['inline_keyboard'][] = [['text' => bt_section_meta('topupdisc_auto_actions')['label'], 'callback_data' => 'bt_sep|topupdisc_auto_actions']];
        $kb['inline_keyboard'][] = [['text' => '👥 ریست سهمیه‌ی استفاده‌شده', 'callback_data' => "dsgrpresetusage:{$lang}:{$group}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '🔄 ریست تنظیمات به پیش‌فرض', 'callback_data' => "dsgrpreset:{$lang}:{$group}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => "dsgrp:{$lang}:{$group}", 'style' => 'danger']];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('topup_disc_code_payload')) {
    function topup_disc_code_payload($lang, $key, $idx, $textbotlang)
    {
        $c = topup_disc_code_get($lang, $key, $idx);
        if (empty($c)) {
            return topup_disc_hub_payload($lang, $key, $textbotlang);
        }
        $st = topup_disc_code_status($c);
        $mode = ($c['mode'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
        $used = topup_disc_code_used_count($c['code']);
        $uniq = topup_disc_code_unique_users($c['code']);
        $lim = intval($c['limitTotal'] ?? 0);
        $perUser = intval($c['limitPerUser'] ?? 1);
        $exp = intval($c['expiry'] ?? 0);
        $newOnly = !empty($c['newUserOnly']);

        $info = "🎟 <b>کد تخفیف</b>\n➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "کد: <code>{$c['code']}</code>\n";
        $info .= "💳 فقط برای درگاه: " . topup_disc_gateway_label($key, $textbotlang) . "\n";
        $info .= "وضعیت: " . topup_disc_admin_status_label($st) . "\n";
        $info .= "نوع: " . ($mode === 'fixed' ? 'مبلغ ثابت' : 'درصدی') . " — " . topup_disc_admin_value_label($c) . "\n";
        $info .= "سهمیه کل: " . ($lim > 0 ? "{$used} از {$lim}" : "{$used} (نامحدود)") . "\n";
        $info .= "هر کاربر: " . ($perUser > 0 ? "{$perUser} بار" : 'نامحدود') . " (پیش‌فرض: ۱ بار)\n";
        $info .= "انقضا: " . ($exp > 0 ? jdate('Y/m/d H:i', $exp) : 'ندارد') . "\n";
        $info .= "فقط کاربران جدید: " . ($newOnly ? 'بله' : 'خیر') . "\n";
        $info .= "👥 استفاده‌کننده‌ها: {$uniq} نفر\n";
        if (floatval($c['value'] ?? 0) > 0) {
            $info .= "\n👁 چیزی که کاربر می‌بینه:\n<blockquote><b>"
                . htmlspecialchars(topup_disc_caption_line($c, $textbotlang), ENT_QUOTES) . "</b></blockquote>";
        }

        $on = !empty($c['enabled']);
        $kb = ['inline_keyboard' => []];
        $kb['inline_keyboard'][] = [[
            'text' => $on ? '✅ فعال' : '❌ غیرفعال',
            'callback_data' => "tpdtog:{$lang}:{$key}:{$idx}",
            'style' => $on ? 'success' : 'primary',
        ]];
        $kb['inline_keyboard'][] = [
            ['text' => ($mode === 'percent' ? '✅ ' : '') . '٪ درصدی', 'callback_data' => "tpdmode:{$lang}:{$key}:{$idx}:percent", 'style' => 'primary'],
            ['text' => ($mode === 'fixed' ? '✅ ' : '') . '💵 مبلغ ثابت', 'callback_data' => "tpdmode:{$lang}:{$key}:{$idx}:fixed", 'style' => 'primary'],
        ];
        $kb['inline_keyboard'][] = [['text' => '✏️ مقدار (' . topup_disc_admin_value_label($c) . ')', 'callback_data' => "tpdval:{$lang}:{$key}:{$idx}", 'style' => floatval($c['value'] ?? 0) > 0 ? 'success' : 'primary']];
        $kb['inline_keyboard'][] = [
            ['text' => '🔢 سهمیه کل', 'callback_data' => "tpdlimit:{$lang}:{$key}:{$idx}", 'style' => $lim > 0 ? 'success' : 'primary'],
            ['text' => '👤 سهمیه هر کاربر', 'callback_data' => "tpduser:{$lang}:{$key}:{$idx}", 'style' => $perUser > 0 ? 'success' : 'primary'],
        ];
        $kb['inline_keyboard'][] = [['text' => '⏳ مدت اعتبار', 'callback_data' => "tpdexp:{$lang}:{$key}:{$idx}", 'style' => $exp > 0 ? 'success' : 'primary']];
        $kb['inline_keyboard'][] = [['text' => ($newOnly ? '✅ ' : '') . '🆕 فقط کاربران جدید', 'callback_data' => "tpdnewonly:{$lang}:{$key}:{$idx}", 'style' => $newOnly ? 'success' : 'primary']];
        $kb['inline_keyboard'][] = [['text' => '🗑 حذف این کد', 'callback_data' => "tpddel:{$lang}:{$key}:{$idx}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => topup_disc_key_back_cb($lang, $key), 'style' => 'danger']];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('topup_disc_map')) {
    // {lang: {gatewayKey: {auto: {...}, codes: [ {...}, ... ]}}}
    // "auto" is the single codeless discount for that gateway/language (only one
    // can be active, which removes any ambiguity about stacking two of them);
    // "codes" is the admin-managed list of redeemable codes.
    function topup_disc_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['topup_discounts'] ?? ''), true);
        $cache = is_array($m) ? $m : [];
        return $cache;
    }
}
if (!function_exists('topup_disc_save')) {
    function topup_disc_save(array $map)
    {
        $json = empty($map) ? '{}' : json_encode($map, JSON_UNESCAPED_UNICODE);
        update("setting", "topup_discounts", $json, null, null);
        topup_disc_map(true);
    }
}
if (!function_exists('topup_disc_auto_for')) {
    function topup_disc_auto_for($lang, $gatewayKey)
    {
        $m = topup_disc_map();
        $a = $m[$lang][$gatewayKey]['auto'] ?? [];
        return is_array($a) ? $a : [];
    }
}
if (!function_exists('topup_disc_auto_set')) {
    function topup_disc_auto_set($lang, $gatewayKey, array $auto)
    {
        $m = topup_disc_map();
        $clean = [];
        $clean['enabled'] = !empty($auto['enabled']);
        $clean['mode'] = (isset($auto['mode']) && $auto['mode'] === 'fixed') ? 'fixed' : 'percent';
        $clean['value'] = max(0, floatval($auto['value'] ?? 0));
        $clean['expiry'] = max(0, intval($auto['expiry'] ?? 0));
        $clean['limitPerUser'] = max(0, intval($auto['limitPerUser'] ?? 1));
        $clean['newUserOnly'] = !empty($auto['newUserOnly']);
        $clean['resetAt'] = max(0, intval($auto['resetAt'] ?? 0));
        $m[$lang][$gatewayKey]['auto'] = $clean;
        topup_disc_save($m);
    }
}
if (!function_exists('topup_disc_group_map')) {
    // ---- discounts set on a whole CATEGORY at once ----
    // Its own column rather than a reserved key inside topup_discounts, whose
    // top level is iterated as {lang: {gateway: ...}} and would misread one -
    // the same reason topup_disc_notify has its own column.
    // Shape: {lang: {group: {enabled, mode, value, expiry, limitPerUser,
    // newUserOnly, resetAt}}} - deliberately identical to a gateway's own auto
    // entry, so everything that already reads one reads this too.
    function topup_disc_group_map($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $m = json_decode((string) ($setting['topup_disc_groups'] ?? ''), true);
        $cache = is_array($m) ? $m : [];
        return $cache;
    }
}
if (!function_exists('topup_disc_group_save')) {
    function topup_disc_group_save(array $map)
    {
        $json = empty($map) ? '{}' : json_encode($map, JSON_UNESCAPED_UNICODE);
        update("setting", "topup_disc_groups", $json, null, null);
        topup_disc_group_map(true);
    }
}
if (!function_exists('topup_disc_group_for')) {
    // A scope's row holds {auto: {...}, codes: [...]}. Rows written before
    // scoped codes existed are the bare auto array - read those as-is rather
    // than migrating, so an already-configured category keeps working.
    function topup_disc_group_for($lang, $group)
    {
        $m = topup_disc_group_map();
        $row = $m[$lang][$group] ?? [];
        if (!is_array($row)) {
            return [];
        }
        if (array_key_exists('auto', $row)) {
            return is_array($row['auto']) ? $row['auto'] : [];
        }
        // legacy flat shape: the row IS the auto discount
        unset($row['codes']);
        return $row;
    }
}
if (!function_exists('topup_disc_group_set')) {
    function topup_disc_group_set($lang, $group, array $auto)
    {
        $m = topup_disc_group_map();
        // whatever codes this scope already had survive an auto-discount edit
        $codes = topup_disc_codes_for($lang, topup_disc_scope_key($group));
        $m[$lang][$group] = [
            'auto' => [
                'enabled' => !empty($auto['enabled']),
                'mode' => (isset($auto['mode']) && $auto['mode'] === 'fixed') ? 'fixed' : 'percent',
                'value' => max(0, floatval($auto['value'] ?? 0)),
                'expiry' => max(0, intval($auto['expiry'] ?? 0)),
                'limitPerUser' => max(0, intval($auto['limitPerUser'] ?? 1)),
                'newUserOnly' => !empty($auto['newUserOnly']),
                'resetAt' => max(0, intval($auto['resetAt'] ?? 0)),
            ],
            'codes' => $codes,
        ];
        topup_disc_group_save($m);
    }
}
if (!function_exists('topup_disc_scope_disable_others')) {
    // "اعمال همگانی" is meant to be the one discount in force: switching it on
    // turns off every category discount and every gateway's own, so what the
    // customer gets is the bulk one rather than whichever happened to be
    // larger. Returns how many were switched off, for the confirmation line.
    function topup_disc_scope_disable_others($lang, $keepScope)
    {
        $n = 0;
        foreach (array_keys(gateway_disc_groups()) as $g) {
            if ($g === $keepScope) {
                continue;
            }
            $a = topup_disc_group_for($lang, $g);
            if (!empty($a['enabled'])) {
                $a['enabled'] = false;
                topup_disc_group_set($lang, $g, $a);
                $n++;
            }
        }
        foreach (array_keys(topup_disc_map()[$lang] ?? []) as $gw) {
            $a = topup_disc_auto_for($lang, $gw);
            if (!empty($a['enabled'])) {
                $a['enabled'] = false;
                topup_disc_auto_set($lang, $gw, $a);
                $n++;
            }
        }
        return $n;
    }
}
if (!function_exists('topup_disc_group_live')) {
    // a group discount that is switched on, has a value, and has not run out
    function topup_disc_group_live($lang, $group)
    {
        $g = topup_disc_group_for($lang, $group);
        if (empty($g['enabled']) || floatval($g['value'] ?? 0) <= 0) {
            return null;
        }
        $exp = intval($g['expiry'] ?? 0);
        if ($exp > 0 && time() >= $exp) {
            return null;
        }
        return $g;
    }
}
if (!function_exists('topup_disc_group_reset_usage')) {
    function topup_disc_group_reset_usage($lang, $group)
    {
        $g = topup_disc_group_for($lang, $group);
        $g['resetAt'] = time();
        topup_disc_group_set($lang, $group, $g);
    }
}
if (!function_exists('topup_disc_auto_reset_usage')) {
    // "ریست سهمیه‌ی استفاده‌شده" - lets every user hit their per-user quota
    // (limitPerUser) again, WITHOUT touching the discount's own settings
    // (value/mode/expiry/newUserOnly stay exactly as they were - unlike
    // topup_disc_auto_reset() which wipes those back to defaults) and WITHOUT
    // deleting anything from topup_discount_use, so admin stats/reports keep
    // their full history. topup_disc_auto_user_count() only counts uses after
    // this marker.
    function topup_disc_auto_reset_usage($lang, $gatewayKey)
    {
        $auto = topup_disc_auto_for($lang, $gatewayKey);
        $auto['resetAt'] = time();
        topup_disc_auto_set($lang, $gatewayKey, $auto);
    }
}
if (!function_exists('topup_disc_scope_of_key')) {
    // A "key" in this whole family is normally a gateway. Prefixed with '@' it
    // is a SCOPE instead: one of the four categories, or 'all' for every
    // gateway at once. No gateway key contains '@', so the two can never be
    // confused - which matters because 'card' is both a gateway and a category.
    //
    // Everything downstream (the code list screen, add/edit/remove, the status
    // rules, the redemption check) works on that key without caring which kind
    // it is; only the two storage accessors below and the label do.
    function topup_disc_scope_of_key($key)
    {
        $key = (string) $key;
        return (strlen($key) > 1 && $key[0] === '@') ? substr($key, 1) : null;
    }
    // Where a code screen's "back" goes: to the gateway's own discount screen,
    // or to the scope's. One place, so every code screen agrees.
    function topup_disc_key_back_cb($lang, $key)
    {
        $scope = topup_disc_scope_of_key($key);
        return $scope !== null ? "dsgrp:{$lang}:{$scope}" : "topupdisc:{$lang}:{$key}";
    }
    // ...and the screen itself, for the handlers that re-render after an edit
    function topup_disc_key_screen($lang, $key, $textbotlang)
    {
        $scope = topup_disc_scope_of_key($key);
        return $scope !== null
            ? topup_disc_group_list_payload($lang, $scope, $textbotlang)
            : topup_disc_hub_payload($lang, $key, $textbotlang);
    }
    function topup_disc_scope_key($scope)
    {
        return '@' . $scope;
    }
    // every scope a discount can be attached to, in display order
    function topup_disc_scopes()
    {
        return array_merge(array_keys(gateway_disc_groups()), ['all']);
    }
    // the gateways a scope covers, filtered to what this language can see
    function topup_disc_scope_members($scope, $lang, $textbotlang)
    {
        if ($scope === 'all') {
            return topup_disc_enabled_gateways($lang, $textbotlang);
        }
        return gateway_disc_group_members($scope, $lang, $textbotlang);
    }
    function topup_disc_scope_label($scope, $textbotlang)
    {
        if ($scope === 'all') {
            return '⚡️ همه‌ی درگاه‌ها';
        }
        return trim(strip_tags((string) gateway_group_label($scope, $textbotlang)));
    }
}
if (!function_exists('topup_disc_codes_for')) {
    function topup_disc_codes_for($lang, $gatewayKey)
    {
        $scope = topup_disc_scope_of_key($gatewayKey);
        if ($scope !== null) {
            $m = topup_disc_group_map();
            $c = $m[$lang][$scope]['codes'] ?? [];
            return is_array($c) ? array_values($c) : [];
        }
        $m = topup_disc_map();
        $c = $m[$lang][$gatewayKey]['codes'] ?? [];
        return is_array($c) ? array_values($c) : [];
    }
}
if (!function_exists('topup_disc_codes_set')) {
    function topup_disc_codes_set($lang, $gatewayKey, array $codes)
    {
        $clean = [];
        foreach ($codes as $c) {
            $code = trim((string) ($c['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $clean[] = [
                'code' => $code,
                'mode' => (isset($c['mode']) && $c['mode'] === 'fixed') ? 'fixed' : 'percent',
                'value' => max(0, floatval($c['value'] ?? 0)),
                'enabled' => !empty($c['enabled']),
                'expiry' => max(0, intval($c['expiry'] ?? 0)),
                'limitTotal' => max(0, intval($c['limitTotal'] ?? 0)),
                'limitPerUser' => max(0, intval($c['limitPerUser'] ?? 1)),
                'newUserOnly' => !empty($c['newUserOnly']),
                'reported' => !empty($c['reported']),
            ];
        }
        $scope = topup_disc_scope_of_key($gatewayKey);
        if ($scope !== null) {
            $m = topup_disc_group_map();
            $m[$lang][$scope]['codes'] = $clean;
            topup_disc_group_save($m);
            return;
        }
        $m = topup_disc_map();
        $m[$lang][$gatewayKey]['codes'] = $clean;
        topup_disc_save($m);
    }
}
if (!function_exists('topup_disc_code_get')) {
    function topup_disc_code_get($lang, $gatewayKey, $index)
    {
        $codes = topup_disc_codes_for($lang, $gatewayKey);
        return $codes[$index] ?? [];
    }
}
if (!function_exists('topup_disc_code_add')) {
    function topup_disc_code_add($lang, $gatewayKey, $code)
    {
        $codes = topup_disc_codes_for($lang, $gatewayKey);
        $codes[] = ['code' => $code, 'mode' => 'percent', 'value' => 0, 'enabled' => true, 'expiry' => 0, 'limitTotal' => 0, 'limitPerUser' => 1];
        topup_disc_codes_set($lang, $gatewayKey, $codes);
        return count($codes) - 1;
    }
}
if (!function_exists('topup_disc_code_update')) {
    // null = leave that field alone, matching topup_packages_set_style()'s
    // established sentinel convention
    function topup_disc_code_update($lang, $gatewayKey, $index, array $fields)
    {
        $codes = topup_disc_codes_for($lang, $gatewayKey);
        if (!isset($codes[$index])) {
            return;
        }
        foreach ($fields as $k => $v) {
            if ($v !== null) {
                $codes[$index][$k] = $v;
            }
        }
        topup_disc_codes_set($lang, $gatewayKey, $codes);
    }
}
if (!function_exists('topup_disc_code_remove')) {
    function topup_disc_code_remove($lang, $gatewayKey, $index)
    {
        $codes = topup_disc_codes_for($lang, $gatewayKey);
        unset($codes[$index]);
        topup_disc_codes_set($lang, $gatewayKey, array_values($codes));
    }
}
if (!function_exists('topup_disc_code_used_count')) {
    function topup_disc_code_used_count($code, $lang = null, $gatewayKey = null)
    {
        global $pdo;
        $sql = "SELECT COUNT(*) FROM topup_discount_use WHERE code = :code";
        $params = [':code' => $code];
        if ($lang !== null) {
            $sql .= " AND lang = :lang";
            $params[':lang'] = $lang;
        }
        if ($gatewayKey !== null) {
            $sql .= " AND gateway = :gw";
            $params[':gw'] = $gatewayKey;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return intval($st->fetchColumn());
    }
}
if (!function_exists('topup_disc_code_user_count')) {
    function topup_disc_code_user_count($code, $userId)
    {
        global $pdo;
        $st = $pdo->prepare("SELECT COUNT(*) FROM topup_discount_use WHERE code = :code AND id_user = :u");
        $st->execute([':code' => $code, ':u' => $userId]);
        return intval($st->fetchColumn());
    }
}
if (!function_exists('topup_disc_code_unique_users')) {
    function topup_disc_code_unique_users($code)
    {
        global $pdo;
        $st = $pdo->prepare("SELECT COUNT(DISTINCT id_user) FROM topup_discount_use WHERE code = :code");
        $st->execute([':code' => $code]);
        return intval($st->fetchColumn());
    }
}
if (!function_exists('topup_disc_code_status')) {
    // 'active' | 'disabled' | 'expired' | 'exhausted' - the single place that
    // decides whether a code can still be used, so the admin list, the
    // activation check and the payment-time check can never disagree
    function topup_disc_code_status(array $c)
    {
        if (empty($c['enabled'])) {
            return 'disabled';
        }
        if (intval($c['expiry'] ?? 0) > 0 && time() >= intval($c['expiry'])) {
            return 'expired';
        }
        $limit = intval($c['limitTotal'] ?? 0);
        if ($limit > 0 && topup_disc_code_used_count($c['code']) >= $limit) {
            return 'exhausted';
        }
        return 'active';
    }
}
if (!function_exists('topup_disc_find_code')) {
    // resolves a typed code across every gateway/language it is defined for -
    // returns [lang, gatewayKey, index, code] or null
    function topup_disc_find_code($typed)
    {
        $typed = trim((string) $typed);
        if ($typed === '') {
            return null;
        }
        foreach (topup_disc_map() as $lang => $byGw) {
            foreach ((array) $byGw as $gw => $bucket) {
                foreach ((array) ($bucket['codes'] ?? []) as $i => $c) {
                    if (strcasecmp(trim((string) ($c['code'] ?? '')), $typed) === 0) {
                        return ['lang' => $lang, 'gateway' => $gw, 'index' => $i, 'code' => $c];
                    }
                }
            }
        }
        // codes attached to a category, or to every gateway at once. 'gateway'
        // carries the '@'-prefixed scope key so the rest of the flow - which
        // only ever stores and compares that field - needs no special case.
        foreach (topup_disc_group_map() as $lang => $byScope) {
            foreach ((array) $byScope as $scope => $row) {
                foreach ((array) ($row['codes'] ?? []) as $i => $c) {
                    if (strcasecmp(trim((string) ($c['code'] ?? '')), $typed) === 0) {
                        return [
                            'lang' => $lang,
                            'gateway' => topup_disc_scope_key($scope),
                            'scope' => $scope,
                            'index' => $i,
                            'code' => $c,
                        ];
                    }
                }
            }
        }
        return null;
    }
    // Does an activated code apply on this gateway? A per-gateway code only on
    // its own; a scoped one on every gateway the scope covers.
    function topup_disc_code_covers($activeGateway, $gatewayKey)
    {
        $scope = topup_disc_scope_of_key($activeGateway);
        if ($scope === null) {
            return (string) $activeGateway === (string) $gatewayKey;
        }
        if ($scope === 'all') {
            return true;
        }
        return in_array($gatewayKey, gateway_disc_groups()[$scope] ?? [], true);
    }
}
if (!function_exists('topup_disc_user_active')) {
    function topup_disc_user_active($userId)
    {
        global $pdo;
        $st = $pdo->prepare("SELECT * FROM topup_discount_user WHERE id_user = :u LIMIT 1");
        $st->execute([':u' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
if (!function_exists('topup_disc_user_activate')) {
    // one active code per user - activating another replaces it rather than
    // stacking, so "which code applies" is never ambiguous
    function topup_disc_user_activate($userId, $code, $lang, $gatewayKey)
    {
        global $pdo;
        $pdo->prepare("DELETE FROM topup_discount_user WHERE id_user = :u")->execute([':u' => $userId]);
        $st = $pdo->prepare("INSERT INTO topup_discount_user (id_user, code, lang, gateway, activated_at) VALUES (:u, :c, :l, :g, :t)");
        $st->execute([':u' => $userId, ':c' => $code, ':l' => $lang, ':g' => $gatewayKey, ':t' => (string) time()]);
    }
}
if (!function_exists('topup_disc_user_clear')) {
    function topup_disc_user_clear($userId)
    {
        global $pdo;
        $pdo->prepare("DELETE FROM topup_discount_user WHERE id_user = :u")->execute([':u' => $userId]);
    }
}
if (!function_exists('topup_disc_log_use')) {
    function topup_disc_log_use($userId, $code, $lang, $gatewayKey, $paid, $bonus)
    {
        global $pdo;
        $st = $pdo->prepare("INSERT INTO topup_discount_use (id_user, code, lang, gateway, paid, bonus, used_at) VALUES (:u, :c, :l, :g, :p, :b, :t)");
        $st->execute([':u' => $userId, ':c' => $code, ':l' => $lang, ':g' => $gatewayKey, ':p' => (string) $paid, ':b' => (string) $bonus, ':t' => (string) time()]);
    }
}
if (!function_exists('topup_disc_bonus_of')) {
    // the bonus a given discount definition would add on top of $amount.
    // Both modes ADD credit (never reduce what the user pays) - percent adds a
    // share of the amount, fixed adds a flat number.
    function topup_disc_bonus_of(array $disc, $amount)
    {
        $amount = floatval($amount);
        if ($amount <= 0) {
            return 0;
        }
        $value = floatval($disc['value'] ?? 0);
        if ($value <= 0) {
            return 0;
        }
        if (($disc['mode'] ?? 'percent') === 'fixed') {
            return round($value);
        }
        // The 100% ceiling is enforced where a value is TYPED, against the mode
        // selected at that moment - so switching the mode afterwards used to
        // carry the old number over unchecked: a legal fixed 50000 became a
        // 50000% discount, crediting 50,000,000 on a 100,000 payment. Clamping
        // here makes every read safe, including rows already stored wrong.
        if ($value > 100) {
            $value = 100;
        }
        return round(($amount * $value) / 100);
    }
}
if (!function_exists('topup_disc_effective')) {
    // resolves which discount actually applies for this user+gateway+amount.
    // Both an activated code and a codeless auto-discount may qualify; the one
    // giving the LARGER bonus wins (never stacked, never silently worse).
    // Returns ['source'=>'code'|'auto', 'bonus'=>int, 'disc'=>[...], 'code'=>string]
    // or null when nothing applies.
    function topup_disc_effective($userId, $lang, $gatewayKey, $amount)
    {
        $candidates = [];
        $adminBypass = topup_disc_admin_restrictions_bypassed($userId);
        $auto = topup_disc_auto_for($lang, $gatewayKey);
        $autoLive = !empty($auto['enabled'])
            && (intval($auto['expiry'] ?? 0) === 0 || time() < intval($auto['expiry']));
        if ($autoLive && topup_disc_auto_eligible($userId, $lang, $gatewayKey, $auto)) {
            $b = topup_disc_bonus_of($auto, $amount);
            if ($b > 0) {
                $candidates[] = ['source' => 'auto', 'bonus' => $b, 'disc' => $auto, 'code' => ''];
            }
        }
        // the discount set on this gateway's whole CATEGORY, and the one set on
        // every gateway at once. Both are candidates, not precedence tiers: a
        // gateway that has its own auto discount simply competes with them and
        // the better offer wins, exactly like a code competing with an auto
        // discount. Nothing stacks.
        foreach ([gateway_disc_group_of($gatewayKey), 'all'] as $discGroup) {
            if ($discGroup === null) {
                continue;
            }
            $gAuto = topup_disc_group_live($lang, $discGroup);
            if ($gAuto !== null && topup_disc_group_eligible($userId, $lang, $discGroup, $gAuto)) {
                $b = topup_disc_bonus_of($gAuto, $amount);
                if ($b > 0) {
                    // the scope it came from travels with it, so the caption can
                    // say "درگاه‌های آنلاین" instead of a bare percentage
                    $gAuto['group'] = $discGroup;
                    $candidates[] = ['source' => 'group', 'bonus' => $b, 'disc' => $gAuto, 'code' => ''];
                }
            }
        }
        $active = topup_disc_user_active($userId);
        if ($active && topup_disc_code_covers($active['gateway'] ?? '', $gatewayKey) && (string) $active['lang'] === (string) $lang) {
            $found = topup_disc_find_code($active['code']);
            if ($found !== null && topup_disc_code_status($found['code']) === 'active') {
                $perUser = intval($found['code']['limitPerUser'] ?? 1);
                $codeOk = $adminBypass || (($perUser === 0 || topup_disc_code_user_count($found['code']['code'], $userId) < $perUser)
                    && (empty($found['code']['newUserOnly']) || !topup_disc_user_has_any_paid_topup($userId)));
                if ($codeOk) {
                    $b = topup_disc_bonus_of($found['code'], $amount);
                    if ($b > 0) {
                        $candidates[] = ['source' => 'code', 'bonus' => $b, 'disc' => $found['code'], 'code' => $found['code']['code']];
                    }
                }
            }
        }
        if (empty($candidates)) {
            return null;
        }
        usort($candidates, fn($x, $y) => $y['bonus'] <=> $x['bonus']);
        return $candidates[0];
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
    function volumepct_tier_set_style($index, $style = null, $emoji = null, $emojiIcon = null, $pos = null, $simple = null, $hidden = null)
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
        if ($hidden !== null) {
            if ($hidden) {
                $tiers[$index]['hidden'] = true;
            } else {
                unset($tiers[$index]['hidden']);
            }
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
if (!function_exists('volumepct_tier_is_custom')) {
    // True when a tier carries anything volumepct_tier_reset_style() would clear.
    // Deliberately kept field-for-field in step with that function: the 🔋 row in
    // the message manager takes its green state from here, so if the two ever
    // disagreed, a reset would visibly "do nothing" - which is exactly what
    // happened when the row went green merely because a threshold existed.
    // A threshold on its own is structure, not message customization, and a
    // reset does not (and should not) remove it.
    function volumepct_tier_is_custom($tier, $lang)
    {
        if (!is_array($tier)) {
            return false;
        }
        foreach (['text', 'btnLabel', 'sticker'] as $perLang) {
            if (!empty($tier[$perLang][$lang])) {
                return true;
            }
        }
        foreach (['style', 'emoji', 'emojiIcon', 'pos', 'simple'] as $global) {
            if (!empty($tier[$global])) {
                return true;
            }
        }
        return false;
    }
}
if (!function_exists('volumepct_has_custom')) {
    // does any warning tier - of any kind - carry a customization for $lang?
    function volumepct_has_custom($lang)
    {
        foreach (volumepct_tiers_map() as $tier) {
            if (volumepct_tier_is_custom($tier, $lang)) {
                return true;
            }
        }
        return false;
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
        unset($tiers[$index]['style'], $tiers[$index]['emoji'], $tiers[$index]['emojiIcon'], $tiers[$index]['pos'], $tiers[$index]['simple'], $tiers[$index]['hidden']);
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
    // null when the admin hid the button; $ignoreHidden is for the admin preview
    function volumepct_tier_kb($index, $lang, $textbotlang, $invoiceId, $ignoreHidden = false)
    {
        $tier = volumepct_tier_get($index);
        if (!$ignoreHidden && !empty($tier['hidden'])) {
            return null;
        }
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
        $previewKb = json_decode(volumepct_tier_kb($index, $lang, $textbotlang, 0, true), true);
        $previewBtn = $previewKb['inline_keyboard'][0][0];
        $previewBtn['callback_data'] = 'none';
        $isHidden = !empty($tier['hidden']);
        if ($isHidden) {
            $previewBtn['text'] = '🚫 ' . $previewBtn['text'];
        }

        $kind = volumepct_tier_kind($tier);
        $meta = volumepct_kind_meta($kind);
        $currentCaptionRaw = $tier['text'][$lang] ?? volumepct_tier_default_text($textbotlang, $kind);
        $title = $meta['single'] ? $meta['title'] : sprintf($meta['title'], $pct);
        $info = "{$meta['icon']} <b>{$title}</b>\n➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "✏️ کپشن: " . ($hasCustomText ? "سفارشی ✅" : "پیش‌فرض") . "\n";
        $info .= "<blockquote>" . htmlspecialchars($currentCaptionRaw, ENT_QUOTES) . "</blockquote>\n";
        $info .= "🖼 استیکر: " . ($hasSticker ? "ست شده ✅" : "ندارد ❌") . "\n";
        if ($isHidden) {
            $info .= "🚫 دکمه مخفیه - این پیام بدون دکمه فرستاده می‌شه.\n";
        }
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
        $kb['inline_keyboard'][] = [[
            'text' => $isHidden ? '👁 نمایش دادن دکمه' : '🚫 مخفی کردن دکمه',
            'callback_data' => "volpct|hide|{$lang}|{$index}",
            'style' => $isHidden ? 'success' : 'danger',
        ]];
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
    function config_col_order_payload($textbotlang, $originLang = null, $originKey = null, $backCallback = null, $captionKey = 'users.status.getConfigHint', $kind = 'usertest')
    {
        $lang = $originLang ?? 'fa';
        $setting = select("setting", "*", null, null, "select");
        $colOrderField = ($kind === 'buy') ? 'configColOrderBuy' : 'configColOrder';
        $nameFirst = (($setting[$colOrderField] ?? '') === 'name_first');
        $get = configdisplay_element_current($lang, 0, $textbotlang, $kind);
        $hConfig = configdisplay_element_current($lang, 1, $textbotlang, $kind);
        $hName = configdisplay_element_current($lang, 2, $textbotlang, $kind);
        // the buy hub uses its own parallel callback tokens (cfgcolel*buy*-)
        // so it never shares state or routing with the usertest hub below
        $elPrefix = ($kind === 'buy') ? 'cfgcolelbuy-' : 'cfgcolel-';
        $headerConfig = ['text' => $hConfig['text'], 'callback_data' => "{$elPrefix}1-{$lang}"];
        if ($hConfig['style'] !== '') {
            $headerConfig['style'] = $hConfig['style'];
        }
        $headerName = ['text' => $hName['text'], 'callback_data' => "{$elPrefix}2-{$lang}"];
        if ($hName['style'] !== '') {
            $headerName['style'] = $hName['style'];
        }
        $sampleGet = ['text' => $get['text'], 'callback_data' => "{$elPrefix}0-{$lang}"];
        if ($get['style'] !== '') {
            $sampleGet['style'] = $get['style'];
        }
        $sampleName = ['text' => '🇩🇪 Germany #1', 'callback_data' => 'none'];
        $getAll = configdisplay_element_current($lang, 3, $textbotlang, $kind);
        $getAllBtn = ['text' => $getAll['text'], 'callback_data' => "{$elPrefix}3-{$lang}"];
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
        if ($kind === 'buy') {
            $cbConfigFirst = "cfgcolbtbuy-getfirst-{$lang}";
            $cbNameFirst = "cfgcolbtbuy-namefirst-{$lang}";
        } else {
            $cbConfigFirst = ($originLang !== null) ? "cfgcolbt-getfirst-{$originLang}" : 'configcolorder-config_first';
            $cbNameFirst = ($originLang !== null) ? "cfgcolbt-namefirst-{$originLang}" : 'configcolorder-name_first';
        }
        $hubTitle = ($kind === 'buy') ? '🗂 تنظیم نمایش و کپشن کانفیگ (خرید سرویس)' : '🗂 تنظیم نمایش و کپشن کانفیگ';
        $info = "🗂 <b>{$hubTitle}</b>\n➖➖➖➖➖➖➖➖➖➖\n";
        if ($kind === 'buy') {
            $info .= "این بخش، نمایشِ صفحه‌ی کانفیگِ سرویس‌های خریداری‌شده رو کنترل می‌کنه (جدا از اکانت تست): ترتیب ستون‌ها، و متن/رنگ هرکدوم از دکمه‌ها.\n";
        } else {
            $info .= "این بخش، نمایشِ صفحه‌ی کانفیگ‌های هر سرویس رو کنترل می‌کنه: ترتیب ستون‌ها، و متن/رنگ هرکدوم از دکمه‌ها.\n";
        }
        $info .= "حالت فعلی ترتیب: <b>" . ($nameFirst ? "اول نام کانفیگ، بعد دکمه‌ی دریافت" : "اول دکمه‌ی دریافت، بعد نام کانفیگ (پیش‌فرض)") . "</b>\n";
        $info .= "➖➖➖➖➖➖➖➖➖➖\n🔹 راهنما:\n• روی متنِ هرکدوم از دکمه‌های پیش‌نمایش بزن → صفحه‌ی ویرایش همون دکمه باز می‌شه\n• اونجا هم می‌تونی متنش رو عوض کنی (✏️ ویرایش متن)، هم رنگش رو (🔵 آبی / 🟢 سبز / 🔴 قرمز)\n• برای برگردوندن یه دکمه به حالت پیش‌فرض، همونجا 🔁 ریست رو بزن\n";
        $info .= "➖➖➖➖➖➖➖➖➖➖\n👁 پیش‌نمایش زنده 👇";
        $kb = ['inline_keyboard' => []];
        $kb['inline_keyboard'][] = $nameFirst ? [$headerName, $headerConfig] : [$headerConfig, $headerName];
        $kb['inline_keyboard'][] = $nameFirst ? [$sampleName, $sampleGet] : [$sampleGet, $sampleName];
        $kb['inline_keyboard'][] = [$getAllBtn];
        $kb['inline_keyboard'][] = [['text' => bt_section_meta('cfgcol_actions')['label'], 'callback_data' => 'bt_sep|cfgcol_actions']];
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
            if ($captionKey !== null) {
                $kb['inline_keyboard'][] = [['text' => '✏️ ویرایش کپشن این بخش', 'callback_data' => "bt_edit|{$originLang}|{$captionKey}", 'style' => 'primary']];
            }
            $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => $backCallback ?? "bt_edit|{$originLang}|{$originKey}", 'style' => 'danger']];
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
if (!function_exists('configdisplay_element_store_key')) {
    // 'usertest' keeps the original 'configDisplay' key (no migration needed
    // for existing overrides); 'buy' gets its own sibling key so the my-
    // services "دریافت کانفیگ" screen can be customized independently of
    // the test-account one, per the admin's explicit request that the two
    // stop sharing settings.
    function configdisplay_element_store_key($kind)
    {
        return $kind === 'buy' ? 'configDisplayBuy' : 'configDisplay';
    }
}
if (!function_exists('configdisplay_element_override')) {
    function configdisplay_element_override($lang, $idx, $kind = 'usertest')
    {
        $storeKey = configdisplay_element_store_key($kind);
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        return (is_array($be) && isset($be[$lang][$storeKey][$idx]) && is_array($be[$lang][$storeKey][$idx]))
            ? $be[$lang][$storeKey][$idx]
            : [];
    }
}
if (!function_exists('configdisplay_element_current')) {
    function configdisplay_element_current($lang, $idx, $textbotlang, $kind = 'usertest')
    {
        $defs = configdisplay_element_defs($textbotlang);
        $d = $defs[$idx] ?? $defs[0];
        $ov = configdisplay_element_override($lang, $idx, $kind);
        $text = (isset($ov['text']) && $ov['text'] !== '') ? $ov['text'] : $d['text'];
        $style = (isset($ov['style']) && in_array($ov['style'], ['primary', 'success', 'danger'], true)) ? $ov['style'] : '';
        return ['text' => $text, 'style' => $style, 'name' => $d['name']];
    }
}
if (!function_exists('configdisplay_element_set_text')) {
    function configdisplay_element_set_text($lang, $idx, $text, $kind = 'usertest')
    {
        $storeKey = configdisplay_element_store_key($kind);
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        if (!is_array($be)) {
            $be = [];
        }
        $be[$lang][$storeKey][$idx]['text'] = $text;
        update("setting", "button_edit", json_encode($be, JSON_UNESCAPED_UNICODE), null, null);
    }
}
if (!function_exists('configdisplay_element_set_style')) {
    function configdisplay_element_set_style($lang, $idx, $style, $kind = 'usertest')
    {
        $storeKey = configdisplay_element_store_key($kind);
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        if (!is_array($be)) {
            $be = [];
        }
        $be[$lang][$storeKey][$idx]['style'] = $style;
        update("setting", "button_edit", json_encode($be, JSON_UNESCAPED_UNICODE), null, null);
    }
}
if (!function_exists('configdisplay_element_reset')) {
    function configdisplay_element_reset($lang, $idx, $kind = 'usertest')
    {
        $storeKey = configdisplay_element_store_key($kind);
        $setting = select("setting", "*", null, null, "select");
        $be = json_decode((string) ($setting['button_edit'] ?? ''), true);
        if (is_array($be) && isset($be[$lang][$storeKey][$idx])) {
            unset($be[$lang][$storeKey][$idx]);
            if (empty($be[$lang][$storeKey])) {
                unset($be[$lang][$storeKey]);
            }
            if (empty($be[$lang])) {
                unset($be[$lang]);
            }
            update("setting", "button_edit", empty($be) ? null : json_encode($be, JSON_UNESCAPED_UNICODE), null, null);
        }
    }
}
if (!function_exists('configdisplay_element_payload')) {
    function configdisplay_element_payload($lang, $idx, $textbotlang, $kind = 'usertest')
    {
        $cur = configdisplay_element_current($lang, $idx, $textbotlang, $kind);
        $info = "🔘 <b>{$cur['name']}</b>\n➖➖➖➖➖➖➖➖➖➖\n👁 پیش‌نمایش زنده 👇";
        $previewBtn = ['text' => $cur['text'], 'callback_data' => 'none'];
        if ($cur['style'] !== '') {
            $previewBtn['style'] = $cur['style'];
        }
        $kb = ['inline_keyboard' => []];
        $kb['inline_keyboard'][] = [$previewBtn];
        if ($kind === 'buy') {
            $kb['inline_keyboard'][] = [['text' => '✏️ ویرایش متن', 'callback_data' => "cfgcoltextbuy-{$idx}-{$lang}"]];
            $kb['inline_keyboard'][] = [
                ['text' => ($cur['style'] === 'primary' ? '✅ ' : '') . '🔵 آبی', 'callback_data' => "cfgcolelstylebuy-{$idx}-primary-{$lang}", 'style' => 'primary'],
                ['text' => ($cur['style'] === 'success' ? '✅ ' : '') . '🟢 سبز', 'callback_data' => "cfgcolelstylebuy-{$idx}-success-{$lang}", 'style' => 'success'],
                ['text' => ($cur['style'] === 'danger' ? '✅ ' : '') . '🔴 قرمز', 'callback_data' => "cfgcolelstylebuy-{$idx}-danger-{$lang}", 'style' => 'danger'],
            ];
            $kb['inline_keyboard'][] = [['text' => '🔁 ریست این المان', 'callback_data' => "cfgcolelrstbuy-{$idx}-{$lang}", 'style' => 'danger']];
            $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => "btact|cfgcolbuy|{$lang}|users.status.getConfigHintBuy"]];
        } else {
            $kb['inline_keyboard'][] = [['text' => '✏️ ویرایش متن', 'callback_data' => "cfgcoltext-{$idx}-{$lang}"]];
            $kb['inline_keyboard'][] = [
                ['text' => ($cur['style'] === 'primary' ? '✅ ' : '') . '🔵 آبی', 'callback_data' => "cfgcolelstyle-{$idx}-primary-{$lang}", 'style' => 'primary'],
                ['text' => ($cur['style'] === 'success' ? '✅ ' : '') . '🟢 سبز', 'callback_data' => "cfgcolelstyle-{$idx}-success-{$lang}", 'style' => 'success'],
                ['text' => ($cur['style'] === 'danger' ? '✅ ' : '') . '🔴 قرمز', 'callback_data' => "cfgcolelstyle-{$idx}-danger-{$lang}", 'style' => 'danger'],
            ];
            $kb['inline_keyboard'][] = [['text' => '🔁 ریست این المان', 'callback_data' => "cfgcolelrst-{$idx}-{$lang}", 'style' => 'danger']];
            $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => "btact|cfgcol|{$lang}|users.usertest.selectUsernamePrompt"]];
        }
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
        $dbOn = ($setting['backup_db_enabled'] ?? '1') !== '0';
        $botOn = ($setting['backup_bot_enabled'] ?? '1') !== '0';
        $backupInterval = $setting['backup_interval_hours'] ?? '5';
        $caption = strtr($t['hubCaption'], [
            '{dbStatus}' => $dbSet ? $t['setStatus'] : $t['notSetStatus'],
            '{botStatus}' => $botSet ? $t['setStatus'] : $t['notSetStatus'],
        ]);
        $kb = ['inline_keyboard' => [
            [['text' => ($dbOn ? '✅ ' : '❌ ') . $t['dbEnabledBtn'], 'callback_data' => 'backupset_toggle_db', 'style' => ($dbOn ? 'success' : 'danger')]],
            [['text' => ($dbSet ? '🔐 ' : '🔓 ') . $t['dbPasswordBtn'], 'callback_data' => 'backupset_db', 'style' => ($dbSet ? 'success' : 'primary')]],
            [['text' => ($botOn ? '✅ ' : '❌ ') . $t['botEnabledBtn'], 'callback_data' => 'backupset_toggle_bot', 'style' => ($botOn ? 'success' : 'danger')]],
            [['text' => ($botSet ? '🔐 ' : '🔓 ') . $t['botPasswordBtn'], 'callback_data' => 'backupset_bot', 'style' => ($botSet ? 'success' : 'primary')]],
            [['text' => strtr($t['intervalBtn'], ['{hours}' => $backupInterval]), 'callback_data' => 'backupset_interval', 'style' => 'primary']],
            [['text' => $t['closeBtn'], 'callback_data' => 'backupset_close', 'style' => 'danger']],
        ]];
        return [$caption, json_encode($kb)];
    }
}
if (!function_exists('backup_run_now')) {
    // manual, on-demand counterpart to cronbot/backupbot.php - triggered by
    // the /backup admin command. Respects each backup type's own on/off
    // toggle and password *unless both toggles are off*, in which case it
    // still sends both files (as an escape hatch so a manual backup is
    // always obtainable), but forces them unencrypted since an admin who
    // turned the feature off never set an intentional password expectation
    // for this manual run.
    function backup_run_now($fromAdminId, $textbotlang)
    {
        global $dbhost, $usernamedb, $passworddb, $dbname;
        $setting = select("setting", "*", null, null, "select");
        $reportbackup = select("topicid", "idreport", "report", "backupfile", "select")['idreport'];
        $dbEnabled = ($setting['backup_db_enabled'] ?? '1') !== '0';
        $botEnabled = ($setting['backup_bot_enabled'] ?? '1') !== '0';
        $forceNoPassword = false;
        if (!$dbEnabled && !$botEnabled) {
            $dbEnabled = true;
            $botEnabled = true;
            $forceNoPassword = true;
        }

        $backupDir = __DIR__ . '/cronbot';
        $sentAny = false;
        $stamp = date("Y-m-d_His");

        if ($dbEnabled) {
            $backupFileName = $backupDir . '/backup_manual_' . $stamp . '.sql';
            $zipFileName = $backupDir . '/backup_manual_' . $stamp . '.zip';
            $dbhostForCommand = empty($dbhost) ? "localhost" : $dbhost;
            $command = "mysqldump -h $dbhostForCommand -u $usernamedb -p'$passworddb' --no-tablespaces --ssl-mode=DISABLED $dbname > " . escapeshellarg($backupFileName);
            $output = [];
            $returnVar = 0;
            exec($command, $output, $returnVar);
            if ($returnVar !== 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $reportbackup,
                    'text' => $textbotlang['keyboard']['backupError'],
                ]);
            } else {
                $dbBackupPassword = $forceNoPassword ? '' : ($setting['backup_db_password'] ?? '');
                if ($dbBackupPassword !== '') {
                    shell_exec("zip -j -P " . escapeshellarg($dbBackupPassword) . " " . escapeshellarg($zipFileName) . " " . escapeshellarg($backupFileName));
                } else {
                    shell_exec("zip -j " . escapeshellarg($zipFileName) . " " . escapeshellarg($backupFileName));
                }
                $fileToSend = is_file($zipFileName) ? $zipFileName : $backupFileName;
                telegram('sendDocument', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $reportbackup,
                    'document' => new CURLFile($fileToSend),
                    'caption' => $textbotlang['hardcoded']['backupDatabaseCaption'],
                ]);
                $sentAny = true;
                if (is_file($zipFileName)) {
                    unlink($zipFileName);
                }
                if (is_file($backupFileName)) {
                    unlink($backupFileName);
                }
            }
        }

        if ($botEnabled) {
            $botFolderZipName = $backupDir . '/botfolder_manual_' . $stamp . '.zip';
            $botRootDir = __DIR__;
            $botBackupPassword = $forceNoPassword ? '' : ($setting['backup_bot_password'] ?? '');
            $zipExcludes = "-x '.git/*' -x '*.bak_*'";
            if ($botBackupPassword !== '') {
                $botZipCommand = "cd " . escapeshellarg($botRootDir) . " && zip -r -P " . escapeshellarg($botBackupPassword) . " " . escapeshellarg($botFolderZipName) . " . " . $zipExcludes;
            } else {
                $botZipCommand = "cd " . escapeshellarg($botRootDir) . " && zip -r " . escapeshellarg($botFolderZipName) . " . " . $zipExcludes;
            }
            shell_exec($botZipCommand);
            if (is_file($botFolderZipName)) {
                telegram('sendDocument', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $reportbackup,
                    'document' => new CURLFile($botFolderZipName),
                    'caption' => $textbotlang['hardcoded']['botFolderBackupCaption'],
                ]);
                $sentAny = true;
                unlink($botFolderZipName);
            } else {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $reportbackup,
                    'text' => $textbotlang['keyboard']['backupError'],
                ]);
            }
        }

        if ($sentAny) {
            telegram('sendmessage', [
                'chat_id' => $fromAdminId,
                'text' => $textbotlang['Admin']['BackupSettings']['manualBackupDone'],
            ]);
        }
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
            $help_send_methods = [
                'video' => ['sendvideo', 'video'],
                'document' => ['sendDocument', 'document'],
                'photo' => ['sendphoto', 'photo'],
            ];
            if (isset($help_send_methods[$resolved['media_type']])) {
                list($help_send_method, $help_send_field) = $help_send_methods[$resolved['media_type']];
                $send[$help_send_field] = $resolved['media'];
                $help_send_res = telegram($help_send_method, $send);
                if (is_array($help_send_res) && !empty($help_send_res['ok'])) {
                    return $help_send_res;
                }
                // A file_id only works for the bot that received the file.
                // A database restored into a different bot, or a row that came
                // with the project, is rejected with "wrong file identifier".
                // The caller has already deleted the menu message by this
                // point, so returning the failure left the customer looking at
                // an empty chat with no way back. Fall through to the text, so
                // a tutorial is never a dead end.
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
if (!function_exists('help_section_on')) {
    // One switch for the whole education section. Off means the customer is
    // never offered it: the main menu button goes, and the handler turns them
    // away. Nothing is deleted - every tutorial, category and display setting
    // stays exactly as it was, ready for the moment it is switched back on.
    function help_section_on()
    {
        $setting = select("setting", "*", null, null, "select");
        return (string) ($setting['help_status'] ?? 'onhelp') !== 'offhelp';
    }
    function help_section_set($on)
    {
        update("setting", "help_status", $on ? 'onhelp' : 'offhelp', null, null);
    }
    // every customer-facing tutorial screen reads the list through here, so one
    // screen can never disagree with another about what a customer can see
    function help_rows_for_user($category = null)
    {
        $rows = ($category === null)
            ? select("help", "*", null, null, "fetchAll")
            : select("help", "*", "category", $category, "fetchAll");
        return is_array($rows) ? $rows : [];
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
if (!function_exists('admin_perm_payload')) {
    // 🛡 دسترسی ادمین: what an admin's own test accounts and purchases are held
    // to. Both switches are bot-wide, not per language - $lang only threads the
    // customization screen's back button.
    function admin_perm_payload($lang)
    {
        $setting = select("setting", "*", null, null, "select");
        $testFree = (string) ($setting['admin_test_unlimited'] ?? '1') !== '0';
        $buyFree = (string) ($setting['admin_buy_free'] ?? '0') === '1';
        $info = "🛡 <b>دسترسی ادمین</b>\n➖➖➖➖➖➖➖➖➖➖\n";
        $info .= "این تنظیمات فقط روی حساب ادمین‌ها اثر داره، نه کاربرها.\n\n";
        $info .= "🔑 <b>اکانت تست بدون محدودیت:</b> " . ($testFree ? "روشن ✅" : "خاموش ❌") . "\n";
        $info .= ($testFree ? "ادمین هر چندتا بخواد اکانت تست می‌گیره." : "ادمین هم مثل کاربرها محدودیت تعداد اکانت تست داره.") . "\n\n";
        $info .= "🛍 <b>خرید رایگان:</b> " . ($buyFree ? "روشن ✅" : "خاموش ❌") . "\n";
        $info .= $buyFree
            ? "خرید سرویس، خرید چندتایی، تمدید و حجم/زمان اضافه برای ادمین رایگانه و از موجودیش چیزی کم نمی‌شه."
            : "ادمین هم مثل کاربرها هزینه‌ی خرید رو از موجودیش پرداخت می‌کنه.";
        $kb = ['inline_keyboard' => []];
        $kb['inline_keyboard'][] = [['text' => '🔑 اکانت تست بدون محدودیت: ' . ($testFree ? 'روشن ✅' : 'خاموش ❌'), 'callback_data' => "admperm|test|{$lang}", 'style' => $testFree ? 'success' : 'danger']];
        $kb['inline_keyboard'][] = [['text' => '🛍 خرید رایگان: ' . ($buyFree ? 'روشن ✅' : 'خاموش ❌'), 'callback_data' => "admperm|buy|{$lang}", 'style' => $buyFree ? 'success' : 'danger']];
        $kb['inline_keyboard'][] = [['text' => '🔙 برگشت به لیست', 'callback_data' => "btact|back|{$lang}", 'style' => 'danger']];
        $kb['inline_keyboard'][] = [['text' => '❌ بستن', 'callback_data' => 'bt_close', 'style' => 'danger']];
        return [$info, json_encode($kb)];
    }
}
if (!function_exists('bt_button')) {
    // A few registry items are not messages but BUTTON LABELS - the two back
    // buttons of the purchase flow and the ❌ بستن of each section. They are
    // genbtn items now (name, colour, emoji, position, reset - the exact tool
    // set su/cf/sc/... already have), not captions with a "کپشن" to edit, so
    // this renders them the same way genbtn_render() does: $label is only the
    // FACTORY DEFAULT text, an override in button_edit[lang][key][0] (name,
    // colour, emoji, ...) wins whenever one exists.
    function bt_button($lang, $key, $label, $callback, $default = 'danger')
    {
        if (!function_exists('genbtn_render') || !function_exists('genbtn_override')) {
            return ['text' => $label, 'callback_data' => $callback, 'style' => $default];
        }
        $ov = genbtn_override($lang, $key, 0);
        return genbtn_render(['text' => $label, 'style' => $default], $ov, $callback);
    }
    // "should this button be on the screen at all?" - kept separate from
    // bt_button() so every call site stays explicit about DROPPING THE WHOLE
    // ROW rather than rendering an empty/broken button; a row with a null in
    // it is not something Telegram accepts.
    function bt_button_hidden($lang, $key)
    {
        if (!function_exists('genbtn_override')) {
            return false;
        }
        $ov = genbtn_override($lang, $key, 0);
        return !empty($ov['hidden']);
    }
    // the registry items that are button labels rather than messages
    function bt_btnitem_keys()
    {
        return [
            'users.sell.backToPreviousBtn',
            'users.sell.backToPanelListBtn',
            'bottext.btnCloseBuy',
            'bottext.btnCloseTopup',
            'bottext.btnCloseAccount',
            'bottext.btnCloseTest',
            'bottext.btnCloseHelp',
            // the four buttons of the features 🌐 وضعیت قابلیت‌ها (هر زبان)
            // switches on and off. sendPhoneNumber and acceptRules can land on
            // a REPLY keyboard, where Telegram has no colour or callback - only
            // their label override applies there (bt_reply_label()).
            'keyboard.sendPhoneNumber',
            'keyboard.acceptRules',
            'keyboard.receiveMembershipGift',
            'keyboard.shareLink',
        ];
    }
    // label-only override, for buttons that may render on a reply keyboard
    function bt_reply_label($lang, $key, $default)
    {
        if (!function_exists('genbtn_override')) {
            return $default;
        }
        $ov = genbtn_override($lang, $key, 0);
        $txt = trim((string) ($ov['text'] ?? ''));
        return $txt !== '' ? $txt : $default;
    }
    // where each of the seven's own "🎨 ظاهر دکمه" screen (genbtn_detail_payload,
    // reached through genbtn_key_to_alias()) goes back to - their real owning
    // screen, since none of them share their genbtn alias with a sibling button
    // for genbtn's own "list" screen to usefully return to.
    function bt_btnitem_back_cb($key, $lang)
    {
        if ($key === 'bottext.btnCloseTest') {
            // lives inside 🔑 تنظیم اکانت تست, not a bt_group screen of its own
            return "bt_edit|{$lang}|users.usertest.selectUsernamePrompt";
        }
        $groups = [
            'users.sell.backToPreviousBtn' => 'buyflow',
            'users.sell.backToPanelListBtn' => 'buyflow',
            'bottext.btnCloseBuy' => 'buyflow',
            'bottext.btnCloseTopup' => 'topup',
            'bottext.btnCloseAccount' => 'account',
            'bottext.btnCloseHelp' => 'help',
            'keyboard.sendPhoneNumber' => 'verify',
            'keyboard.acceptRules' => 'verify',
            'keyboard.receiveMembershipGift' => 'referral',
            'keyboard.shareLink' => 'referral',
        ];
        $group = $groups[$key] ?? '';
        return $group !== '' ? "bt_group|{$lang}|{$group}" : "btact|back|{$lang}";
    }
}
if (!function_exists('close_sticker_default_file_id')) {
    // The shop's own choice of "closing" sticker, given once as the starting
    // point - shipped as the day-one default so a fresh shop shows something
    // rather than nothing; 🎨 شخصی‌سازی can change it or turn it off entirely.
    function close_sticker_default_file_id()
    {
        return 'CAACAgIAAxkBAAJyXmqfzCu-JQ_peUcwxB9kxRP7mb9gAAJJAgACVp29CiqXDJ0IUyEOPQQ';
    }
    // one place for the factory duration, so the reader, the writer and the
    // "is this customized?" check can never drift apart on this number
    function close_sticker_default_duration()
    {
        return 1;
    }
}
if (!function_exists('close_sticker_keys')) {
    // The six ❌ بستن screens that were already individually editable (label +
    // colour) in 🎨 شخصی‌سازی before this - each gets its OWN sticker+timer now,
    // not one shared setting for all six. The five bottext.* keys are the
    // existing bt_btnitem_keys() minus the two back buttons (a back button
    // navigates, it doesn't close anything, so it gets no sticker); 'servclose'
    // (🛍 سرویس‌های من) is edited through the older genbtn system instead and
    // is not a bottext.* key at all, so it is spelled out here as its own
    // literal store key.
    function close_sticker_keys()
    {
        return [
            'bottext.btnCloseBuy',
            'bottext.btnCloseTopup',
            'bottext.btnCloseAccount',
            'bottext.btnCloseTest',
            'bottext.btnCloseHelp',
            'servclose',
        ];
    }
    // short 2-letter codes for callback_data (64-byte cap) - the same reason
    // genbtn_alias_map() exists for the older button-editor family
    function close_sticker_alias_map()
    {
        return [
            'bu' => 'bottext.btnCloseBuy',
            'tp' => 'bottext.btnCloseTopup',
            'ac' => 'bottext.btnCloseAccount',
            'te' => 'bottext.btnCloseTest',
            'he' => 'bottext.btnCloseHelp',
            'sv' => 'servclose',
        ];
    }
    function close_sticker_key_to_alias($key)
    {
        return array_search($key, close_sticker_alias_map(), true) ?: '';
    }
    function close_sticker_alias_to_key($alias)
    {
        return close_sticker_alias_map()[$alias] ?? null;
    }
    // which genbtn screen this key's settings live on. 'servclose' has no
    // registry key of its own, so it cannot go through genbtn_key_to_alias().
    function close_sticker_genbtn_alias($key)
    {
        return $key === 'servclose' ? 'sc' : genbtn_key_to_alias($key);
    }
}
if (!function_exists('close_sticker_settings')) {
    // Reads ONE key's sticker+timer out of the shared close_sticker JSON blob
    // (shaped {key: {enabled, file_id, duration}, ...}) - independent of every
    // other key, so customizing خرید اشتراک's sticker never touches افزایش
    // موجودی's.
    function close_sticker_settings($key, $fresh = false)
    {
        static $cache = [];
        if (isset($cache[$key]) && !$fresh) {
            return $cache[$key];
        }
        $setting = select("setting", "*", null, null, "select");
        $all = json_decode((string) ($setting['close_sticker'] ?? ''), true);
        if (!is_array($all)) {
            $all = [];
        }
        $cs = is_array($all[$key] ?? null) ? $all[$key] : [];
        $duration = isset($cs['duration']) ? (int) $cs['duration'] : close_sticker_default_duration();
        $cache[$key] = [
            // never configured for this key = on, with the shop's own default
            // sticker - every key starts out identical until customized apart
            'enabled' => array_key_exists('enabled', $cs) ? ($cs['enabled'] === '1') : true,
            'file_id' => array_key_exists('file_id', $cs) ? (string) $cs['file_id'] : close_sticker_default_file_id(),
            // capped 1-10: close_sticker_play() blocks the request for this
            // whole duration (see its own docblock), so a stray large number
            // cannot turn every ❌ بستن tap into a long hang
            'duration' => max(1, min(10, $duration > 0 ? $duration : close_sticker_default_duration())),
        ];
        return $cache[$key];
    }
    function close_sticker_save($key, array $patch)
    {
        $cur = close_sticker_settings($key, true);
        $next = array_merge($cur, $patch);
        $setting = select("setting", "*", null, null, "select");
        $all = json_decode((string) ($setting['close_sticker'] ?? ''), true);
        if (!is_array($all)) {
            $all = [];
        }
        $all[$key] = [
            'enabled' => $next['enabled'] ? '1' : '0',
            'file_id' => (string) $next['file_id'],
            'duration' => (string) max(1, min(10, (int) $next['duration'])),
        ];
        update("setting", "close_sticker", json_encode($all, JSON_UNESCAPED_UNICODE), null, null);
        close_sticker_settings($key, true);
    }
    // The sticker/timer state as a Telegram quote, shown inside the button's own
    // edit caption. $note is the "just changed" line and sits in the same quote,
    // so the confirmation and the state it produced read as one block.
    function close_sticker_caption_block($key, $note = '')
    {
        $cs = close_sticker_settings($key);
        $lines = [];
        if ($note !== '') {
            $lines[] = $note;
        }
        if ($cs['enabled']) {
            $lines[] = "🖼 استیکر دکمه بستن: <b>روشن</b>";
            $lines[] = "⏱ مدت نمایش: <b>{$cs['duration']} ثانیه</b>";
            $lines[] = "🎁 استیکر: <b>" . ($cs['file_id'] === close_sticker_default_file_id() ? 'پیش‌فرض' : 'سفارشی') . "</b>";
            $lines[] = "بعد از این مدت، خودِ صفحه و استیکرش و این استیکر با هم پاک می‌شن.";
        } else {
            $lines[] = "🖼 استیکر دکمه بستن: <b>خاموش</b>";
            $lines[] = "با زدن ❌ بستن، صفحه فوراً پاک می‌شه، بدون هیچ استیکری.";
        }
        return "<blockquote>" . implode("\n", $lines) . "</blockquote>\n";
    }
}
if (!function_exists('close_sticker_play')) {
    // The ❌ بستن screen and this sticker disappear TOGETHER, after the
    // sticker's own timer - not the screen instantly and the sticker later.
    // $key picks which of the six sections' own settings to use (see
    // close_sticker_keys()). $alsoDelete is every message id the caller wants
    // removed at that same moment: the caption itself, and whatever sticker
    // was already sitting with it (menu-tap sticker, buy-flow sticker, ...) -
    // the caller reads those out (sell_sticker_capture() and the equivalent
    // inline reads for menu_sticker_id / Processing_value_tow /
    // topup_range_msg_id) but must NOT delete them itself; this function is
    // what deletes them, in the same breath as its own sticker.
    //
    // Off for this key, or no sticker configured: $alsoDelete goes right
    // away, exactly the instant-close behaviour the bot had before this
    // feature existed - a section that has it turned off must not end up
    // with its screens lingering for no reason.
    //
    // This is the same synchronous send → sleep() → continue shape 🎰 گردونه
    // شانس already uses for its dice roll (index.php) - not a new pattern in
    // this codebase. duration is capped at 10s in close_sticker_settings(),
    // so this can never hold a PHP-FPM worker for more than that on any
    // single ❌ بستن tap.
    function close_sticker_play($chat_id, $key, array $alsoDelete = [])
    {
        $alsoDelete = array_values(array_unique(array_filter(array_map('intval', $alsoDelete), function ($id) {
            return $id > 0;
        })));
        $cs = close_sticker_settings($key);
        if (!$cs['enabled'] || trim($cs['file_id']) === '') {
            foreach ($alsoDelete as $mid) {
                deletemessage($chat_id, $mid);
            }
            return;
        }
        $res = telegram('sendSticker', [
            'chat_id' => $chat_id,
            'sticker' => $cs['file_id'],
        ]);
        $stickerMid = (int) ($res['result']['message_id'] ?? 0);
        sleep($cs['duration']);
        foreach ($alsoDelete as $mid) {
            deletemessage($chat_id, $mid);
        }
        if ($stickerMid > 0) {
            deletemessage($chat_id, $stickerMid);
        }
    }
}
if (!function_exists('lang_switch_settings')) {
    // One reader for setting.lang_switch, so the admin screen, the picker and
    // the "who may use this bot" rule can never disagree about what is on.
    // Shape: {enabled, mode: once|always, langs: [..], blockOthers: '0'|'1'}
    function lang_switch_settings($fresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $setting = select("setting", "*", null, null, "select");
        $ls = json_decode((string) ($setting['lang_switch'] ?? ''), true);
        if (!is_array($ls)) {
            $ls = [];
        }
        $all = ['fa', 'en', 'ru', 'zh', 'tk'];
        $langs = (is_array($ls['langs'] ?? null) && !empty($ls['langs']))
            ? array_values(array_intersect($all, $ls['langs']))
            : $all;
        if (empty($langs)) {
            $langs = $all;
        }
        $cache = [
            'enabled' => (($ls['enabled'] ?? '0') === '1'),
            'mode' => (($ls['mode'] ?? 'once') === 'always') ? 'always' : 'once',
            'langs' => $langs,
            'blockOthers' => (($ls['blockOthers'] ?? '0') === '1'),
        ];
        return $cache;
    }
    function lang_switch_enabled_langs()
    {
        return lang_switch_settings()['langs'];
    }
    function lang_switch_save(array $patch)
    {
        $cur = lang_switch_settings(true);
        $next = array_merge($cur, $patch);
        update("setting", "lang_switch", json_encode([
            'enabled' => $next['enabled'] ? '1' : '0',
            'mode' => $next['mode'] === 'always' ? 'always' : 'once',
            'langs' => array_values($next['langs']),
            'blockOthers' => $next['blockOthers'] ? '1' : '0',
        ], JSON_UNESCAPED_UNICODE), null, null);
        lang_switch_settings(true);
    }
}
if (!function_exists('lang_switch_blocked_count')) {
    // How many of the bot's real users the rule refuses as configured right
    // now. One grouped count, only ever called from the admin screen.
    function lang_switch_blocked_count()
    {
        global $pdo;
        $blocked = 0;
        $total = 0;
        foreach ($pdo->query("SELECT lang, COUNT(*) c FROM user GROUP BY lang")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c = (int) $r['c'];
            $total += $c;
            if (!lang_is_served($r['lang'] ?? '')) {
                $blocked += $c;
            }
        }
        return ['blocked' => $blocked, 'total' => $total];
    }
}
if (!function_exists('lang_is_served')) {
    // "May this user use the bot at all?" - false only when the shop has turned
    // the rule on AND the user's language is not one it serves. Admins are never
    // refused: locking the owner out of their own bot over a language setting is
    // not a state anyone could recover from inside Telegram.
    function lang_is_served($userLang, $isAdmin = false)
    {
        if ($isAdmin) {
            return true;
        }
        $ls = lang_switch_settings();
        if (!$ls['blockOthers']) {
            return true;
        }
        $l = trim((string) $userLang);
        if ($l === '') {
            return true;
        }
        return in_array($l, $ls['langs'], true);
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
            // {key => true} for the buttons the admin has switched off in
            // 📐 چیدمان. A hide is a display choice only: the item itself stays
            // exactly as it is in the database, and its own on/off switch
            // (a panel's status, a gateway's toggle) is untouched.
            'hidden' => is_array($sec['hidden'] ?? null) ? $sec['hidden'] : [],
        ];
    }
}
if (!function_exists('help_layout_visible')) {
    // drops the keys the admin hid, for the customer-facing keyboards only -
    // every admin screen keeps listing them (marked 🚫) or there would be no way
    // back to visible.
    //
    // If EVERY item ends up hidden the hide list is ignored instead of shipping
    // a keyboard with nothing on it. The editor already refuses to hide the last
    // visible button, so this only catches the case where the items themselves
    // changed underneath a saved list (a panel deleted, a gateway switched off).
    function help_layout_visible(array $orderedKeys, array $section)
    {
        $hidden = $section['hidden'] ?? [];
        if (!is_array($hidden) || empty($hidden)) {
            return $orderedKeys;
        }
        $out = [];
        foreach ($orderedKeys as $key) {
            if (!empty($hidden[(string) $key])) {
                continue;
            }
            $out[] = $key;
        }
        return empty($out) ? $orderedKeys : $out;
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
        if ($kind === 'langpick') {
            // The five buttons on the language picker - the screen the bot shows
            // before the user has a language at all. Keyed by language code so a
            // rename never orphans the styling saved for it.
            //
            // Only the languages the shop actually offers are listed, the same
            // way 'gateway' lists only live gateways: styling a button nobody
            // will ever see is noise. The picker itself filters the same list.
            $lp_names = ['fa' => '🇮🇷 فارسی', 'en' => '🇬🇧 English', 'ru' => '🇷🇺 Русский', 'zh' => '🇨🇳 中文', 'tk' => '🇹🇲 Türkmençe'];
            $lp_section = help_layout_section($lang, 'langpick');
            $out = [];
            foreach (lang_switch_enabled_langs() as $code) {
                if (isset($lp_names[$code])) {
                    $out[$code] = $lp_section['rename'][$code] ?? $lp_names[$code];
                }
            }
            return $out;
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

if (!function_exists('bt_nosticker_keys')) {
    // Messages whose 🖼 استیکر can never do anything useful - hidden on their
    // editing screen and skipped when stickers are sent. A sticker stored on
    // one earlier is left where it is, just never used.
    function bt_nosticker_keys()
    {
        return [
            // blocks pasted into the referral screen, never a message of their own
            'users.affiliates.membershipGiftInfo',
            'users.affiliates.purchaseCommissionInfo',
            // temporary text replaced within seconds - its sticker stayed behind
            'users.sell.creating',
            // temporary too, and reworded per gateway so it rarely even matched
            'users.Balance.linkpayments',
        ];
    }
}
if (!function_exists('bottext_extras_key_hint')) {
    // For a text that starts with a filled-in value and has no wording of its
    // own to be recognised by: the caller names the key, and the next sticker
    // lookup uses it once. Called with no argument it reads and clears it.
    function bottext_extras_key_hint($key = null)
    {
        static $pending = null;
        if ($key !== null) {
            $pending = (string) $key;
            return null;
        }
        $k = $pending;
        $pending = null;
        return $k;
    }
}
if (!function_exists('bt_default_stickers')) {
    // Stickers a message ships WITH, as opposed to one an admin attached. Kept
    // out of setting.keyboardmain's text_stickers on purpose: everything in that
    // store counts as "customized" (green row, cleared by a reset), and a
    // factory sticker is neither.
    function bt_default_stickers()
    {
        return [
            'users.sell.noPaymentMethod' => 'CAACAgQAAxkBAAJyomqmL8XWREbwt2BPYfm8fToL4HqdAAKGDwACnQVRU0jlv2uEhl4wPQQ',
        ];
    }
    function bt_default_sticker($key)
    {
        return bt_default_stickers()[$key] ?? '';
    }
    // what actually gets sent: the admin's sticker when there is one, otherwise
    // whatever the message ships with
    function bt_effective_sticker($map, $key, $lang)
    {
        $own = function_exists('bt_media_lookup') ? bt_media_lookup($map, $key, $lang) : '';
        return $own !== '' ? $own : bt_default_sticker($key);
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
function sendMessageService($panel_info, $config, $sub_link, $username_service, $reply_markup, $caption, $invoice_id, $user_id = null, $image = 'images.jpg', $kind = 'purchase')
{
    global $setting, $from_id, $textbotlang;
    if (!check_active_btn($setting['keyboardmain'], "text_help"))
        $reply_markup = null;
    $user_id = $user_id == null ? $from_id : $user_id;
    $configCount = is_array($config) ? count($config) : 0;
    if ($panel_info['type'] == "WGDashboard") {
        // WireGuard delivers its .conf file rather than a QR - unchanged
        if ($panel_info['config'] == "onconfig" && $configCount != 1) {
            sendmessage($user_id, $caption, $reply_markup, 'HTML');
        } else {
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
        }
    } else {
        // What the QR stands for: the subscription link covers every config, so
        // it wins even when there are several - that case used to send no QR at
        // all. A lone config works on its own; several configs with no
        // subscription link have nothing a single QR could stand for.
        $out_put_qrcode = "";
        if ($panel_info['sublink'] == "onsublink") {
            $out_put_qrcode = (string) $sub_link;
        } elseif ($panel_info['config'] == "onconfig" && $configCount == 1) {
            $out_put_qrcode = (string) $config[0];
        }
        $captionSent = false;
        if ($out_put_qrcode !== '' && config_delivery_qr_on($kind, $panel_info['code_panel'] ?? null)) {
            $urlimage = "$user_id$invoice_id.png";
            try {
                $qrCode = createqrcode($out_put_qrcode);
                file_put_contents($urlimage, $qrCode->getString());
                addBackgroundImage($urlimage, $qrCode, $image);
                // a photo caption is capped at 1024 characters - a longer message
                // (several config links) follows the QR as its own text instead
                $captionFits = mb_strlen(html_entity_decode(strip_tags((string) $caption), ENT_QUOTES, 'UTF-8')) <= 1024;
                $res = telegram('sendphoto', [
                    'chat_id' => $user_id,
                    'photo' => new CURLFile($urlimage),
                    'reply_markup' => $captionFits ? $reply_markup : null,
                    'caption' => $captionFits ? $caption : '',
                    'parse_mode' => "HTML",
                ]);
                $captionSent = $captionFits && !empty($res['ok']);
            } catch (\Throwable $e) {
                error_log('sendMessageService QR: ' . $e->getMessage());
            }
            if (is_file($urlimage)) {
                unlink($urlimage);
            }
        }
        // the service details must reach the customer even when the QR could not
        if (!$captionSent) {
            sendmessage($user_id, $caption, $reply_markup, 'HTML');
        }
    }
    if ($panel_info['config'] == "onconfig" && config_delivery_mode($kind, $panel_info['code_panel'] ?? null) === "2") {
        if (is_array($config)) {
            $cd_hintKey = ($kind === 'usertest') ? 'textbot.getConfigHintTest' : 'textbot.getConfigHintBuy';
            $cd_hintText = bottext_resolve_key($cd_hintKey);
            // sendMessageService's own $kind is 'purchase'|'usertest' - keyboard_config()'s
            // is 'usertest'|'buy', so normalize rather than let 'purchase' silently
            // fall through to the usertest column/button settings below
            $cc_kbKind = ($kind === 'usertest') ? 'usertest' : 'buy';
            sendmessage($user_id, $cd_hintText !== '' ? $cd_hintText : $textbotlang['hardcoded']['getConfigHint'], keyboard_config($config, $invoice_id, false, $cc_kbKind), 'HTML');
        }
    }
}
function isValidInvitationCode($setting, $fromId, $verfy_status, $lang = 'fa')
{
    global $textbotlang;

    if (feature_value('verifybucodeuser', $lang, $setting['verifybucodeuser']) == "onverify" && $verfy_status != 1) {
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
