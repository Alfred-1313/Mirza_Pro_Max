<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

// 🖥 نودهای ربکا - the very screens of the bot's 🛠 قابلیت های پنل ← a Rebecca
// panel ← 🖥 مدیریت نودها (ربکا) (rbnodes.php): every node with its state, CPU, RAM,
// speed and traffic, its actions, a new node, the report picture, a node's
// traffic chart and the history of ups and downs. Rebecca panels only.
$t = $textbotlang['panel'];
$panels = rbn_panels();
$pid = (int) ($_GET['p'] ?? $_POST['p'] ?? ($panels[0]['id'] ?? 0));
$panel = rbn_panel($pid);
$here = fn($extra = []) => 'nodes.php?' . http_build_query(['p' => $pid] + $extra);
$plain = fn($html) => html_entity_decode(strip_tags((string) $html), ENT_QUOTES, 'UTF-8');
$rt = panel_texts()['Admin']['RbNodes'];

// a picture: the report, or one node's traffic
if ($panel !== null && isset($_GET['img'])) {
    if ($_GET['img'] === 'daily') {
        [$node] = rbn_node($panel, (int) ($_GET['n'] ?? 0));
        $rows = $node === null ? null : rbn_daily($panel, (int) $node['id'], 30);
        $png = $rows === null ? null : rbn_daily_png($panel, $node, $rows, 30);
    } else {
        [$png] = rbn_build_report($panel);
    }
    if ($png === null || !is_file($png)) {
        http_response_code(502);
        exit;
    }
    header('Content-Type: image/png');
    header('Cache-Control: no-store');
    readfile($png);
    @unlink($png);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $panel !== null) {
    csrf_check_post();
    $do = (string) ($_POST['do'] ?? '');
    $nid = (int) ($_POST['n'] ?? 0);
    $label = $rt['act'][$do] ?? $do;
    $ok = false;
    $why = '';
    if ($do === 'dest') {
        // 📮 where this panel's watch reports go; a test message shows it works
        $label = $t['rbnDest'];
        $v = (string) ($_POST['dest'] ?? '');
        ob_start();
        require_once __DIR__ . '/../botapi.php';
        if ($v === 'chat') {
            $chat = rbn_chat_value($_POST['chat'] ?? '');
            if ($chat === null) {
                $why = $t['rbnDestChatNeeded'];
            } else {
                [$chatId, $w] = rbn_chat_try($panel, $chat);
                if ($chatId === null) {
                    $why = sprintf($t['rbnDestFail'], $w);
                } else {
                    rbn_dest_set($panel, 'chat:' . $chatId);
                    $ok = true;
                }
            }
        } elseif (in_array($v, ['group', 'bot', 'off', 'auto'], true)) {
            rbn_dest_set($panel, $v);
            $ok = true;
            if ($v !== 'off') {
                rbn_alert($panel, strtr($rt['destTestText'], ['{panel}' => htmlspecialchars(rbn_names($panel)), '{host}' => htmlspecialchars(rbn_host($panel))]));
            }
        }
        ob_end_clean();
    } elseif ($do === 'watch') {
        rbn_watch_set(!rbn_watch_on());
        $ok = true;
        $label = $t['rbnWatch'];
    } elseif ($do === 'load') {
        // 🚨 high CPU/RAM: this bot's own setting, for every Rebecca panel
        $label = $t['rbnLoadTitle'];
        $cpu = rbn_load_value($_POST['cpu'] ?? '');
        $ram = rbn_load_value($_POST['ram'] ?? '');
        if ($cpu === null || $ram === null) {
            $why = $plain($rt['loadBad']);
        } else {
            rbn_load_set(!empty($_POST['on']));
            rbn_load_limit_set('cpu', $cpu);
            rbn_load_limit_set('ram', $ram);
            $ok = true;
        }
    } elseif ($do === 'rep') {
        // 🔁 reminders while a node stays down / over its limit: this bot's own setting, for every Rebecca panel
        $kind = ($_POST['kind'] ?? '') === 'load' ? 'load' : 'down';
        $label = $t[$kind === 'load' ? 'rbnRepTitleLoad' : 'rbnRepTitleDown'];
        $every = rbn_rep_value('e', $_POST['every'] ?? '');
        $max = rbn_rep_value('n', $_POST['max'] ?? '');
        if ($every === null || $max === null) {
            $why = $t['rbnRepBad'];
        } else {
            rbn_rep_set_on($kind, !empty($_POST['on']));
            rbn_rep_set($kind, 'every', $every);
            rbn_rep_set($kind, 'max', $max);
            $ok = true;
        }
    } elseif ($do === 'sua') {
        [$nodes, $why] = rbn_nodes($panel);
        $ids = array_map(fn($n) => (int) $n['id'], array_filter($nodes ?? [], fn($n) => ($n['status'] ?? '') !== 'disabled'));
        [$ok, $why] = $nodes === null ? [false, $why] : rbn_update_all_services($panel, $ids);
        $label = $rt['btnUpdateAll'];
    } elseif ($do === 'cv') {
        [$ok, $why] = rbn_do($panel, $nid, 'cv', (string) ($_POST['version'] ?? ''));
        $label = $rt['act']['core'] . ' ' . (string) ($_POST['version'] ?? '');
    } elseif ($do === 'edit') {
        $label = $rt['act']['ed'];
        [$node, $why] = rbn_node($panel, $nid);
        if ($node !== null) {
            $ok = true;
            foreach (['name', 'address', 'port', 'api_port', 'coef'] as $f) {
                $raw = trim((string) ($_POST[$f] ?? ''));
                if ($raw === '' || $raw === rbn_field_now($node, $f)) {
                    continue;
                }
                $v = rbn_field_value($f, $raw);
                if ($v === null) {
                    [$ok, $why] = [false, $t['rbnBadValue'] . ' — ' . $plain($rt['field'][$f])];
                    break;
                }
                [$ok, $why] = rbn_edit($panel, $nid, $f, $v);
                if (!$ok) {
                    break;
                }
            }
        }
    } elseif ($do === 'add') {
        $label = $rt['btnAdd'];
        $d = [];
        foreach (['name', 'address'] as $f) {
            $d[$f] = rbn_field_value($f, $_POST[$f] ?? '');
        }
        $d['port'] = trim((string) ($_POST['port'] ?? '')) === '' ? 62050 : rbn_field_value('port', $_POST['port']);
        $d['api_port'] = trim((string) ($_POST['api_port'] ?? '')) === '' ? 62051 : rbn_field_value('api_port', $_POST['api_port']);
        $d['coef'] = trim((string) ($_POST['coef'] ?? '')) === '' ? 1 : rbn_field_value('coef', $_POST['coef']);
        if (in_array(null, $d, true)) {
            $why = $t['rbnBadValue'];
        } elseif ($d['port'] === $d['api_port']) {
            $why = $plain($rt['addSamePorts']);
        } else {
            [$node, $why] = rbn_add($panel, $d);
            if ($node !== null) {
                $ok = true;
                // the install bundle is shown once, on the next page, and not kept
                $_SESSION['rbn_bundle'] = ['name' => (string) $node['name'], 'port' => $d['port'], 'api' => $d['api_port'],
                    'pem' => trim((string) ($node['node_certificate'] ?? '')) . "\n" . trim((string) ($node['node_certificate_key'] ?? ''))];
            }
        }
    } elseif ($nid > 0 && ($do === 'dl' || $do === 'ds' || $do === 'en' || isset(rbn_actions()[$do]))) {
        [$ok, $why] = rbn_do($panel, $nid, $do);
    }
    flash($ok ? 'success' : 'error', $ok ? $plain(strtr($rt['done'], ['{action}' => $label])) : $plain(strtr($rt['failed'], ['{action}' => $label, '{why}' => $why])));
    header('Location: ' . $here() . ($nid > 0 && $do !== 'dl' ? '#node' . $nid : ''));
    exit;
}

