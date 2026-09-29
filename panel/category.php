<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/langsync.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
  csrf_check_post();
  $remark = trim($_POST['remark'] ?? '');
  if ($remark === '') {
    flash('error', $textbotlang['panel']['categoryNameRequired']);
    header('Location: category.php');
    exit;
  }
  if (db_count($pdo, "SELECT COUNT(*) FROM category WHERE remark = ?", [$remark])) {
    flash('error', $textbotlang['panel']['categoryNameExists']);
    header('Location: category.php');
    exit;
  }
  try {
    // which languages see it - the same column 🌐 مدیریت نمایش بر اساس زبان sets
    db_query($pdo, "INSERT INTO category (remark, lang) VALUES (?, ?)", [$remark, web_lang_value($_POST['langs'] ?? [])]);
    flash('success', $textbotlang['panel']['categoryAdded']);
  } catch (Exception $e) {
    flash('error', $textbotlang['panel']['productDbError'] . $e->getMessage());
  }
  header('Location: category.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
  csrf_check_post();
  $cid = (int) ($_POST['edit_id'] ?? 0);
  $remark = trim($_POST['remark'] ?? '');
  $old = $cid ? db_fetch($pdo, "SELECT remark FROM category WHERE id = ?", [$cid]) : null;
  if ($old && $remark !== '' && $remark !== $old['remark'] && db_count($pdo, "SELECT COUNT(*) FROM category WHERE remark = ?", [$remark])) {
    flash('error', $textbotlang['panel']['categoryNameExists']);
    header('Location: category.php');
    exit;
  }
  if ($old && $remark !== '') {
    try {
      db_query($pdo, "UPDATE category SET remark=?, lang=? WHERE id=?", [$remark, web_lang_value($_POST['langs'] ?? []), $cid]);
      // products name their category, not its id - they move with it, as when
      // the bot renames one
      db_query($pdo, "UPDATE product SET category=? WHERE category=?", [$remark, $old['remark']]);
      flash('success', $textbotlang['panel']['categoryEdited']);
    } catch (Exception $e) {
      flash('error', $textbotlang['panel']['productErrorPrefix'] . $e->getMessage());
    }
  }
  header('Location: category.php');
  exit;
}

if (isset($_GET['delete'])) {
  csrf_check_get();
  db_query($pdo, "DELETE FROM category WHERE id = ?", [(int) $_GET['delete']]);
  flash('success', $textbotlang['panel']['categoryDeleted']);
  header('Location: category.php');
  exit;
}

$view = in_array($_GET['view'] ?? '', panel_langs(), true) ? $_GET['view'] : '';
$categories = array_values(array_filter(db_fetchAll($pdo, "SELECT * FROM category ORDER BY id"), fn($c) => $view === '' || web_lang_has($c['lang'] ?? 'all', $view)));

$pageTitle = $textbotlang['panel']['categoryPageTitle'];
$pageLede = $textbotlang['panel']['categoryPageLede'];
$activeNav = 'category';
include __DIR__ . '/inc/layout_head.php';
echo web_lang_assets();
?>

<?= web_lang_tabs($view, fn($c) => 'category.php?' . http_build_query(['view' => $c ?: null]), true) ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px" class="fade-up">
  <div style="font-size:.85rem;color:var(--mute)"><?= count($categories) ?> <?= $textbotlang['panel']['categoryCount'] ?></div>
  <button class="btn btn-primary" onclick="openModal('addModal')"><?= icon('plus', 14) ?> <?= $textbotlang['panel']['categoryCreateBtn'] ?></button>
</div>

