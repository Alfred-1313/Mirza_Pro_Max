<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/langsync.php';
require_auth();

// A product the way the bot makes one: which languages see it, the currency
// its price is in, a volume that may be under a gigabyte (0.5, 200MB), and a
// category and panel of those languages.
$t = $textbotlang['panel'];
$resetPanels = ['marzban', 'marzneshin', 'remnawave'];
$panels = db_fetchAll($pdo, "SELECT * FROM marzban_panel ORDER BY id");
$categories = db_fetchAll($pdo, "SELECT * FROM category ORDER BY id");
$currencies = currency_offered();

// what was posted, checked the way the bot checks it; [row, error]
$readForm = function (?int $id) use ($pdo, $t, $panels, $categories, $currencies, $resetPanels) {
    $name = trim((string) ($_POST['name_product'] ?? ''));
    if ($name === '') {
        return [null, $t['productNameRequired']];
    }
    if (containsHtmlMarkup($name) || mb_strlen($name) > 150) {
        return [null, $t['prodNameInvalid']];
    }
    if (db_count($pdo, "SELECT COUNT(*) FROM product WHERE name_product = ? AND id <> ?", [$name, (int) $id])) {
        return [null, $t['productNameExists']];
    }
    $lang = web_lang_value($_POST['langs'] ?? []);
    $cur = (string) ($_POST['currency'] ?? '');
    if (!isset($currencies[$cur])) {
        $cur = currency_default_code();
    }
    if (!money_valid($_POST['price_product'] ?? '', $cur)) {
        return [null, sprintf($t['prodPriceInvalid'], $currencies[$cur]['title'] ?? $cur, (int) (currency_get($cur)['decimals'] ?? 0))];
    }
    $gb = volume_parse((string) ($_POST['volume_product'] ?? ''), 'gb', true);
    if ($gb === null) {
        return [null, $t['prodVolumeInvalid']];
    }
    $days = trim((string) ($_POST['time_product'] ?? ''));
    if (!ctype_digit($days)) {
        return [null, $t['prodDaysInvalid']];
    }
    $location = (string) ($_POST['namepanel'] ?? '');
    $panel = null;
    foreach ($panels as $p) {
        if ($p['name_panel'] === $location) {
            $panel = $p;
        }
    }
    if ($location !== '/all' && $panel === null) {
        return [null, $t['prodPanelInvalid']];
    }
    $category = (string) ($_POST['category'] ?? '');
    if ($category !== '' && !in_array($category, array_column($categories, 'remark'), true)) {
        return [null, $t['prodCategoryInvalid']];
    }
    $agent = in_array($_POST['agent_product'] ?? '', ['f', 'n', 'n2'], true) ? $_POST['agent_product'] : 'f';
    // only these panels reset a user's traffic on a schedule
    $reset = ($panel !== null && in_array($panel['type'], $resetPanels, true) && in_array($_POST['data_limit_reset'] ?? '', ['no_reset', 'day', 'week', 'month', 'year'], true))
        ? $_POST['data_limit_reset'] : 'no_reset';
    return [[
        'name_product' => $name, 'price_product' => money_normalize($_POST['price_product']), 'Volume_constraint' => volume_store($gb),
        'Service_time' => (string) (int) $days, 'Location' => $location, 'agent' => $agent, 'data_limit_reset' => $reset,
        'note' => trim((string) ($_POST['note_product'] ?? '')), 'category' => $category === '' ? null : $category, 'lang' => $lang, 'currency' => $cur,
    ], null];
};

$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'add' || $action === 'edit')) {
    csrf_check_post();
    $pid = $action === 'edit' ? (int) ($_POST['edit_id'] ?? 0) : null;
    [$row, $err] = $readForm($pid);
    if ($err !== null) {
        flash('error', $err);
    } else {
        try {
            if ($action === 'add') {
                $row['code_product'] = bin2hex(random_bytes(2));
                db_query($pdo, "INSERT INTO product (name_product,price_product,Volume_constraint,Service_time,Location,agent,data_limit_reset,note,category,lang,currency,code_product,hide_panel,one_buy_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'{}','0')", array_values($row));
                flash('success', $t['productAddedPrefix'] . $row['name_product'] . $t['productAddedSuffix']);
            } elseif ($pid) {
                db_query($pdo, "UPDATE product SET name_product=?,price_product=?,Volume_constraint=?,Service_time=?,Location=?,agent=?,data_limit_reset=?,note=?,category=?,lang=?,currency=? WHERE id=?", array_merge(array_values($row), [$pid]));
                flash('success', $t['productEdited']);
            }
        } catch (Exception $e) {
            flash('error', $t['productDbError'] . $e->getMessage());
        }
    }
    header('Location: product.php?' . http_build_query(['view' => $_GET['view'] ?? null]));
    exit;
}