$nodes = null;
$why = '';
$releases = [];
if ($panel !== null) {
    // CPU, RAM and speed as they are this moment (Rebecca's list leaves them out)
    [$nodes, $why] = rbn_nodes_live($panel);
    if ($nodes) {
        [$releases] = rbn_xray_releases($panel);
    }
}
$bundle = $_SESSION['rbn_bundle'] ?? null;
unset($_SESSION['rbn_bundle']);
$events = $panel !== null ? rbn_events($panel, 30) : [];

$csrf = '<input type="hidden" name="_csrf" value="' . csrf_token() . '"><input type="hidden" name="p" value="' . $pid . '">';
$form = fn($do, $n, $label, $class = 'btn btn-sm', $confirm = '') => '<form method="POST" action="' . htmlspecialchars($here()) . '" data-ajax="off" style="display:inline"'
    . ($confirm !== '' ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm, JSON_UNESCAPED_UNICODE)) . ')"' : '') . '>' . $csrf
    . '<input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="n" value="' . (int) $n . '"><button type="submit" class="' . $class . '">' . htmlspecialchars($plain($label)) . '</button></form>';
$statusTag = function ($st) {
    $cls = ['connected' => 'tag-ok', 'connecting' => 'tag-warn', 'limited' => 'tag-warn', 'disabled' => 'tag-plain'][(string) $st] ?? 'tag-no';
    return '<span class="tag ' . $cls . '">' . htmlspecialchars(rbn_status_name($st)) . '</span>';
};
$bar = function ($pct) {
    if ($pct === null || $pct === '') {
        return '<span class="rbn-mute">—</span>';
    }
    $p = max(0, min(100, (float) $pct));
    $cls = $p >= 85 ? 'no' : ($p >= 65 ? 'warn' : 'ok');
    return '<div class="rbn-bar"><span class="' . $cls . '" style="width:' . round($p, 1) . '%"></span></div><small>' . number_format($p, 1) . '٪</small>';
};

