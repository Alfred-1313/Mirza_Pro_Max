<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/langsync.php';
require_auth();

// 💎 مالی ← 🎁 تخفیف شارژ on the web, one language at a time: the automatic
// discount and the discount codes of each gateway this language sells
// through, of each category of them, and of all of them at once - the bot's
// own stores, written through its own topup_disc_* helpers and checked by the
// rules its prompts use.
$t = $textbotlang['panel'];
$faTx = lang_tab_texts('fa');
$lang = web_lang_pick();
$cur = currency_for_lang($lang);
$curRow = currency_get($cur);
$live = topup_disc_enabled_gateways($lang, $faTx);
// what the bot lists for this language, in its order: each category with its
// gateways (a category of one is just that gateway), then all of them at once
$sections = [];
$scopes = [];
foreach (array_keys(gateway_disc_groups()) as $group) {
    $members = array_keys(gateway_disc_group_members($group, $lang, $faTx));
    if (!$members) {
        continue;
    }
    $label = gateway_tab_group_name($lang, $group);
    $own = topup_disc_group_is_single($lang, $group, $faTx) === null ? topup_disc_scope_key($group) : null;
    $sections[] = [$label, $own, $members];
    if ($own !== null) {
        $scopes[$own] = $label;
    }
    foreach ($members as $k) {
        $scopes[$k] = $live[$k];
    }
}
$allKey = topup_disc_scope_key('all');
if ($live) {
    $scopes[$allKey] = topup_disc_scope_label('all', $faTx);
}

// a gateway's automatic discount, or a scope's ('@rial', '@all')
function disc_auto_get(string $lang, string $k): array
{
    $scope = topup_disc_scope_of_key($k);
    return $scope !== null ? topup_disc_group_for($lang, $scope) : topup_disc_auto_for($lang, $k);
}

// days left until an expiry, the way the bot asks for it: '0' = none, and
// empty once it has passed (a 0 typed there then lifts the end date)
function disc_days_left($ts): string
{
    if ((int) $ts <= 0) {
        return '0';
    }
    return (int) $ts > time() ? (string) (int) ceil(((int) $ts - time()) / 86400) : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $err = null;
    $note = '';
    $a = (string) ($_POST['a'] ?? '');
    $k = (string) ($_POST['k'] ?? '');
    $p = $_POST;
    // a value as the bot's prompts take it: a number, not below zero, and a
    // percentage no more than 100
    $readValue = function () use ($p, $t, &$err): array {
        $mode = ($p['mode'] ?? '') === 'fixed' ? 'fixed' : 'percent';
        $v = trim((string) ($p['value'] ?? ''));
        $v = $v === '' ? '0' : $v;
        if (!is_numeric($v) || (float) $v < 0) {
            $err = $t['discNumberInvalid'];
        } elseif ($mode === 'percent' && (float) $v > 100) {
            $err = $t['discPercentMax'];
        }
        return [$mode, (float) $v];
    };
    // every field an automatic discount and a code share (a code also has its
    // total quota), over what is stored now
    $read = function (array $old, bool $isCode) use ($p, $t, &$err, $readValue): array {
        $new = $old;
        [$new['mode'], $new['value']] = $readValue();
        $min = trim((string) ($p['min'] ?? ''));
        $min = $min === '' ? '0' : money_normalize($min);
        if ($min === null) {
            $err = $t['discNumberInvalid'];
        }
        $new['minAmount'] = (float) $min;
        foreach ($isCode ? ['limitPerUser' => 'per_user', 'limitTotal' => 'limit_total'] : ['limitPerUser' => 'per_user'] as $field => $name) {
            $n = trim((string) ($p[$name] ?? ''));
            $n = $n === '' ? '0' : $n;
            if (!ctype_digit($n)) {
                $err = $t['discNumberInvalid'];
            }
            $new[$field] = (int) $n;
        }
        // days from now, re-counted only when the number shown was changed:
        // saving the form again must not push the end further away
        $days = trim((string) ($p['days'] ?? ''));
        if ($days !== (string) ($p['days_was'] ?? '')) {
            $days = $days === '' ? '0' : $days;
            if (!ctype_digit($days)) {
                $err = $t['discNumberInvalid'];
            }
            $new['expiry'] = (int) $days > 0 ? time() + (int) $days * 86400 : 0;
        }
        $new['newUserOnly'] = ($p['new_only'] ?? '') === '1';
        $on = ($p['enabled'] ?? '') === '1';
        // as in the bot, typing a real value switches it on - unless the
        // switch was the thing changed in this save
        if (!$on && empty($old['enabled']) && $new['value'] > 0 && (float) ($old['value'] ?? 0) !== $new['value']) {
            $on = true;
        }
        $new['enabled'] = $on;
        return $new;
    };
    $scope = topup_disc_scope_of_key($k);
    if (!isset($scopes[$k])) {
        $err = $t['discScopeMissing'];
    } elseif ($a === 'auto') {
        $old = disc_auto_get($lang, $k);
        $new = $read($old, false);
        if ($err === null) {
            if ($scope !== null) {
                topup_disc_group_set($lang, $scope, $new);
            } else {
                topup_disc_auto_set($lang, $k, $new);
            }
            // the discount for every gateway is meant to be THE one: switching
            // it on switches off every category's and every gateway's own
            if ($k === $allKey && $new['enabled'] && empty($old['enabled'])) {
                $n = topup_disc_scope_disable_others($lang, 'all');
                $note = $n > 0 ? ' ' . sprintf($t['discOthersOff'], $n) : '';
            }
        }
    } elseif ($a === 'resetusage') {
        // every customer's used count back to zero; the discount itself stays
        if ($scope !== null) {
            topup_disc_group_reset_usage($lang, $scope);
        } else {
            topup_disc_auto_reset_usage($lang, $k);
        }
        $note = ' ' . $t['discResetUsageDone'];
    } elseif ($a === 'codeadd') {
        $code = trim((string) ($p['code'] ?? ''));
        [$mode, $value] = $readValue();
        if (!preg_match('/^[A-Za-z0-9_-]{2,32}$/', $code)) {
            $err = $t['discCodeInvalid'];
        } elseif (topup_disc_find_code($code) !== null) {
            $err = $t['discCodeExists'];
        } elseif ($err === null) {
            $idx = topup_disc_code_add($lang, $k, $code);
            topup_disc_code_update($lang, $k, $idx, ['mode' => $mode, 'value' => $value]);
        }
    } elseif ($a === 'code' || $a === 'codedel') {
        // by its place and its name both: the list may have changed since
        // the page was opened
        $idx = (int) ($p['idx'] ?? -1);
        $old = topup_disc_code_get($lang, $k, $idx);
        if (($old['code'] ?? null) === null || $old['code'] !== (string) ($p['code'] ?? '')) {
            $err = $t['discCodeGone'];
        } elseif ($a === 'codedel') {
            topup_disc_code_remove($lang, $k, $idx);
        } else {
            $new = $read($old, true);
            if ($err === null) {
                topup_disc_code_update($lang, $k, $idx, $new);
            }
        }
    }
    flash($err === null ? 'success' : 'error', $err ?? $t['refSaved'] . $note);
    header('Location: discounts.php?' . http_build_query(['lang' => $lang]) . '#d-' . str_replace('@', 'g-', $k));
    exit;
}