if (isset($_GET['delete'])) {
    csrf_check_get();
    db_query($pdo, "DELETE FROM product WHERE id = ?", [(int) $_GET['delete']]);
    flash('success', $t['productDeleted']);
    header('Location: product.php?' . http_build_query(['view' => $_GET['view'] ?? null]));
    exit;
}

$view = in_array($_GET['view'] ?? '', panel_langs(), true) ? $_GET['view'] : '';
$products = array_values(array_filter(db_fetchAll($pdo, "SELECT * FROM product ORDER BY id"), fn($p) => $view === '' || web_lang_has($p['lang'] ?? 'all', $view)));
$agentLabels = ['f' => $t['prodAgentF'], 'n' => $t['prodAgentN'], 'n2' => $t['prodAgentN2']];
$curByLang = [];
foreach (panel_langs() as $code) {
    $curByLang[$code] = currency_for_lang($code);
}

$pageTitle = $t['productsTitle'];
$pageLede = $t['prodLede'];
$activeNav = 'product';
include __DIR__ . '/inc/layout_head.php';
echo web_lang_assets();
?>

<?= web_lang_tabs($view, fn($c) => 'product.php?' . http_build_query(['view' => $c ?: null]), true) ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px" class="fade-up">
  <div style="font-size:.85rem;color:var(--mute)"><?= count($products) ?> <?= $t['productsHeading'] ?></div>
  <button class="btn btn-primary" onclick="openProduct(null)"><?= icon('plus', 14) ?> <?= $t['productAddProductBtn'] ?></button>
</div>