// 🔁 the reminder form of 'down' or 'load': on/off, the gap, how many messages in all
$repForm = function ($kind) use ($t, $here, $csrf) {
    $every = rbn_rep_every($kind);
    $max = rbn_rep_max($kind);
    $everyOpts = [2, 5, 10, 15, 30, 60, 120, 360, 720, 1440];
    $maxOpts = [2, 3, 5, 10, 20, 50, 100, 0];
    if (!in_array($every, $everyOpts, true)) {
        $everyOpts[] = $every;
        sort($everyOpts);
    }
    if (!in_array($max, $maxOpts, true)) {
        array_splice($maxOpts, -1, 0, [$max]);
    }
    $h = '<form method="POST" action="' . htmlspecialchars($here()) . '#rep' . $kind . '" data-ajax="off" class="rbn-form" style="padding-top:12px">' . $csrf
        . '<input type="hidden" name="do" value="rep"><input type="hidden" name="kind" value="' . $kind . '">'
        . '<label>' . htmlspecialchars($t['rbnRepOn']) . '<span><input type="checkbox" name="on" value="1"' . (rbn_rep_on($kind) ? ' checked' : '') . '> ' . htmlspecialchars($t['rbnOn']) . '</span></label>'
        . '<label>' . htmlspecialchars($t['rbnRepEvery']) . '<select class="select" name="every">';
    foreach ($everyOpts as $v) {
        $h .= '<option value="' . $v . '"' . ($v === $every ? ' selected' : '') . '>' . htmlspecialchars(sprintf($t['rbnRepMinutes'], $v)) . '</option>';
    }
    $h .= '</select></label><label>' . htmlspecialchars($t['rbnRepMax']) . '<select class="select" name="max">';
    foreach ($maxOpts as $v) {
        $h .= '<option value="' . $v . '"' . ($v === $max ? ' selected' : '') . '>' . htmlspecialchars($v === 0 ? $t['rbnRepUnlimited'] : sprintf($t['rbnRepMessages'], $v)) . '</option>';
    }
    return $h . '</select></label><label>&nbsp;<button type="submit" class="btn btn-sm btn-primary">' . icon('check', 13) . ' ' . htmlspecialchars($t['bottextSaveBtn']) . '</button></label></form>';
};

