<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/langsync.php';
require_auth();

// 🎨 what a customer's services look like after buying, one language at a
// time: the buttons under a service's status, how a new config is delivered
// (per panel) and its buttons, the 🔋 warnings before a service runs out, and
// the sticker the ❌ بستن buttons play. The bot's own stores and setters
// (statusbtn_*, config_delivery_*, configdisplay_*, volumepct_*,
// close_sticker_*), by its rules.
$t = $textbotlang['panel'];
$lang = web_lang_pick();
$tx = lang_tab_texts($lang);
$parts = ['status' => '🔘 ' . $t['svcStatus'], 'delivery' => '📦 ' . $t['svcDelivery'], 'tiers' => '🔋 ' . $t['svcTiers'], 'close' => '🖼 ' . $t['svcClose']];
$part = (string) ($_GET['part'] ?? $_POST['part'] ?? '');
$part = isset($parts[$part]) ? $part : 'status';
$kinds = ['purchase' => '🛒 ' . $t['svcKindBuy'], 'usertest' => '🎁 ' . $t['svcKindTest'], 'affrw' => '👥 ' . $t['svcKindReward']];
$kind = (string) ($_GET['kind'] ?? $_POST['kind'] ?? '');
$kind = isset($kinds[$kind]) ? $kind : 'purchase';
// the config-columns store names the purchase kind «buy»
$cdKind = ['purchase' => 'buy', 'usertest' => 'usertest', 'affrw' => 'affrw'][$kind];
$here = 'svclook.php?' . http_build_query(['lang' => $lang, 'part' => $part, 'kind' => $part === 'delivery' ? $kind : null]);
$colorNames = ['primary' => '🔵 ' . $t['menuColorBlue'], 'success' => '🟢 ' . $t['menuColorGreen'], 'danger' => '🔴 ' . $t['menuColorRed']];
$emojiOk = fn($e) => $e === '' || (preg_match('/^\X$/u', $e) && !preg_match('/^[0-9a-zA-Z]$/', $e));
$nameOk = fn($s) => !containsHtmlMarkup($s) && mb_strlen($s) <= 64;
$closeNames = ['bottext.btnCloseBuy' => $t['svcCloseBuy'], 'bottext.btnCloseTopup' => $t['svcCloseTopup'], 'bottext.btnCloseAccount' => $t['svcCloseAccount'],
    'bottext.btnCloseTest' => $t['svcCloseTest'], 'bottext.btnCloseHelp' => $t['svcCloseHelp'], 'servclose' => $t['svcCloseServices']];

