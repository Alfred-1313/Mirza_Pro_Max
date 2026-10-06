<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/langsync.php';
require_auth();

// The bot's own 🎨 شخصی‌سازی پیام‌های ربات, on the web: the same items, one
// language tab at a time, saved into the same places - the text in
// setting.text_edit, the sticker and reaction in setting.keyboardmain's
// text_stickers / text_reactions, a message's own switch in bt_item_lang. A
// change here is the change the bot makes, and its 🎨 screen shows it too.
$t = $textbotlang['panel'];
$bt = $textbotlang['bottext'];
$lang = web_lang_pick();

$labels = [];
foreach ($bt['items'] as $it) {
    if (!empty($it['key'])) {
        $labels[$it['key']] = ['label' => $it['label'] ?? $it['key'], 'group' => $it['group'] ?? ''];
    }
}
foreach (bottext_all_item_keys($textbotlang) as $key) {
    $labels[$key] = $labels[$key] ?? ['label' => $key, 'group' => ''];
}
// what the language says before any change: its own file, Persian where it has no word
$defaults = require dirname(__DIR__) . '/lang/' . $lang . '.php';
if ($lang !== 'fa') {
    $defaults = bt_lang_fill_defaults($defaults, require dirname(__DIR__) . '/lang/fa.php');
}
$dig = function ($arr, $key) {
    foreach (explode('.', $key) as $p) {
        if (!is_array($arr) || !array_key_exists($p, $arr)) {
            return null;
        }
        $arr = $arr[$p];
    }
    return is_string($arr) ? $arr : null;
};
$row = db_fetch($pdo, "SELECT text_edit, keyboardmain FROM setting LIMIT 1") ?? [];
$map = json_decode((string) ($row['text_edit'] ?? ''), true);
$map = is_array($map) ? $map : [];
$layout = json_decode((string) ($row['keyboardmain'] ?? ''), true);
$stickers = is_array($layout['text_stickers'] ?? null) ? $layout['text_stickers'] : [];
$reactions = is_array($layout['text_reactions'] ?? null) ? $layout['text_reactions'] : [];
$noSticker = array_flip(bt_nosticker_keys());
// a reaction goes on the customer's own message - only these have one
$canReact = array_flip(bt_react_keys());
$switchable = bt_item_switch_defaults();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $err = null;
    foreach ((array) ($_POST['texts'] ?? []) as $key => $value) {
        $key = (string) $key;
        $default = isset($labels[$key]) ? $dig($defaults, $key) : null;
        if ($default === null || !is_string($value)) {
            continue;
        }
        $value = trim(str_replace("\r\n", "\n", $value));
        if (!isset($map[$lang]) || !is_array($map[$lang])) {
            $map[$lang] = [];
        }
        // empty or the default again: back to the default, as «0» does in the bot
        if ($value === '' || $value === $default) {
            bottext_dotted_unset($map[$lang], $key);
        } else {
            bottext_dotted_set($map[$lang], $key, $value);
        }
    }
    if (isset($map[$lang]) && empty($map[$lang])) {
        unset($map[$lang]);
    }
    // the reaction - one emoji, as the bot takes it - and the sticker
    $mediaChanged = false;
    foreach ((array) ($_POST['react'] ?? []) as $key => $value) {
        $key = (string) $key;
        if (!isset($labels[$key]) || !isset($canReact[$key])) {
            continue;
        }
        $value = trim((string) $value);
        $own = bt_media_lookup_own($reactions, $key, $lang);
        if ($value === $own) {
            continue;
        }
        if ($value === '') {
            bt_media_unset($reactions, $key, $lang);
        } elseif (!preg_match('/^\X$/u', $value) || preg_match('/^[0-9a-zA-Z]$/', $value)) {
            $err = sprintf($t['btReactionInvalid'], $labels[$key]['label']);
            continue;
        } else {
            bt_media_set($reactions, $key, $lang, $value);
        }
        $mediaChanged = true;
    }
    foreach ((array) ($_POST['sticker'] ?? []) as $key => $value) {
        $key = (string) $key;
        if (!isset($labels[$key]) || isset($noSticker[$key])) {
            continue;
        }
        $value = trim((string) $value);
        if (!empty($_POST['sticker_del'][$key])) {
            bt_media_unset($stickers, $key, $lang);
            $mediaChanged = true;
        } elseif ($value !== '' && $value !== bt_media_lookup_own($stickers, $key, $lang)) {
            // a Telegram file id: the one way to name a sticker outside Telegram
            if (!preg_match('/^[A-Za-z0-9_-]{20,}$/', $value)) {
                $err = sprintf($t['btStickerInvalid'], $labels[$key]['label']);
                continue;
            }
            bt_media_set($stickers, $key, $lang, $value);
            $mediaChanged = true;
        }
    }
    if ($mediaChanged && is_array($layout) && isset($layout['keyboard'])) {
        $layout['text_stickers'] = $stickers;
        $layout['text_reactions'] = $reactions;
        db_query($pdo, "UPDATE setting SET keyboardmain = ?", [json_encode($layout, JSON_UNESCAPED_UNICODE)]);
    }
    foreach ($switchable as $key => $default) {
        if (isset($_POST['on'][$key])) {
            $on = $_POST['on'][$key] === '1';
            if ($on !== bt_item_enabled($key, $lang)) {
                bt_item_set_enabled($key, $lang, $on);
            }
        }
    }
    db_query($pdo, "UPDATE setting SET text_edit = ?", [empty($map) ? null : json_encode($map, JSON_UNESCAPED_UNICODE)]);
    flash($err === null ? 'success' : 'error', $err ?? $t['bottextSaved']);
    header('Location: bottext.php?' . http_build_query(['lang' => $lang, 'group' => $_GET['group'] ?? null, 'q' => $_GET['q'] ?? null, 'changed' => $_GET['changed'] ?? null]));
    exit;
}