$pageTitle = $t['rbnPageTitle'];
$pageLede = $t['rbnPageLede'];
$activeNav = 'nodes';
include __DIR__ . '/inc/layout_head.php';
?>
<style>
  .rbn-grid { display: grid; gap: 14px; }
  .rbn-node .card-body { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px 18px; }
  .rbn-k { color: var(--mute); font-size: .74rem; margin-bottom: 4px; }
  .rbn-v { font-size: .86rem; direction: ltr; text-align: right; unicode-bidi: plaintext; }
  .rbn-bar { height: 8px; border-radius: 6px; background: var(--sf3); overflow: hidden; margin: 6px 0 2px; }
  .rbn-bar span { display: block; height: 100%; border-radius: 6px; }
  .rbn-bar .ok { background: var(--ok); } .rbn-bar .warn { background: var(--warn); } .rbn-bar .no { background: var(--no); }
  .rbn-mute { color: var(--mute); }
  .rbn-actions { display: flex; flex-wrap: wrap; gap: 6px; padding: 0 16px 14px; }
  .rbn-msg { grid-column: 1 / -1; color: var(--no); font-size: .8rem; direction: ltr; text-align: right; unicode-bidi: plaintext; }
  .rbn-img { width: 100%; height: auto; border-radius: 10px; border: 1px solid var(--bd); display: block; }
  .rbn-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; padding: 0 16px 14px; }
  .rbn-form label { font-size: .74rem; color: var(--mute); display: grid; gap: 4px; }
  details.rbn-more > summary { cursor: pointer; padding: 0 16px 12px; color: var(--ach); font-size: .8rem; }
  .rbn-pem { width: 100%; min-height: 140px; direction: ltr; font-family: ui-monospace, monospace; font-size: .72rem; }
</style>

<?php if (!$panels): ?>
  <div class="card fade-up"><div class="card-body"><div class="empty" style="padding:24px"><p><?= htmlspecialchars($t['rbnNoPanel']) ?></p></div></div></div>
<?php include __DIR__ . '/inc/layout_foot.php'; return; endif; ?>

<?php if (count($panels) > 1): ?>
  <div class="lang-tabs fade-up">
    <?php foreach ($panels as $pp): ?>
      <a class="lang-tab<?= (int) $pp['id'] === $pid ? ' on' : '' ?>" href="nodes.php?p=<?= (int) $pp['id'] ?>"><?= icon('server', 15) ?><?= htmlspecialchars((string) $pp['name_panel']) ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($bundle): ?>
  <div class="card fade-up" style="margin-bottom:14px">
    <div class="card-head"><div><div class="card-title">🔐 <?= htmlspecialchars(sprintf($t['rbnBundleTitle'], $bundle['name'])) ?></div><div class="card-subtitle"><?= htmlspecialchars($t['rbnBundleSub']) ?></div></div></div>
    <div class="card-body">
      <textarea class="input rbn-pem" readonly onclick="this.select()"><?= htmlspecialchars($bundle['pem']) ?></textarea>
      <div class="field-hint" style="margin-top:10px"><?= htmlspecialchars(sprintf($t['rbnBundleHow'], (int) $bundle['port'], (int) $bundle['api'])) ?></div>
      <pre class="rbn-pem" style="min-height:0;white-space:pre-wrap;margin-top:8px">curl -sL https://raw.githubusercontent.com/rebeccapanel/Rebecca/master/scripts/rebecca/rebecca-node-binary.sh | sudo bash -s -- install</pre>
    </div>
  </div>
<?php endif; ?>

<?php if ($nodes === null): ?>
  <div class="notice notice-no"><?= htmlspecialchars($plain($why)) ?></div>
