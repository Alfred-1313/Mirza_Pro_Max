<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/langsync.php';
require_auth();

// 🎨 ← ⚙️ ابزارها و تنظیمات جانبی on the web: 🌐 the language picker's
// switches and 🛡 the admins' own allowances - one for every language, as in
// the bot (lang_switch_*, setting.admin_*) - and 🔁 one language tab's reset
// to the defaults, with the very sections, counts and resets of the bot's own
// picker (bt_reset_sections / _counts / _apply_mask).
$t = $textbotlang['panel'];
$lang = web_lang_pick();
$back = fn($card) => 'tools.php?' . http_build_query(['lang' => $lang]) . '#' . $card;
$isOn = fn($k) => isset($_POST[$k]) && $_POST[$k] === '1';
$sections = bt_reset_sections();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $card = (string) ($_POST['card'] ?? '');
    $err = null;
    $okMsg = $t['refSaved'];
    if ($card === 'langsw') {
        $langs = array_values(array_intersect(panel_langs(), (array) ($_POST['langs'] ?? [])));
        if (!$langs) {
            $err = $t['toolsLangsMin'];
        } else {
            lang_switch_save(['enabled' => $isOn('enabled'), 'mode' => ($_POST['mode'] ?? '') === 'always' ? 'always' : 'once', 'langs' => $langs]);
        }
    } elseif ($card === 'admperm') {
        foreach (['admin_test_unlimited', 'admin_buy_free', 'admin_affrw_unlimited'] as $col) {
            update("setting", $col, $isOn($col) ? '1' : '0', null, null);
        }
    } elseif ($card === 'reset') {
        $mask = 0;
        foreach ($sections as $name => $s) {
            if (!empty($_POST['rst'][$name])) {
                $mask |= $s['bit'];
            }
        }
        if ($mask === 0) {
            $err = $t['toolsResetNone'];
        } elseif (!$isOn('confirm')) {
            $err = $t['toolsResetConfirmNeeded'];
        } else {
            $done = bt_reset_apply_mask($lang, $mask, $textbotlang);
            $okMsg = $done ? $t['toolsResetDone'] . ' ' . implode(' ', $done) : $t['toolsResetNothing'];
        }
    }
    flash($err === null ? 'success' : 'error', $err ?? $okMsg);
    header('Location: ' . $back($card));
    exit;
}

$setting = db_fetch($pdo, "SELECT * FROM setting LIMIT 1") ?? [];
$ls = lang_switch_settings(true);
$perm = [
    'admin_test_unlimited' => (string) ($setting['admin_test_unlimited'] ?? '1') !== '0',
    'admin_buy_free' => (string) ($setting['admin_buy_free'] ?? '0') === '1',
    'admin_affrw_unlimited' => (string) ($setting['admin_affrw_unlimited'] ?? '0') === '1',
];
$counts = bt_reset_counts($lang, $textbotlang);

$switch = function ($name, $on, $label, $hint = '') use ($t) {
    return '<div class="set-row"><div><div class="set-label">' . htmlspecialchars($label) . '</div>' . ($hint !== '' ? '<div class="set-hint">' . $hint . '</div>' : '') . '</div>'
        . '<div class="set-ctl"><label class="lang-chip" style="justify-content:center"><input type="hidden" name="' . $name . '" value="0"><input type="checkbox" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . '> ' . htmlspecialchars($t['refOn']) . '</label></div></div>';
};
$cardHead = fn($title, $sub) => '<div class="card-head"><div><div class="card-title">' . $title . '</div><div class="card-subtitle">' . $sub . '</div></div></div>';
$formOpen = fn($card) => '<form method="POST" action="tools.php?' . http_build_query(['lang' => $lang]) . '"><input type="hidden" name="_csrf" value="' . csrf_token() . '"><input type="hidden" name="card" value="' . $card . '">';
$saveBtn = '<div class="modal-foot" style="border-radius:0 0 10px 10px"><button type="submit" class="btn btn-primary">' . icon('check', 13) . ' ' . $t['bottextSaveBtn'] . '</button></div>';

$pageTitle = $t['toolsPageTitle'];
$pageLede = $t['toolsPageLede'];
$activeNav = 'tools';
include __DIR__ . '/inc/layout_head.php';
echo web_lang_assets();
?>

<?= web_lang_tabs($lang, fn($code) => 'tools.php?' . http_build_query(['lang' => $code])) ?>