// each group named as its screen in the bot names it
$captionOf = ['myservices' => 'groupServicesCaption', 'topup' => 'groupTopupCaption', 'topupdisc' => 'groupTopupDiscCaption', 'account' => 'groupAccountCaption',
    'help' => 'groupHelpCaption', 'verify' => 'groupVerifyCaption', 'wheel' => 'groupWheelCaption', 'referral' => 'groupReferralCaption', 'usermgmt' => 'groupUserMgmtCaption', 'buyflow' => 'groupBuyflowCaption',
    'payg' => 'groupPaygCaption'];
$groupName = function ($g) use ($bt, $captionOf, $t) {
    if ($g === '') {
        return $t['bottextHomeGroup'];
    }
    $cap = (string) ($bt[$captionOf[$g] ?? ''] ?? $g);
    return trim(preg_replace('/\s*—?\s*\{lang\}/u', '', strip_tags(strtok($cap, "\n"))));
};

$group = $_GET['group'] ?? '*';
$query = trim($_GET['q'] ?? '');
$onlyChanged = !empty($_GET['changed']);
$groupCounts = [];
$visible = [];
foreach ($labels as $key => $meta) {
    $default = $dig($defaults, $key);
    if ($default === null) {
        continue;
    }
    $own = $dig($map[$lang] ?? [], $key);
    $current = $own ?? $default;
    $ownSticker = bt_media_lookup_own($stickers, $key, $lang);
    $ownReaction = bt_media_lookup_own($reactions, $key, $lang);
    $changed = $own !== null || $ownSticker !== '' || $ownReaction !== '' || (isset($switchable[$key]) && bt_item_enabled($key, $lang) !== (bool) $switchable[$key]);
    if ($onlyChanged && !$changed) {
        continue;
    }
    if ($query !== '' && mb_stripos($key . "\n" . $meta['label'] . "\n" . $current, $query) === false) {
        continue;
    }
    $groupCounts[$meta['group']] = ($groupCounts[$meta['group']] ?? 0) + 1;
    if ($group === '*' || $meta['group'] === $group) {
        $visible[$key] = ['label' => $meta['label'], 'current' => $current, 'default' => $default, 'textChanged' => $own !== null, 'changed' => $changed,
            'sticker' => $ownSticker, 'reaction' => $ownReaction];
    }
}
$changedCount = count(array_filter($visible, fn($v) => $v['changed']));
$tabUrl = fn($g) => 'bottext.php?' . http_build_query(['lang' => $lang, 'group' => $g, 'q' => $query ?: null, 'changed' => $onlyChanged ? 1 : null]);