<?php else:
    $up = count(array_filter($nodes, fn($n) => ($n['status'] ?? '') === 'connected'));
    $off = count(array_filter($nodes, fn($n) => ($n['status'] ?? '') === 'disabled')); ?>
  <div class="stats fade-up" style="margin-bottom:14px">
    <div class="stat ok"><div class="stat-label"><?= htmlspecialchars($t['rbnUp']) ?></div><div class="stat-num"><?= $up ?></div></div>
    <div class="stat <?= count($nodes) - $up - $off > 0 ? 'no' : '' ?>"><div class="stat-label"><?= htmlspecialchars($t['rbnDown']) ?></div><div class="stat-num"><?= count($nodes) - $up - $off ?></div></div>
    <div class="stat"><div class="stat-label"><?= htmlspecialchars($t['rbnOff']) ?></div><div class="stat-num"><?= $off ?></div></div>
    <div class="stat"><div class="stat-label"><?= htmlspecialchars($t['rbnWatch']) ?></div><div class="stat-num" style="font-size:1rem"><?= rbn_watch_on() ? '🔔 ' . htmlspecialchars($t['rbnOn']) : '🔕 ' . htmlspecialchars($t['rbnOffWord']) ?></div>
      <div class="stat-meta"><?= $form('watch', 0, rbn_watch_on() ? $t['rbnWatchStop'] : $t['rbnWatchStart']) ?></div></div>
  </div>

  <?php $dest = rbn_dest($panel); $others = array_filter(rbn_twins($panel), fn($x) => (int) $x['id'] !== $pid); ?>
  <div class="card fade-up" id="dest" style="margin-bottom:14px">
    <div class="card-head"><div><div class="card-title">📮 <?= htmlspecialchars($t['rbnDest']) ?> — <?= htmlspecialchars((string) $panel['name_panel']) ?> <span class="rbn-v rbn-mute" style="font-size:.78rem"><?= htmlspecialchars(rbn_host($panel)) ?></span></div>
      <div class="card-subtitle"><?= htmlspecialchars($t['rbnDestSub']) ?></div></div></div>
    <?php if ($others): ?><div class="notice notice-warn" style="margin:12px 16px 0"><?= htmlspecialchars($plain(strtr($rt['hubTwin'], ['{twins}' => implode('، ', array_map(fn($x) => (string) $x['name_panel'], $others))]))) ?></div><?php endif; ?>
    <form method="POST" action="<?= htmlspecialchars($here()) ?>" data-ajax="off" class="rbn-form" style="padding-top:12px">
      <?= $csrf ?><input type="hidden" name="do" value="dest">
      <label><?= htmlspecialchars($t['rbnDest']) ?>
        <select class="select" name="dest">
          <?php if ($dest === ''): ?><option value="" selected><?= htmlspecialchars($plain($rt['destUnset'])) ?></option><?php endif; ?>
          <?php if (rbn_report_group() !== ''): ?><option value="group"<?= $dest === 'group' ? ' selected' : '' ?>><?= htmlspecialchars($plain($rt['destGroup'])) ?></option><?php endif; ?>
          <option value="chat"<?= strpos($dest, 'chat:') === 0 ? ' selected' : '' ?>><?= htmlspecialchars($plain($rt['destChat'])) ?></option>
          <option value="bot"<?= $dest === 'bot' ? ' selected' : '' ?>><?= htmlspecialchars($plain($rt['destBot'])) ?></option>
          <option value="off"<?= $dest === 'off' ? ' selected' : '' ?>><?= htmlspecialchars($plain($rt['destOff'])) ?></option>
          <option value="auto"<?= $dest === 'auto' ? ' selected' : '' ?>><?= htmlspecialchars($plain($rt['destAutoLabel'])) ?></option>
        </select></label>
      <label><?= htmlspecialchars($t['rbnDestChat']) ?><input class="input" name="chat" dir="ltr" placeholder="-1001234567890" value="<?= strpos($dest, 'chat:') === 0 ? htmlspecialchars(substr($dest, 5)) : '' ?>"></label>
      <label>&nbsp;<button type="submit" class="btn btn-sm btn-primary"><?= icon('check', 13) ?> <?= htmlspecialchars($t['rbnDestSave']) ?></button></label>
    </form>
  </div>

  <div class="card fade-up" id="repdown" style="margin-bottom:14px">
    <div class="card-head"><div><div class="card-title">🔁 <?= htmlspecialchars($t['rbnRepTitleDown']) ?> <span class="tag <?= rbn_rep_on('down') ? 'tag-ok' : 'tag-plain' ?>"><?= htmlspecialchars(rbn_rep_on('down') ? $t['rbnOn'] : $t['rbnOffWord']) ?></span></div>
      <div class="card-subtitle"><?= htmlspecialchars($t['rbnRepSubDown']) ?></div></div></div>
    <?= $repForm('down') ?>
  </div>

  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
    <a class="btn btn-primary" href="#add"><?= icon('plus', 13) ?> <?= htmlspecialchars($plain($rt['btnAdd'])) ?></a>
    <a class="btn" href="#report">📊 <?= htmlspecialchars($t['rbnReportBtn']) ?></a>
    <?php if ($nodes): ?><?= $form('sua', 0, $rt['btnUpdateAll'], 'btn', $plain(strtr($rt['ask']['sua'], ['{n}' => count($nodes)]))) ?><?php endif; ?>
  </div>

  <div class="card fade-up" id="load" style="margin-bottom:14px">
    <div class="card-head"><div><div class="card-title">🚨 <?= htmlspecialchars($t['rbnLoadTitle']) ?> <span class="tag <?= rbn_load_on() ? 'tag-ok' : 'tag-plain' ?>"><?= htmlspecialchars(rbn_load_on() ? $t['rbnOn'] : $t['rbnOffWord']) ?></span></div>
      <div class="card-subtitle"><?= htmlspecialchars($t['rbnLoadSub']) ?></div></div></div>
    <form method="POST" action="<?= htmlspecialchars($here()) ?>#load" data-ajax="off" class="rbn-form" style="padding-top:12px">
      <?= $csrf ?><input type="hidden" name="do" value="load">
      <label><?= htmlspecialchars($t['rbnLoadOn']) ?><span><input type="checkbox" name="on" value="1"<?= rbn_load_on() ? ' checked' : '' ?>> <?= htmlspecialchars($t['rbnOn']) ?></span></label>
      <label><?= htmlspecialchars($t['rbnLoadCpu']) ?><input class="input" type="number" name="cpu" min="1" max="100" dir="ltr" value="<?= rbn_load_limit('cpu') ?>"></label>
      <label><?= htmlspecialchars($t['rbnLoadRam']) ?><input class="input" type="number" name="ram" min="1" max="100" dir="ltr" value="<?= rbn_load_limit('ram') ?>"></label>
      <label>&nbsp;<button type="submit" class="btn btn-sm btn-primary"><?= icon('check', 13) ?> <?= htmlspecialchars($t['bottextSaveBtn']) ?></button></label>
    </form>
    <div class="card-subtitle" id="repload" style="padding:6px 16px 0">🔁 <?= htmlspecialchars($t['rbnRepTitleLoad']) ?> <span class="tag <?= rbn_rep_on('load') ? 'tag-ok' : 'tag-plain' ?>"><?= htmlspecialchars(rbn_rep_on('load') ? $t['rbnOn'] : $t['rbnOffWord']) ?></span> — <?= htmlspecialchars($t['rbnRepSubLoad']) ?></div>
    <?= $repForm('load') ?>
  </div>

  <div class="rbn-grid">
  <?php if (!$nodes): ?>
    <div class="card fade-up"><div class="card-body"><div class="empty" style="padding:24px"><p><?= htmlspecialchars($plain($rt['hubEmpty'])) ?></p></div></div></div>
  <?php endif; ?>
  <?php foreach ($nodes as $n): $id = (int) $n['id']; $st = (string) ($n['status'] ?? ''); $since = rbn_since($panel, $id); $ask = fn($a) => $plain(strtr($rt['ask'][$a], ['{name}' => (string) $n['name']])); ?>
    <div class="card fade-up rbn-node" id="node<?= $id ?>">
      <div class="card-head"><div><div class="card-title"><?= rbn_status_icon($st) ?> <?= htmlspecialchars((string) $n['name']) ?> <?= $statusTag($st) ?></div>
        <div class="card-subtitle"><span class="rbn-v"><?= htmlspecialchars((string) ($n['address'] ?? '')) ?> · <?= (int) ($n['port'] ?? 0) ?> / API <?= (int) ($n['api_port'] ?? 0) ?></span><?= $since !== null ? ' · ' . htmlspecialchars(sprintf($t['rbnSince'], rbn_when($since))) : '' ?></div></div></div>
      <div class="card-body">
        <?php if (!empty($n['message']) && $st !== 'connected'): ?><div class="rbn-msg"><?= htmlspecialchars((string) $n['message']) ?></div><?php endif; ?>
        <?php $live = rbn_has_metrics($n); ?>
        <div><div class="rbn-k">CPU<?= $live && !empty($n['cpu_cores']) ? ' · ' . (int) $n['cpu_cores'] . ' ' . htmlspecialchars($t['rbnCores']) : '' ?></div><?= $bar($live ? ($n['cpu_usage_percent'] ?? null) : null) ?></div>
        <div><div class="rbn-k">RAM<?= $live ? ' · ' . rbn_bytes($n['memory_used'] ?? 0) . ' / ' . rbn_bytes($n['memory_total']) : '' ?></div><?= $bar($live ? ($n['memory_usage_percent'] ?? null) : null) ?></div>
        <div><div class="rbn-k"><?= htmlspecialchars($t['rbnSpeed']) ?></div><div class="rbn-v"><?= $live ? '↑ ' . rbn_bytes($n['upload_speed'] ?? 0) . '/s · ↓ ' . rbn_bytes($n['download_speed'] ?? 0) . '/s' : '<span class="rbn-mute">—</span>' ?></div></div>
        <div><div class="rbn-k"><?= htmlspecialchars($t['rbnTraffic']) ?><?= (float) ($n['usage_coefficient'] ?? 1) != 1 ? ' · x' . (float) $n['usage_coefficient'] : '' ?></div><div class="rbn-v">↑ <?= rbn_bytes($n['uplink'] ?? 0) ?> · ↓ <?= rbn_bytes($n['downlink'] ?? 0) ?></div></div>
        <div><div class="rbn-k"><?= htmlspecialchars($t['rbnVersions']) ?></div><div class="rbn-v">Xray <?= htmlspecialchars((string) (($n['xray_version'] ?? '') ?: '—')) ?> · node <?= htmlspecialchars((string) (($n['node_service_version'] ?? '') ?: '—')) ?></div></div>
        <div><div class="rbn-k"><?= htmlspecialchars($t['rbnUptime']) ?></div><div class="rbn-v"><?= $live && !empty($n['uptime_seconds']) ? htmlspecialchars(rbn_duration((int) $n['uptime_seconds'])) : '—' ?></div></div>
      </div>
      <div class="rbn-actions">
        <?= $form('rc', $id, $rt['act']['rc']) ?>
        <?= $form('sy', $id, $rt['act']['sy']) ?>
        <?= $form('rs', $id, $rt['act']['rs'], 'btn btn-sm', $ask('rs')) ?>
        <?= $form('sr', $id, $rt['act']['sr'], 'btn btn-sm', $ask('sr')) ?>
        <?= $form('su', $id, $rt['act']['su'], 'btn btn-sm', $ask('su')) ?>
        <?= $st === 'disabled' ? $form('en', $id, $rt['act']['en'], 'btn btn-sm btn-primary') : $form('ds', $id, $rt['act']['ds'], 'btn btn-sm', $ask('ds')) ?>
        <?= $form('ur', $id, $rt['act']['ur'], 'btn btn-sm', $ask('ur')) ?>
        <?= $form('hr', $id, $rt['act']['hr'], 'btn btn-sm btn-no', $ask('hr')) ?>
        <?= $form('dl', $id, $rt['act']['dl'], 'btn btn-sm btn-no', $ask('dl')) ?>
      </div>
      <details class="rbn-more"><summary><?= htmlspecialchars($t['rbnMore']) ?></summary>
        <?php if ($releases): ?>
        <form method="POST" action="<?= htmlspecialchars($here()) ?>" data-ajax="off" class="rbn-form" onsubmit="return confirm(<?= htmlspecialchars(json_encode($t['rbnCoreConfirm'], JSON_UNESCAPED_UNICODE)) ?>)">
          <?= $csrf ?><input type="hidden" name="do" value="cv"><input type="hidden" name="n" value="<?= $id ?>">
          <label><?= htmlspecialchars($plain($rt['act']['core'])) ?>
            <select class="select" name="version"><?php foreach ($releases as $tag): ?><option value="<?= htmlspecialchars($tag) ?>"><?= htmlspecialchars($tag) ?><?= ltrim($tag, 'v') === ltrim((string) ($n['xray_version'] ?? ''), 'v') ? ' ✓' : '' ?></option><?php endforeach; ?></select></label>
          <label>&nbsp;<button type="submit" class="btn btn-sm btn-primary"><?= htmlspecialchars($t['rbnInstall']) ?></button></label>
        </form>
        <?php endif; ?>
        <form method="POST" action="<?= htmlspecialchars($here()) ?>" data-ajax="off" class="rbn-form">
          <?= $csrf ?><input type="hidden" name="do" value="edit"><input type="hidden" name="n" value="<?= $id ?>">
          <?php foreach (['name', 'address', 'port', 'api_port', 'coef'] as $f): ?>
            <label><?= htmlspecialchars($plain($rt['field'][$f])) ?><input class="input" name="<?= $f ?>" value="<?= htmlspecialchars(rbn_field_now($n, $f)) ?>" dir="ltr"></label>
          <?php endforeach; ?>
          <label>&nbsp;<button type="submit" class="btn btn-sm btn-primary"><?= icon('check', 13) ?> <?= htmlspecialchars($t['rbnSave']) ?></button></label>
        </form>
        <div style="padding:0 16px 14px"><img class="rbn-img" loading="lazy" alt="" src="<?= htmlspecialchars($here(['img' => 'daily', 'n' => $id])) ?>"></div>
      </details>
    </div>
  <?php endforeach; ?>
  </div>

  <div class="card fade-up" id="add" style="margin-top:14px">
    <div class="card-head"><div><div class="card-title"><?= htmlspecialchars($plain($rt['btnAdd'])) ?></div><div class="card-subtitle"><?= htmlspecialchars($t['rbnAddSub']) ?></div></div></div>
    <form method="POST" action="<?= htmlspecialchars($here()) ?>" data-ajax="off" class="rbn-form" style="padding-top:12px">
      <?= $csrf ?><input type="hidden" name="do" value="add">
      <label><?= htmlspecialchars($plain($rt['field']['name'])) ?><input class="input" name="name" required dir="ltr" placeholder="Germany-1"></label>
      <label><?= htmlspecialchars($plain($rt['field']['address'])) ?><input class="input" name="address" required dir="ltr" placeholder="1.2.3.4"></label>
      <label><?= htmlspecialchars($plain($rt['field']['port'])) ?><input class="input" name="port" dir="ltr" placeholder="62050"></label>
      <label><?= htmlspecialchars($plain($rt['field']['api_port'])) ?><input class="input" name="api_port" dir="ltr" placeholder="62051"></label>
      <label><?= htmlspecialchars($plain($rt['field']['coef'])) ?><input class="input" name="coef" dir="ltr" placeholder="1"></label>
      <label>&nbsp;<button type="submit" class="btn btn-primary"><?= icon('plus', 13) ?> <?= htmlspecialchars($t['rbnCreate']) ?></button></label>
    </form>
  </div>

  <div class="card fade-up" id="report" style="margin-top:14px">
    <div class="card-head"><div><div class="card-title">📊 <?= htmlspecialchars($t['rbnReportBtn']) ?></div><div class="card-subtitle"><?= htmlspecialchars($t['rbnReportSub']) ?></div></div></div>
    <div class="card-body"><img class="rbn-img" loading="lazy" alt="" src="<?= htmlspecialchars($here(['img' => 'report'])) ?>"></div>
  </div>
