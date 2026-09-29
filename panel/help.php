<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/langsync.php';
require_auth();

// 📚 the tutorials of one language: each one's name and text - Persian is the
// tutorial itself, another language its translation (left empty, that
// language's customer gets the Persian one). A tutorial's category is one for
// every language. The same rows and helpers as the bot's 📚 screens
// (help_set_lang_field, help_category_list); a photo or video is attached
// from the bot, the text around it can be edited here.
$t = $textbotlang['panel'];
$lang = web_lang_pick();
$isFa = $lang === 'fa';
$cats = help_category_list();


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $err = null;
    $a = (string) ($_POST['a'] ?? '');
    $name = trim((string) ($_POST['name'] ?? ''));
    $desc = trim(str_replace("\r\n", "\n", (string) ($_POST['desc'] ?? '')));
    // where it sits: a category from the list, none ('0', as the bot writes
    // it), or a new one typed in
    $newCat = trim((string) ($_POST['new_cat'] ?? ''));
    $cat = $newCat !== '' ? $newCat : (string) ($_POST['cat'] ?? '0');
    if ($cat !== '0' && !isset($cats[$cat]) && $newCat === '') {
        $cat = '0';
    }
    $sameName = function ($id) use ($pdo, $name, $cat) {
        foreach (db_fetchAll($pdo, "SELECT id, name_os, category FROM help") as $r) {
            $c = trim((string) $r['category']);
            if ((int) $r['id'] !== $id && (string) $r['name_os'] === $name && (($c === '' ? '0' : $c) === $cat)) {
                return true;
            }
        }
        return false;
    };
    if ($a === 'status') {
        help_section_set(($_POST['on'] ?? '') === '1');
    } elseif ($a === 'add' && $isFa) {
        if ($name === '' || mb_strlen($name) >= 150 || mb_strlen($cat) >= 150) {
            $err = $t['helpNameInvalid'];
        } elseif ($desc === '') {
            $err = $t['helpContentEmpty'];
        } elseif (!web_tg_html_ok($desc)) {
            $err = $t['helpHtmlInvalid'];
        } elseif ($sameName(0)) {
            // unique inside its category, as in the bot
            $err = $t['helpNameExists'];
        } else {
            if ($cat !== '0' && !isset($cats[$cat])) {
                help_category_store_add($cat);
            }
            db_query($pdo, "INSERT INTO help (name_os, category, Media_os, type_Media_os, Description_os) VALUES (?, ?, '', '', ?)", [$name, $cat, $desc]);
        }
    } elseif (in_array($a, ['edit', 'reset', 'delete'], true)) {
        $id = (int) ($_POST['id'] ?? 0);
        $row = db_fetch($pdo, "SELECT * FROM help WHERE id = ?", [$id]);
        if (!$row) {
            $err = $t['helpMissing'];
        } elseif ($a === 'delete') {
            db_query($pdo, "DELETE FROM help WHERE id = ?", [$id]);
        } elseif ($a === 'reset') {
            // this language back to the Persian tutorial
            $tr = json_decode((string) ($row['translations'] ?? ''), true);
            if (!$isFa && is_array($tr) && isset($tr[$lang])) {
                unset($tr[$lang]);
                db_query($pdo, "UPDATE help SET translations = ? WHERE id = ?", [json_encode($tr, JSON_UNESCAPED_UNICODE), $id]);
            }
        } else {
            $entry = json_decode((string) ($row['translations'] ?? ''), true)[$lang] ?? [];
            $rawName = $isFa ? (string) $row['name_os'] : (string) ($entry['name'] ?? '');
            $rawDesc = $isFa ? (string) $row['Description_os'] : (string) ($entry['description'] ?? '');
            $rawMedia = $isFa ? (string) $row['Media_os'] : (string) ($entry['media'] ?? '');
            $curCat = trim((string) $row['category']) === '' ? '0' : trim((string) $row['category']);
            if (($isFa && $name === '') || mb_strlen($name) >= 150 || mb_strlen($cat) >= 150) {
                $err = $t['helpNameInvalid'];
            } elseif ($isFa && $desc === '' && $rawMedia === '') {
                $err = $t['helpContentEmpty'];
            } elseif ($desc !== $rawDesc && !web_tg_html_ok($desc)) {
                $err = $t['helpHtmlInvalid'];
            } elseif ($isFa && ($name !== $rawName || $cat !== $curCat) && $sameName($id)) {
                $err = $t['helpNameExists'];
            } else {
                $fields = [];
                if ($name !== $rawName) {
                    $fields['name'] = $name;
                }
                if ($desc !== $rawDesc) {
                    // the text alone changes; its media stays. Telegram's own
                    // formatting only fits the text it came with, so it goes
                    $fields['description'] = $desc;
                    $fields['entities'] = null;
                }
                if ($fields) {
                    help_set_lang_field($id, $lang, $fields);
                }
                if ($cat !== $curCat) {
                    if ($cat !== '0' && !isset($cats[$cat])) {
                        help_category_store_add($cat);
                    }
                    // one place for every language, as the bot's picker sets it
                    db_query($pdo, "UPDATE help SET category = ? WHERE id = ?", [$cat, $id]);
                }
            }
        }
    }
    flash($err === null ? 'success' : 'error', $err ?? $t['refSaved']);
    header('Location: help.php?' . http_build_query(['lang' => $lang]) . (isset($id) && $err === null && $a === 'edit' ? '#h-' . $id : ''));
    exit;
}

