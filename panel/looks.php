<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/langsync.php';
require_auth();

// 🎨 شخصی‌سازی نمایش دکمه‌ها on the web, one language and one kind at a time:
// the buttons a customer picks from - panels, categories and products while
// buying, gateways while paying, the language picker, the tutorials'
// categories and tutorials. Their order, which two share a row, emoji,
// colour, own name and whether they show: setting.help_layout, through the
// bot's own help_layout_* helpers and by its rules.
$t = $textbotlang['panel'];
$fa = lang_tab_texts('fa');
$lang = web_lang_pick();
$kinds = [
    'panel' => '🖥 ' . $t['looksPanel'],
    'category' => '🗂 ' . $t['looksCategory'],
    'product' => '🛍 ' . $t['looksProduct'],
    'gateway' => '💳 ' . $t['looksGateway'],
    'langpick' => '🌐 ' . $t['looksLangpick'],
    'categories' => '📚 ' . $t['looksHelpCats'],
    'tutorials' => '📘 ' . $t['looksHelpTuts'],
];
$kind = (string) ($_GET['kind'] ?? $_POST['kind'] ?? '');
$kind = isset($kinds[$kind]) ? $kind : 'panel';
$items = [];
foreach (help_layout_items($lang, $kind) as $k => $label) {
    $items[(string) $k] = (string) $label;
}
$section = help_layout_section($lang, $kind);
$ordered = array_map('strval', help_layout_apply_order(array_keys($items), $section['order']));
$colors = ['' => $t['menuColorDefault'], 'primary' => '🔵 ' . $t['menuColorBlue'], 'success' => '🟢 ' . $t['menuColorGreen'], 'danger' => '🔴 ' . $t['menuColorRed']];
$here = 'looks.php?' . http_build_query(['lang' => $lang, 'kind' => $kind]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $err = null;
    $posted = (array) ($_POST['it'] ?? []);
    // the arrangement as sent: every button once, on its row
    $rows = [];
    $seen = [];
    foreach ($posted as $p) {
        $key = (string) ($p['key'] ?? '');
        if (!in_array($key, $ordered, true) || isset($seen[$key])) {
            continue;
        }
        $row = (string) ($p['row'] ?? '');
        if (!ctype_digit($row) || (int) $row < 1) {
            $err = $t['menuRowInvalid'];
            break;
        }
        $seen[$key] = $p;
        $rows[(int) $row][] = $key;
    }
    ksort($rows);
    if ($err === null && count($seen) !== count($ordered)) {
        $err = $t['menuIncomplete'];
    } elseif ($err === null && $rows && max(array_map('count', $rows)) > 2) {
        $err = $t['menuRowFull'];
    }
    // checked first, written after: a mistake leaves the buttons as they were
    $new = $section;
    if ($err === null) {
        $new['order'] = [];
        foreach ($rows as $r) {
            foreach ($r as $key) {
                $new['order'][] = $key;
                // two on one row are the pair the bot calls «half»
                $new['width'][$key] = count($r) === 2 ? 'half' : 'full';
            }
        }
        foreach ($seen as $key => $p) {
            $name = trim((string) ($p['name'] ?? ''));
            $emoji = trim((string) ($p['emoji'] ?? ''));
            if (mb_strlen($name) > 150) {
                $err = $t['looksNameTooLong'];
                break;
            }
            if ($emoji !== '' && (!preg_match('/^\X$/u', $emoji) || preg_match('/^[0-9a-zA-Z]$/', $emoji))) {
                $err = $t['menuEmojiInvalid'];
                break;
            }
            if ($name === '') {
                unset($new['rename'][$key]);
            } else {
                $new['rename'][$key] = $name;
            }
            if (!empty($p['emoji_del'])) {
                // «0» in the bot: no emoji at all, premium or plain
                unset($new['emoji'][$key], $new['emojiIcon'][$key]);
            } elseif ($emoji !== (string) ($section['emoji'][$key] ?? '')) {
                if ($emoji === '') {
                    unset($new['emoji'][$key]);
                } else {
                    // a plain emoji replaces a premium one, as in the bot
                    $new['emoji'][$key] = $emoji;
                    unset($new['emojiIcon'][$key]);
                }
            }
            $style = (string) ($p['style'] ?? '');
            if (in_array($style, ['primary', 'success', 'danger'], true)) {
                $new['color'][$key] = $style;
            } else {
                unset($new['color'][$key]);
            }
            if (($p['hidden'] ?? '') === '1') {
                $new['hidden'][$key] = true;
            } else {
                unset($new['hidden'][$key]);
            }
        }
        // the bot never lets the last one go: a keyboard with nothing on it
        // is a dead end for the customer
        if ($err === null && $ordered && !array_filter($ordered, fn($k) => empty($new['hidden'][$k]))) {
            $err = strip_tags($fa['Admin']['Help']['hideLastAlert']);
        }
        $new['emojiSimple'] = ($_POST['simple'] ?? '') === '1';
    }
    // the families on the payment method screen: each one's own name and
    // colour, and the caption of a family's own screen
    $famWrites = [];
    if ($err === null && $kind === 'gateway') {
        foreach (array_keys(gateway_groups()) as $group) {
            $p = (array) ($_POST['fam'][$group] ?? []);
            $name = trim((string) ($p['name'] ?? ''));
            if (containsHtmlMarkup($name) || mb_strlen($name) > 64) {
                $err = $t['menuNameInvalid'];
                break;
            }
            $cur = topup_group_btnstyle_for($lang, $group);
            $style = $cur;
            $style['label'] = $name;
            // blue, green or red - the three the bot's colour button cycles
            $style['color'] = in_array($p['color'] ?? '', ['primary', 'success', 'danger'], true) ? $p['color'] : 'primary';
            if (($style['label'] ?? '') !== ($cur['label'] ?? '') || $style['color'] !== ($cur['color'] ?? 'primary')) {
                $famWrites[] = fn() => topup_group_btnstyle_set($lang, $group, $style);
            }
        }
        $cap = trim(str_replace("\r\n", "\n", (string) ($_POST['fam_caption'] ?? '')));
        if ($err === null && $cap !== (topup_group_caption_has_override($lang) ? (string) topup_group_caption_for($lang, '') : '')) {
            if (!web_tg_html_ok($cap)) {
                $err = $t['helpHtmlInvalid'];
            }
            $famWrites[] = fn() => topup_group_caption_set($lang, $cap);
        }
    }
    if ($err === null) {
        help_layout_set_section($lang, $kind, $new);
        if ($kind === 'gateway' && (($_POST['groups'] ?? '') === '1') !== topup_group_methods_on($lang)) {
            topup_group_methods_set($lang, ($_POST['groups'] ?? '') === '1');
        }
        foreach ($famWrites as $w) {
            $w();
        }
    }
    flash($err === null ? 'success' : 'error', $err ?? $t['refSaved']);
    header('Location: ' . $here);
    exit;
}