<?php endif; ?>

<div class="card fade-up" id="history" style="margin-top:14px">
  <div class="card-head"><div><div class="card-title">📜 <?= htmlspecialchars($t['rbnHistory']) ?></div><div class="card-subtitle"><?= htmlspecialchars($t['rbnHistorySub']) ?></div></div></div>
  <div class="tbl-wrap"><table class="tbl-sm"><thead><tr><th><?= htmlspecialchars($t['rbnColWhen']) ?></th><th><?= htmlspecialchars($t['rbnColNode']) ?></th><th><?= htmlspecialchars($t['rbnColChange']) ?></th><th><?= htmlspecialchars($t['rbnColWhy']) ?></th></tr></thead><tbody>
    <?php if (!$events): ?><tr><td colspan="4"><div class="empty" style="padding:18px"><p><?= htmlspecialchars($plain($rt['historyEmpty'])) ?></p></div></td></tr><?php endif; ?>
    <?php foreach ($events as $e): ?>
      <tr><td><?= htmlspecialchars(rbn_when($e['at'])) ?></td>
        <td><?= (int) $e['node_id'] === 0 ? htmlspecialchars($plain($rt['panelItself'])) : htmlspecialchars((string) $e['name']) ?></td>
        <td><?= rbn_status_icon($e['status_to']) ?> <?= htmlspecialchars(rbn_status_name($e['status_from'])) ?> ← <?= htmlspecialchars(rbn_status_name($e['status_to'])) ?></td>
        <td class="rbn-v" style="font-size:.78rem"><?= htmlspecialchars(mb_substr((string) $e['message'], 0, 120)) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
