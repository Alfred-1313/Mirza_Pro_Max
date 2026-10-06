<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/langsync.php';
require_auth();

// a language tab ('' = all): its users, orders and payments, the way the bot's 📊 splits them
$lang = web_lang_pick('lang', '');
[$uw, $up] = $lang !== '' ? user_lang_where($lang) : ['1 = 1', []];
[$iw, $ip] = $lang !== '' ? stats_lang_where($lang, 'i') : ['1 = 1', []];
[$pw, $pp] = $lang !== '' ? stats_lang_where($lang, 'p') : ['1 = 1', []];

$totalUsers = 0;
$newToday = 0;
$totalRevenue = [];
$activeNow = 0;
$pendingPay = 0;
$txToday = 0;
$langCounts = [];

try {
    $totalUsers = db_count($pdo, "SELECT COUNT(*) FROM user WHERE $uw", $up);
    $newToday = db_count($pdo, "SELECT COUNT(*) FROM user WHERE register > ? AND $uw", array_merge([strtotime('today')], $up));
    $cnt = um_lang_counts();
    $langCounts = ['' => $cnt['total']] + array_intersect_key($cnt, array_flip(panel_langs()));
} catch (Exception $e) {
}

try {
    $totalRevenue = web_sum_by_currency("SELECT COALESCE(SUM(i.price_product),0) FROM invoice i WHERE i.Status IN ('active','end_of_time','end_of_volume','sendedwarn','send_on_hold') AND {lang}", [], 'i', $lang);
    $activeNow = db_count($pdo, "SELECT COUNT(*) FROM invoice i WHERE i.Status='active' AND $iw", $ip);
} catch (Exception $e) {
}

try {
    $pendingPay = db_count($pdo, "SELECT COUNT(*) FROM Payment_report p WHERE p.payment_Status='waiting' AND $pw", $pp);
    $txToday = db_count($pdo, "SELECT COUNT(*) FROM Payment_report p WHERE p.time > ? AND $pw", array_merge([strtotime('today')], $pp));
} catch (Exception $e) {
}

// ⏱ what hourly services brought in: their invoices cost nothing, the money
// comes by the minute (payg_charge) - shown once the feature has been used
$paygEver = 0;
$paygOpen = 0;
$paygIncome = [];
try {
    payg_ensure_schema();
    [$pgW, $pgP] = $lang !== '' ? ['lang = ?', [$lang]] : ['1 = 1', []];
    $paygEver = db_count($pdo, "SELECT COUNT(*) FROM payg_service WHERE $pgW", $pgP);
    $paygOpen = db_count($pdo, "SELECT COUNT(*) FROM payg_service WHERE status IN ('waiting', 'active', 'stopped') AND $pgW", $pgP);
    foreach (db_fetchAll($pdo, "SELECT currency, SUM(amount) s FROM payg_charge WHERE $pgW GROUP BY currency", $pgP) as $pgRow) {
        $paygIncome[$pgRow['currency']] = (float) $pgRow['s'];
    }
} catch (Exception $e) {
}

$recentInvoices = [];
$recentUsers = [];
try {
    $recentInvoices = db_fetchAll($pdo, "SELECT * FROM invoice i WHERE $iw ORDER BY time_sell DESC LIMIT 8", $ip);
} catch (Exception $e) {
}
try {
    $recentUsers = db_fetchAll($pdo, "SELECT * FROM user WHERE $uw ORDER BY register DESC LIMIT 8", $up);
} catch (Exception $e) {
}
$langQ = $lang !== '' ? '?lang=' . urlencode($lang) : '';

$pageTitle = $textbotlang['panel']['dashboardTitle'];
$activeNav = 'dashboard';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
echo web_lang_assets();
?>

<?= web_lang_tabs($lang, fn($c) => 'index.php?' . http_build_query(['lang' => $c ?: null]), true, $langCounts) ?>

<div class="stats fade-up">
    <div class="stat">
        <div class="stat-label"><?= $textbotlang['panel']['dashTotalUsers'] ?></div>
        <div class="stat-num"><?= number_format($totalUsers) ?></div>
        <div class="stat-meta"><?= $newToday > 0 ? '<span class="up">+' . $newToday . $textbotlang['panel']['dashTodaySpan'] : $textbotlang['panel']['dashNoChange'] ?>
        </div>
    </div>
    <div class="stat ok">
        <div class="stat-label"><?= $textbotlang['panel']['dashTotalRevenue'] ?></div>
        <div class="stat-num"<?= count($totalRevenue) > 1 ? ' style="font-size:1.15rem;line-height:1.6"' : '' ?>><?= web_money_lines($totalRevenue) ?></div>
        <div class="stat-meta"><?= $textbotlang['panel']['dashTotalSales'] ?></div>
    </div>
    <div class="stat warn">
        <div class="stat-label"><?= $textbotlang['panel']['dashActiveService'] ?></div>
        <div class="stat-num"><?= number_format($activeNow) ?></div>
    </div>
    <div class="stat <?= $pendingPay > 0 ? 'no' : '' ?>">
        <div class="stat-label"><?= $pendingPay > 0 ? $textbotlang['panel']['dashPendingPayment'] : $textbotlang['panel']['dashTodayTransaction'] ?></div>
        <div class="stat-num" style="<?= $pendingPay > 0 ? 'color:var(--no)' : '' ?>">
            <?= number_format($pendingPay > 0 ? $pendingPay : $txToday) ?>
        </div>
        <div class="stat-meta">
            <?= $pendingPay > 0 ? $textbotlang['panel']['dashReviewLink'] : $textbotlang['panel']['dashStatusRegistered'] ?>
        </div>
    </div>
    <?php if ($paygEver > 0): ?>
    <div class="stat">
        <div class="stat-label"><a href="payg.php<?= $langQ ?>" style="color:inherit"><?= htmlspecialchars($textbotlang['panel']['pgPageTitle']) ?></a></div>
        <div class="stat-num"<?= count($paygIncome) > 1 ? ' style="font-size:1.15rem;line-height:1.6"' : '' ?>><?= web_money_lines($paygIncome) ?></div>
        <div class="stat-meta"><?= htmlspecialchars(sprintf($textbotlang['panel']['pgDashMeta'], number_format($paygOpen))) ?></div>
    </div>
    <?php endif; ?>
