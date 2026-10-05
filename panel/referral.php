<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/langsync.php';
require_auth();

// 👥 طرح‌های زیرمجموعه‌گیری, one language at a time - the very settings of the
// bot's 🌐 وضعیت قابلیت‌ها ← 👥 screens (feature_lang_settings), written with
// the same rules: 🎁 cannot go on without a panel, 🚪 without 🛡's channels,
// 🛡's phone check without 📞 احراز شماره, and so on.
$t = $textbotlang['panel'];
$fs = lang_tab_texts('fa')['Admin']['FeatureSection'];
$lang = web_lang_pick();
$cur = currency_for_lang($lang);
$setting = db_fetch($pdo, "SELECT * FROM setting LIMIT 1") ?? [];
$affRow = db_fetch($pdo, "SELECT * FROM affiliates LIMIT 1") ?? [];
$panels = db_fetchAll($pdo, "SELECT * FROM marzban_panel ORDER BY id");
$channels = db_fetchAll($pdo, "SELECT * FROM channels ORDER BY id");
$back = fn($card) => 'referral.php?' . http_build_query(['lang' => $lang]) . '#' . $card;
$isOn = fn($k) => isset($_POST[$k]) && $_POST[$k] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $card = (string) ($_POST['card'] ?? '');
    $err = null;
    $save = [];
    $okMsg = $t['refSaved'];
    if ($card === 'rwreset') {
        // 🔄 one customer's free config from the start - its count and its
        // 🔁 allowance, as the bot's own «صفر کردن دعوت‌های یک کاربر» does
        $who = trim((string) ($_POST['who'] ?? ''));
        $u = ctype_digit($who) ? db_fetch($pdo, "SELECT id, username FROM user WHERE id = ?", [$who])
            : (preg_match('/^@?(\w{3,32})$/', $who, $m) ? db_fetch($pdo, "SELECT id, username FROM user WHERE username = ?", [$m[1]]) : null);
        if ($u === null) {
            $err = sprintf($t['refVipMissing'], $who);
        } else {
            affrw_reset($u['id']);
            $okMsg = sprintf($t['refResetDone'], $u['id'] . (!empty($u['username']) && $u['username'] !== 'none' ? ' @' . $u['username'] : ''));
        }
    } elseif ($card === 'status') {
        feature_set('affiliatesstatus', $lang, $isOn('affiliatesstatus') ? 'onaffiliates' : 'offaffiliates');
    } elseif ($card === 'classic') {
        $pct = trim((string) ($_POST['aff_percent'] ?? ''));
        $gift = (string) ($_POST['aff_giftamount'] ?? '');
        if (!ctype_digit($pct) || (int) $pct > 100) {
            $err = $t['refPercentInvalid'];
        } elseif (!money_valid($gift, $cur)) {
            $err = sprintf($t['prodPriceInvalid'], currency_get($cur)['title'] ?? $cur, (int) (currency_get($cur)['decimals'] ?? 0));
        } else {
            $save = [
                'aff_classic' => $isOn('aff_classic') ? '1' : '0',
                'aff_percent' => (string) (int) $pct,
                'aff_commission' => $isOn('aff_commission') ? 'oncommission' : 'offcommission',
                'aff_firstbuy' => $isOn('aff_firstbuy') ? 'on_buy_porsant' : 'off_buy_porsant',
                'aff_startgift' => $isOn('aff_startgift') ? 'onDiscountaffiliates' : 'offDiscountaffiliates',
                'aff_giftamount' => money_normalize($gift),
                'aff_banner_text' => trim((string) ($_POST['aff_banner_text'] ?? '')),
            ];
            if ($isOn('aff_banner_nophoto')) {
                $save['aff_banner_media'] = 'none';
            }
        }
    } elseif ($card === 'reward') {
        $rw = affrw_cfg($lang);
        $panelCode = (string) ($_POST['affrw_panel'] ?? '');
        $gb = volume_parse((string) ($_POST['affrw_gb'] ?? ''), 'gb');
        $ints = [];
        foreach (['affrw_need', 'affrw_days', 'affrw_max'] as $k) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if (!ctype_digit($v) || (int) $v < 1) {
                $err = $t['refCountInvalid'];
            }
            $ints[$k] = (string) (int) $v;
        }
        $hours = trim((string) ($_POST['affrw_del_hours'] ?? ''));
        if ($gb === null) {
            $err = $t['prodVolumeInvalid'];
        } elseif ($panelCode !== '' && !in_array($panelCode, array_column($panels, 'code_panel'), true)) {
            $err = $t['prodPanelInvalid'];
        } elseif (($_POST['affrw_del'] ?? '') === 'hours' && (!ctype_digit($hours) || (int) $hours < 1 || (int) $hours > 8760)) {
            $err = $t['refHoursInvalid'];
        } elseif ($isOn('affrw_on') && $panelCode === '') {
            $err = $fs['affrwPanelFirst'];
        } elseif ($isOn('affrw_leave') && (!refv_cfg($lang, 'r')['channel'] || !refv_channel_rows(refv_cfg($lang, 'r')))) {
            $err = strip_tags($fs['affrwLeaveNeedsChannel']);
        }
        if ($err === null) {
            $save = $ints + [
                'affrw_panel' => $panelCode,
                'affrw_gb' => volume_store($gb),
                'affrw_mode' => ($_POST['affrw_mode'] ?? '') === 'auto' ? 'auto' : 'admin',
                'affrw_leave' => $isOn('affrw_leave') ? '1' : '0',
                'affrw_uname' => in_array($_POST['affrw_uname'] ?? '', ['ref', 'random', 'user'], true) ? $_POST['affrw_uname'] : 'ref',
                'affrw_renew' => $isOn('affrw_renew') ? '1' : '0',
                'affrw_del' => in_array($_POST['affrw_del'] ?? '', ['no', 'end', 'hours'], true) ? $_POST['affrw_del'] : 'no',
            ];
            if (($save['affrw_del'] === 'hours')) {
                $save['affrw_del_hours'] = (string) (int) $hours;
            }
            // switched on: only people who join from now count
            if ($isOn('affrw_on') && !$rw['on']) {
                $save['affrw_since'] = (string) time();
            }
            $save['affrw_on'] = $isOn('affrw_on') ? '1' : '0';
        }
    } elseif ($card === 'refv_c' || $card === 'refv_r') {
        $plan = substr($card, -1);
        $picked = array_values(array_intersect(array_map('intval', (array) ($_POST['channels'] ?? [])), array_map('intval', array_column($channels, 'id'))));
        $vip = [];
        $missing = [];
        foreach (preg_split('/[\s,،]+/u', trim((string) ($_POST['vip'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            $u = ctype_digit($tok) ? db_fetch($pdo, "SELECT id FROM user WHERE id = ?", [$tok])
                : (preg_match('/^@?(\w{3,32})$/', $tok, $m) ? db_fetch($pdo, "SELECT id FROM user WHERE username = ?", [$m[1]]) : null);
            if ($u) {
                $vip[] = (string) $u['id'];
            } else {
                $missing[] = $tok;
            }
        }
        $phoneOn = feature_value('get_number', $lang, $setting['get_number'] ?? '') == 'onAuthenticationphone';
        if ($missing) {
            $err = sprintf($t['refVipMissing'], implode('، ', $missing));
        } elseif ($isOn('phone') && !$phoneOn) {
            $err = strip_tags($fs['refvPhoneNeeds']);
        } elseif ($isOn('channel') && !$picked) {
            $err = strip_tags($channels ? $fs['refvChannelNeeds'] : $fs['refvNoChannels']);
        } else {
            $save = [
                "refv_{$plan}_phone" => $isOn('phone') ? '1' : '0',
                "refv_{$plan}_channels" => implode(',', $picked),
                // nothing to join is no channel check at all
                "refv_{$plan}_channel" => $isOn('channel') && $picked ? '1' : '0',
                "refv_{$plan}_vip" => implode(',', array_unique($vip)),
            ];
        }
    }
    if ($err !== null) {
        flash('error', $err);
    } else {
        foreach ($save as $k => $v) {
            feature_setting_set($k, $lang, $v);
        }
        flash('success', $okMsg);
    }
    header('Location: ' . $back($card));
    exit;
}

// what the bot uses now, for this language
$affOn = feature_value('affiliatesstatus', $lang, $setting['affiliatesstatus'] ?? '') != 'offaffiliates';
$c = [
    'on' => aff_classic_on($lang),
    'percent' => feature_setting_value('aff_percent', $lang, (string) ($setting['affiliatespercentage'] ?? '0')),
    'commission' => feature_setting_value('aff_commission', $lang, (string) ($affRow['status_commission'] ?? '')) === 'oncommission',
    'firstbuy' => feature_setting_value('aff_firstbuy', $lang, (string) ($affRow['porsant_one_buy'] ?? '')) === 'on_buy_porsant',
    'startgift' => feature_setting_value('aff_startgift', $lang, (string) ($affRow['Discount'] ?? '')) === 'onDiscountaffiliates',
    'gift' => feature_setting_value('aff_giftamount', $lang, (string) ($affRow['price_Discount'] ?? '0')),
];
[$bannerText, $bannerMedia] = feature_aff_banner($lang, $affRow);
$rw = affrw_cfg($lang);
$rules = [
    'uname' => (string) feature_setting_value('affrw_uname', $lang, 'ref'),
    'renew' => (string) feature_setting_value('affrw_renew', $lang, '0') === '1',
    'del' => (string) feature_setting_value('affrw_del', $lang, 'no'),
    'hours' => (string) feature_setting_value('affrw_del_hours', $lang, '24'),
    'leave' => (string) feature_setting_value('affrw_leave', $lang, '0') === '1',
];

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
$formOpen = fn($card) => '<form method="POST" action="referral.php?' . http_build_query(['lang' => $lang]) . '"><input type="hidden" name="_csrf" value="' . csrf_token() . '"><input type="hidden" name="card" value="' . $card . '">';
$saveBtn = '<div class="modal-foot" style="border-radius:0 0 10px 10px"><button type="submit" class="btn btn-primary">' . icon('check', 13) . ' ' . $t['bottextSaveBtn'] . '</button></div>';
$panelOptions = ['' => $t['refNoPanel']];
foreach ($panels as $p) {
    $panelOptions[$p['code_panel']] = $p['name_panel'];
}

$pageTitle = $t['refPageTitle'];
$pageLede = $t['refPageLede'];
$activeNav = 'referral';
include __DIR__ . '/inc/layout_head.php';
echo web_lang_assets();
?>

<?= web_lang_tabs($lang, fn($code) => 'referral.php?' . http_build_query(['lang' => $code])) ?>

<div class="card fade-up" id="status" style="margin-bottom:14px">
  <?= $cardHead('👥 ' . $t['refStatusTitle'], sprintf($t['refStatusSub'], htmlspecialchars(web_langs()[$lang]))) ?>
  <?= $formOpen('status') ?>
  <div class="card-body"><?= $switch('affiliatesstatus', $affOn, $t['refStatusLabel'], $t['refStatusHint']) ?></div>
  <?= $saveBtn ?></form>
</div>

<div class="card fade-up<?= $affOn ? '' : ' set-off' ?>" id="classic" style="margin-bottom:14px">
  <?= $cardHead('💼 ' . $t['refClassicTitle'], $t['refClassicSub']) ?>
  <?= $formOpen('classic') ?>
  <div class="card-body">
    <?= $switch('aff_classic', $c['on'], $t['refClassicOn']) ?>
    <?= $field($t['refPercent'], $input('aff_percent', $c['percent'], 'inputmode="numeric"'), $t['refPercentHint']) ?>
    <?= $switch('aff_commission', $c['commission'], $t['refCommission'], $t['refCommissionHint']) ?>
    <?= $switch('aff_firstbuy', $c['firstbuy'], $t['refFirstBuy']) ?>
    <?= $switch('aff_startgift', $c['startgift'], $t['refStartGift'], $t['refStartGiftHint']) ?>
    <?= $field(sprintf($t['refGiftAmount'], currency_get($cur)['title'] ?? $cur), $input('aff_giftamount', $c['gift'], 'inputmode="decimal"')) ?>
    <?= $field($t['refBannerText'], '<textarea class="textarea" name="aff_banner_text" rows="3" dir="auto">' . htmlspecialchars($bannerText) . '</textarea>', $t['refBannerHint']) ?>
    <?php if ($bannerMedia !== '' && $bannerMedia !== 'none'): ?>
      <?= $switch('aff_banner_nophoto', false, $t['refBannerRemovePhoto'], $t['refBannerHasPhoto']) ?>
    <?php endif; ?>
  </div>
  <?= $saveBtn ?></form>
</div>

<div class="card fade-up<?= $affOn ? '' : ' set-off' ?>" id="reward" style="margin-bottom:14px">
  <?= $cardHead('🎁 ' . $t['refRewardTitle'], $t['refRewardSub']) ?>
  <?= $formOpen('reward') ?>
  <div class="card-body">
    <?= $switch('affrw_on', $rw['on'], $t['refRewardOn'], $t['refRewardOnHint']) ?>
    <?= $field($t['refRewardPanel'], $select('affrw_panel', $panelOptions, $rw['panel'])) ?>
    <?= $field($t['refRewardNeed'], $input('affrw_need', $rw['need'], 'inputmode="numeric"')) ?>
    <?= $field($t['refRewardVolume'], $input('affrw_gb', volume_num($rw['gb'])), $t['prodVolumeHint']) ?>
    <?= $field($t['refRewardDays'], $input('affrw_days', $rw['days'], 'inputmode="numeric"')) ?>
    <?= $field($t['refRewardMax'], $input('affrw_max', $rw['max'], 'inputmode="numeric"'), $t['refRewardMaxHint']) ?>
    <?= $field($t['refRewardMode'], $select('affrw_mode', ['admin' => $t['refModeAdmin'], 'auto' => $t['refModeAuto']], $rw['mode'])) ?>
    <?= $switch('affrw_leave', $rules['leave'], $t['refRewardLeave'], $t['refRewardLeaveHint']) ?>
    <div class="set-row"><div class="set-label" style="color:var(--mute)"><?= $t['refRulesTitle'] ?></div></div>
    <?= $field($t['refRuleUname'], $select('affrw_uname', ['ref' => $t['refUnameRef'], 'random' => $t['refUnameRandom'], 'user' => $t['refUnameUser']], $rules['uname'])) ?>
    <?= $switch('affrw_renew', $rules['renew'], $t['refRuleRenew']) ?>
    <?= $field($t['refRuleDel'], $select('affrw_del', ['no' => $t['refDelNo'], 'end' => $t['refDelEnd'], 'hours' => $t['refDelHours']], $rules['del'])) ?>
    <?= $field($t['refRuleHours'], $input('affrw_del_hours', $rules['hours'], 'inputmode="numeric"'), $t['refRuleHoursHint']) ?>
  </div>
  <?= $saveBtn ?></form>
</div>

<div class="card fade-up<?= $affOn ? '' : ' set-off' ?>" id="rwreset" style="margin-bottom:14px">
  <?= $cardHead('🔄 ' . $t['refResetTitle'], $t['refResetSub']) ?>
  <?= $formOpen('rwreset') ?>
  <div class="card-body">
    <?= $field($t['refResetWho'], $input('who', '', 'dir="ltr" placeholder="123456789 · @username"'), $t['refResetHint']) ?>
  </div>
  <div class="modal-foot" style="border-radius:0 0 10px 10px"><button type="submit" class="btn btn-primary">🔄 <?= $t['refResetBtn'] ?></button></div></form>
</div>

<?php foreach (['c' => '💼 ' . $t['refClassicTitle'], 'r' => '🎁 ' . $t['refRewardTitle']] as $plan => $planName):
  $rv = refv_cfg($lang, $plan);
  $vipIds = $rv['vip'];
  $vipText = implode("\n", $vipIds);
?>
<div class="card fade-up<?= $affOn ? '' : ' set-off' ?>" id="refv_<?= $plan ?>" style="margin-bottom:14px">
  <?= $cardHead('🛡 ' . $t['refVerifyTitle'] . ' — ' . $planName, $t['refVerifySub']) ?>
  <?= $formOpen('refv_' . $plan) ?>
  <div class="card-body">
    <?= $switch('phone', $rv['phone'], $t['refVerifyPhone'], $t['refVerifyPhoneHint']) ?>
    <?= $switch('channel', $rv['channel'], $t['refVerifyChannel']) ?>
    <div class="set-row"><div><div class="set-label"><?= $t['refVerifyChannels'] ?></div></div><div class="set-ctl"><div class="lang-chips">
      <?php if (!$channels): ?><span class="cf"><?= htmlspecialchars(strip_tags($fs['refvNoChannels'])) ?></span><?php endif; ?>
      <?php foreach ($channels as $ch): ?>
        <label class="lang-chip"><input type="checkbox" name="channels[]" value="<?= (int) $ch['id'] ?>"<?= in_array((int) $ch['id'], $rv['channels'], true) ? ' checked' : '' ?>> <?= htmlspecialchars((string) (($ch['remark'] ?? '') ?: ($ch['link'] ?? $ch['id']))) ?></label>
      <?php endforeach; ?>
    </div></div></div>
    <?= $field($t['refVerifyVip'], '<textarea class="textarea" name="vip" rows="3" dir="ltr" placeholder="123456789&#10;@username">' . htmlspecialchars($vipText) . '</textarea>', $t['refVerifyVipHint']) ?>
  </div>
  <?= $saveBtn ?></form>
</div>
<?php endforeach; ?>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
