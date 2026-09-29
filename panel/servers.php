<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/langsync.php';
require_auth();

// 🖥 which languages see each panel (server) when they buy - the column the
// bot's 🌐 مدیریت نمایش بر اساس زبان ← پنل‌ها sets. The panels themselves are
// added and set up in the bot.
$t = $textbotlang['panel'];
$view = in_array($_GET['view'] ?? '', panel_langs(), true) ? $_GET['view'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $code = (string) ($_POST['code_panel'] ?? '');
    if ($code === '' || !db_count($pdo, "SELECT COUNT(*) FROM marzban_panel WHERE code_panel = ?", [$code])) {
        flash('error', $t['srvMissing']);
    } else {
        db_query($pdo, "UPDATE marzban_panel SET lang = ? WHERE code_panel = ?", [web_lang_value($_POST['langs'] ?? []), $code]);
        flash('success', $t['refSaved']);
    }
    header('Location: servers.php?' . http_build_query(['view' => $view ?: null]));
    exit;
}

$panels = array_values(array_filter(db_fetchAll($pdo, "SELECT * FROM marzban_panel ORDER BY id"), fn($p) => $view === '' || web_lang_has($p['lang'] ?? 'all', $view)));
$prodCount = [];
foreach (db_fetchAll($pdo, "SELECT Location, COUNT(*) AS n FROM product GROUP BY Location") as $r) {
    $prodCount[(string) $r['Location']] = (int) $r['n'];
}

$pageTitle = $t['srvPageTitle'];
$pageLede = $t['srvPageLede'];
$activeNav = 'servers';
include __DIR__ . '/inc/layout_head.php';
echo web_lang_assets();
?>

<?= web_lang_tabs($view, fn($c) => 'servers.php?' . http_build_query(['view' => $c ?: null]), true) ?>

<div class="card fade-up">
  <?php if (!$panels): ?>
    <div class="empty" style="padding:40px 20px"><p><?= $t['srvEmpty'] ?></p></div>
  <?php else: ?>
    <div class="tbl-wrap">
      <table class="tbl-xl">
        <thead><tr><th style="width:50px">#</th><th><?= $t['srvColName'] ?></th><th><?= $t['srvColType'] ?></th><th><?= $t['srvColStatus'] ?></th><th><?= $t['srvColProducts'] ?></th><th><?= $t['prodColLangs'] ?></th></tr></thead>
        <tbody>
        <?php foreach ($panels as $i => $p): ?>
          <tr>
            <td class="cf"><?= $i + 1 ?></td>
            <td class="cs"><?= htmlspecialchars((string) $p['name_panel']) ?></td>
            <td><span class="tag tag-plain"><?= htmlspecialchars((string) $p['type']) ?></span></td>
            <td><?= ($p['status'] ?? '') === 'active' ? '<span class="tag tag-ok">' . $t['srvActive'] . '</span>' : '<span class="tag tag-no">' . $t['srvInactive'] . '</span>' ?></td>
            <td class="cf"><?= $prodCount[(string) $p['name_panel']] ?? 0 ?></td>
            <td>
              <form method="POST" action="servers.php?<?= http_build_query(['view' => $view ?: null]) ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="code_panel" value="<?= htmlspecialchars((string) $p['code_panel']) ?>">
                <?= web_lang_checkboxes('langs', $p['lang'] ?? 'all', 'srv' . (int) $p['id']) ?>
                <button type="submit" class="btn btn-primary btn-sm"><?= icon('check', 13) ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <div class="card-body"><div class="field-hint"><?= $t['srvHint'] ?></div></div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