<div class="card fade-up d1">
  <?php if (empty($categories)): ?>
    <div class="empty" style="padding:60px 20px">
      <svg class="ill" viewBox="0 0 200 160" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect x="40" y="30" width="120" height="100" rx="12" fill="var(--surface-3)" />
        <rect x="56" y="50" width="88" height="12" rx="6" fill="var(--border-strong)" />
        <rect x="56" y="72" width="60" height="8" rx="4" fill="var(--border)" />
        <rect x="56" y="90" width="72" height="8" rx="4" fill="var(--border)" />
        <rect x="56" y="108" width="44" height="8" rx="4" fill="var(--border)" />
        <circle cx="155" cy="125" r="22" fill="var(--accent-s)" stroke="var(--accent)" stroke-width="2" />
        <path d="M147 125h16M155 117v16" stroke="var(--accent)" stroke-width="2.5" stroke-linecap="round" />
      </svg>
      <p><?= $textbotlang['panel']['categoryEmpty'] ?></p>
      <button class="btn btn-primary" style="margin-top:14px" onclick="openModal('addModal')"><?= icon('plus', 14) ?>
        <?= $textbotlang['panel']['categoryCreateBtn'] ?></button>
    </div>
  <?php else: ?>
    <div class="toolbar">
      <div class="toolbar-title"><?= $textbotlang['panel']['categoryPageTitle'] ?> <small>(<?= count($categories) ?>)</small></div>
      <div class="search-box" style="min-width:220px">
        <?= icon('search', 14) ?>
        <input type="text" placeholder="<?= htmlspecialchars($textbotlang['panel']['categorySearchPlaceholder']) ?>" data-filter="catTbl">
        <button type="button" class="search-clear">✕</button>
      </div>
    </div>
    <div class="tbl-wrap">
      <table id="catTbl" class="tbl-xl">
        <thead>
          <tr>
            <th style="width:70px">#</th>
            <th><?= $textbotlang['panel']['categoryColName'] ?></th>
            <th><?= $textbotlang['panel']['prodColLangs'] ?></th>
            <th style="width:110px;text-align:left"><?= $textbotlang['panel']['categoryColActions'] ?></th>
          </tr>
        </thead>
        <tbody>
          <?php $i = 1;
          foreach ($categories as $c): ?>
            <tr>
              <td class="cf"><?= $i++ ?></td>
              <td class="cs"><?= htmlspecialchars($c['remark'] ?? '') ?></td>
              <td><span class="tag tag-plain"><?= htmlspecialchars(web_lang_label($c['lang'] ?? 'all')) ?></span></td>
              <td>
                <div style="display:flex;gap:5px;justify-content:flex-end">
                  <button class="btn btn-ghost btn-sm btn-icon" title="<?= htmlspecialchars($textbotlang['panel']['productEditBtn']) ?>"
                    onclick="openEditModal(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)">
                    <?= icon('edit', 13) ?>
                  </button>
                  <a href="category.php?delete=<?= (int) $c['id'] ?>&_csrf=<?= csrf_token() ?>"
                    class="btn btn-no btn-sm btn-icon" title="<?= htmlspecialchars($textbotlang['panel']['productDeleteBtn']) ?>"
                    data-confirm="<?= htmlspecialchars($textbotlang['panel']['categoryDeleteConfirm']) ?>">
                    <?= icon('trash', 13) ?>
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="modal-veil" id="addModal">
  <div class="modal">
    <div class="modal-head">
      <h3><?= $textbotlang['panel']['categoryCreateTitle'] ?></h3>
      <button class="modal-x" onclick="closeModal('addModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add">
        <div class="form-grid">
          <div class="field full">
            <label><?= $textbotlang['panel']['categoryNameLabel'] ?></label>
            <input type="text" name="remark" class="input" placeholder="<?= htmlspecialchars($textbotlang['panel']['categoryNamePlaceholder']) ?>" required>
          </div>
          <div class="field full">
            <label><?= $textbotlang['panel']['prodFieldLangs'] ?></label>
            <?= web_lang_checkboxes('langs', $view ?: 'all', 'catAddLang') ?>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary"><?= icon('plus', 13) ?> <?= $textbotlang['panel']['categorySaveBtn'] ?></button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')"><?= $textbotlang['panel']['categoryCancelBtn'] ?></button>
      </div>
    </form>
  </div>
</div>

<div class="modal-veil" id="editModal">
  <div class="modal">
    <div class="modal-head">
      <h3><?= $textbotlang['panel']['categoryEditTitle'] ?></h3>
      <button class="modal-x" onclick="closeModal('editModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="edit_id" id="edit_id">
        <div class="form-grid">
          <div class="field full">
            <label><?= $textbotlang['panel']['categoryNameLabel'] ?></label>
            <input type="text" name="remark" id="edit_remark" class="input" required>
          </div>
          <div class="field full">
            <label><?= $textbotlang['panel']['prodFieldLangs'] ?></label>
            <?= web_lang_checkboxes('langs', 'all', 'catEditLang') ?>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> <?= $textbotlang['panel']['categorySaveChangeBtn'] ?></button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')"><?= $textbotlang['panel']['categoryCancelBtn'] ?></button>
      </div>
    </form>
  </div>
</div>

<script>
window.openEditModal = function(c) {
  document.getElementById('edit_id').value = c.id || '';
  document.getElementById('edit_remark').value = c.remark || '';
  var langs = (!c.lang || c.lang === 'all') ? [] : c.lang.split(',');
  document.querySelectorAll('[data-lang-group=catEditLang] input').forEach(function (b) {
    b.checked = b.value === 'all' ? !langs.length : langs.indexOf(b.value) !== -1;
  });
  openModal('editModal');
};
</script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
