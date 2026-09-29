<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/langsync.php';
require_auth();

// Every other button 🎨 can restyle (genbtn_*: cancel/confirm, close and back
// buttons, the buttons under a message...), one language at a time: its own
// text, colour, emoji and side, simple mode, and - where the bot allows it -
// hidden. Saved into setting.button_edit through the bot's own setters.
$t = $textbotlang['panel'];
$lang = web_lang_pick();
$tx = lang_tab_texts($lang);
$colors = ['' => $t['menuColorDefault'], 'primary' => '🔵 ' . $t['menuColorBlue'], 'success' => '🟢 ' . $t['menuColorGreen'], 'danger' => '🔴 ' . $t['menuColorRed']];
$itemLabels = [];
foreach ($textbotlang['bottext']['items'] as $it) {
    if (!empty($it['key'])) {
        $itemLabels[$it['key']] = $it['label'] ?? $it['key'];
    }
}
// alias => [key, defs]
$groups = [];
foreach (genbtn_alias_map() as $alias => $key) {
    $defs = genbtn_defs($alias, $tx);
    if ($defs) {
        $groups[$alias] = [$key, $defs];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $alias = (string) ($_POST['alias'] ?? '');
    $err = null;
    if (!isset($groups[$alias])) {
        $err = $t['btnGroupMissing'];
    } else {
        [$key, $defs] = $groups[$alias];
        $posted = (array) ($_POST['b'] ?? []);
        // checked first, written after: a mistake leaves the group as it was
        foreach ($defs as $idx => $def) {
            $p = (array) ($posted[$idx] ?? []);
            $text = trim((string) ($p['text'] ?? ''));
            $emoji = trim((string) ($p['emoji'] ?? ''));
            if (containsHtmlMarkup($text) || mb_strlen($text) > 64) {
                $err = $t['menuNameInvalid'];
            } elseif ($emoji !== '' && (!preg_match('/^\X$/u', $emoji) || preg_match('/^[0-9a-zA-Z]$/', $emoji))) {
                $err = $t['menuEmojiInvalid'];
            }
        }
        if ($err === null) {
            foreach ($defs as $idx => $def) {
                $p = (array) ($posted[$idx] ?? []);
                $ov = genbtn_override($lang, $key, $idx);
                $text = trim((string) ($p['text'] ?? ''));
                if ($text === '' || $text === (string) $def['text']) {
                    if (isset($ov['text'])) {
                        genbtn_set_text($lang, $key, $idx, null);
                    }
                } elseif ($text !== ($ov['text'] ?? '')) {
                    genbtn_set_text($lang, $key, $idx, $text);
                }
                if (genbtn_text_only($alias)) {
                    continue;
                }
                $style = (string) ($p['style'] ?? '');
                $emoji = trim((string) ($p['emoji'] ?? ''));
                $pos = ($p['pos'] ?? '') === 'left' ? 'left' : 'right';
                $simple = ($p['simple'] ?? '') === '1';
                $hidden = null;
                if (genbtn_hideable($alias, $idx)) {
                    $wantHidden = ($p['hidden'] ?? '') === '1';
                    $isHidden = array_key_exists('hidden', $ov) ? (bool) $ov['hidden'] : !empty($def['hidden']);
                    if ($wantHidden !== $isHidden) {
                        // a button that ships hidden needs «shown» said out loud
                        $hidden = $wantHidden ? true : (!empty($def['hidden']) ? 'shown' : false);
                    }
                }
                $curStyle = (string) ($ov['style'] ?? '');
                $args = [
                    $style !== $curStyle ? (in_array($style, ['primary', 'success', 'danger'], true) ? $style : '') : null,
                    $emoji !== (string) ($ov['emoji'] ?? '') ? $emoji : null,
                    null,
                    $pos !== ((($ov['pos'] ?? '') === 'left') ? 'left' : 'right') ? $pos : null,
                    $simple !== !empty($ov['simple']) ? $simple : null,
                    $hidden
                ];
                // nothing changed: nothing written
                if (array_filter($args, fn($a) => $a !== null)) {
                    genbtn_set_style($lang, $key, $idx, ...$args);
                }
            }
        }
    }
    flash($err === null ? 'success' : 'error', $err ?? $t['refSaved']);
    header('Location: buttons.php?' . http_build_query(['lang' => $lang]) . '#g-' . $alias);
    exit;
}

$pageTitle = $t['btnPageTitle'];
$pageLede = $t['btnPageLede'];
$activeNav = 'buttons';
include __DIR__ . '/inc/layout_head.php';
echo web_lang_assets();
?>

<?= web_lang_tabs($lang, fn($code) => 'buttons.php?' . http_build_query(['lang' => $code])) ?>

<?php foreach ($groups as $alias => [$key, $defs]):
  $shared = in_array($key, genbtn_shared_look_keys(), true);
  $textOnly = genbtn_text_only($alias);
?>
<form method="POST" action="buttons.php?<?= http_build_query(['lang' => $lang]) ?>" class="card fade-up" id="g-<?= htmlspecialchars($alias) ?>" style="margin-bottom:14px">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="alias" value="<?= htmlspecialchars($alias) ?>">
  <div class="card-head"><div><div class="card-title"><?= htmlspecialchars($itemLabels[$key] ?? $key) ?></div>
    <div class="card-subtitle"><?= $shared ? $t['btnSharedLook'] : ($textOnly ? $t['btnTextOnly'] : '') ?></div></div></div>
  <div class="card-body">
    <?php foreach ($defs as $idx => $def): $ov = genbtn_override($lang, $key, $idx); $n = "b[$idx]";
      $isHidden = array_key_exists('hidden', $ov) ? (bool) $ov['hidden'] : !empty($def['hidden']); ?>
      <div class="set-row" style="flex-wrap:wrap">
        <div style="min-width:180px"><div class="set-label"><?= htmlspecialchars($def['name']) ?></div><?php if (!empty($def['note'])): ?><div class="set-hint"><?= htmlspecialchars($def['note']) ?></div><?php endif; ?></div>
        <div class="lang-chips" style="flex:1;justify-content:flex-end">
          <input type="text" class="input" name="<?= $n ?>[text]" value="<?= htmlspecialchars((string) ($ov['text'] ?? '')) ?>" placeholder="<?= htmlspecialchars((string) $def['text']) ?>" maxlength="64" style="max-width:220px">
          <?php if (!$textOnly): ?>
            <input type="text" class="input" name="<?= $n ?>[emoji]" value="<?= htmlspecialchars((string) ($ov['emoji'] ?? '')) ?>" placeholder="<?= !empty($ov['emojiIcon']) ? '✨' : $t['menuColEmoji'] ?>" style="width:70px;text-align:center">
            <select class="select" name="<?= $n ?>[style]" style="width:auto"><?php foreach ($colors as $v => $label): ?><option value="<?= $v ?>"<?= (string) ($ov['style'] ?? '') === $v ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select>
            <select class="select" name="<?= $n ?>[pos]" style="width:auto"><option value="right"><?= $t['menuPosRight'] ?></option><option value="left"<?= ($ov['pos'] ?? '') === 'left' ? ' selected' : '' ?>><?= $t['menuPosLeft'] ?></option></select>
            <label class="lang-chip"><input type="hidden" name="<?= $n ?>[simple]" value="0"><input type="checkbox" name="<?= $n ?>[simple]" value="1"<?= !empty($ov['simple']) ? ' checked' : '' ?>> <?= $t['btnSimple'] ?></label>
            <?php if (genbtn_hideable($alias, $idx)): ?>
              <label class="lang-chip"><input type="hidden" name="<?= $n ?>[hidden]" value="0"><input type="checkbox" name="<?= $n ?>[hidden]" value="1"<?= $isHidden ? ' checked' : '' ?>> <?= $t['menuHidden'] ?></label>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="modal-foot" style="border-radius:0 0 10px 10px"><button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> <?= $t['bottextSaveBtn'] ?></button></div>
</form>
<?php endforeach; ?>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