$pageTitle = $t['bottextPageTitle'];
$pageLede = $t['bottextPageLede'];
$activeNav = 'bottext';
include __DIR__ . '/inc/layout_head.php';
echo web_lang_assets();
?>

<?= web_lang_tabs($lang, fn($code) => 'bottext.php?' . http_build_query(['lang' => $code, 'group' => $group, 'q' => $query ?: null, 'changed' => $onlyChanged ? 1 : null])) ?>

<div style="display:flex;gap:4px;margin-bottom:14px;background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:5px;overflow-x:auto" class="bt-groups fade-up">
    <?php foreach (['*' => array_sum($groupCounts)] + $groupCounts as $g => $n): ?>
        <a href="<?= htmlspecialchars($tabUrl((string) $g)) ?>"
            style="display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:7px;font-size:.82rem;font-weight:600;white-space:nowrap;flex-shrink:0;text-decoration:none;
                  <?= $group === (string) $g ? 'background:var(--acs);color:var(--ach);font-weight:700' : 'color:var(--mute)' ?>">
            <?= htmlspecialchars($g === '*' ? $t['bottextAllGroups'] : $groupName((string) $g)) ?>
            <small style="opacity:.75">(<?= $n ?>)</small>
        </a>
    <?php endforeach; ?>
</div>