$rows = db_fetchAll($pdo, "SELECT * FROM help ORDER BY id");
// by category, in the bot's order; no category last
$groups = [];
foreach (array_keys($cats) as $c) {
    $groups[$c] = [];
}
foreach ($rows as $r) {
    $c = trim((string) $r['category']);
    $groups[($c === '' || $c === '0') ? '0' : $c][] = $r;
}
if (isset($groups['0'])) {
    $none = $groups['0'];
    unset($groups['0']);
    $groups['0'] = $none;
}
$mediaLabels = ['photo' => '🖼 ' . $t['helpMediaPhoto'], 'video' => '🎬 ' . $t['helpMediaVideo'], 'document' => '📄 ' . $t['helpMediaDoc']];
$catSelect = function ($cur) use ($cats, $t) {
    $h = '<select class="select" name="cat"><option value="0">🚫 ' . $t['helpNoCat'] . '</option>';
    foreach (array_keys($cats) as $c) {
        $c = (string) $c;
        $h .= '<option value="' . htmlspecialchars($c) . '"' . ($c === $cur ? ' selected' : '') . '>🗂 ' . htmlspecialchars($c) . '</option>';
    }
    return $h . '</select>';
};

$pageTitle = $t['helpPageTitle'];
$pageLede = $t['helpPageLede'];
$activeNav = 'help';
include __DIR__ . '/inc/layout_head.php';
echo web_lang_assets();
$on = help_section_on();
?>

<?= web_lang_tabs($lang, fn($code) => 'help.php?' . http_build_query(['lang' => $code])) ?>

<form method="POST" action="help.php?<?= http_build_query(['lang' => $lang]) ?>" class="card fade-up" style="margin-bottom:14px">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="a" value="status">
  <div class="card-body"><div class="set-row"><div><div class="set-label">📚 <?= $t['helpSection'] ?></div><div class="set-hint"><?= $t['helpSectionHint'] ?></div></div>
    <div class="set-ctl" style="display:flex;gap:8px;align-items:center"><label class="lang-chip"><input type="checkbox" name="on" value="1"<?= $on ? ' checked' : '' ?>> <?= $t['refOn'] ?></label><button type="submit" class="btn btn-primary btn-sm"><?= icon('check', 13) ?></button></div></div></div>
</form>

<?php if ($isFa): ?>
<form method="POST" action="help.php?<?= http_build_query(['lang' => $lang]) ?>" class="card fade-up" style="margin-bottom:14px">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="a" value="add">
  <div class="card-head"><div><div class="card-title">➕ <?= $t['helpAdd'] ?></div><div class="card-subtitle"><?= $t['helpAddSub'] ?></div></div></div>
  <div class="card-body">
    <div class="lang-chips" style="margin-bottom:8px"><input type="text" class="input" name="name" placeholder="<?= $t['helpName'] ?>" maxlength="149" required style="max-width:260px"><?= $catSelect('0') ?><input type="text" class="input" name="new_cat" placeholder="<?= $t['helpNewCat'] ?>" maxlength="149" style="max-width:200px"></div>
    <textarea class="textarea" name="desc" rows="4" placeholder="<?= $t['helpText'] ?>" required></textarea>
    <div class="field-hint"><?= htmlspecialchars($t['helpTextHint']) ?></div>
  </div>
  <div class="modal-foot" style="border-radius:0 0 10px 10px"><button type="submit" class="btn btn-primary"><?= icon('plus', 13) ?> <?= $t['helpAdd'] ?></button></div>