</div>

<div class="two-col">
    <div class="card fade-up d1">
        <div class="card-head">
            <div>
                <div class="card-title"><?= $textbotlang['panel']['dashRecentOrders'] ?></div>
                <div class="card-subtitle"><?= count($recentInvoices) ?> <?= $textbotlang['panel']['dashRecentItem'] ?></div>
            </div>
            <a href="invoice.php<?= $langQ ?>" class="btn-link" style="font-size:.78rem"><?= $textbotlang['panel']['dashViewAll'] ?></a>
        </div>
        <div class="tbl-wrap">
            <table class="tbl-sm">
                <thead>
                    <tr>
                        <th data-m="0"><?= $textbotlang['panel']['dashColUser'] ?></th>
                        <th><?= $textbotlang['panel']['dashColProduct'] ?></th>
                        <th><?= $textbotlang['panel']['dashColAmount'] ?></th>
                        <th><?= $textbotlang['panel']['dashColStatus'] ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentInvoices)): ?>
                        <tr>
                            <td colspan="4">
                                <div class="empty" style="padding:24px">
                                    <p><?= $textbotlang['panel']['dashNoOrdersYet'] ?></p>
                                </div>
                            </td>
                        </tr>
                    <?php else:
                        $statusMap = [
                            'active' => ['tag-ok', $textbotlang['panel']['dashStatusActive']],
                            'end_of_time' => ['tag-warn', $textbotlang['panel']['dashStatusExpired']],
                            'end_of_volume' => ['tag-no', $textbotlang['panel']['dashStatusVolumeFinished']],
                            'sendedwarn' => ['tag-warn', $textbotlang['panel']['dashStatusWarning']],
                            'send_on_hold' => ['tag-plain', $textbotlang['panel']['dashStatusWaiting']],
                        ];
                        foreach ($recentInvoices as $inv):
                            [$tagClass, $label] = $statusMap[$inv['Status'] ?? ''] ?? ['tag-plain', $inv['Status'] ?? '—'];
                            ?>
                            <tr>
                                <td class="cm cf" data-m="0"><?= htmlspecialchars($inv['id_user'] ?? '—') ?></td>
                                <td class="cs"
                                    style="max-width:150px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                    <?= htmlspecialchars(trunc($inv['name_product'] ?? '—', 20)) ?>
                                </td>
                                <td class="cn" style="white-space:nowrap">
                                    <?= htmlspecialchars(money($inv['price_product'] ?? 0, invoice_currency($inv))) ?>
                                </td>
                                <td><span class="tag <?= $tagClass ?>"><?= $label ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card fade-up d2">
        <div class="card-head">
            <div>
                <div class="card-title"><?= $textbotlang['panel']['dashRecentUsers'] ?></div>
                <div class="card-subtitle"><?= count($recentUsers) ?> <?= $textbotlang['panel']['dashRecentItem2'] ?></div>
            </div>
            <a href="users.php<?= $langQ ?>" class="btn-link" style="font-size:.78rem"><?= $textbotlang['panel']['dashViewAll2'] ?></a>
        </div>
        <div class="tbl-wrap">
            <table class="tbl-sm">
                <thead>
                    <tr>
                        <th data-m="0"><?= $textbotlang['panel']['dashColId'] ?></th>
                        <th><?= $textbotlang['panel']['dashColName'] ?></th>
                        <th><?= $textbotlang['panel']['dashColBalance'] ?></th>
                        <th><?= $textbotlang['panel']['dashColGroup'] ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentUsers)): ?>
                        <tr>
                            <td colspan="4">
                                <div class="empty" style="padding:24px">
                                    <p><?= $textbotlang['panel']['dashNoUsersYet'] ?></p>
                                </div>
                            </td>
                        </tr>
                    <?php else:
                        foreach ($recentUsers as $u):
                            $agent = $u['agent'] ?? 'f';
                            $isBlocked = ($u['User_Status'] ?? '') === 'block';
                            $name = $u['namecustom'] ?? '';
                            if ($name === 'none')
                                $name = '';
                            $uname = $u['username'] ?? '';
                            if ($uname === 'none')
                                $uname = '';
                            ?>
                            <tr>
                                <td class="cm cf" data-m="0"><?= htmlspecialchars($u['id']) ?></td>
                                <td>
                                    <?php if ($name): ?>
                                        <span class="cs"><?= htmlspecialchars(trunc($name, 14)) ?></span>
                                    <?php elseif ($uname): ?>
                                        <span class="cm" style="color:var(--ac)">@<?= htmlspecialchars(trunc($uname, 12)) ?></span>
                                    <?php else: ?>
                                        <span class="cf">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="cn" style="white-space:nowrap">
                                    <?= htmlspecialchars(money($u['Balance'] ?? 0, currency_for_user($u))) ?>
                                </td>
                                <td>
                                    <?php if ($isBlocked): ?>
                                        <span class="tag tag-no" style="font-size:.65rem"><?= $textbotlang['panel']['dashLabelBlocked'] ?></span>
                                    <?php else: ?>
                                        <span class="tag <?= user_role_tag($agent) ?>" style="font-size:.65rem">
                                            <?= user_role_label($agent) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>