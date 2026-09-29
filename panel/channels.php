<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/langsync.php';
require_auth();

// 📯 the channel buttons of one language - their order, name, emoji, colour
// and whether they show - and who has to join each channel for the bot to
// work in that language. The stores the bot's 📯 screens write
// (channel_btn_set, channel_btn_order_set, chn_gate); the channels themselves
// are added in the bot.
$t = $textbotlang['panel'];
$ct = lang_tab_texts('fa')['Admin']['channel'];
$lang = web_lang_pick();
$rows = channels_effective_order($lang);
$auds = ['all' => $ct['gateAll'], 'new' => $ct['gateNew'], 'invited' => $ct['gateInvited'], 'none' => $ct['gateNone']];
$colors = ['' => $t['menuColorDefault'], 'primary' => '🔵 ' . $t['menuColorBlue'], 'success' => '🟢 ' . $t['menuColorGreen'], 'danger' => '🔴 ' . $t['menuColorRed']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $err = null;
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int) $r['id']] = $r;
    }
    // checked first, written after: a mistake leaves every channel as it was
    $placed = [];
    foreach ((array) ($_POST['ch'] ?? []) as $p) {
        $id = (int) ($p['id'] ?? 0);
        if (!isset($byId[$id]) || isset($placed[$id])) {
            continue;
        }
        $order = trim((string) ($p['order'] ?? ''));
        $name = trim((string) ($p['name'] ?? ''));
        $emoji = trim((string) ($p['emoji'] ?? ''));
        if (!ctype_digit($order) || (int) $order < 1 || (int) $order > 99) {
            $err = $t['chnOrderInvalid'];
        } elseif (containsHtmlMarkup($name) || mb_strlen($name) > 64) {
            $err = $t['menuNameInvalid'];
        } elseif ($emoji !== '' && (!preg_match('/^\X$/u', $emoji) || preg_match('/^[0-9a-zA-Z]$/', $emoji))) {
            $err = $t['menuEmojiInvalid'];
        }
        if ($err !== null) {
            break;
        }
        $aud = (string) ($p['aud'] ?? '');
        $placed[$id] = [
            'order' => (int) $order,
            'name' => $name,
            'emoji' => $emoji,
            'emoji_del' => !empty($p['emoji_del']),
            'pos' => ($p['pos'] ?? '') === 'left' ? 'left' : 'right',
            'style' => in_array($p['style'] ?? '', ['primary', 'success', 'danger'], true) ? $p['style'] : null,
            'hidden' => ($p['hidden'] ?? '') === '1',
            'aud' => isset($auds[$aud]) ? $aud : 'all',
        ];
    }
    if ($err === null && count($placed) !== count($byId)) {
        $err = $t['menuIncomplete'];
    }
    if ($err === null) {
        $gate = channel_gate_map($lang);
        $gateChanged = false;
        foreach ($placed as $id => $w) {
            $r = $byId[$id];
            // a field is written only when it changed, as the bot's buttons do
            $set = function ($field, $value) use ($lang, $id, $r) {
                if ((string) ($r[$field] ?? '') !== (string) $value) {
                    channel_btn_set($lang, $id, $field, $value);
                }
            };
            $set('custom_text', $w['name'] === '' ? null : $w['name']);
            $set('style', $w['style']);
            $set('hidden', $w['hidden'] ? '1' : null);
            if ($w['emoji_del']) {
                // «0» in the bot: no emoji at all, premium or plain
                $set('emoji', null);
                $set('icon_emoji', null);
                $set('emoji_pos', null);
            } else {
                if ($w['emoji'] !== (string) ($r['emoji'] ?? '')) {
                    $set('emoji', $w['emoji'] === '' ? null : $w['emoji']);
                    if ($w['emoji'] !== '') {
                        // a plain emoji replaces a premium one, as in the bot
                        $set('icon_emoji', null);
                    }
                }
                if ($w['pos'] !== ((($r['emoji_pos'] ?? '') === 'left') ? 'left' : 'right')) {
                    channel_btn_set($lang, $id, 'emoji_pos', $w['pos']);
                }
            }
            if ($w['aud'] !== channel_gate_aud($lang, $id)) {
                // «new» means from now on
                $gate[(string) $id] = ['aud' => $w['aud'], 'since' => $w['aud'] === 'new' ? time() : 0];
                $gateChanged = true;
            }
        }
        // buttons in their numbers' order, ties in the order they were listed
        $ids = array_keys($placed);
        $listed = array_flip($ids);
        usort($ids, fn($a, $b) => [$placed[$a]['order'], $listed[$a]] <=> [$placed[$b]['order'], $listed[$b]]);
        if ($ids !== array_map(fn($r) => (int) $r['id'], $rows)) {
            channel_btn_order_set($lang, $ids);
        }
        if ($gateChanged) {
            feature_setting_set('chn_gate', $lang, json_encode($gate));
        }
    }
    flash($err === null ? 'success' : 'error', $err ?? $t['refSaved']);
    header('Location: channels.php?' . http_build_query(['lang' => $lang]));
    exit;
}

