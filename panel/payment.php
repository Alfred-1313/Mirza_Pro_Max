<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/langsync.php';
require_auth();

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
// a language tab ('' = all): its payments, the way the bot's 📊 counts them
$lang = web_lang_pick('lang', '');
[$langSQL, $langParams] = $lang !== '' ? stats_lang_where($lang, 'p') : ['1 = 1', []];
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($search !== '') {
  $where[] = "(`id_user` LIKE ? OR `id_order` LIKE ?)";
  $params = ["%$search%", "%$search%"];
}
if ($status !== '') {
  $where[] = "payment_Status = ?";
  $params[] = $status;
}
if ($lang !== '') {
  $where[] = $langSQL;
  $params = array_merge($params, $langParams);
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$orderSQL = "ORDER BY time DESC";

$langCounts = [];
try {
  $total = db_count($pdo, "SELECT COUNT(*) FROM Payment_report p $whereSQL", $params);
  $payments = db_fetchAll($pdo, "SELECT * FROM Payment_report p $whereSQL $orderSQL LIMIT $perPage OFFSET $offset", $params);
  $langCounts[''] = db_count($pdo, "SELECT COUNT(*) FROM Payment_report");
  foreach (panel_langs() as $l) {
    [$lw, $lp] = stats_lang_where($l, 'p');
    $langCounts[$l] = db_count($pdo, "SELECT COUNT(*) FROM Payment_report p WHERE $lw", $lp);
  }
} catch (Exception $e) {
  $total = 0;
  $payments = [];
  flash('error', $textbotlang['panel']['paymentDbErrorTransactions'] . $e->getMessage());
}
$totalPages = max(1, (int) ceil($total / $perPage));

$totalSuccess = [];
$todayCount = 0;
try {
  $totalSuccess = web_sum_by_currency("SELECT COALESCE(SUM(p.price),0) FROM Payment_report p WHERE p.payment_Status = 'paid' AND {lang}", [], 'p', $lang);
  $todayCount = db_count($pdo, "SELECT COUNT(*) FROM Payment_report p WHERE p.time > ? AND $langSQL", array_merge([strtotime('today')], $langParams));
} catch (Exception $e) {
}

$statusMap = [
  'paid' => ['tag-ok', $textbotlang['panel']['paymentStatusPaid']],
  'Unpaid' => ['tag-no', $textbotlang['panel']['paymentStatusUnpaid']],
  'expire' => ['tag-plain', $textbotlang['panel']['paymentStatusExpired']],
  'reject' => ['tag-no', $textbotlang['panel']['paymentStatusRejected']],
  'waiting' => ['tag-warn', $textbotlang['panel']['paymentStatusWaiting']],
];
$methodMap = [
  'cart to cart' => $textbotlang['panel']['paymentMethodCardToCard'],
  'low balance by admin' => $textbotlang['panel']['paymentMethodAdminDeduct'],
  'add balance by admin' => $textbotlang['panel']['paymentMethodAdminAdd'],
  'Currency Rial 1' => $textbotlang['panel']['paymentMethodRialGateway1'],
  'Currency Rial tow' => $textbotlang['panel']['paymentMethodRialGateway2'],
  'Currency Rial 3' => $textbotlang['panel']['paymentMethodRialGateway3'],
  'aqayepardakht' => $textbotlang['panel']['paymentMethodAqayePardakht'],
  'zarinpal' => $textbotlang['panel']['paymentMethodZarinpal'],
  'plisio' => 'Plisio',
  'arze digital offline' => $textbotlang['panel']['paymentMethodCryptoOffline'],
  'Star Telegram' => $textbotlang['panel']['paymentMethodTelegramStar'],
  'nowpayment' => 'NowPayment',
  'USDT-BEP20' => 'USDT (BEP20)',
  'cubepay' => 'CubePay',
  'AbanGateway' => 'AbanGateway',
  'variza' => $textbotlang['panel']['paymentMethodVariza'],
];

$pageTitle = $textbotlang['panel']['paymentTransactionsTitle'];
$pageLede = $textbotlang['panel']['paymentTransactionsSubtitle'];
$activeNav = 'payment';
include __DIR__ . '/inc/layout_head.php';
echo web_lang_assets();
?>

<?= web_lang_tabs($lang, fn($c) => 'payment.php?' . http_build_query(['lang' => $c ?: null]), true, $langCounts) ?>

<div class="stats" style="grid-template-columns:repeat(3,1fr);margin-bottom:24px">
  <div class="stat success">
    <div class="stat-label"><?= $textbotlang['panel']['paymentTransactionsHeading'] ?></div>
    <div class="stat-num"<?= count($totalSuccess) > 1 ? ' style="font-size:1.15rem;line-height:1.6"' : '' ?>><?= web_money_lines($totalSuccess) ?></div>
    <div class="stat-meta"><?= $textbotlang['panel']['paymentAllMethods'] ?></div>
  </div>
  <div class="stat">
    <div class="stat-label"><?= $textbotlang['panel']['paymentSearchBtn'] ?></div>
    <div class="stat-num"><?= number_format($total) ?></div>
    <div class="stat-meta"><?= $textbotlang['panel']['paymentClearBtn'] ?></div>
  </div>
  <div class="stat warn">
    <div class="stat-label"><?= $textbotlang['panel']['paymentColUser'] ?></div>
    <div class="stat-num"><?= number_format($todayCount) ?></div>
    <div class="stat-meta"><?= $textbotlang['panel']['paymentColAmount'] ?></div>
  </div>
</div>

<div class="card">
  <div class="toolbar">
    <div class="toolbar-title"><?= $textbotlang['panel']['paymentColMethod'] ?> <small>(<?= number_format($total) ?>)</small></div>
    <form method="GET" class="toolbar-end">
      <?php if ($lang !== ''): ?><input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>"><?php endif; ?>
      <select name="status" class="select" style="width:auto" onchange="this.form.submit()">
        <option value=""><?= $textbotlang['panel']['paymentColStatus'] ?></option>
        <?php foreach ($statusMap as $k => [$_, $lbl]): ?>
          <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
      <div class="search-box" style="min-width:230px">
        <?= icon('search', 14) ?>
        <input type="text" name="q" placeholder="<?= htmlspecialchars($textbotlang['panel']['paymentSearchTransactionPlaceholder']) ?>"
          value="<?= htmlspecialchars($search) ?>">
        <button type="button" class="search-clear">✕</button>
        <button type="submit" class="search-btn"><?= $textbotlang['panel']['paymentColTrackingCode'] ?></button>
      </div>
      <?php if ($search || $status): ?>
        <a href="payment.php<?= $lang !== '' ? '?lang=' . urlencode($lang) : '' ?>" class="btn-link" style="font-size:.78rem"><?= $textbotlang['panel']['paymentColDate'] ?></a>
      <?php endif; ?>
    </form>
  </div>

  <div class="tbl-wrap">
    <table class="tbl-lg">
      <thead>
        <tr>
          <th>#</th>
          <th><?= $textbotlang['panel']['paymentColAuthority'] ?></th>
          <th><?= $textbotlang['panel']['paymentColDescription'] ?></th>
          <th><?= $textbotlang['panel']['paymentDetailsTitle'] ?></th>
          <th><?= $textbotlang['panel']['paymentDetailUser'] ?></th>
          <th><?= $textbotlang['panel']['paymentDetailAmount'] ?></th>
          <th><?= $textbotlang['panel']['paymentDetailMethod'] ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($payments)): ?>
          <tr>
            <td>
              <div class="empty">
                <div class="empty-mark">—</div>
                <p><?= $textbotlang['panel']['paymentDetailStatus'] ?></p>
              </div>
            </td>
          </tr>
        <?php else:
          $i = $offset + 1;
          foreach ($payments as $p):
            $st = $p['payment_Status'] ?? '';
            [$cls, $lbl] = $statusMap[$st] ?? ['tag-plain', $st ?: '—'];
            $methodRaw = $p['Payment_Method'] ?? '';
            $method = $methodMap[$methodRaw] ?? ($methodRaw ?: '—');
            ?>
            <tr>
              <td style="color:var(--text-dim)"><?= $i++ ?></td>
              <td class="cell-mono"><?= htmlspecialchars($p['id_user'] ?? '—') ?></td>
              <td class="cell-mono" style="color:var(--accent)">
                <?= htmlspecialchars(trunc((string) ($p['id_order'] ?? '—'), 18)) ?>
              </td>
              <td class="cell-strong cell-num"><?= htmlspecialchars(money($p['price'] ?? 0, payment_currency($p))) ?></td>
              <td style="font-size:.8rem"><?= htmlspecialchars($method) ?></td>
              <td style="font-size:.78rem;color:var(--text-dim);white-space:nowrap">
                <?= safe_date($p['time'] ?? null, 'Y/m/d H:i') ?>
              </td>
              <td><span class="tag <?= $cls ?>"><?= $lbl ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="tbl-foot">
    <span><?= number_format($total) ?> <?= $textbotlang['panel']['paymentDetailDate'] ?> <?= $page ?> <?= $textbotlang['panel']['paymentCloseBtn'] ?> <?= $totalPages ?></span>
    <div class="pager">
      <?php $qs = fn($p) => '?q=' . urlencode($search) . '&status=' . urlencode($status) . ($lang !== '' ? '&lang=' . urlencode($lang) : '') . '&page=' . $p; ?>
      <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $qs(max(1, $page - 1)) ?>">‹</a>
      <?php for ($p2 = max(1, $page - 2); $p2 <= min($totalPages, $page + 2); $p2++): ?>
        <a class="<?= $p2 === $page ? 'active' : '' ?>" href="<?= $qs($p2) ?>"><?= $p2 ?></a>
      <?php endfor; ?>
      <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $qs(min($totalPages, $page + 1)) ?>">›</a>
    </div>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>