<div class="card fade-up" style="overflow:visible">
    <div class="toolbar">
        <div class="toolbar-title"><?= $t['bottextPageTitle'] ?>
            <small>(<?= count($visible) ?> <?= $t['bottextCountLabel'] ?> · <?= $changedCount ?> <?= $t['bottextChangedLabel'] ?>)</small>
        </div>
        <form method="GET" class="toolbar-end">
            <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">
            <input type="hidden" name="group" value="<?= htmlspecialchars($group) ?>">
            <div class="search-box" style="min-width:220px">
                <?= icon('search', 14) ?>
                <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="<?= htmlspecialchars($t['bottextSearchPlaceholder']) ?>">
            </div>
            <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;color:var(--mute);white-space:nowrap">
                <input type="checkbox" name="changed" value="1" <?= $onlyChanged ? 'checked' : '' ?> onchange="this.form.submit()">
                <?= $t['bottextOnlyChanged'] ?>
            </label>
            <button type="submit" class="btn btn-ghost btn-sm"><?= icon('search', 13) ?> <?= $t['bottextFilterBtn'] ?></button>
        </form>
    </div>

    <?php if (!$visible): ?>
        <div class="empty" style="padding:60px 20px">
            <p><?= $t['bottextEmpty'] ?></p>
        </div>
    <?php else: ?>
        <form method="POST" onsubmit="this.querySelectorAll('textarea').forEach(function (t) { t.disabled = t.value === t.defaultValue; })">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <div class="card-body" style="display:flex;flex-direction:column;gap:18px">
                <?php $tierSwitches = array_diff_key($switchable, $labels); if ($tierSwitches && ($group === '*' || $group === 'myservices')): ?>
                    <div class="field">
                        <label><?= $t['btTierSwitches'] ?></label>
                        <div class="lang-chips">
                            <?php foreach ($tierSwitches as $key => $default): $k = htmlspecialchars($key); ?>
                                <label class="lang-chip"><input type="hidden" name="on[<?= $k ?>]" value="0"><input type="checkbox" name="on[<?= $k ?>]" value="1"<?= bt_item_enabled($key, $lang) ? ' checked' : '' ?>> <?= htmlspecialchars($t['btTier_' . str_replace('volpct.', '', $key)] ?? $key) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php foreach ($visible as $key => $v): $k = htmlspecialchars($key); ?>
                    <div class="field">
                        <label style="display:flex;justify-content:space-between;align-items:center;gap:8px">
                            <span><?= htmlspecialchars($v['label']) ?> <span class="cm" dir="ltr" style="font-size:.7rem;opacity:.6"><?= $k ?></span></span>
                            <?php if ($v['changed']): ?>
                                <span class="tag tag-warn"><?= $t['bottextChangedLabel'] ?></span>
                            <?php endif; ?>
                        </label>
                        <textarea name="texts[<?= $k ?>]" class="textarea" dir="auto" rows="<?= min(8, substr_count($v['current'], "\n") + 1) ?>"><?= htmlspecialchars($v['current']) ?></textarea>
                        <?php if ($v['textChanged']): ?>
                            <div class="field-hint" style="display:flex;align-items:flex-start;gap:8px">
                                <span style="flex:1;white-space:pre-wrap"><?= $t['bottextDefaultLabel'] ?> <?= htmlspecialchars($v['default']) ?></span>
                                <button type="button" class="btn btn-ghost btn-sm" data-default="<?= htmlspecialchars($v['default']) ?>"
                                    onclick="this.closest('.field').querySelector('textarea').value = this.dataset.default"><?= $t['bottextResetBtn'] ?></button>
                            </div>
                        <?php endif; ?>
                        <div class="lang-chips" style="margin-top:8px;align-items:center">
                            <?php if (isset($switchable[$key])): ?>
                                <label class="lang-chip"><input type="hidden" name="on[<?= $k ?>]" value="0"><input type="checkbox" name="on[<?= $k ?>]" value="1"<?= bt_item_enabled($key, $lang) ? ' checked' : '' ?>> <?= htmlspecialchars(bt_item_switch_label($key)) ?></label>
                            <?php endif; ?>
                            <?php if (isset($canReact[$key])): ?>
                                <label class="lang-chip" title="<?= htmlspecialchars($t['btReactionHint']) ?>"><?= $t['btReaction'] ?> <input type="text" name="react[<?= $k ?>]" value="<?= htmlspecialchars($v['reaction']) ?>" class="input" style="width:64px;padding:4px 8px;text-align:center" placeholder="—"></label>
                            <?php endif; ?>
                            <?php if (!isset($noSticker[$key])): ?>
                                <label class="lang-chip"><?= $t['btSticker'] ?> <?= $v['sticker'] !== '' ? '✅' : (bt_default_sticker($key) !== '' ? '<span title="' . htmlspecialchars($t['btStickerDefault']) . '">🔹</span>' : '—') ?>
                                    <input type="text" name="sticker[<?= $k ?>]" value="" class="input" dir="ltr" style="width:170px;padding:4px 8px" placeholder="<?= htmlspecialchars($t['btStickerPlaceholder']) ?>"></label>
                                <?php if ($v['sticker'] !== ''): ?>
                                    <label class="lang-chip"><input type="checkbox" name="sticker_del[<?= $k ?>]" value="1"> <?= $t['btStickerDelete'] ?></label>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="field-hint"><?= $t['btStickerHint'] ?> <?= $t['btStickerDefault'] ?></div>
            </div>
            <div class="modal-foot" style="position:sticky;bottom:0;z-index:5;border-radius:0 0 10px 10px;box-shadow:0 -8px 20px rgba(0,0,0,.25)">
                <button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> <?= $t['bottextSaveBtn'] ?></button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