$pageTitle = $t['chnPageTitle'];
$pageLede = $t['chnPageLede'];
$activeNav = 'channels';
include __DIR__ . '/inc/layout_head.php';
echo web_lang_assets();
?>

<?= web_lang_tabs($lang, fn($code) => 'channels.php?' . http_build_query(['lang' => $code])) ?>

<?php if (!$rows): ?>
  <div class="notice notice-warn"><?= $t['chnEmpty'] ?></div>
<?php else: ?>
<div class="card fade-up" style="margin-bottom:14px">
  <div class="card-head"><div><div class="card-title">👁 <?= $t['menuPreview'] ?></div><div class="card-subtitle"><?= $t['chnPreviewSub'] ?></div></div></div>
  <div class="card-body" style="display:flex;flex-direction:column;gap:6px;max-width:420px">
    <?php foreach ($rows as $r): if (!empty($r['hidden'])) continue; [$label, $icon] = channel_button_text($r); $c = ['primary' => '#2b6ef2', 'success' => '#16a34a', 'danger' => '#dc2626'][channel_button_style($r)] ?? 'var(--sf3)'; ?>
      <div style="text-align:center;padding:9px 6px;border-radius:9px;background:<?= $c ?>;color:#fff;font-size:.84rem"><?= htmlspecialchars($label) ?><?= $icon !== '' ? ' ✨' : '' ?></div>
    <?php endforeach; ?>
  </div>
</div>

<form method="POST" action="channels.php?<?= http_build_query(['lang' => $lang]) ?>" class="card fade-up">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <div class="card-head"><div><div class="card-title">📯 <?= $t['chnButtons'] ?></div><div class="card-subtitle"><?= $t['chnButtonsSub'] ?></div></div></div>
  <div class="card-body">
    <div class="tbl-wrap"><table class="tbl-xl">
      <thead><tr><th><?= $t['chnColOrder'] ?></th><th><?= $t['chnColChannel'] ?></th><th><?= $t['chnColJoin'] ?></th><th><?= $t['menuColName'] ?></th><th><?= $t['menuColEmoji'] ?></th><th><?= $t['menuEmojiPos'] ?></th><th><?= $t['menuColColor'] ?></th><th><?= $t['menuColHidden'] ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $r): $n = 'ch[' . $i . ']'; $aud = channel_gate_aud($lang, $r['id']); ?>
        <tr>
          <td><input type="hidden" name="<?= $n ?>[id]" value="<?= (int) $r['id'] ?>"><input type="text" class="input" name="<?= $n ?>[order]" value="<?= $i + 1 ?>" inputmode="numeric" style="width:56px"></td>
          <td class="cs"><?= htmlspecialchars((string) $r['remark']) ?><div class="field-hint" dir="ltr"><?= htmlspecialchars((string) ($r['linkjoin'] ?? '')) ?></div></td>
          <td><select class="select" name="<?= $n ?>[aud]"><?php foreach ($auds as $v => $label): ?><option value="<?= $v ?>"<?= $aud === $v ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></td>
          <td><input type="text" class="input" name="<?= $n ?>[name]" value="<?= htmlspecialchars((string) ($r['custom_text'] ?? '')) ?>" placeholder="<?= htmlspecialchars((string) $r['remark']) ?>" maxlength="64"></td>
          <td style="white-space:nowrap"><input type="text" class="input" name="<?= $n ?>[emoji]" value="<?= htmlspecialchars((string) ($r['emoji'] ?? '')) ?>" style="width:64px;text-align:center" placeholder="<?= !empty($r['icon_emoji']) ? '✨' : '—' ?>">
            <?php if (!empty($r['emoji']) || !empty($r['icon_emoji'])): ?><label class="lang-chip" style="display:inline-flex;margin-top:4px"><input type="checkbox" name="<?= $n ?>[emoji_del]" value="1"> <?= $t['chnEmojiDel'] ?></label><?php endif; ?></td>
          <td><select class="select" name="<?= $n ?>[pos]"><option value="right"><?= $t['menuPosRight'] ?></option><option value="left"<?= ($r['emoji_pos'] ?? '') === 'left' ? ' selected' : '' ?>><?= $t['menuPosLeft'] ?></option></select></td>
          <td><select class="select" name="<?= $n ?>[style]"><?php foreach ($colors as $v => $label): ?><option value="<?= $v ?>"<?= channel_button_style($r) === $v ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></td>
          <td><label class="lang-chip"><input type="hidden" name="<?= $n ?>[hidden]" value="0"><input type="checkbox" name="<?= $n ?>[hidden]" value="1"<?= !empty($r['hidden']) ? ' checked' : '' ?>> <?= $t['menuHidden'] ?></label></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="field-hint"><?= $t['chnJoinHint'] ?></div>
  </div>
  <div class="modal-foot" style="border-radius:0 0 10px 10px"><button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> <?= $t['bottextSaveBtn'] ?></button></div>
</form>
<?php endif; ?>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
