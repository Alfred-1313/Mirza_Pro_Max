<?php
// Verifies that every translated string keeps the same placeholders as the
// Persian reference. A mismatched %s count breaks sprintf() at runtime, and a
// dropped {token} silently leaves a literal placeholder in the user's message.
//   php i18n_validate.php

$dir = __DIR__ . '/';

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

// sprintf specifiers, ignoring the escaped %%
function specs($s)
{
    $s = str_replace('%%', '', $s);
    preg_match_all('/%[-+ 0#\']*[0-9]*(?:\.[0-9]+)?[bcdeEfFgGosuxX]/', $s, $m);
    return $m[0];
}

function tokens($s)
{
    preg_match_all('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', $s, $m);
    $t = $m[0];
    sort($t);
    return $t;
}

$fa = flatten(require $dir . 'lang/fa.php');
$problems = 0;

foreach (['en', 'ru', 'zh', 'tk'] as $code) {
    $p = $dir . "lang/$code.php";
    if (!is_file($p)) {
        continue;
    }
    $other = flatten(require $p);
    $bad = [];
    foreach ($other as $k => $v) {
        if (!isset($fa[$k])) {
            continue;
        }
        $fs = specs($fa[$k]);
        $os = specs($v);
        if (count($fs) !== count($os)) {
            $bad[] = sprintf('%s  sprintf: fa=%d %s | %s=%d %s', $k, count($fs), implode(',', $fs), $code, count($os), implode(',', $os));
            continue;
        }
        if ($fs !== $os) {
            $bad[] = sprintf('%s  sprintf type mismatch: fa=%s | %s=%s', $k, implode(',', $fs), $code, implode(',', $os));
            continue;
        }
        $ft = tokens($fa[$k]);
        $ot = tokens($v);
        if ($ft !== $ot) {
            $bad[] = sprintf('%s  tokens: fa=%s | %s=%s', $k, implode(',', $ft), $code, implode(',', $ot));
        }
    }
    printf("%-3s checked %5d translated keys | problems: %d\n", $code, count(array_intersect_key($other, $fa)), count($bad));
    foreach ($bad as $b) {
        echo "    $b\n";
    }
    $problems += count($bad);
}

echo "\ntotal problems: $problems\n";
exit($problems > 0 ? 1 : 0);