</form>
<?php else: ?>
  <div class="notice notice-warn"><?= $t['helpOtherLangNote'] ?></div>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="card fade-up"><div class="empty" style="padding:40px 20px"><p><?= $t['helpEmpty'] ?></p></div></div>
<?php endif; ?>

<?php foreach ($groups as $c => $list): if (!$list) continue; ?>
  <h3 style="margin:18px 2px 10px;font-size:.95rem"><?= (string) $c === '0' ? '🚫 ' . $t['helpNoCat'] : '🗂 ' . htmlspecialchars((string) $c) ?> <small class="cf">(<?= count($list) ?>)</small></h3>
  <?php foreach ($list as $r):
    $entry = json_decode((string) ($r['translations'] ?? ''), true)[$lang] ?? null;
    $name = $isFa ? (string) $r['name_os'] : (string) ($entry['name'] ?? '');
    $desc = $isFa ? (string) $r['Description_os'] : (string) ($entry['description'] ?? '');
    $media = $isFa ? (string) $r['Media_os'] : (string) ($entry['media'] ?? '');
    $mediaType = $isFa ? (string) $r['type_Media_os'] : (string) ($entry['media_type'] ?? '');
    $ents = $isFa ? json_decode((string) ($r['entities_os'] ?? ''), true) : ($entry['entities'] ?? null);
    $curCat = trim((string) $r['category']) === '' ? '0' : trim((string) $r['category']);
  ?>
  <div class="card fade-up" id="h-<?= (int) $r['id'] ?>" style="margin-bottom:12px">
    <form method="POST" action="help.php?<?= http_build_query(['lang' => $lang]) ?>">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="a" value="edit"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      <div class="card-head"><div><div class="card-title">📘 <?= htmlspecialchars($name !== '' ? $name : (string) $r['name_os']) ?>
        <?php if (!$isFa): ?><span class="tag <?= $entry !== null ? 'tag-ok' : 'tag-plain' ?>"><?= $entry !== null ? $t['helpTranslated'] : $t['helpUsesFa'] ?></span><?php endif; ?></div>
        <div class="card-subtitle"><?= $media !== '' ? htmlspecialchars($mediaLabels[$mediaType] ?? $mediaType) : $t['helpNoMedia'] ?></div></div></div>
      <div class="card-body">
        <div class="lang-chips" style="margin-bottom:8px"><input type="text" class="input" name="name" value="<?= htmlspecialchars($name) ?>" placeholder="<?= htmlspecialchars((string) $r['name_os']) ?>" maxlength="149" style="max-width:260px"<?= $isFa ? ' required' : '' ?>><?= $catSelect($curCat) ?><input type="text" class="input" name="new_cat" placeholder="<?= $t['helpNewCat'] ?>" maxlength="149" style="max-width:200px"></div>
        <textarea class="textarea" name="desc" rows="4" placeholder="<?= htmlspecialchars($isFa ? $t['helpText'] : (string) $r['Description_os']) ?>"><?= htmlspecialchars($desc) ?></textarea>
        <?php if (!empty($ents)): ?><div class="field-hint">⚠️ <?= $t['helpEntitiesNote'] ?></div><?php endif; ?>
        <div class="field-hint"><?= $isFa ? $t['helpCatShared'] : $t['helpEmptyUsesFa'] ?></div>
      </div>
      <div class="modal-foot" style="border-radius:0 0 10px 10px;display:flex;gap:8px;justify-content:space-between">
        <button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> <?= $t['bottextSaveBtn'] ?></button>
        <span style="display:flex;gap:8px">
          <?php if (!$isFa && $entry !== null): ?><button type="submit" class="btn btn-ghost btn-sm" name="a" value="reset" onclick="return confirm(<?= htmlspecialchars(json_encode($t['helpResetConfirm'], JSON_UNESCAPED_UNICODE)) ?>)">↩️ <?= $t['helpReset'] ?></button><?php endif; ?>
          <button type="submit" class="btn btn-no btn-sm" name="a" value="delete" formnovalidate onclick="return confirm(<?= htmlspecialchars(json_encode($t['helpDeleteConfirm'], JSON_UNESCAPED_UNICODE)) ?>)"><?= icon('trash', 13) ?></button>
        </span>
      </div>
    </form>
  </div>
  <?php endforeach; ?>
<?php endforeach; ?>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
