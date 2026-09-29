<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/langsync.php';
require_auth();

// The main menu of one language - the same layout the bot's ✏️ نام و نمایش
// دکمه‌ها edits (mainmenu_layout_get/_save): which row each button sits on,
// its own name, emoji, colour, whether it is hidden, its tap sticker, and the
// two whole-menu switches. The change-language button's hidden state is one
// for every language, as in the bot.
$t = $textbotlang['panel'];
$lang = web_lang_pick();
$tx = lang_tab_texts($lang);
$names = [
    'text_sell' => $tx['textbot']['sell'], 'text_extend' => $tx['textbot']['extend'], 'text_usertest' => $tx['textbot']['userTest'],
    'text_wheel_luck' => $tx['textbot']['wheelLuck'], 'text_Purchased_services' => $tx['textbot']['purchasedServices'],
    'accountwallet' => $tx['textbot']['accountWallet'], 'addbalance' => $tx['textbot']['addBalance'], 'text_affiliates' => $tx['textbot']['affiliates'],
    'text_Tariff_list' => $tx['textbot']['tariffList'], 'text_support' => $tx['textbot']['support'], 'text_help' => $tx['textbot']['help'],
    'text_change_language' => $tx['language']['changeButton'],
];
$colors = ['' => $t['menuColorDefault'], 'primary' => '🔵 ' . $t['menuColorBlue'], 'success' => '🟢 ' . $t['menuColorGreen'], 'danger' => '🔴 ' . $t['menuColorRed']];
$layout = mainmenu_layout_get($lang);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $err = null;
    // every button as it is now, by its token - nothing can be added or lost here
    $byToken = [];
    foreach ($layout['keyboard'] as $row) {
        foreach ((array) $row as $btn) {
            if (is_array($btn) && isset($btn['text'])) {
                $byToken[$btn['text']] = $btn;
            }
        }
    }
    $placed = [];
    $langBtnHidden = null;
    foreach ((array) ($_POST['btn'] ?? []) as $p) {
        $token = (string) ($p['token'] ?? '');
        if (!isset($byToken[$token]) || isset($placed[$token])) {
            continue;
        }
        $b = $byToken[$token];
        $rowNo = trim((string) ($p['row'] ?? ''));
        if (!ctype_digit($rowNo) || (int) $rowNo < 1 || (int) $rowNo > 30) {
            $err = $t['menuRowInvalid'];
            break;
        }
        $custom = trim((string) ($p['custom_text'] ?? ''));
        if (containsHtmlMarkup($custom) || mb_strlen($custom) > 64) {
            $err = $t['menuNameInvalid'];
            break;
        }
        $emoji = trim((string) ($p['emoji'] ?? ''));
        if ($emoji !== '' && (!preg_match('/^\X$/u', $emoji) || preg_match('/^[0-9a-zA-Z]$/', $emoji))) {
            $err = $t['menuEmojiInvalid'];
            break;
        }
        $custom === '' ? ($b['custom_text'] = null) : ($b['custom_text'] = $custom);
        if ($emoji !== ($b['emoji'] ?? '')) {
            // a plain emoji replaces a premium one, as in the bot
            unset($b['icon_emoji']);
        }
        $b['emoji'] = $emoji === '' ? null : $emoji;
        $style = (string) ($p['style'] ?? '');
        $b['style'] = in_array($style, ['primary', 'success', 'danger'], true) ? $style : null;
        $hidden = ($p['hidden'] ?? '') === '1';
        if ($token === 'text_change_language') {
            $langBtnHidden = $hidden;
        }
        $b['hidden'] = $hidden ? true : null;
        if (!empty($p['sticker_del'])) {
            $b['sticker'] = null;
        }
        $b = array_filter($b, fn($v) => $v !== null);
        $placed[$token] = [(int) $rowNo, $b];
    }
    if ($err === null && count($placed) !== count($byToken)) {
        $err = $t['menuIncomplete'];
    }
    if ($err === null) {
        // rows in their numbers' order, buttons in the order they were listed
        $rows = [];
        foreach ($placed as [$rowNo, $b]) {
            $rows[$rowNo][] = $b;
        }
        ksort($rows);
        $layout['keyboard'] = array_values($rows);
        if (!empty($_POST['simple_emoji'])) {
            $layout['simple_emoji'] = true;
        } else {
            unset($layout['simple_emoji']);
        }
        if (($_POST['emoji_pos_global'] ?? '') === 'left') {
            $layout['emoji_pos_global'] = 'left';
        } else {
            unset($layout['emoji_pos_global']);
        }
        mainmenu_layout_save($lang, $layout);
        // shown or hidden for every language at once (Persian's menu holds it)
        if ($langBtnHidden !== null && $langBtnHidden !== mainmenu_langbtn_hidden()) {
            mainmenu_langbtn_set_hidden($langBtnHidden);
        }
    }
    flash($err === null ? 'success' : 'error', $err ?? $t['refSaved']);
    header('Location: menu.php?' . http_build_query(['lang' => $lang]));
    exit;
}

$pageTitle = $t['menuPageTitle'];
$pageLede = $t['menuPageLede'];
$activeNav = 'menu';
include __DIR__ . '/inc/layout_head.php';
echo web_lang_assets();
$simple = !empty($layout['simple_emoji']);
$pos = ($layout['emoji_pos_global'] ?? '') === 'left' ? 'left' : 'right';
?>

