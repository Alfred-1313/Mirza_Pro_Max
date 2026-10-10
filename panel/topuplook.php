<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/langsync.php';
require_auth();

// 🎨 ← 💰 شارژ کیف پول on the web, one language and one gateway at a time:
// every text of that gateway's top-up steps (left empty, the bot's default
// wording), the buttons of the amount screens, the look of its package
// buttons and of its invoice's buttons - the stores and setters the bot's
// own screens use (topup_caption_*, topup_gwcap_*, topup_btnstyle_*,
// topup_packages_set_style, topup/card_invoice_btnstyle_*).
$t = $textbotlang['panel'];
$fa = lang_tab_texts('fa');
$tp = $fa['Admin']['TopupPkg'];
$lang = web_lang_pick();
$tx = lang_tab_texts($lang);
$names = gateway_registry($fa);
$gws = array_values(array_filter(gateway_all_keys(), fn($k) => gateway_applicable_for_lang($k, $lang)));
$key = (string) ($_GET['gw'] ?? $_POST['gw'] ?? '');
$key = in_array($key, $gws, true) ? $key : ($gws[0] ?? '');
$has = $key !== '' ? topup_gw_customizables($key) : [];
$here = 'topuplook.php?' . http_build_query(['lang' => $lang, 'gw' => $key]);
$colorNames = ['primary' => '🔵 ' . $t['menuColorBlue'], 'success' => '🟢 ' . $t['menuColorGreen'], 'danger' => '🔴 ' . $t['menuColorRed']];
$b = $tx['users']['Balance'];