<div class="card fade-up d1">
  <?php if (empty($products)): ?>
    <div class="empty" style="padding:60px 20px">
      <p><?= $t['prodEmpty'] ?></p>
      <button class="btn btn-primary" style="margin-top:14px" onclick="openProduct(null)"><?= icon('plus', 14) ?> <?= $t['productAddProductBtn'] ?></button>
    </div>
  <?php else: ?>
    <div class="toolbar">
      <div class="toolbar-title"><?= $t['productsTitle'] ?> <small>(<?= count($products) ?>)</small></div>
      <div class="search-box" style="min-width:220px">
        <?= icon('search', 14) ?>
        <input type="text" placeholder="<?= htmlspecialchars($t['productSearchPlaceholder']) ?>" data-filter="prodTbl">
        <button type="button" class="search-clear">✕</button>
      </div>
    </div>
    <div class="tbl-wrap">
      <table id="prodTbl" class="tbl-xl">
        <thead>
          <tr>
            <th>#</th>
            <th><?= $t['prodColName'] ?></th>
            <th><?= $t['prodColPrice'] ?></th>
            <th><?= $t['prodColVolume'] ?></th>
            <th><?= $t['prodColDays'] ?></th>
            <th><?= $t['prodColPanel'] ?></th>
            <th><?= $t['prodColCategory'] ?></th>
            <th><?= $t['prodColLangs'] ?></th>
            <th><?= $t['prodColAgent'] ?></th>
            <th><?= $t['prodColActions'] ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($products as $i => $p): ?>
            <tr>
              <td class="cf"><?= $i + 1 ?></td>
              <td class="cs"><?= htmlspecialchars($p['name_product'] ?? '') ?></td>
              <td class="cn cs"><?= htmlspecialchars(money($p['price_product'] ?? 0, $p['currency'] ?? currency_default_code())) ?></td>
              <td class="cn"><?= (float) ($p['Volume_constraint'] ?? 0) > 0 ? volume_num($p['Volume_constraint']) . ' <span class="cf">GB</span>' : $t['prodUnlimited'] ?></td>
              <td class="cn"><?= (int) ($p['Service_time'] ?? 0) > 0 ? (int) $p['Service_time'] . ' <span class="cf">' . $t['prodDayUnit'] . '</span>' : $t['prodUnlimited'] ?></td>
              <td class="cf"><?= htmlspecialchars(trunc(($p['Location'] ?? '') === '/all' ? $t['prodAllPanels'] : ($p['Location'] ?? '—'), 16)) ?></td>
              <td><?php if (!empty($p['category'])): ?><span class="tag tag-info"><?= htmlspecialchars($p['category']) ?></span><?php else: ?><span class="cf">—</span><?php endif; ?></td>
              <td><span class="tag tag-plain"><?= htmlspecialchars(web_lang_label($p['lang'] ?? 'all')) ?></span></td>
              <td class="cf"><?= htmlspecialchars($agentLabels[$p['agent'] ?? 'f'] ?? ($p['agent'] ?? '')) ?></td>
              <td>
                <div style="display:flex;gap:5px">
                  <button class="btn btn-ghost btn-sm btn-icon" title="<?= htmlspecialchars($t['productEditBtn']) ?>"
                    onclick="openProduct(<?= htmlspecialchars(json_encode($p, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"><?= icon('edit', 13) ?></button>
                  <a href="product.php?<?= http_build_query(['delete' => (int) $p['id'], '_csrf' => csrf_token(), 'view' => $view ?: null]) ?>"
                    class="btn btn-no btn-sm btn-icon" title="<?= htmlspecialchars($t['productDeleteBtn']) ?>"
                    data-confirm="<?= htmlspecialchars(sprintf($t['prodDeleteConfirm'], $p['name_product'])) ?>"><?= icon('trash', 13) ?></a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="modal-veil" id="prodModal">
  <div class="modal">
    <div class="modal-head">
      <h3 id="prodTitle"><?= $t['prodAddTitle'] ?></h3>
      <button class="modal-x" onclick="closeModal('prodModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST" action="product.php?<?= http_build_query(['view' => $view ?: null]) ?>">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" id="prodAction" value="add">
        <input type="hidden" name="edit_id" id="prodId">
        <div class="form-grid">
          <div class="field full">
            <label><?= $t['prodFieldName'] ?> *</label>
            <input type="text" name="name_product" id="prodName" class="input" maxlength="150" placeholder="<?= htmlspecialchars($t['productNameExample']) ?>" required>
          </div>
          <div class="field full">
            <label><?= $t['prodFieldLangs'] ?></label>
            <?= web_lang_checkboxes('langs', 'all', 'prodLang') ?>
            <div class="field-hint"><?= $t['prodLangsHint'] ?></div>
          </div>
          <div class="field">
            <label><?= $t['prodFieldCurrency'] ?></label>
            <select name="currency" id="prodCurrency" class="select">
              <?php foreach ($currencies as $code => $c): ?>
                <option value="<?= htmlspecialchars($code) ?>" data-dec="<?= (int) ($c['decimals'] ?? 0) ?>"><?= htmlspecialchars(($c['title'] ?? $code) . (!empty($c['symbol']) ? ' (' . $c['symbol'] . ')' : '')) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label><?= $t['prodFieldPrice'] ?></label>
            <input type="text" name="price_product" id="prodPrice" class="input" inputmode="decimal" placeholder="0" required>
          </div>
          <div class="field">
            <label><?= $t['prodFieldVolume'] ?></label>
            <input type="text" name="volume_product" id="prodVolume" class="input" placeholder="50 · 0.5 · 200MB" required>
            <div class="field-hint"><?= $t['prodVolumeHint'] ?></div>
          </div>
          <div class="field">
            <label><?= $t['prodFieldDays'] ?></label>
            <input type="text" name="time_product" id="prodDays" class="input" inputmode="numeric" placeholder="30" required>
            <div class="field-hint"><?= $t['prodDaysHint'] ?></div>
          </div>
          <div class="field">
            <label><?= $t['prodFieldPanel'] ?></label>
            <select name="namepanel" id="prodPanel" class="select">
              <option value="/all" data-lang="all" data-type=""><?= $t['prodAllPanels'] ?></option>
              <?php foreach ($panels as $pl): ?>
                <option value="<?= htmlspecialchars($pl['name_panel']) ?>" data-lang="<?= htmlspecialchars($pl['lang'] ?? 'all') ?>" data-type="<?= htmlspecialchars($pl['type'] ?? '') ?>"><?= htmlspecialchars($pl['name_panel']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" id="prodResetField">
            <label><?= $t['prodFieldReset'] ?></label>
            <select name="data_limit_reset" id="prodReset" class="select">
              <?php foreach (['no_reset', 'day', 'week', 'month', 'year'] as $r): ?>
                <option value="<?= $r ?>"><?= htmlspecialchars($t['prodReset_' . $r]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label><?= $t['prodFieldCategory'] ?></label>
            <select name="category" id="prodCategory" class="select">
              <option value="" data-lang="all"><?= $t['prodNoCategory'] ?></option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= htmlspecialchars($c['remark']) ?>" data-lang="<?= htmlspecialchars($c['lang'] ?? 'all') ?>"><?= htmlspecialchars($c['remark']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label><?= $t['prodFieldAgent'] ?></label>
            <select name="agent_product" id="prodAgent" class="select">
              <?php foreach ($agentLabels as $code => $label): ?>
                <option value="<?= $code ?>"><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field full">
            <label><?= $t['prodFieldNote'] ?></label>
            <input type="text" name="note_product" id="prodNote" class="input" placeholder="<?= htmlspecialchars($t['productDescriptionOptional']) ?>">
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> <?= $t['prodSaveBtn'] ?></button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('prodModal')"><?= $t['categoryCancelBtn'] ?></button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var curByLang = <?= json_encode($curByLang) ?>, curDefault = <?= json_encode(currency_default_code()) ?>;
  var resetTypes = <?= json_encode($resetPanels) ?>;
  var $ = function (id) { return document.getElementById(id); };
  var curTouched = false;
  $('prodCurrency').addEventListener('change', function () { curTouched = true; });
  function picked() {
    var out = [];
    document.querySelectorAll('[data-lang-group=prodLang] input:checked').forEach(function (b) { if (b.value !== 'all') out.push(b.value); });
    return out;
  }
  // a category or panel of other languages only is not offered
  function fits(optLang, langs) {
    if (!optLang || optLang === 'all' || !langs.length) return true;
    var own = optLang.split(',');
    return langs.some(function (l) { return own.indexOf(l) !== -1; });
  }
  function sync() {
    var langs = picked();
    ['prodCategory', 'prodPanel'].forEach(function (id) {
      var sel = $(id);
      Array.prototype.forEach.call(sel.options, function (o) { o.hidden = !fits(o.dataset.lang, langs); });
      if (sel.selectedOptions[0] && sel.selectedOptions[0].hidden) sel.value = sel.options[0].value;
    });
    if (!curTouched) $('prodCurrency').value = langs.length === 1 && curByLang[langs[0]] ? curByLang[langs[0]] : curDefault;
    var type = ($('prodPanel').selectedOptions[0] || {}).dataset || {};
    $('prodResetField').style.display = resetTypes.indexOf(type.type || '') !== -1 ? '' : 'none';
  }
  document.addEventListener('langchange', sync);
  $('prodPanel').addEventListener('change', sync);
  window.openProduct = function (p) {
    var edit = !!p;
    p = p || {};
    $('prodTitle').textContent = edit ? <?= json_encode($t['prodEditTitle']) ?> : <?= json_encode($t['prodAddTitle']) ?>;
    $('prodAction').value = edit ? 'edit' : 'add';
    $('prodId').value = p.id || '';
    $('prodName').value = p.name_product || '';
    $('prodPrice').value = p.price_product || '';
    $('prodVolume').value = p.Volume_constraint || '';
    $('prodDays').value = p.Service_time || '';
    $('prodNote').value = p.note || '';
    $('prodAgent').value = p.agent || 'f';
    $('prodReset').value = p.data_limit_reset || 'no_reset';
    var langs = (!p.lang || p.lang === 'all') ? [] : p.lang.split(',');
    document.querySelectorAll('[data-lang-group=prodLang] input').forEach(function (b) {
      b.checked = b.value === 'all' ? !langs.length : langs.indexOf(b.value) !== -1;
    });
    curTouched = edit;
    $('prodCurrency').value = p.currency || curDefault;
    $('prodPanel').value = p.Location || '/all';
    $('prodCategory').value = p.category || '';
    sync();
    if (edit) { $('prodPanel').value = p.Location || '/all'; $('prodCategory').value = p.category || ''; }
    openModal('prodModal');
  };
})();
</script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
