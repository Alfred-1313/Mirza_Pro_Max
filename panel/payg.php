<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/langsync.php';
require_auth();

// ⏱ اکانت ساعتی, one language at a time - the very settings of the bot's
// 🏬 تنظیمات فروشگاه ← ⏱ اکانت ساعتی (feature_lang_settings), checked by the
// same rules (payg.php): it does not go on without a panel and prices, no
// group may be free on both sides, and only panels the bot can bill on.
$t = $textbotlang['panel'];
$r = payg_admin_texts();
$lang = web_lang_pick();
$cur = currency_for_lang($lang);
$curTitle = currency_get($cur)['title'] ?? $cur;
$panels = db_fetchAll($pdo, "SELECT * FROM marzban_panel ORDER BY id");
$back = fn($card) => 'payg.php?' . http_build_query(['lang' => $lang]) . '#' . $card;
$isOn = fn($k) => isset($_POST[$k]) && $_POST[$k] === '1';
$groupNames = payg_group_names();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $card = (string) ($_POST['card'] ?? '');
    $err = null;
    $okMsg = $t['pgSaved'];
    if ($card === 'status') {
        feature_setting_set('payg_unit', $lang, ($_POST['unit'] ?? '') === 'minute' ? 'minute' : 'hour');
        feature_setting_set('payg_start', $lang, ($_POST['start'] ?? '') === 'connect' ? 'connect' : 'create');
        // 💡 روش ساخت نام کاربری, as the bot's ⏱ screen sets it
        if (array_key_exists((string) ($_POST['uname'] ?? ''), payg_uname_modes())) {
            feature_setting_set('payg_uname', $lang, (string) $_POST['uname']);
        }
        if ($isOn('on') !== payg_cfg($lang)['on']) {
            $err = payg_admin_toggle($lang, 'on');
        }
    } elseif ($card === 'panels') {
        $codes = array_map('strval', array_column($panels, 'code_panel'));
        $picked = array_values(array_intersect(array_map('strval', (array) ($_POST['panels'] ?? [])), $codes));
        foreach ($picked as $code) {
            $p = $panels[array_search($code, $codes, true)];
            $why = payg_panel_problem($lang, $p);
            if ($why !== null) {
                $err = strip_tags(strtr($r['probPanel_' . $why], ['{panel}' => (string) $p['name_panel']]));
                break;
            }
        }
        if ($err === null && !$picked && payg_cfg($lang)['on']) {
            $err = strip_tags($r['lastPanel']);
        }
        if ($err === null) {
            feature_setting_set('payg_panels', $lang, implode(',', $picked));
        }
    } elseif ($card === 'prices') {
        // the whole table at once: a group may end up free on one side, never on both
        $cfg = payg_cfg($lang);
        $new = [];
        foreach (payg_price_keys() as $key) {
            [$dim, $g] = explode('_', $key, 2);
            $mode = (string) ($_POST["mode_{$key}"] ?? 'price');
            if ($mode === 'free') {
                $new[$key] = 'u';
            } elseif ($mode === 'same' && $g !== 'f') {
                $new[$key] = '';
            } else {
                $p = payg_admin_parse($lang, $key, (string) ($_POST["price_{$key}"] ?? ''));
                if (isset($p['error'])) {
                    $err = strip_tags($p['error']) . ' — ' . strip_tags($groupNames[$g]) . ' / ' . ($dim === 't' ? $t['pgTimePrice'] : $t['pgVolPrice']);
                    break;
                }
                $new[$key] = $p['ok'];
            }
            $cfg[$dim === 't' ? 'tprice' : 'vprice'][$g] = $new[$key];
        }
        if ($err === null) {
            foreach (payg_groups() as $g) {
                if (payg_price_raw($cfg, 't', $g) === 'u' && payg_price_raw($cfg, 'v', $g) === 'u') {
                    $err = strip_tags(strtr($r['bothFree'], ['{group}' => $groupNames[$g]]));
                }
            }
            $free = payg_free_group($cfg);
            if ($err === null && $cfg['on'] && $free !== null) {
                $err = strip_tags(strtr($r['bothFree'], ['{group}' => $groupNames[$free]]));
            }
        }
        if ($err === null) {
            foreach ($new as $key => $v) {
                [$dim, $g] = explode('_', $key, 2);
                feature_setting_set(($dim === 't' ? 'payg_tprice_' : 'payg_vprice_') . $g, $lang, (string) $v);
            }
        }
    } elseif ($card === 'rules') {
        $save = [];
        foreach (['minbal', 'max', 'minage', 'grace'] as $key) {
            $raw = trim((string) ($_POST[$key] ?? ''));
            $p = payg_admin_parse($lang, $key, $raw === '' ? '0' : $raw);
            if ($key === 'max' && $raw === '') {
                $p = ['ok' => '1'];
            }
            if (isset($p['error'])) {
                $err = strip_tags($p['error']);
                break;
            }
            $save[$key] = $p['ok'];
        }
        $warnRaw = trim((string) ($_POST['warn'] ?? ''));
        if ($err === null) {
            $p = payg_admin_parse($lang, 'warn', $warnRaw === '' ? '0' : $warnRaw);
            if (isset($p['error'])) {
                $err = strip_tags($p['error']);
            } else {
                $save['warn'] = $p['ok'];
            }
        }
        if ($err === null) {
            foreach ($save as $key => $v) {
                payg_admin_set($lang, $key, $v);
            }
            feature_setting_set('payg_delmode', $lang, ($_POST['delmode'] ?? '') === 'keep' ? 'keep' : 'remove');
            feature_setting_set('payg_convert', $lang, $isOn('convert') ? '1' : '0');
        }
    } elseif ($card === 'del') {
        // 🗑 by hand: what it used up to now is settled, it goes from the
        // panel and its owner is told - the bot's own 📋 list does the same.
        // Whatever the panel and Telegram code prints on the way is not
        // this page's: it would stop the redirect below.
        ob_start();
        require_once __DIR__ . '/../botapi.php';
        require_once __DIR__ . '/../panels.php';
        $id = (string) ($_POST['id'] ?? '');
        $done = preg_match('/^[A-Za-z0-9]+$/', $id) ? payg_locked($id, function () use ($id) {
            $svc = payg_row($id);
            if ($svc === null || !in_array($svc['status'], ['waiting', 'active', 'stopped'], true)) {
                return null;
            }
            payg_settle($svc);
            return payg_delete(payg_row($id), 'admin');
        }) : null;
        ob_end_clean();
        if (!is_array($done)) {
            $err = strip_tags($r['gone']);
        } else {
            $okMsg = sprintf($t['pgDeleted'], $done['username']);
        }
        $card = 'list';
    }
    flash($err === null ? 'success' : 'error', $err ?? $okMsg);
    header('Location: ' . $back($card));
    exit;
}