// the texts this gateway has, in the order the customer meets them:
// kind => [label, default wording, popup (plain text), hint]
$texts = [];
if ($key !== '') {
    $label = fn($k) => trim(preg_replace('/^✏️\s*ویرایش\s*/u', '', (string) $tp[$k]));
    $texts['caption'] = [$label('editCaptionBtn'), $b['pkgPromptTitle'], false, ''];
    $texts['custom'] = [$label('editCustomCaptionBtn'), topup_custom_caption_default($key, $tx), false, ''];
    if (in_array('linkmsg', $has, true)) {
        $texts['linkmsg'] = [$label('editLinkMsgBtn'), $b['linkpayments'], false, ''];
    }
    $caps = ['range' => ['editRangeCaptionBtn', 'range'], 'notnumber' => ['editNotNumberBtn', 'notnumber'], 'noaddress' => ['editNoAddressBtn', 'noaddress'],
        'askhash' => ['editAskHashBtn', 'askhash'], 'hashbad' => ['editHashBadBtn', 'hashbad'], 'notseen' => ['editNotSeenBtn', 'notseen'],
        'paidalert' => ['editPaidAlertBtn', 'paidalert'], 'inv' => ['editInvoiceCaptionBtn', 'invoice'], 'exp' => ['editExpCaptionBtn', 'expired']];
    foreach ($caps as $kind => [$lk, $need]) {
        if (!in_array($need, $has, true)) {
            continue;
        }
        $popup = in_array($kind, ['paidalert', 'notseen'], true);
        $hint = ['range' => $t['tlHintRange'], 'exp' => $t['tlHintExp']][$kind] ?? '';
        if ($kind === 'inv' && !empty($tp['invPlaceholders'][$key])) {
            $hint = trim(strip_tags((string) $tp['invPlaceholders'][$key]));
        }
        $texts[$kind] = [$label($lk), topup_gwcap_default($kind, $key, $tx), $popup, $popup ? $t['tlHintPopup'] : $hint];
    }
}
// the wording an admin gave this gateway, '' while it still shows the default
function tl_text_own($kind, $lang, $key, $tx)
{
    if ($kind === 'caption') {
        return topup_caption_has_override($lang, $key) ? (string) topup_caption_for($lang, $key, '') : '';
    }
    if ($kind === 'custom') {
        return topup_custom_caption_has_override($lang, $key) ? (string) topup_custom_caption_for($lang, $key, '') : '';
    }
    if ($kind === 'linkmsg') {
        return topup_linkmsg_has_override($lang, $key) ? (string) topup_linkmsg_for($lang, $key, '') : '';
    }
    return topup_gwcap_has_override($kind, $lang, $key) ? (string) topup_gwcap_for($kind, $lang, $key, $tx) : '';
}
function tl_text_set($kind, $lang, $key, $text)
{
    if ($kind === 'caption') {
        topup_caption_set($lang, $key, $text);
    } elseif ($kind === 'custom') {
        topup_custom_caption_set($lang, $key, $text);
    } elseif ($kind === 'linkmsg') {
        topup_linkmsg_set($lang, $key, $text);
    } else {
        topup_gwcap_set($kind, $lang, $key, $text);
    }
}
// one button's look from the form over what it has now: its own name, a plain
// emoji (which replaces a premium one; «remove» clears both), colour and side
function tl_btn_style(array $cur, array $p, bool $withEmoji = true, bool $withPos = true): array
{
    $new = $cur;
    $name = trim((string) ($p['name'] ?? ''));
    if ($name === '') {
        unset($new['label']);
    } else {
        $new['label'] = $name;
    }
    if ($withEmoji) {
        $emoji = trim((string) ($p['emoji'] ?? ''));
        if (!empty($p['emoji_del'])) {
            unset($new['emoji'], $new['emojiIcon']);
        } elseif ($emoji !== (string) ($cur['emoji'] ?? '')) {
            if ($emoji === '') {
                unset($new['emoji']);
            } else {
                $new['emoji'] = $emoji;
                unset($new['emojiIcon'], $new['noEmoji']);
            }
        }
    }
    $color = (string) ($p['color'] ?? '');
    if (in_array($color, ['primary', 'success', 'danger'], true)) {
        $new['color'] = $color;
    } else {
        unset($new['color']);
    }
    if ($withPos) {
        $pos = ($p['pos'] ?? '') === 'left' ? 'left' : 'right';
        if ($pos !== ((($cur['pos'] ?? '') === 'left') ? 'left' : 'right')) {
            $new['pos'] = $pos;
        }
    }
    return $new;
}
$emojiOk = fn($e) => $e === '' || (preg_match('/^\X$/u', $e) && !preg_match('/^[0-9a-zA-Z]$/', $e));
$invItems = [];
if (in_array('btnstyle', $has, true)) {
    if ($key === 'card') {
        // the ones the bot's card-invoice screens restyle
        foreach (card_invoice_btnstyle_items($tx, $lang) as $which => $name) {
            if (preg_match('/^(copyamt|copyCard\d*|paidReceipt|reissue)$/', (string) $which)) {
                $invItems[$which] = [$name, card_invoice_btnstyle_default_color($which), card_invoice_btnstyle_for($lang, $which)];
            }
        }
    } else {
        foreach (topup_invoice_btnstyle_items($key, $tx, $lang) as $which => $name) {
            $invItems[$which] = [$name, topup_invoice_btnstyle_default_color($which, $key), topup_invoice_btnstyle_for($lang, $key, $which)];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $err = null;
    $writes = [];
    if ($key === '' || $key !== (string) ($_POST['gw'] ?? '')) {
        // a gateway this language does not have: nothing falls to another one
        $err = $t['gwMissing'];
    }
    // 📝 texts
    foreach ($err === null ? $texts : [] as $kind => [$lbl, $def, $popup]) {
        $new = trim(str_replace("\r\n", "\n", (string) ($_POST['text'][$kind] ?? '')));
        if ($new === tl_text_own($kind, $lang, $key, $tx)) {
            continue;
        }
        if ($popup && containsHtmlMarkup($new)) {
            $err = sprintf($t['tlPopupPlain'], $lbl);
        } elseif (!$popup && !web_tg_html_ok($new)) {
            $err = $t['helpHtmlInvalid'];
        }
        $writes[] = fn() => tl_text_set($kind, $lang, $key, $new);
    }
    // 🎛 the amount screens' buttons
    foreach ($err === null ? topup_slot_defs($tx) : [] as $slot => $def) {
        $p = (array) ($_POST['slot'][$slot] ?? []);
        if (!$emojiOk(trim((string) ($p['emoji'] ?? '')))) {
            $err = $t['menuEmojiInvalid'];
            break;
        }
        if (containsHtmlMarkup((string) ($p['name'] ?? '')) || mb_strlen(trim((string) ($p['name'] ?? ''))) > 64) {
            $err = $t['menuNameInvalid'];
            break;
        }
        $cur = topup_btnstyle_for($lang, $key, $slot);
        $new = tl_btn_style($cur, $p);
        if ($new != $cur) {
            $writes[] = fn() => topup_btnstyle_set($lang, $key, $slot, $new);
        }
    }
    if ($err === null) {
        $flags = [
            ['swap_amount', topup_custom_back_swapped($lang, $key), fn() => topup_custom_back_toggle($lang, $key)],
            ['swap_custom', topup_custom_screen_swapped($lang, $key), fn() => topup_custom_screen_toggle($lang, $key)],
            ['simple', topup_emoji_simple_for($lang, $key), fn() => topup_emoji_simple_toggle($lang, $key)],
        ];
        foreach ($flags as [$name, $on, $toggle]) {
            if ((($_POST[$name] ?? '') === '1') !== $on) {
                $writes[] = $toggle;
            }
        }
        $cols = (int) ($_POST['columns'] ?? 0);
        if (in_array($cols, [1, 2, 3], true) && $cols !== topup_columns_for($lang, $key)) {
            $writes[] = fn() => topup_columns_set($lang, $key, $cols);
        }
    }
    // 📦 the package buttons, each by its place and its amount
    $pkgs = $key !== '' ? topup_packages_for($lang, $key) : [];
    foreach ($err === null ? (array) ($_POST['pkg'] ?? []) : [] as $i => $p) {
        $i = (int) $i;
        if (!isset($pkgs[$i]) || (string) $pkgs[$i]['amount'] !== (string) ($p['amount'] ?? '')) {
            $err = $t['tlPkgGone'];
            break;
        }
        $emoji = trim((string) ($p['emoji'] ?? ''));
        if (!$emojiOk($emoji)) {
            $err = $t['menuEmojiInvalid'];
            break;
        }
        $cur = $pkgs[$i];
        $emojiArg = !empty($p['emoji_del']) ? '' : ($emoji !== (string) ($cur['emoji'] ?? '') ? $emoji : null);
        $color = (string) ($p['color'] ?? '');
        $colorArg = $color !== (string) ($cur['color'] ?? '') ? $color : null;
        $pos = ($p['pos'] ?? '') === 'left' ? 'left' : 'right';
        $posArg = $pos !== ((($cur['pos'] ?? '') === 'left') ? 'left' : 'right') ? $pos : null;
        if ($emojiArg !== null || $colorArg !== null || $posArg !== null) {
            $writes[] = fn() => topup_packages_set_style($lang, $key, $i, $emojiArg, $colorArg, null, $posArg);
        }
    }
    // 🧾 the invoice's buttons
    foreach ($err === null ? $invItems : [] as $which => [$name, $defColor, $cur]) {
        $p = (array) ($_POST['inv'][$which] ?? []);
        if (containsHtmlMarkup((string) ($p['name'] ?? '')) || mb_strlen(trim((string) ($p['name'] ?? ''))) > 64) {
            $err = $t['menuNameInvalid'];
            break;
        }
        if ($key === 'card') {
            // label and colour only, as the bot's card screens offer
            $new = tl_btn_style($cur, $p, false, false);
            if ($new != $cur) {
                $writes[] = fn() => card_invoice_btnstyle_set($lang, $which, $new);
            }
            continue;
        }
        if (!$emojiOk(trim((string) ($p['emoji'] ?? '')))) {
            $err = $t['menuEmojiInvalid'];
            break;
        }
        $new = tl_btn_style($cur, $p, true, false);
        if ($new != $cur) {
            $writes[] = fn() => topup_invoice_btnstyle_set($lang, $key, $which, $new);
        }
    }
    if ($err === null) {
        foreach ($writes as $w) {
            $w();
        }
    }
    flash($err === null ? 'success' : 'error', $err ?? $t['refSaved']);
    header('Location: ' . $here);
    exit;
}

$pageTitle = $t['tlPageTitle'];
$pageLede = $t['tlPageLede'];
$activeNav = 'topuplook';
include __DIR__ . '/inc/layout_head.php';
echo web_lang_assets();
$colorSel = function ($name, $cur, $default) use ($t, $colorNames) {
    $h = '<select class="select" name="' . $name . '" style="width:auto"><option value="">' . htmlspecialchars(sprintf($t['tlColorDefault'], $colorNames[$default] ?? '⚪️')) . '</option>';
    foreach ($colorNames as $v => $label) {
        $h .= '<option value="' . $v . '"' . ($cur === $v ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
    }
    return $h . '</select>';
};
$posSel = fn($name, $cur) => '<select class="select" name="' . $name . '" style="width:auto"><option value="right">' . $t['menuPosRight'] . '</option><option value="left"' . ($cur === 'left' ? ' selected' : '') . '>' . $t['menuPosLeft'] . '</option></select>';
$emojiIn = function ($name, array $style) use ($t) {
    $h = '<input type="text" class="input" name="' . $name . '[emoji]" value="' . htmlspecialchars((string) ($style['emoji'] ?? '')) . '" style="width:64px;text-align:center" placeholder="' . (!empty($style['emojiIcon']) ? '✨' : '—') . '">';
    if (!empty($style['emoji']) || !empty($style['emojiIcon'])) {
        $h .= ' <label class="lang-chip" style="display:inline-flex"><input type="checkbox" name="' . $name . '[emoji_del]" value="1"> ' . $t['chnEmojiDel'] . '</label>';
    }
    return $h;
};
$chk = fn($name, $on, $label) => '<label class="lang-chip"><input type="checkbox" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . '> ' . htmlspecialchars($label) . '</label>';
?>

<?= web_lang_tabs($lang, fn($code) => 'topuplook.php?' . http_build_query(['lang' => $code])) ?>
<div class="lang-tabs fade-up">
  <?php foreach ($gws as $g): $live = gateway_allowed_for_lang($g, $lang) && gateway_globally_on($g); ?>
    <a href="topuplook.php?<?= http_build_query(['lang' => $lang, 'gw' => $g]) ?>" class="lang-tab<?= $g === $key ? ' on' : '' ?>"><?= $live ? '' : '⚪️ ' ?><?= htmlspecialchars(trim(strip_tags((string) ($names[$g] ?? $g)))) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($key === ''): ?>
  <div class="notice notice-warn"><?= $t['discNoGateways'] ?></div>
<?php else: ?>
<form method="POST" action="<?= $here ?>" class="fade-up">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="gw" value="<?= htmlspecialchars($key) ?>">

  <div class="card" style="margin-bottom:14px">
    <div class="card-head"><div><div class="card-title">📝 <?= $t['tlTexts'] ?></div><div class="card-subtitle"><?= $t['tlTextsSub'] ?></div></div></div>
    <div class="card-body">
      <?php foreach ($texts as $kind => [$lbl, $def, $popup, $hint]): $own = tl_text_own($kind, $lang, $key, $tx); ?>
        <div class="set-row" style="flex-direction:column;align-items:stretch">
          <div><div class="set-label"><?= $own !== '' ? '✏️ ' : '' ?><?= htmlspecialchars($lbl) ?></div><?php if ($hint !== ''): ?><div class="set-hint"><?= htmlspecialchars($hint) ?></div><?php endif; ?></div>
          <textarea class="textarea" name="text[<?= $kind ?>]" rows="<?= $popup ? 2 : 4 ?>" placeholder="<?= htmlspecialchars(mb_substr(strip_tags((string) $def), 0, 400)) ?>"><?= htmlspecialchars($own) ?></textarea>
          <details><summary class="field-hint"><?= $t['tlShowDefault'] ?></summary><pre style="white-space:pre-wrap;font-size:.8rem;margin:6px 0"><?= htmlspecialchars((string) $def) ?></pre></details>
        </div>
      <?php endforeach; ?>
      <div class="field-hint"><?= $t['tlTextsHint'] ?></div>
    </div>
  </div>

  <div class="card" style="margin-bottom:14px">
    <div class="card-head"><div><div class="card-title">🎛 <?= $t['tlSlots'] ?></div><div class="card-subtitle"><?= $t['tlSlotsSub'] ?></div></div></div>
    <div class="card-body">
      <div class="tbl-wrap"><table class="tbl-xl">
        <thead><tr><th><?= $t['menuColButton'] ?></th><th><?= $t['menuColName'] ?></th><th><?= $t['menuColEmoji'] ?></th><th><?= $t['menuEmojiPos'] ?></th><th><?= $t['menuColColor'] ?></th></tr></thead>
        <tbody>
        <?php foreach (topup_slot_defs($tx) as $slot => $def): $st = topup_btnstyle_for($lang, $key, $slot); $n = "slot[$slot]"; ?>
          <tr>
            <td class="cs"><?= htmlspecialchars((string) $def['label']) ?><div class="field-hint"><?= htmlspecialchars(strip_tags((string) ($tp['slotSectionLabel'][$def['screen']] ?? ''))) ?></div></td>
            <td><input type="text" class="input" name="<?= $n ?>[name]" value="<?= htmlspecialchars((string) ($st['label'] ?? '')) ?>" placeholder="<?= htmlspecialchars((string) $def['label']) ?>" maxlength="64"></td>
            <td style="white-space:nowrap"><?= $emojiIn($n, $st) ?></td>
            <td><?= $posSel($n . '[pos]', (string) ($st['pos'] ?? '')) ?></td>
            <td><?= $colorSel($n . '[color]', (string) ($st['color'] ?? ''), (string) $def['color']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="set-row"><div><div class="set-label"><?= $t['tlSwapAmount'] ?></div></div><div class="set-ctl"><?= $chk('swap_amount', topup_custom_back_swapped($lang, $key), $t['refOn']) ?></div></div>
      <div class="set-row"><div><div class="set-label"><?= $t['tlSwapCustom'] ?></div></div><div class="set-ctl"><?= $chk('swap_custom', topup_custom_screen_swapped($lang, $key), $t['refOn']) ?></div></div>
      <div class="set-row"><div><div class="set-label"><?= $t['menuSimple'] ?></div><div class="set-hint"><?= $t['looksSimpleHint'] ?></div></div><div class="set-ctl"><?= $chk('simple', topup_emoji_simple_for($lang, $key), $t['refOn']) ?></div></div>
      <div class="set-row"><div><div class="set-label"><?= $t['tlColumns'] ?></div></div>
        <div class="set-ctl"><select class="select" name="columns"><?php foreach ([1, 2, 3] as $c): ?><option value="<?= $c ?>"<?= topup_columns_for($lang, $key) === $c ? ' selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select></div></div>
    </div>
  </div>

  <?php $pkgs = topup_packages_for($lang, $key); ?>
  <div class="card" style="margin-bottom:14px">
    <div class="card-head"><div><div class="card-title">📦 <?= $t['tlPackages'] ?></div><div class="card-subtitle"><?= $t['tlPackagesSub'] ?></div></div></div>
    <div class="card-body">
      <?php if (!$pkgs): ?><div class="field-hint"><?= $t['tlNoPackages'] ?></div><?php else: ?>
      <div class="tbl-wrap"><table class="tbl-xl">
        <thead><tr><th><?= $t['menuColButton'] ?></th><th><?= $t['menuColEmoji'] ?></th><th><?= $t['menuEmojiPos'] ?></th><th><?= $t['menuColColor'] ?></th></tr></thead>
        <tbody>
        <?php foreach ($pkgs as $i => $pk): $n = "pkg[$i]"; ?>
          <tr>
            <td class="cs"><input type="hidden" name="<?= $n ?>[amount]" value="<?= htmlspecialchars((string) $pk['amount']) ?>"><?= htmlspecialchars(topup_package_label($pk, $lang)) ?></td>
            <td style="white-space:nowrap"><?= $emojiIn($n, $pk) ?></td>
            <td><?= $posSel($n . '[pos]', (string) ($pk['pos'] ?? '')) ?></td>
            <td><?= $colorSel($n . '[color]', (string) ($pk['color'] ?? ''), '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($invItems): ?>
  <div class="card" style="margin-bottom:14px">
    <div class="card-head"><div><div class="card-title">🧾 <?= $t['tlInvoice'] ?></div><div class="card-subtitle"><?= $key === 'card' ? $t['tlInvoiceCardSub'] : $t['tlInvoiceSub'] ?></div></div></div>
    <div class="card-body">
      <div class="tbl-wrap"><table class="tbl-xl">
        <thead><tr><th><?= $t['menuColButton'] ?></th><th><?= $t['menuColName'] ?></th><?php if ($key !== 'card'): ?><th><?= $t['menuColEmoji'] ?></th><?php endif; ?><th><?= $t['menuColColor'] ?></th></tr></thead>
        <tbody>
        <?php foreach ($invItems as $which => [$name, $defColor, $st]): $n = "inv[$which]"; ?>
          <tr>
            <td class="cs"><?= htmlspecialchars((string) $name) ?></td>
            <td><input type="text" class="input" name="<?= $n ?>[name]" value="<?= htmlspecialchars((string) ($st['label'] ?? '')) ?>" placeholder="<?= htmlspecialchars((string) $name) ?>" maxlength="64"></td>
            <?php if ($key !== 'card'): ?><td style="white-space:nowrap"><?= $emojiIn($n, $st) ?></td><?php endif; ?>
            <td><?= $colorSel($n . '[color]', (string) ($st['color'] ?? ''), (string) $defColor) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card"><div class="card-body"><div class="field-hint"><?= $t['looksHint'] ?></div></div>
    <div class="modal-foot" style="border-radius:0 0 10px 10px"><button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> <?= $t['bottextSaveBtn'] ?></button></div></div>
</form>
<?php endif; ?>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