$pageTitle = $t['looksPageTitle'];
$pageLede = $t['looksPageLede'];
$activeNav = 'looks';
include __DIR__ . '/inc/layout_head.php';
echo web_lang_assets();
$rowsNow = help_layout_chunk_rows($ordered, array_combine($ordered, $ordered) ?: [], $section['width']);
// what the customer reads on a button: its emoji (✨ = premium) and its name
$face = function ($key) use ($items, $section) {
    $e = help_layout_emoji_prefix($key, $section);
    return $e['prefix'] . ($section['rename'][$key] ?? $items[$key]) . ($e['icon'] !== '' ? ' ✨' : '');
};
?>

<?= web_lang_tabs($lang, fn($code) => 'looks.php?' . http_build_query(['lang' => $code, 'kind' => $kind])) ?>
<div class="lang-tabs fade-up">
  <?php foreach ($kinds as $k => $label): ?>
    <a href="looks.php?<?= http_build_query(['lang' => $lang, 'kind' => $k]) ?>" class="lang-tab<?= $k === $kind ? ' on' : '' ?>"><?= htmlspecialchars($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$ordered): ?>
  <div class="notice notice-warn"><?= $t['looksEmpty'] ?></div>
<?php else: ?>
<form method="POST" action="<?= $here ?>" class="fade-up">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="kind" value="<?= $kind ?>">
  <div class="card" style="margin-bottom:14px">
    <div class="card-head"><div><div class="card-title">🧩 <?= $t['menuArrange'] ?></div><div class="card-subtitle"><?= $t['menuArrangeSub'] ?></div></div></div>
    <div class="card-body">
      <div class="kb-grid">
        <?php foreach ($rowsNow as $r => $row): ?>
          <div class="kb-row">
            <?php foreach ($row as $key): $key = (string) $key; $i = array_search($key, $ordered, true); $c = ['primary' => '#2b6ef2', 'success' => '#16a34a', 'danger' => '#dc2626'][$section['color'][$key] ?? ''] ?? ''; $off = !empty($section['hidden'][$key]); ?>
              <div class="kb-chip<?= $c === '' ? ' plain' : '' ?><?= $off ? ' off' : '' ?>" draggable="true"<?= $c !== '' ? ' style="background:' . $c . '"' : '' ?>>
                <input type="hidden" name="it[<?= $i ?>][key]" value="<?= htmlspecialchars($key) ?>"><input type="hidden" name="it[<?= $i ?>][row]" value="<?= $r + 1 ?>" class="kb-rowin">
                <?= $off ? '🚫 ' : '' ?><?= htmlspecialchars($face($key)) ?>
              </div>
            <?php endforeach; ?>
            <?php if (count($row) < 2): ?><div class="kb-slot">＋</div><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <div class="kb-newrow"><?= $t['menuNewRow'] ?></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div><div class="card-title">🔘 <?= $t['menuButtons'] ?></div><div class="card-subtitle"><?= $t['menuButtonsSub'] ?></div></div></div>
    <div class="card-body">
      <div class="tbl-wrap"><table class="tbl-xl">
        <thead><tr><th><?= $t['menuColButton'] ?></th><th><?= $t['menuColName'] ?></th><th><?= $t['menuColEmoji'] ?></th><th><?= $t['menuColColor'] ?></th><th><?= $t['menuColHidden'] ?></th></tr></thead>
        <tbody>
        <?php foreach ($ordered as $i => $key): $n = "it[$i]"; $icon = !empty($section['emojiIcon'][$key]); ?>
          <tr>
            <td class="cs"><?= htmlspecialchars($items[$key]) ?></td>
            <td><input type="text" class="input" name="<?= $n ?>[name]" value="<?= htmlspecialchars((string) ($section['rename'][$key] ?? '')) ?>" placeholder="<?= htmlspecialchars($items[$key]) ?>" maxlength="150"></td>
            <td style="white-space:nowrap"><input type="text" class="input" name="<?= $n ?>[emoji]" value="<?= htmlspecialchars((string) ($section['emoji'][$key] ?? '')) ?>" style="width:64px;text-align:center" placeholder="<?= $icon ? '✨' : '—' ?>">
              <?php if ($icon || !empty($section['emoji'][$key])): ?><label class="lang-chip" style="display:inline-flex;margin-top:4px"><input type="checkbox" name="<?= $n ?>[emoji_del]" value="1"> <?= $t['chnEmojiDel'] ?></label><?php endif; ?></td>
            <td><select class="select" name="<?= $n ?>[style]"><?php foreach ($colors as $v => $label): ?><option value="<?= $v ?>"<?= (string) ($section['color'][$key] ?? '') === $v ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></td>
            <td><label class="lang-chip"><input type="hidden" name="<?= $n ?>[hidden]" value="0"><input type="checkbox" name="<?= $n ?>[hidden]" value="1"<?= !empty($section['hidden'][$key]) ? ' checked' : '' ?>> <?= $t['menuHidden'] ?></label></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="set-row"><div><div class="set-label"><?= $t['menuSimple'] ?></div><div class="set-hint"><?= $t['looksSimpleHint'] ?></div></div>
        <div class="set-ctl"><label class="lang-chip"><input type="checkbox" name="simple" value="1"<?= $section['emojiSimple'] ? ' checked' : '' ?>> <?= $t['refOn'] ?></label></div></div>
      <?php if ($kind === 'gateway'): $famTx = lang_tab_texts($lang); ?>
        <div class="set-row"><div><div class="set-label"><?= htmlspecialchars($fa['Admin']['BtnStyle']['groupMethodsBtn']) ?></div><div class="set-hint"><?= $t['looksGroupsHint'] ?></div></div>
          <div class="set-ctl"><label class="lang-chip"><input type="checkbox" name="groups" value="1"<?= topup_group_methods_on($lang) ? ' checked' : '' ?>> <?= $t['refOn'] ?></label></div></div>
        <div class="set-row" style="flex-direction:column;align-items:stretch"><div><div class="set-label">🗂 <?= $t['looksFamilies'] ?></div><div class="set-hint"><?= $t['looksFamiliesHint'] ?></div></div>
          <div class="tbl-wrap"><table class="tbl-xl"><tbody>
          <?php foreach (array_keys(gateway_groups()) as $group): $fs = topup_group_btnstyle_for($lang, $group); $fname = trim(strip_tags((string) gateway_group_label($group, $famTx))); ?>
            <tr>
              <td class="cs"><?= htmlspecialchars($fname) ?></td>
              <td><input type="text" class="input" name="fam[<?= $group ?>][name]" value="<?= htmlspecialchars((string) ($fs['label'] ?? '')) ?>" placeholder="<?= htmlspecialchars($fname) ?>" maxlength="64"></td>
              <td><select class="select" name="fam[<?= $group ?>][color]"><?php foreach (['primary' => '🔵 ' . $t['menuColorBlue'], 'success' => '🟢 ' . $t['menuColorGreen'], 'danger' => '🔴 ' . $t['menuColorRed']] as $v => $label): ?><option value="<?= $v ?>"<?= (string) ($fs['color'] ?? 'primary') === $v ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></td>
            </tr>
          <?php endforeach; ?>
          </tbody></table></div>
          <div class="set-label" style="margin-top:8px"><?= $t['looksFamilyCaption'] ?></div><div class="set-hint"><?= $t['looksFamilyCaptionHint'] ?></div>
          <textarea class="textarea" name="fam_caption" rows="3" placeholder="<?= htmlspecialchars(strip_tags((string) $famTx['users']['Balance']['groupMethodCaption'])) ?>"><?= htmlspecialchars(topup_group_caption_has_override($lang) ? (string) topup_group_caption_for($lang, '') : '') ?></textarea>
        </div>
      <?php endif; ?>
      <div class="field-hint"><?= $t['looksHint'] ?></div>
    </div>
    <div class="modal-foot" style="border-radius:0 0 10px 10px"><button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> <?= $t['bottextSaveBtn'] ?></button></div>
  </div>
</form>
<script src="js/kbgrid.js"></script>
<?php endif; ?>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