// what the bot uses now, for this language
$cfg = payg_cfg($lang);
$problems = payg_admin_problems($lang);
$stats = payg_stats($lang);
$day = payg_stats($lang, time() - 86400, time());
$open = db_fetchAll($pdo, "SELECT * FROM payg_service WHERE lang = ? AND status IN ('waiting', 'active', 'stopped') ORDER BY created_at DESC LIMIT 200", [$lang]);

$switch = function ($name, $on, $label, $hint = '') use ($t) {
    return '<div class="set-row"><div><div class="set-label">' . htmlspecialchars($label) . '</div>' . ($hint !== '' ? '<div class="set-hint">' . $hint . '</div>' : '') . '</div>'
        . '<div class="set-ctl"><label class="lang-chip" style="justify-content:center"><input type="hidden" name="' . $name . '" value="0"><input type="checkbox" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . '> ' . htmlspecialchars($t['refOn']) . '</label></div></div>';
};
$field = function ($label, $ctl, $hint = '') {
    return '<div class="set-row"><div><div class="set-label">' . htmlspecialchars($label) . '</div>' . ($hint !== '' ? '<div class="set-hint">' . $hint . '</div>' : '') . '</div><div class="set-ctl">' . $ctl . '</div></div>';
};
$input = fn($name, $value, $extra = '') => '<input type="text" class="input" name="' . $name . '" value="' . htmlspecialchars((string) $value) . '" ' . $extra . '>';
$select = function ($name, $options, $value) {
    $h = '<select class="select" name="' . $name . '">';
    foreach ($options as $v => $label) {
        $h .= '<option value="' . htmlspecialchars((string) $v) . '"' . ((string) $v === (string) $value ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
    }
    return $h . '</select>';
};
$cardHead = fn($title, $sub) => '<div class="card-head"><div><div class="card-title">' . $title . '</div><div class="card-subtitle">' . $sub . '</div></div></div>';
$formOpen = fn($card, $extra = '') => '<form method="POST" action="payg.php?' . http_build_query(['lang' => $lang]) . '"' . $extra . '><input type="hidden" name="_csrf" value="' . csrf_token() . '"><input type="hidden" name="card" value="' . $card . '">';
$saveBtn = '<div class="modal-foot" style="border-radius:0 0 10px 10px"><button type="submit" class="btn btn-primary">' . icon('check', 13) . ' ' . $t['bottextSaveBtn'] . '</button></div>';
// the calculator line under each price, as the bot's own shows it
$calc = function ($key) use ($cfg, $cur, $t) {
    [$dim, $g] = explode('_', $key, 2);
    $v = payg_price_num(payg_price_raw($cfg, $dim, $g));
    if ($v === null) {
        return '';
    }
    if ($dim === 't') {
        $perHour = $cfg['unit'] === 'minute' ? $v * 60 : $v;
        return sprintf($t['pgCalcTime'], payg_money($perHour / 60, $cur), payg_money($perHour * 24, $cur), payg_money($perHour * 720, $cur));
    }
    return sprintf($t['pgCalcVol'], payg_money($v * 10, $cur), payg_money($v * 100, $cur));
};
$statusName = ['waiting' => '⏸ ' . $t['pgStatusWaiting'], 'active' => '✅ ' . $t['pgStatusActive'], 'stopped' => '⛔️ ' . $t['pgStatusStopped']];

$pageTitle = $t['pgPageTitle'];
$pageLede = $t['pgPageLede'];
$activeNav = 'payg';
include __DIR__ . '/inc/layout_head.php';
echo web_lang_assets();
?>

<?= web_lang_tabs($lang, fn($code) => 'payg.php?' . http_build_query(['lang' => $code])) ?>

<div class="card fade-up" id="status" style="margin-bottom:14px">
  <?= $cardHead('⏱ ' . $t['pgStatusTitle'], sprintf($t['pgStatusSub'], htmlspecialchars(web_langs()[$lang]))) ?>
  <?php if ($problems): ?>
    <div class="notice notice-no" style="margin:12px 16px 0"><?= implode('<br>', $problems) ?></div>
  <?php endif; ?>
  <?= $formOpen('status') ?>
  <div class="card-body">
    <?= $switch('on', $cfg['on'], $t['pgOn'], $t['pgOnHint']) ?>
    <?= $field($t['pgUnit'], $select('unit', ['hour' => $t['pgUnitHour'], 'minute' => $t['pgUnitMinute']], $cfg['unit'])) ?>
    <?= $field($t['pgStart'], $select('start', ['create' => $t['pgStartCreate'], 'connect' => $t['pgStartConnect']], $cfg['start'])) ?>
    <?= $field($t['pgUname'], $select('uname', array_combine(array_keys(payg_uname_modes()), array_map(fn($m) => payg_uname_label($m, panel_texts()), array_keys(payg_uname_modes()))), $cfg['uname']), $t['pgUnameHint']) ?>
  </div>
  <?= $saveBtn ?></form>
</div>

<div class="card fade-up<?= $cfg['on'] ? '' : ' set-off' ?>" id="panels" style="margin-bottom:14px">
  <?= $cardHead('🖥 ' . $t['pgPanelsTitle'], $t['pgPanelsSub']) ?>
  <?= $formOpen('panels') ?>
  <div class="card-body"><div class="lang-chips">
    <?php if (!$panels): ?><span class="cf"><?= htmlspecialchars($t['pgNoPanels']) ?></span><?php endif; ?>
    <?php foreach ($panels as $p): $why = payg_panel_problem($lang, $p); $on = in_array((string) $p['code_panel'], $cfg['panels'], true); ?>
      <label class="lang-chip"<?= $why !== null && !$on ? ' style="opacity:.55"' : '' ?>><input type="checkbox" name="panels[]" value="<?= htmlspecialchars((string) $p['code_panel']) ?>"<?= $on ? ' checked' : '' ?><?= $why !== null && !$on ? ' disabled' : '' ?>> <?= htmlspecialchars((string) $p['name_panel']) ?><?= $why !== null ? ' — ' . htmlspecialchars($why === 'type' ? $t['pgPanelBadType'] : $t['pgPanelNoProduct']) : '' ?></label>
    <?php endforeach; ?>
  </div></div>
  <?= $saveBtn ?></form>
</div>

<div class="card fade-up<?= $cfg['on'] ? '' : ' set-off' ?>" id="prices" style="margin-bottom:14px">
  <?= $cardHead('💵 ' . $t['pgPricesTitle'], sprintf($t['pgPricesSub'], htmlspecialchars($curTitle))) ?>
  <?= $formOpen('prices') ?>
  <div class="card-body">
    <?php foreach (['t' => sprintf($t['pgTimePriceOf'], $cfg['unit'] === 'minute' ? $t['pgPerMinute'] : $t['pgPerHour']), 'v' => $t['pgVolPrice']] as $dim => $dimLabel): ?>
      <div class="set-row"><div class="set-label" style="color:var(--mute)"><?= htmlspecialchars($dimLabel) ?></div></div>
      <?php foreach (payg_groups() as $g): $key = "{$dim}_{$g}"; $raw = (string) (($dim === 't' ? $cfg['tprice'] : $cfg['vprice'])[$g] ?? '');
        $mode = $raw === 'u' ? 'free' : ($raw === '' ? ($g === 'f' ? 'free' : 'same') : 'price');
        $modes = ['price' => $t['pgModePrice'], 'free' => $t['pgModeFree']] + ($g !== 'f' ? ['same' => $t['pgModeSame']] : []); ?>
        <?= $field(strip_tags($groupNames[$g]), '<div style="display:flex;gap:6px;flex-wrap:wrap">' . $select("mode_{$key}", $modes, $mode) . $input("price_{$key}", payg_price_num($raw) !== null ? $raw : '', 'inputmode="decimal" dir="ltr" style="max-width:160px" placeholder="0"') . '</div>', htmlspecialchars($calc($key))) ?>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
  <?= $saveBtn ?></form>
</div>

<div class="card fade-up<?= $cfg['on'] ? '' : ' set-off' ?>" id="rules" style="margin-bottom:14px">
  <?= $cardHead('⚙️ ' . $t['pgRulesTitle'], $t['pgRulesSub']) ?>
  <?= $formOpen('rules') ?>
  <div class="card-body">
    <?= $field(sprintf($t['pgMinbal'], $curTitle), $input('minbal', $cfg['minbal'] > 0 ? rtrim(rtrim(number_format($cfg['minbal'], 6, '.', ''), '0'), '.') : '0', 'inputmode="decimal" dir="ltr"'), $t['pgMinbalHint']) ?>
    <?= $field($t['pgMax'], $input('max', $cfg['max'], 'inputmode="numeric" dir="ltr"')) ?>
    <?= $field($t['pgMinage'], $input('minage', $cfg['minage'], 'inputmode="numeric" dir="ltr"'), $t['pgMinageHint']) ?>
    <?= $field($t['pgDelmode'], $select('delmode', ['remove' => $t['pgDelRemove'], 'keep' => $t['pgDelKeep']], $cfg['delmode'])) ?>
    <?= $field(sprintf($t['pgWarn'], $curTitle), $input('warn', implode(' ', array_map(fn($a) => rtrim(rtrim(number_format($a, 6, '.', ''), '0'), '.'), $cfg['warn'])), 'dir="ltr"'), $t['pgWarnHint']) ?>
    <?= $field($t['pgGrace'], $input('grace', $cfg['grace'], 'inputmode="numeric" dir="ltr"'), $t['pgGraceHint']) ?>
    <?= $switch('convert', $cfg['convert'], $t['pgConvert'], $t['pgConvertHint']) ?>
  </div>
  <?= $saveBtn ?></form>
</div>

<div class="card fade-up" id="list" style="margin-bottom:14px">
  <?= $cardHead('📋 ' . $t['pgListTitle'], sprintf($t['pgListSub'], number_format($stats['open']), money($stats['income'], $cur), money($day['income'], $cur))) ?>
  <div class="card-body">
    <?php if (!$open): ?><div class="cf"><?= htmlspecialchars($t['pgEmpty']) ?></div><?php endif; ?>
    <?php foreach ($open as $s): ?>
      <div class="set-row">
        <div>
          <div class="set-label"><?= htmlspecialchars(($statusName[$s['status']] ?? $s['status']) . ' · ' . $s['username']) ?></div>
          <div class="set-hint"><?= htmlspecialchars(sprintf($t['pgRowHint'], $s['user_id'], $s['panel'], money((float) $s['charged'], $s['currency']), safe_date((int) $s['created_at'], 'Y/m/d H:i'))) ?></div>
        </div>
        <div class="set-ctl">
          <?= $formOpen('del', ' onsubmit="return confirm(' . htmlspecialchars(json_encode($t['pgDeleteConfirm'], JSON_UNESCAPED_UNICODE)) . ')"') ?><input type="hidden" name="id" value="<?= htmlspecialchars($s['id_invoice']) ?>"><button class="btn btn-no btn-sm" type="submit"><?= icon('trash', 13) ?> <?= htmlspecialchars($t['pgDelete']) ?></button></form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
