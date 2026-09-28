<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

// The bot's own 🎨 شخصی‌سازی پیام‌های ربات, on the web: the same items, one
// language tab at a time, saved into the same place (setting.text_edit) - a
// text changed here is the text the bot sends, and the 🎨 screen shows it too.
$bt = $textbotlang['bottext'];
$langs = $bt['langs'];
$lang = isset($langs[$_GET['lang'] ?? '']) && is_file(dirname(__DIR__) . '/lang/' . $_GET['lang'] . '.php') ? $_GET['lang'] : 'fa';

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
$map = json_decode((string) (db_fetch($pdo, "SELECT text_edit FROM setting LIMIT 1")['text_edit'] ?? ''), true);
$map = is_array($map) ? $map : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
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
    db_query($pdo, "UPDATE setting SET text_edit = ?", [empty($map) ? null : json_encode($map, JSON_UNESCAPED_UNICODE)]);
    flash('success', $textbotlang['panel']['bottextSaved']);
    header('Location: bottext.php?' . http_build_query(['lang' => $lang, 'group' => $_GET['group'] ?? null, 'q' => $_GET['q'] ?? null, 'changed' => $_GET['changed'] ?? null]));
    exit;
}

// each group named as its screen in the bot names it
$captionOf = ['myservices' => 'groupServicesCaption', 'topup' => 'groupTopupCaption', 'topupdisc' => 'groupTopupDiscCaption', 'account' => 'groupAccountCaption',
    'help' => 'groupHelpCaption', 'verify' => 'groupVerifyCaption', 'wheel' => 'groupWheelCaption', 'referral' => 'groupReferralCaption', 'usermgmt' => 'groupUserMgmtCaption', 'buyflow' => 'groupBuyflowCaption'];
$groupName = function ($g) use ($bt, $captionOf, $textbotlang) {
    if ($g === '') {
        return $textbotlang['panel']['bottextHomeGroup'];
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
    if ($onlyChanged && $own === null) {
        continue;
    }
    if ($query !== '' && mb_stripos($key . "\n" . $meta['label'] . "\n" . $current, $query) === false) {
        continue;
    }
    $groupCounts[$meta['group']] = ($groupCounts[$meta['group']] ?? 0) + 1;
    if ($group === '*' || $meta['group'] === $group) {
        $visible[$key] = ['label' => $meta['label'], 'current' => $current, 'default' => $default, 'changed' => $own !== null];
    }
}
$changedCount = count(array_filter($visible, fn($v) => $v['changed']));
$tabUrl = fn($g) => 'bottext.php?' . http_build_query(['lang' => $lang, 'group' => $g, 'q' => $query ?: null, 'changed' => $onlyChanged ? 1 : null]);

$pageTitle = $textbotlang['panel']['bottextPageTitle'];
$pageLede = $textbotlang['panel']['bottextPageLede'];
$activeNav = 'bottext';
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;gap:4px;margin-bottom:14px;background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:5px;overflow-x:auto" class="fade-up">
    <?php foreach (['*' => array_sum($groupCounts)] + $groupCounts as $g => $n): ?>
        <a href="<?= htmlspecialchars($tabUrl((string) $g)) ?>"
            style="display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:7px;font-size:.82rem;font-weight:600;white-space:nowrap;flex-shrink:0;text-decoration:none;
                  <?= $group === (string) $g ? 'background:var(--acs);color:var(--ach);font-weight:700' : 'color:var(--mute)' ?>">
            <?= htmlspecialchars($g === '*' ? $textbotlang['panel']['bottextAllGroups'] : $groupName((string) $g)) ?>
            <small style="opacity:.75">(<?= $n ?>)</small>
        </a>
    <?php endforeach; ?>
</div>

<div class="card fade-up" style="overflow:visible">
    <div class="toolbar">
        <div class="toolbar-title"><?= $textbotlang['panel']['bottextPageTitle'] ?>
            <small>(<?= count($visible) ?> <?= $textbotlang['panel']['bottextCountLabel'] ?> · <?= $changedCount ?> <?= $textbotlang['panel']['bottextChangedLabel'] ?>)</small>
        </div>
        <form method="GET" class="toolbar-end">
            <select name="lang" class="select" style="width:auto" title="<?= htmlspecialchars($textbotlang['panel']['bottextLangLabel']) ?>" onchange="this.form.submit()">
                <?php foreach ($langs as $code => $label): ?>
                    <option value="<?= htmlspecialchars($code) ?>" <?= $lang === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="group" value="<?= htmlspecialchars($group) ?>">
            <div class="search-box" style="min-width:220px">
                <?= icon('search', 14) ?>
                <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="<?= htmlspecialchars($textbotlang['panel']['bottextSearchPlaceholder']) ?>">
            </div>
            <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;color:var(--mute);white-space:nowrap">
                <input type="checkbox" name="changed" value="1" <?= $onlyChanged ? 'checked' : '' ?> onchange="this.form.submit()">
                <?= $textbotlang['panel']['bottextOnlyChanged'] ?>
            </label>
            <button type="submit" class="btn btn-ghost btn-sm"><?= icon('search', 13) ?> <?= $textbotlang['panel']['bottextFilterBtn'] ?></button>
        </form>
    </div>

    <?php if (!$visible): ?>
        <div class="empty" style="padding:60px 20px">
            <p><?= $textbotlang['panel']['bottextEmpty'] ?></p>
        </div>
    <?php else: ?>
        <form method="POST" onsubmit="this.querySelectorAll('textarea').forEach(function (t) { t.disabled = t.value === t.defaultValue; })">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <div class="card-body" style="display:flex;flex-direction:column;gap:18px">
                <?php foreach ($visible as $key => $v): ?>
                    <div class="field">
                        <label style="display:flex;justify-content:space-between;align-items:center;gap:8px">
                            <span><?= htmlspecialchars($v['label']) ?> <span class="cm" dir="ltr" style="font-size:.7rem;opacity:.6"><?= htmlspecialchars($key) ?></span></span>
                            <?php if ($v['changed']): ?>
                                <span class="tag tag-warn"><?= $textbotlang['panel']['bottextChangedLabel'] ?></span>
                            <?php endif; ?>
                        </label>
                        <textarea name="texts[<?= htmlspecialchars($key) ?>]" class="textarea" dir="auto" rows="<?= min(8, substr_count($v['current'], "\n") + 1) ?>"><?= htmlspecialchars($v['current']) ?></textarea>
                        <?php if ($v['changed']): ?>
                            <div class="field-hint" style="display:flex;align-items:flex-start;gap:8px">
                                <span style="flex:1;white-space:pre-wrap"><?= $textbotlang['panel']['bottextDefaultLabel'] ?> <?= htmlspecialchars($v['default']) ?></span>
                                <button type="button" class="btn btn-ghost btn-sm" data-default="<?= htmlspecialchars($v['default']) ?>"
                                    onclick="this.closest('.field').querySelector('textarea').value = this.dataset.default"><?= $textbotlang['panel']['bottextResetBtn'] ?></button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="modal-foot" style="position:sticky;bottom:0;z-index:5;border-radius:0 0 10px 10px;box-shadow:0 -8px 20px rgba(0,0,0,.25)">
                <button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> <?= $textbotlang['panel']['bottextSaveBtn'] ?></button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