<div class="card fade-up" id="langsw" style="margin-bottom:14px">
  <?= $cardHead('🌐 ' . $t['toolsLangTitle'], $t['toolsShared']) ?>
  <?= $formOpen('langsw') ?>
  <div class="card-body">
    <?= $switch('enabled', $ls['enabled'], $t['toolsLangAuto'], $t['toolsLangAutoHint']) ?>
    <div class="set-row"><div><div class="set-label"><?= $t['toolsLangWhen'] ?></div></div>
      <div class="set-ctl"><select class="select" name="mode"><option value="once"><?= $t['toolsLangOnce'] ?></option><option value="always"<?= $ls['mode'] === 'always' ? ' selected' : '' ?>><?= $t['toolsLangAlways'] ?></option></select></div></div>
    <div class="set-row"><div><div class="set-label"><?= $t['toolsLangs'] ?></div><div class="set-hint"><?= $t['toolsLangsHint'] ?></div></div>
      <div class="set-ctl"><div class="lang-chips">
        <?php foreach (panel_langs() as $code): ?>
          <label class="lang-chip"><input type="checkbox" name="langs[]" value="<?= $code ?>"<?= in_array($code, $ls['langs'], true) ? ' checked' : '' ?>> <?= htmlspecialchars(web_langs()[$code] ?? $code) ?></label>
        <?php endforeach; ?>
      </div></div></div>
    <div class="field-hint"><?= sprintf($t['toolsLangLook'], 'bottext.php?' . http_build_query(['lang' => 'fa', 'q' => 'langPickerCaption']), 'looks.php') ?></div>
  </div>
  <?= $saveBtn ?></form>
</div>

<div class="card fade-up" id="admperm" style="margin-bottom:14px">
  <?= $cardHead('🛡 ' . $t['toolsPermTitle'], $t['toolsShared'] . ' ' . $t['toolsPermSub']) ?>
  <?= $formOpen('admperm') ?>
  <div class="card-body">
    <?= $switch('admin_test_unlimited', $perm['admin_test_unlimited'], '🔑 ' . $t['toolsPermTest'], $t['toolsPermTestHint']) ?>
    <?= $switch('admin_buy_free', $perm['admin_buy_free'], '🛍 ' . $t['toolsPermBuy'], $t['toolsPermBuyHint']) ?>
    <?= $switch('admin_affrw_unlimited', $perm['admin_affrw_unlimited'], '🎁 ' . $t['toolsPermAffrw'], $t['toolsPermAffrwHint']) ?>
  </div>
  <?= $saveBtn ?></form>
</div>

<div class="card fade-up" id="reset" style="margin-bottom:14px">
  <?= $cardHead('🔁 ' . $t['toolsResetTitle'], sprintf($t['toolsResetSub'], htmlspecialchars(web_langs()[$lang] ?? $lang))) ?>
  <?= $formOpen('reset') ?>
  <div class="card-body">
    <?php if (array_sum($counts) === 0): ?><div class="field-hint">✨ <?= $t['toolsResetClean'] ?></div><?php endif; ?>
    <?php foreach ($sections as $name => $s): ?>
      <?php if ($s['sep'] !== ''): ?><div class="set-row"><div class="set-label" style="color:var(--mute)"><?= htmlspecialchars($s['sep']) ?></div></div><?php endif; ?>
      <div class="set-row"><div><div class="set-label"><?= htmlspecialchars($s['label']) ?></div><div class="set-hint"><?= sprintf($t['toolsResetCount'], (int) $counts[$name]) ?></div></div>
        <div class="set-ctl"><label class="lang-chip"><input type="checkbox" name="rst[<?= $name ?>]" value="1"<?= $counts[$name] > 0 ? ' checked' : '' ?>> <?= $t['toolsResetPick'] ?></label></div></div>
    <?php endforeach; ?>
    <div class="set-row"><div><div class="set-label">⚠️ <?= $t['toolsResetConfirm'] ?></div><div class="set-hint"><?= $t['toolsResetKeeps'] ?></div></div>
      <div class="set-ctl"><label class="lang-chip"><input type="hidden" name="confirm" value="0"><input type="checkbox" name="confirm" value="1"> <?= $t['toolsResetSure'] ?></label></div></div>
  </div>
  <div class="modal-foot" style="border-radius:0 0 10px 10px"><button type="submit" class="btn btn-no">🗑 <?= $t['toolsResetBtn'] ?></button></div></form>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
