<?php
// This variable added for high load panels which their response time is long and bot can't communicate with online panel!
// null for default settings
$request_exec_timeout = null;
$dbhost = 'CHANGE_ME';
$dbname = 'CHANGE_ME';
$usernamedb = 'CHANGE_ME';
$passworddb = 'CHANGE_ME';
$connect = mysqli_connect($dbhost, $usernamedb, $passworddb, $dbname);
if ($connect->connect_error) { die("CHANGE_ME" . $connect->connect_error); }
mysqli_set_charset($connect, "CHANGE_ME");
$options = [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, ];
$dsn = "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4";
try { $pdo = new PDO($dsn, $usernamedb, $passworddb, $options); } catch (\PDOException $e) { error_log("CHANGE_ME" . $e->getMessage()); }
$APIKEY = 'CHANGE_ME';
$adminnumber = 'CHANGE_ME';
$domainhosts = 'CHANGE_ME';
$usernamebot = 'CHANGE_ME';
?>