// a 🔋 tier's own wording in one language, back to the default when emptied
function svc_tier_word($index, $field, $lang, $value)
{
    $tiers = volumepct_tiers_map(true);
    if (!isset($tiers[$index])) {
        return;
    }
    if ($value === '') {
        unset($tiers[$index][$field][$lang]);
        if (empty($tiers[$index][$field])) {
            unset($tiers[$index][$field]);
        }
    } else {
        $tiers[$index][$field][$lang] = $value;
    }
    volumepct_tiers_save($tiers);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $err = null;
    $writes = [];
    if ($part === 'status') {
        foreach (statusbtn_defs($tx) as $key => $def) {
            $p = (array) ($_POST['sb'][$key] ?? []);
            $ov = statusbtn_override($lang, $key);
            $text = trim((string) ($p['text'] ?? ''));
            $emoji = trim((string) ($p['emoji'] ?? ''));
            if (!$nameOk($text)) {
                $err = $t['menuNameInvalid'];
                break;
            }
            if (!$emojiOk($emoji)) {
                $err = $t['menuEmojiInvalid'];
                break;
            }
            if ($text !== (string) ($ov['text'] ?? '')) {
                $writes[] = fn() => statusbtn_set_field($lang, $key, 'text', $text);
            }
            if (!empty($p['emoji_del'])) {
                if (!empty($ov['emoji']) || !empty($ov['emojiIcon'])) {
                    $writes[] = fn() => statusbtn_set_field($lang, $key, 'emoji', '');
                }
            } elseif ($emoji !== (string) ($ov['emoji'] ?? '')) {
                // a plain emoji replaces a premium one, as in the bot
                $writes[] = fn() => statusbtn_set_field($lang, $key, 'emoji', $emoji);
            }
            $pos = ($p['pos'] ?? '') === 'left' ? 'left' : 'right';
            if ($pos !== ((($ov['pos'] ?? '') === 'left') ? 'left' : 'right')) {
                $writes[] = fn() => statusbtn_set_field($lang, $key, 'pos', $pos);
            }
            $style = in_array($p['style'] ?? '', ['primary', 'success', 'danger'], true) ? $p['style'] : '';
            if ($style !== (string) ($ov['style'] ?? '')) {
                $writes[] = fn() => statusbtn_set_field($lang, $key, 'style', $style);
            }
            $simple = ($p['simple'] ?? '') === '1';
            if ($simple !== !empty($ov['simple'])) {
                $writes[] = fn() => statusbtn_set_field($lang, $key, 'simple', $simple);
            }
        }
    } elseif ($part === 'delivery') {
        foreach ((array) select("marzban_panel", "*", null, null, "fetchAll") as $panel) {
            if (!is_array($panel)) {
                continue;
            }
            $code = (string) $panel['code_panel'];
            $p = (array) ($_POST['pn'][$code] ?? []);
            $mode = ($p['mode'] ?? '') === '2' ? '2' : '1';
            if ($mode !== config_delivery_mode($kind, $code, $lang)) {
                $writes[] = fn() => config_delivery_set_mode($kind, $code, $mode, $lang);
            }
            // the QR goes with the full message only
            $qr = ($p['qr'] ?? '') === '1';
            if ($mode === '1' && $qr !== config_delivery_qr_on($kind, $code, $lang)) {
                $writes[] = fn() => config_delivery_set_qr($kind, $code, $qr, $lang);
            }
        }
        foreach (configdisplay_element_defs($tx) as $idx => $def) {
            $p = (array) ($_POST['el'][$idx] ?? []);
            $ov = configdisplay_element_override($lang, $idx, $cdKind);
            $text = trim((string) ($p['text'] ?? ''));
            if (!$nameOk($text)) {
                $err = $t['menuNameInvalid'];
                break;
            }
            if ($text !== (string) ($ov['text'] ?? '')) {
                $writes[] = fn() => configdisplay_element_set_text($lang, $idx, $text, $cdKind);
            }
            $style = in_array($p['style'] ?? '', ['primary', 'success', 'danger'], true) ? $p['style'] : '';
            if ($style !== (string) ($ov['style'] ?? '')) {
                $writes[] = fn() => configdisplay_element_set_style($lang, $idx, $style, $cdKind);
            }
        }
        $nameFirst = ($_POST['order'] ?? '') === 'name_first';
        if ($nameFirst !== config_col_name_first($lang, $cdKind)) {
            $writes[] = fn() => config_col_set_order($lang, $nameFirst, $cdKind);
        }
    } elseif ($part === 'tiers') {
        $tiers = volumepct_tiers_map(true);
        $meta = volumepct_kind_meta();
        $drop = [];
        foreach ((array) ($_POST['tier'] ?? []) as $i => $p) {
            $i = (int) $i;
            if (!isset($tiers[$i]) || volumepct_tier_kind($tiers[$i]) !== (string) ($p['kind'] ?? '')) {
                $err = $t['svcTierGone'];
                break;
            }
            $tier = $tiers[$i];
            $m = $meta[volumepct_tier_kind($tier)];
            if (!empty($p['del']) && !$m['single']) {
                $drop[] = $i;
                continue;
            }
            if (!$m['single']) {
                $pct = trim((string) ($p['pct'] ?? ''));
                if (!ctype_digit($pct) || (int) $pct > $m['max']) {
                    $err = sprintf($t['svcTierPctInvalid'], $m['max']);
                    break;
                }
                if ((int) $pct !== (int) ($tier['pct'] ?? 0)) {
                    $writes[] = fn() => volumepct_tier_set_pct($i, (int) $pct);
                }
            }
            $text = trim(str_replace("\r\n", "\n", (string) ($p['text'] ?? '')));
            if ($text !== (string) ($tier['text'][$lang] ?? '')) {
                if (!web_tg_html_ok($text)) {
                    $err = $t['helpHtmlInvalid'];
                    break;
                }
                $writes[] = fn() => svc_tier_word($i, 'text', $lang, $text);
            }
            $label = trim((string) ($p['label'] ?? ''));
            if (!$nameOk($label)) {
                $err = $t['menuNameInvalid'];
                break;
            }
            if ($label !== (string) ($tier['btnLabel'][$lang] ?? '')) {
                $writes[] = fn() => svc_tier_word($i, 'btnLabel', $lang, $label);
            }
            // the look is one for every language, as in the bot
            $emoji = trim((string) ($p['emoji'] ?? ''));
            if (!$emojiOk($emoji)) {
                $err = $t['menuEmojiInvalid'];
                break;
            }
            $style = in_array($p['style'] ?? '', ['primary', 'success', 'danger'], true) ? $p['style'] : null;
            $emojiArg = !empty($p['emoji_del']) ? '' : ($emoji !== (string) ($tier['emoji'] ?? '') ? $emoji : null);
            $pos = ($p['pos'] ?? '') === 'left' ? 'left' : 'right';
            $posArg = $pos !== ((($tier['pos'] ?? '') === 'left') ? 'left' : 'right') ? $pos : null;
            $simple = ($p['simple'] ?? '') === '1';
            $hidden = ($p['hidden'] ?? '') === '1';
            $styleArg = ($style !== null && $style !== (string) ($tier['style'] ?? '')) ? $style : null;
            if ($styleArg !== null || $emojiArg !== null || $posArg !== null || $simple !== !empty($tier['simple']) || $hidden !== !empty($tier['hidden'])) {
                // an emoji given (or '' to remove) also drops a premium one
                $writes[] = fn() => volumepct_tier_set_style($i, $styleArg, $emojiArg, null, $posArg, $simple, $hidden);
            }
        }
        // a new tier: the bot's «new threshold» step
        $newKind = (string) ($_POST['new_kind'] ?? '');
        $newPct = trim((string) ($_POST['new_pct'] ?? ''));
        if ($err === null && $newPct !== '') {
            if (!isset($meta[$newKind]) || $meta[$newKind]['single']) {
                $err = $t['svcTierGone'];
            } elseif (!ctype_digit($newPct) || (int) $newPct > $meta[$newKind]['max']) {
                $err = sprintf($t['svcTierPctInvalid'], $meta[$newKind]['max']);
            } else {
                $writes[] = fn() => volumepct_tier_add((int) $newPct, $newKind);
            }
        }
        // the one-of-a-kind messages (end of time, end of volume) exist once
        // their first setting is saved, as the bot creates them on first open
        foreach ((array) ($_POST['single'] ?? []) as $sKind => $p) {
            if ($err !== null || !isset($meta[$sKind]) || !$meta[$sKind]['single'] || volumepct_tiers_for_kind($sKind)) {
                continue;
            }
            $text = trim(str_replace("\r\n", "\n", (string) ($p['text'] ?? '')));
            $label = trim((string) ($p['label'] ?? ''));
            if ($text === '' && $label === '') {
                continue;
            }
            if (!web_tg_html_ok($text)) {
                $err = $t['helpHtmlInvalid'];
            } elseif (!$nameOk($label)) {
                $err = $t['menuNameInvalid'];
            }
            $writes[] = function () use ($sKind, $lang, $text, $label) {
                $i = volumepct_singleton_index($sKind);
                svc_tier_word($i, 'text', $lang, $text);
                svc_tier_word($i, 'btnLabel', $lang, $label);
            };
        }
        // removals last, from the end, so no index moves under another write
        rsort($drop);
        foreach ($drop as $i) {
            $writes[] = fn() => volumepct_tier_remove($i);
        }
    } else {
        foreach ($closeNames as $key => $name) {
            $p = (array) ($_POST['cs'][$key] ?? []);
            $cur = close_sticker_settings($key, true, $lang);
            $on = ($p['on'] ?? '') === '1';
            $dur = trim((string) ($p['duration'] ?? ''));
            if (!ctype_digit($dur) || (int) $dur < 1 || (int) $dur > 10) {
                $err = $t['svcCloseDurInvalid'];
                break;
            }
            if ($on !== $cur['enabled'] || (int) $dur !== (int) $cur['duration']) {
                $writes[] = fn() => close_sticker_save($key, ['enabled' => $on, 'duration' => (int) $dur], $lang);
            }
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

$pageTitle = $t['svcPageTitle'];
$pageLede = $t['svcPageLede'];
$activeNav = 'svclook';
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
$chk = fn($name, $on, $label) => '<label class="lang-chip"><input type="hidden" name="' . $name . '" value="0"><input type="checkbox" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . '> ' . htmlspecialchars($label) . '</label>';
$saveBtn = '<div class="modal-foot" style="border-radius:0 0 10px 10px"><button type="submit" class="btn btn-primary">' . icon('check', 13) . ' ' . $t['bottextSaveBtn'] . '</button></div>';
?>

<?= web_lang_tabs($lang, fn($code) => 'svclook.php?' . http_build_query(['lang' => $code, 'part' => $part])) ?>
<div class="lang-tabs fade-up">
  <?php foreach ($parts as $k => $label): ?><a href="svclook.php?<?= http_build_query(['lang' => $lang, 'part' => $k]) ?>" class="lang-tab<?= $k === $part ? ' on' : '' ?>"><?= htmlspecialchars($label) ?></a><?php endforeach; ?>
</div>

<form method="POST" action="<?= $here ?>" class="card fade-up">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="part" value="<?= $part ?>"><input type="hidden" name="kind" value="<?= $kind ?>">
<?php if ($part === 'status'): ?>
  <div class="card-head"><div><div class="card-title">🔘 <?= $t['svcStatus'] ?></div><div class="card-subtitle"><?= $t['svcStatusSub'] ?></div></div></div>
  <div class="card-body"><div class="tbl-wrap"><table class="tbl-xl">
    <thead><tr><th><?= $t['menuColButton'] ?></th><th><?= $t['menuColName'] ?></th><th><?= $t['menuColEmoji'] ?></th><th><?= $t['menuEmojiPos'] ?></th><th><?= $t['menuColColor'] ?></th><th><?= $t['menuSimple'] ?></th></tr></thead>
    <tbody>
    <?php foreach (statusbtn_defs($tx) as $key => $def): $ov = statusbtn_override($lang, $key); $n = 'sb[' . htmlspecialchars($key) . ']'; ?>
      <tr>
        <td class="cs"><?= htmlspecialchars($def['name']) ?></td>
        <td><input type="text" class="input" name="<?= $n ?>[text]" value="<?= htmlspecialchars((string) ($ov['text'] ?? '')) ?>" placeholder="<?= htmlspecialchars((string) $def['text']) ?>" maxlength="64"></td>
        <td style="white-space:nowrap"><?= $emojiIn($n, $ov) ?></td>
        <td><?= $posSel($n . '[pos]', (string) ($ov['pos'] ?? '')) ?></td>
        <td><?= $colorSel($n . '[style]', (string) ($ov['style'] ?? ''), (string) $def['style']) ?></td>
        <td><?= $chk($n . '[simple]', !empty($ov['simple']), $t['refOn']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <div class="field-hint"><?= $t['looksHint'] ?></div></div>
<?php elseif ($part === 'delivery'): $panels = array_filter((array) select("marzban_panel", "*", null, null, "fetchAll"), 'is_array'); ?>
  <div class="card-head"><div><div class="card-title">📦 <?= $t['svcDelivery'] ?></div><div class="card-subtitle"><?= $t['svcDeliverySub'] ?></div></div></div>
  <div class="card-body">
    <div class="lang-tabs"><?php foreach ($kinds as $k => $label): ?><a href="svclook.php?<?= http_build_query(['lang' => $lang, 'part' => 'delivery', 'kind' => $k]) ?>" class="lang-tab<?= $k === $kind ? ' on' : '' ?>"><?= htmlspecialchars($label) ?></a><?php endforeach; ?></div>
    <?php if (!$panels): ?><div class="field-hint"><?= $t['srvEmpty'] ?></div><?php endif; ?>
    <?php foreach ($panels as $panel): $code = (string) $panel['code_panel']; $mode = config_delivery_mode($kind, $code, $lang); $n = 'pn[' . htmlspecialchars($code) . ']'; ?>
      <div class="set-row"><div><div class="set-label">🖥 <?= htmlspecialchars((string) $panel['name_panel']) ?></div><?php if (($panel['config'] ?? '') !== 'onconfig'): ?><div class="set-hint">⚠️ <?= $t['svcMode2Off'] ?></div><?php endif; ?></div>
        <div class="set-ctl lang-chips"><select class="select" name="<?= $n ?>[mode]"><option value="1"><?= $t['svcMode1'] ?></option><option value="2"<?= $mode === '2' ? ' selected' : '' ?>><?= $t['svcMode2'] ?></option></select>
          <?= $chk($n . '[qr]', config_delivery_qr_on($kind, $code, $lang), '📷 QR') ?></div></div>
    <?php endforeach; ?>
    <div class="set-row" style="flex-direction:column;align-items:stretch"><div><div class="set-label">🎨 <?= $t['svcElements'] ?></div><div class="set-hint"><?= $t['svcElementsSub'] ?></div></div>
      <div class="tbl-wrap"><table class="tbl-xl"><tbody>
      <?php foreach (configdisplay_element_defs($tx) as $idx => $def): $ov = configdisplay_element_override($lang, $idx, $cdKind); ?>
        <tr><td class="cs"><?= htmlspecialchars($def['name']) ?></td>
          <td><input type="text" class="input" name="el[<?= $idx ?>][text]" value="<?= htmlspecialchars((string) ($ov['text'] ?? '')) ?>" placeholder="<?= htmlspecialchars((string) $def['text']) ?>" maxlength="64"></td>
          <td><?= $colorSel('el[' . $idx . '][style]', (string) ($ov['style'] ?? ''), '') ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div></div>
    <div class="set-row"><div><div class="set-label"><?= $t['svcOrder'] ?></div></div>
      <div class="set-ctl"><select class="select" name="order"><option value="config_first"><?= $t['svcOrderConfig'] ?></option><option value="name_first"<?= config_col_name_first($lang, $cdKind) ? ' selected' : '' ?>><?= $t['svcOrderName'] ?></option></select></div></div>
  </div>
<?php elseif ($part === 'tiers'): $meta = volumepct_kind_meta(); ?>
  <div class="card-head"><div><div class="card-title">🔋 <?= $t['svcTiers'] ?></div><div class="card-subtitle"><?= $t['svcTiersSub'] ?></div></div></div>
  <div class="card-body">
    <?php foreach ($meta as $mk => $m): $list = volumepct_tiers_for_kind($mk); ?>
      <h4 style="margin:14px 0 6px"><?= htmlspecialchars($m['label']) ?></h4>
      <?php if ($m['single'] && !$list): ?>
        <div class="set-row" style="flex-direction:column;align-items:stretch">
          <textarea class="textarea" name="single[<?= $mk ?>][text]" rows="3" placeholder="<?= htmlspecialchars(mb_substr(strip_tags((string) volumepct_tier_default_text($tx, $mk)), 0, 300)) ?>"></textarea>
          <input type="text" class="input" name="single[<?= $mk ?>][label]" placeholder="<?= $t['svcTierLabel'] ?>" maxlength="64" style="margin-top:6px">
        </div>
      <?php endif; ?>
      <?php foreach ($list as $i => $tier): $n = "tier[$i]"; ?>
        <div class="set-row" style="flex-direction:column;align-items:stretch">
          <input type="hidden" name="<?= $n ?>[kind]" value="<?= $mk ?>">
          <div class="lang-chips" style="align-items:center">
            <?php if (!$m['single']): ?><b><?= htmlspecialchars(sprintf($m['rowLabel'], (int) ($tier['pct'] ?? 0))) ?></b>
              <input type="text" class="input" name="<?= $n ?>[pct]" value="<?= (int) ($tier['pct'] ?? 0) ?>" inputmode="numeric" style="width:90px" title="<?= htmlspecialchars($m['thresholdBtn'] !== '' ? sprintf($m['thresholdBtn'], (int) ($tier['pct'] ?? 0)) : '') ?>"><?php endif; ?>
            <span class="field-hint">🌐 <?= $t['svcTierLookShared'] ?></span>
            <?= $emojiIn($n, $tier) ?><?= $posSel($n . '[pos]', (string) ($tier['pos'] ?? '')) ?><?= $colorSel($n . '[style]', (string) ($tier['style'] ?? ''), '') ?>
            <?= $chk($n . '[simple]', !empty($tier['simple']), $t['menuSimple']) ?><?= $chk($n . '[hidden]', !empty($tier['hidden']), $t['menuHidden']) ?>
            <?php if (!$m['single']): ?><label class="lang-chip"><input type="checkbox" name="<?= $n ?>[del]" value="1"> <?= $t['svcTierDel'] ?></label><?php endif; ?>
          </div>
          <textarea class="textarea" name="<?= $n ?>[text]" rows="3" style="margin-top:6px" placeholder="<?= htmlspecialchars(mb_substr(strip_tags((string) volumepct_tier_default_text($tx, $mk)), 0, 300)) ?>"><?= htmlspecialchars((string) ($tier['text'][$lang] ?? '')) ?></textarea>
          <input type="text" class="input" name="<?= $n ?>[label]" value="<?= htmlspecialchars((string) ($tier['btnLabel'][$lang] ?? '')) ?>" placeholder="<?= $t['svcTierLabel'] ?>" maxlength="64" style="margin-top:6px">
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <div class="set-row"><div><div class="set-label">➕ <?= $t['svcTierAdd'] ?></div><div class="set-hint"><?= $t['svcTierAddHint'] ?></div></div>
      <div class="set-ctl lang-chips"><select class="select" name="new_kind"><?php foreach ($meta as $mk => $m): if ($m['single']) continue; ?><option value="<?= $mk ?>"><?= htmlspecialchars($m['label']) ?></option><?php endforeach; ?></select>
        <input type="text" class="input" name="new_pct" inputmode="numeric" style="width:90px"></div></div>
    <div class="field-hint"><?= $t['svcTiersHint'] ?></div>
  </div>
<?php else: ?>
  <div class="card-head"><div><div class="card-title">🖼 <?= $t['svcClose'] ?></div><div class="card-subtitle"><?= $t['svcCloseSub'] ?></div></div></div>
  <div class="card-body">
    <?php foreach ($closeNames as $key => $name): $cs = close_sticker_settings($key, true, $lang); ?>
      <div class="set-row"><div><div class="set-label"><?= htmlspecialchars($name) ?></div><div class="set-hint"><?= $cs['file_id'] === close_sticker_default_file_id() ? $t['svcCloseDefault'] : $t['svcCloseOwn'] ?></div></div>
        <div class="set-ctl lang-chips"><?= $chk('cs[' . $key . '][on]', $cs['enabled'], $t['refOn']) ?>
          <input type="text" class="input" name="cs[<?= $key ?>][duration]" value="<?= (int) $cs['duration'] ?>" inputmode="numeric" style="width:70px" title="<?= $t['svcCloseDur'] ?>"> <span class="field-hint"><?= $t['svcCloseDur'] ?></span></div></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
  <?= $saveBtn ?>
</form>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
