<?php
// Translation coverage report.
//   php i18n_coverage.php            → summary per language
//   php i18n_coverage.php en users   → list the keys still missing in en, group `users`
//
// fa.php is the reference: any key present there but absent elsewhere falls back
// to Persian at runtime (see bt_lang_fill_defaults() in function.php).

$dir = __DIR__ . '/';
$ref = 'fa';
$codes = ['en', 'ru', 'zh', 'tk'];

function flatten($a, $prefix = '')
{
    $out = [];
    foreach ($a as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v)) {
            $out += flatten($v, $key);
        } else {
            $out[$key] = is_string($v) ? $v : (string) $v;
        }
    }
    return $out;
}

$base = flatten(require $dir . "lang/$ref.php");

// groups the end user actually sees; the admin panel intentionally stays Persian
$userFacingGroups = ['users', 'common', 'keyboard', 'textbot', 'language', 'paymentGateway', 'panel'];

$wantCode = $argv[1] ?? null;
$wantGroup = $argv[2] ?? null;

if ($wantCode) {
    $other = flatten(require $dir . "lang/$wantCode.php");
    $missing = array_diff_key($base, $other);
    foreach ($missing as $k => $v) {
        if ($wantGroup !== null && strpos($k, $wantGroup . '.') !== 0 && $k !== $wantGroup) {
            continue;
        }
        echo $k . "\t" . str_replace("\n", '\n', $v) . "\n";
    }
    exit(0);
}

printf("reference: lang/%s.php  (%d keys)\n\n", $ref, count($base));
printf("%-5s %8s %10s %10s %9s %12s\n", 'lang', 'keys', 'missing', 'user-miss', 'admin-miss', 'user-cover');
echo str_repeat('-', 60) . "\n";

$baseUser = 0;
foreach (array_keys($base) as $k) {
    if (in_array(explode('.', $k)[0], $userFacingGroups, true)) {
        $baseUser++;
    }
}

foreach ($codes as $c) {
    $p = $dir . "lang/$c.php";
    if (!is_file($p)) {
        printf("%-5s %8s\n", $c, 'MISSING FILE');
        continue;
    }
    $other = flatten(require $p);
    $missing = array_diff_key($base, $other);
    $userMiss = 0;
    $adminMiss = 0;
    foreach (array_keys($missing) as $k) {
        if (in_array(explode('.', $k)[0], $userFacingGroups, true)) {
            $userMiss++;
        } else {
            $adminMiss++;
        }
    }
    $cover = $baseUser > 0 ? (100 * ($baseUser - $userMiss) / $baseUser) : 100;
    printf("%-5s %8d %10d %10d %9d %11.1f%%\n", $c, count($other), count($missing), $userMiss, $adminMiss, $cover);
}

echo "\nuser-facing groups: " . implode(', ', $userFacingGroups) . "\n";
echo "everything missing falls back to Persian (by design).\n";