<?= web_lang_tabs($lang, fn($code) => 'menu.php?' . http_build_query(['lang' => $code])) ?>

<div class="card fade-up" style="margin-bottom:14px">
  <div class="card-head"><div><div class="card-title">👁 <?= $t['menuPreview'] ?></div><div class="card-subtitle"><?= $t['menuPreviewSub'] ?></div></div></div>
  <div class="card-body" style="display:flex;flex-direction:column;gap:6px;max-width:420px">
    <?php foreach ($layout['keyboard'] as $row): $shown = array_filter((array) $row, fn($b) => is_array($b) && empty($b['hidden'])); if (!$shown) continue; ?>
      <div style="display:flex;gap:6px">
        <?php foreach ($shown as $b): [$label] = mainmenu_btn_preview($b, $names[$b['text']] ?? $b['text'], $simple, $pos); $c = ['primary' => '#2b6ef2', 'success' => '#16a34a', 'danger' => '#dc2626'][$b['style'] ?? ''] ?? 'var(--sf3)'; ?>
          <div style="flex:1;text-align:center;padding:9px 6px;border-radius:9px;background:<?= $c ?>;color:#fff;font-size:.84rem"><?= htmlspecialchars($label) ?><?= !empty($b['icon_emoji']) ? ' ✨' : '' ?></div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<form method="POST" action="menu.php?<?= http_build_query(['lang' => $lang]) ?>" class="card fade-up">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <div class="card-head"><div><div class="card-title">🔘 <?= $t['menuButtons'] ?></div><div class="card-subtitle"><?= $t['menuButtonsSub'] ?></div></div></div>
  <div class="card-body">
    <div class="tbl-wrap"><table class="tbl-xl">
      <thead><tr><th><?= $t['menuColRow'] ?></th><th><?= $t['menuColButton'] ?></th><th><?= $t['menuColName'] ?></th><th><?= $t['menuColEmoji'] ?></th><th><?= $t['menuColColor'] ?></th><th><?= $t['menuColHidden'] ?></th><th><?= $t['menuColSticker'] ?></th></tr></thead>
      <tbody>
      <?php $i = 0; foreach ($layout['keyboard'] as $r => $row): foreach ((array) $row as $b): if (!is_array($b) || !isset($b['text'])) continue; $n = "btn[$i]"; ?>
        <tr>
          <td><input type="hidden" name="<?= $n ?>[token]" value="<?= htmlspecialchars($b['text']) ?>"><input type="text" class="input" name="<?= $n ?>[row]" value="<?= $r + 1 ?>" inputmode="numeric" style="width:56px"></td>
          <td class="cs"><?= htmlspecialchars($names[$b['text']] ?? $b['text']) ?><?= $b['text'] === 'text_change_language' ? '<div class="field-hint">' . $t['menuLangBtnShared'] . '</div>' : '' ?></td>
          <td><input type="text" class="input" name="<?= $n ?>[custom_text]" value="<?= htmlspecialchars((string) ($b['custom_text'] ?? '')) ?>" placeholder="<?= htmlspecialchars($names[$b['text']] ?? '') ?>" maxlength="64"></td>
          <td><input type="text" class="input" name="<?= $n ?>[emoji]" value="<?= htmlspecialchars((string) ($b['emoji'] ?? '')) ?>" style="width:64px;text-align:center" placeholder="<?= !empty($b['icon_emoji']) ? '✨' : '—' ?>"></td>
          <td><select class="select" name="<?= $n ?>[style]"><?php foreach ($colors as $v => $label): ?><option value="<?= $v ?>"<?= ($b['style'] ?? '') === $v ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></td>
          <td><label class="lang-chip"><input type="hidden" name="<?= $n ?>[hidden]" value="0"><input type="checkbox" name="<?= $n ?>[hidden]" value="1"<?= !empty($b['hidden']) ? ' checked' : '' ?>> <?= $t['menuHidden'] ?></label></td>
          <td><?php if (!empty($b['sticker'])): ?><label class="lang-chip">✅ <input type="checkbox" name="<?= $n ?>[sticker_del]" value="1"> <?= $t['btStickerDelete'] ?></label><?php else: ?><span class="cf">—</span><?php endif; ?></td>
        </tr>
      <?php $i++; endforeach; endforeach; ?>
      </tbody>
    </table></div>
    <div class="set-row"><div><div class="set-label"><?= $t['menuSimple'] ?></div><div class="set-hint"><?= $t['menuSimpleHint'] ?></div></div>
      <div class="set-ctl"><label class="lang-chip"><input type="checkbox" name="simple_emoji" value="1"<?= $simple ? ' checked' : '' ?>> <?= $t['refOn'] ?></label></div></div>
    <div class="set-row"><div><div class="set-label"><?= $t['menuEmojiPos'] ?></div></div>
      <div class="set-ctl"><select class="select" name="emoji_pos_global"><option value="right"<?= $pos === 'right' ? ' selected' : '' ?>><?= $t['menuPosRight'] ?></option><option value="left"<?= $pos === 'left' ? ' selected' : '' ?>><?= $t['menuPosLeft'] ?></option></select></div></div>
    <div class="field-hint"><?= $t['menuHint'] ?></div>
  </div>
  <div class="modal-foot" style="border-radius:0 0 10px 10px"><button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> <?= $t['bottextSaveBtn'] ?></button></div>
</form>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