$pageTitle = $t['discPageTitle'];
$pageLede = $t['discPageLede'];
$activeNav = 'discounts';
include __DIR__ . '/inc/layout_head.php';
echo web_lang_assets();
$curTitle = htmlspecialchars(($curRow['title'] ?? $cur) . (!empty($curRow['symbol']) ? ' (' . $curRow['symbol'] . ')' : ''));
$chk = fn($name, $on, $label) => '<label class="lang-chip"><input type="checkbox" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . '> ' . htmlspecialchars($label) . '</label>';
$modeSel = fn($mode) => '<select class="select" name="mode" style="width:auto"><option value="percent">٪ ' . $t['discPercent'] . '</option><option value="fixed"' . ($mode === 'fixed' ? ' selected' : '') . '>💵 ' . $t['discFixed'] . '</option></select>';
$hidden = fn($a, $k) => '<input type="hidden" name="_csrf" value="' . csrf_token() . '"><input type="hidden" name="a" value="' . $a . '"><input type="hidden" name="k" value="' . htmlspecialchars($k) . '">';
$action = 'discounts.php?' . http_build_query(['lang' => $lang]);
$num = fn($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');

// one gateway's or scope's card: its automatic discount, then its codes
$card = function (string $k, string $title, string $sub) use ($lang, $t, $faTx, $chk, $modeSel, $hidden, $action, $num) {
    $auto = disc_auto_get($lang, $k);
    $liveAuto = !empty($auto['enabled']) && (float) ($auto['value'] ?? 0) > 0
        && ((int) ($auto['expiry'] ?? 0) === 0 || time() < (int) $auto['expiry']);
    $codes = topup_disc_codes_for($lang, $k);
    $days = disc_days_left($auto['expiry'] ?? 0);
    ?>
<div class="card fade-up" id="d-<?= htmlspecialchars(str_replace('@', 'g-', $k)) ?>" style="margin-bottom:14px">
  <div class="card-head"><div><div class="card-title"><?= htmlspecialchars($title) ?><?= $liveAuto ? ' <span class="tag tag-ok">' . htmlspecialchars(topup_disc_admin_value_label($auto, $lang)) . '</span>' : '' ?></div>
    <div class="card-subtitle"><?= $sub ?></div></div></div>
  <div class="card-body">
    <form method="POST" action="<?= $action ?>">
      <?= $hidden('auto', $k) ?>
      <input type="hidden" name="days_was" value="<?= $days ?>">
      <div class="set-row"><div><div class="set-label">🎯 <?= $t['discAuto'] ?></div><div class="set-hint"><?= $t['discAutoHint'] ?></div></div><div class="set-ctl"><?= $chk('enabled', !empty($auto['enabled']), $t['refOn']) ?></div></div>
      <?php if (empty($auto['enabled']) && (float) ($auto['value'] ?? 0) > 0): ?><div class="notice notice-warn"><?= $t['discValueOff'] ?></div><?php endif; ?>
      <div class="set-row"><div><div class="set-label"><?= $t['discValue'] ?></div><div class="set-hint"><?= $t['discValueHint'] ?></div></div>
        <div class="set-ctl" style="display:flex;gap:6px"><?= $modeSel($auto['mode'] ?? 'percent') ?><input type="text" class="input" name="value" value="<?= $num($auto['value'] ?? 0) ?>" inputmode="decimal"></div></div>
      <div class="set-row"><div><div class="set-label"><?= $t['discMin'] ?></div><div class="set-hint"><?= $t['discMinHint'] ?></div></div>
        <div class="set-ctl"><input type="text" class="input" name="min" value="<?= $num($auto['minAmount'] ?? 0) ?>" inputmode="decimal"></div></div>
      <div class="set-row"><div><div class="set-label"><?= $t['discDays'] ?></div><div class="set-hint"><?= sprintf($t['discDaysHint'], htmlspecialchars(topup_disc_expiry_text($auto['expiry'] ?? 0, $faTx, 'fa'))) ?></div></div>
        <div class="set-ctl"><input type="text" class="input" name="days" value="<?= $days ?>" inputmode="numeric"></div></div>
      <div class="set-row"><div><div class="set-label"><?= $t['discPerUser'] ?></div><div class="set-hint"><?= $t['discPerUserHint'] ?></div></div>
        <div class="set-ctl"><input type="text" class="input" name="per_user" value="<?= (int) ($auto['limitPerUser'] ?? 1) ?>" inputmode="numeric"></div></div>
      <div class="set-row"><div><div class="set-label"><?= $t['discNewOnly'] ?></div><div class="set-hint"><?= $t['discNewOnlyHint'] ?></div></div><div class="set-ctl"><?= $chk('new_only', !empty($auto['newUserOnly']), $t['refOn']) ?></div></div>
      <div style="display:flex;gap:8px;justify-content:flex-end;padding-top:8px"><button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> <?= $t['bottextSaveBtn'] ?></button></div>
    </form>
    <form method="POST" action="<?= $action ?>" onsubmit="return confirm(<?= htmlspecialchars(json_encode($t['discResetUsageConfirm'], JSON_UNESCAPED_UNICODE)) ?>)" style="display:flex;justify-content:flex-end;padding-top:6px">
      <?= $hidden('resetusage', $k) ?>
      <button type="submit" class="btn btn-ghost btn-sm">🔁 <?= $t['discResetUsage'] ?></button>
    </form>

    <div class="set-row" style="margin-top:10px"><div><div class="set-label">🎟 <?= $t['discCodes'] ?></div><div class="set-hint"><?= $t['discCodesHint'] ?></div></div></div>
    <?php if (!$codes): ?><div class="field-hint"><?= $t['discNoCodes'] ?></div><?php endif; ?>
    <?php foreach ($codes as $i => $c):
      $st = topup_disc_code_status($c);
      $used = topup_disc_code_used_count($c['code']);
      $lim = (int) ($c['limitTotal'] ?? 0);
      $cDays = disc_days_left($c['expiry'] ?? 0);
    ?>
      <div class="set-row" style="flex-wrap:wrap;align-items:flex-start">
        <form method="POST" action="<?= $action ?>" class="lang-chips" style="flex:1;align-items:center">
          <?= $hidden('code', $k) ?>
          <input type="hidden" name="idx" value="<?= $i ?>"><input type="hidden" name="code" value="<?= htmlspecialchars($c['code']) ?>"><input type="hidden" name="days_was" value="<?= $cDays ?>">
          <div style="min-width:150px"><b dir="ltr"><?= htmlspecialchars($c['code']) ?></b>
            <span class="tag <?= $st === 'active' ? 'tag-ok' : 'tag-no' ?>"><?= htmlspecialchars(topup_disc_admin_status_label($st)) ?></span>
            <div class="set-hint"><?= sprintf($t['discUsed'], $lim > 0 ? "$used/$lim" : (string) $used) ?> · <?= htmlspecialchars(topup_disc_expiry_text($c['expiry'] ?? 0, $faTx, 'fa')) ?></div></div>
          <?= $chk('enabled', !empty($c['enabled']), $t['refOn']) ?>
          <?= $modeSel($c['mode'] ?? 'percent') ?>
          <input type="text" class="input" name="value" value="<?= $num($c['value'] ?? 0) ?>" title="<?= $t['discValue'] ?>" placeholder="<?= $t['discValue'] ?>" style="width:90px" inputmode="decimal">
          <input type="text" class="input" name="min" value="<?= $num($c['minAmount'] ?? 0) ?>" title="<?= $t['discMin'] ?>" style="width:110px" inputmode="decimal">
          <input type="text" class="input" name="days" value="<?= $cDays ?>" title="<?= $t['discDays'] ?>" style="width:70px" inputmode="numeric">
          <input type="text" class="input" name="limit_total" value="<?= $lim ?>" title="<?= $t['discLimitTotal'] ?>" style="width:70px" inputmode="numeric">
          <input type="text" class="input" name="per_user" value="<?= (int) ($c['limitPerUser'] ?? 1) ?>" title="<?= $t['discPerUser'] ?>" style="width:70px" inputmode="numeric">
          <?= $chk('new_only', !empty($c['newUserOnly']), $t['discNewOnly']) ?>
          <button type="submit" class="btn btn-primary btn-sm"><?= icon('check', 13) ?></button>
        </form>
        <form method="POST" action="<?= $action ?>" onsubmit="return confirm(<?= htmlspecialchars(json_encode($t['discCodeDeleteConfirm'], JSON_UNESCAPED_UNICODE)) ?>)">
          <?= $hidden('codedel', $k) ?>
          <input type="hidden" name="idx" value="<?= $i ?>"><input type="hidden" name="code" value="<?= htmlspecialchars($c['code']) ?>">
          <button type="submit" class="btn btn-no btn-sm"><?= icon('trash', 13) ?></button>
        </form>
      </div>
    <?php endforeach; ?>
    <?php if ($codes): ?><div class="field-hint"><?= $t['discCodeFieldsHint'] ?></div><?php endif; ?>
    <form method="POST" action="<?= $action ?>" class="lang-chips" style="padding-top:10px;align-items:center">
      <?= $hidden('codeadd', $k) ?>
      <input type="text" class="input" name="code" placeholder="SUMMER20" dir="ltr" maxlength="32" style="width:150px" required>
      <?= $modeSel('percent') ?>
      <input type="text" class="input" name="value" placeholder="<?= $t['discValue'] ?>" style="width:90px" inputmode="decimal">
      <button type="submit" class="btn btn-ghost btn-sm"><?= icon('plus', 13) ?> <?= $t['discCodeAdd'] ?></button>
    </form>
  </div>
</div>
<?php
};
?>

<?= web_lang_tabs($lang, fn($code) => 'discounts.php?' . http_build_query(['lang' => $code])) ?>

<div class="card fade-up" style="margin-bottom:14px">
  <div class="card-body"><?= sprintf($t['gwCurrencyLine'], htmlspecialchars(web_langs()[$lang]), $curTitle) ?><div class="field-hint"><?= $t['discHowItWorks'] ?></div></div>
</div>

<?php if (!$live): ?>
  <div class="notice notice-warn"><?= $t['discNoGateways'] ?></div>
<?php endif; ?>

<?php foreach ($sections as [$label, $own, $members]): ?>
  <h3 style="margin:18px 2px 10px;font-size:.95rem"><?= htmlspecialchars($label) ?></h3>
  <?php if ($own !== null) {
      $card($own, sprintf($t['discGroupTitle'], $label), $t['discGroupNote']);
  }
  foreach ($members as $m) {
      $card($m, $live[$m], '');
  } ?>
<?php endforeach; ?>

<?php if ($live): ?>
  <h3 style="margin:18px 2px 10px;font-size:.95rem"><?= htmlspecialchars($scopes[$allKey]) ?></h3>
  <?php $card($allKey, $scopes[$allKey], $t['discAllNote']); ?>
<?php endif; ?>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
