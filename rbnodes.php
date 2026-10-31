<?php
// 🖥 نودهای ربکا: the nodes of a Rebecca panel, run from the bot (🛠 قابلیت های
// پنل ← a Rebecca panel ← 🖥 مدیریت نود ها) and the web panel (🖥 نودهای ربکا).
// Rebecca only - its node API is its own (rebeccapanel/Rebecca,
// internal/app/api): a list of the nodes and an action route per node. The
// list carries no CPU, RAM or speed - Rebecca asks no node for them there;
// GET /api/node/{id} does, so rbn_nodes_live() asks every node that way, all
// at once.
//
//   list / add / edit / delete        GET /api/nodes, POST /api/node, PUT|DELETE /api/node/{id}
//   reconnect, restart the core,      POST /api/node/{id}/{reconnect|restart|sync|
//   sync, restart / update the node        service/restart|service/update|usage/reset|host/reboot}
//   service, reset usage, reboot
//   update the core (Xray)            POST /api/node/{id}/xray/update {"version"} -
//                                     versions from GET /api/core/xray/releases
//   update every node's service       POST /api/nodes/service/update {"nodes":[{"id"}]}
//   traffic by day                    GET /api/node/{id}/usage/daily?start=
//
// The panel's token (marzban_panel.password_panel, a Bearer token - the way
// Rebecca.php already talks to it) must be a sudo admin's: the node routes
// refuse any other.
//
// 🔔 پایش: cronbot/rbnodes.php looks every two minutes; every change of a
// node's status is kept (rbn_event), and a node going down or coming back is
// reported - to the report group (its own topic), or to the main admin when
// there is none. A node that STAYS down is told again - and so is one that
// stays over its CPU/RAM limit (rbn_load_check) - at a gap and up to a number
// of messages the admin sets (rbn_rep_*; on by default), so nothing arrives
// in a burst and nothing is forgotten.
// 📊 گزارش: one picture of every node (rbn_report_png), and one of a node's
// traffic by day (rbn_daily_png), drawn with GD and the bundled Vazirmatn.

if (!function_exists('rbn_ensure_schema')) {
    function rbn_ensure_schema()
    {
        static $done = false;
        global $pdo;
        if ($done || !($pdo instanceof PDO)) {
            return;
        }
        $done = true;
        // the status each node had when last looked at (node 0: the panel itself)
        $pdo->query("CREATE TABLE IF NOT EXISTS rbn_state (
            panel_id INT NOT NULL,
            node_id INT NOT NULL,
            name VARCHAR(190) NULL,
            status VARCHAR(20) NOT NULL,
            message TEXT NULL,
            since INT NOT NULL,
            alerted TINYINT NOT NULL DEFAULT 0,
            PRIMARY KEY (panel_id, node_id)
        ) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
        // a reminder needs to know how many messages an outage has had so far and when the last one went
        foreach (['alert_count', 'alert_at'] as $col) {
            $has = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rbn_state' AND COLUMN_NAME = '{$col}'")->fetchColumn();
            if (!$has) {
                $pdo->exec("ALTER TABLE rbn_state ADD {$col} INT NOT NULL DEFAULT 0");
            }
        }
        // every change: who, from what, to what, when
        $pdo->query("CREATE TABLE IF NOT EXISTS rbn_event (
            id INT AUTO_INCREMENT PRIMARY KEY,
            panel_id INT NOT NULL,
            node_id INT NOT NULL,
            name VARCHAR(190) NULL,
            status_from VARCHAR(20) NULL,
            status_to VARCHAR(20) NOT NULL,
            message TEXT NULL,
            at INT NOT NULL,
            KEY panel_at (panel_id, at)
        ) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
        // 🚨 high CPU/RAM, per node and kind (cpu | ram): state 1 = high on one look, 2 = told;
        // n messages so far, at = when the last went, since = when it was first seen high
        // (the first version kept bit flags in one row: its few rows only held who had been told)
        $old = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rbn_load' AND COLUMN_NAME = 'flags'")->fetchColumn();
        if ($old) {
            $pdo->exec("DROP TABLE rbn_load");
        }
        $pdo->query("CREATE TABLE IF NOT EXISTS rbn_load (
            panel_id INT NOT NULL,
            node_id INT NOT NULL,
            what VARCHAR(4) NOT NULL,
            state TINYINT NOT NULL DEFAULT 0,
            n INT NOT NULL DEFAULT 0,
            at INT NOT NULL DEFAULT 0,
            since INT NOT NULL DEFAULT 0,
            PRIMARY KEY (panel_id, node_id, what)
        ) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    // ---------------------------------------------------------------- API
    function rbn_panels()
    {
        global $pdo;
        $st = $pdo->query("SELECT * FROM marzban_panel WHERE type = 'rebecca' ORDER BY id");
        return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    // a Rebecca panel by its id, or null
    function rbn_panel($id)
    {
        $p = select("marzban_panel", "*", "id", (int) $id, "select");
        return is_array($p) && ($p['type'] ?? '') === 'rebecca' ? $p : null;
    }

    // One call: ['code' => HTTP status, 'json' => decoded body, 'error' => '' or why it never got there]
    function rbn_req(array $panel, $method, $path, $body = null, $timeout = 20)
    {
        $ch = rbn_curl($panel, $method, $path, $body, $timeout);
        $raw = curl_exec($ch);
        $error = $raw === false ? (string) curl_error($ch) : '';
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'json' => is_string($raw) ? json_decode($raw, true) : null, 'error' => $error];
    }

    // a curl handle for one call to the panel, not yet made
    function rbn_curl(array $panel, $method, $path, $body = null, $timeout = 20)
    {
        $ch = curl_init(rtrim((string) $panel['url_panel'], '/') . $path);
        $headers = ['accept: application/json', 'Authorization: Bearer ' . $panel['password_panel']];
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            // as everywhere the bot talks to a panel (request.php): panels often run on their own certificate
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        return $ch;
    }

    function rbn_ok(array $r)
    {
        return $r['error'] === '' && $r['code'] >= 200 && $r['code'] < 300;
    }

    // why a call failed, in the admin's words
    function rbn_why(array $r)
    {
        $t = panel_texts()['Admin']['RbNodes'];
        if ($r['error'] !== '') {
            return sprintf($t['errConnect'], htmlspecialchars(mb_substr($r['error'], 0, 120)));
        }
        if ($r['code'] === 401) {
            return $t['errToken'];
        }
        if ($r['code'] === 403) {
            return $t['errSudo'];
        }
        $detail = is_array($r['json']) ? ($r['json']['detail'] ?? $r['json']['message'] ?? '') : '';
        $detail = is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE);
        if ($r['code'] === 404 && $detail === '') {
            return $t['errOld'];
        }
        return sprintf($t['errCode'], $r['code'], htmlspecialchars(mb_substr((string) $detail, 0, 200)));
    }

    // the panel's nodes: [list, ''] or [null, why]
    function rbn_nodes(array $panel)
    {
        $r = rbn_req($panel, 'GET', '/api/nodes');
        if (!rbn_ok($r)) {
            return [null, rbn_why($r)];
        }
        $list = $r['json'];
        // an older Rebecca wrapped them: {"nodes": [...]}
        if (is_array($list) && isset($list['nodes']) && is_array($list['nodes'])) {
            $list = $list['nodes'];
        }
        if (!is_array($list)) {
            return [null, rbn_why(['code' => $r['code'], 'json' => null, 'error' => 'bad answer'])];
        }
        $out = [];
        foreach ($list as $n) {
            if (is_array($n) && isset($n['id'])) {
                $out[] = $n;
            }
        }
        return [$out, ''];
    }

    function rbn_node(array $panel, $nodeId)
    {
        $r = rbn_req($panel, 'GET', '/api/node/' . (int) $nodeId);
        return rbn_ok($r) && is_array($r['json']) ? [$r['json'], ''] : [null, rbn_why($r)];
    }

    // the fields only a node of its own carries: what it uses right now
    function rbn_metric_keys()
    {
        return ['cpu_usage_percent', 'cpu_cores', 'cpu_frequency_hz', 'memory_used', 'memory_total', 'memory_usage_percent',
            'upload_speed', 'download_speed', 'uptime_seconds', 'xray_cpu_usage_percent', 'xray_memory_used',
            'status', 'message', 'xray_version', 'node_service_version'];
    }

    // The list, with each node's CPU, RAM and speed as they are this moment.
    // Rebecca's list leaves those out (it asks no node anything); each node's
    // own GET /api/node/{id} asks it. All asked at once, so this waits for the
    // slowest node, not for all of them in a row. A node switched off or out
    // of traffic is not asked - Rebecca does not ask those either.
    function rbn_nodes_live(array $panel, $timeout = 12)
    {
        [$nodes, $why] = rbn_nodes($panel);
        if ($nodes === null || !$nodes || !function_exists('curl_multi_init')) {
            return [$nodes, $why];
        }
        $mh = curl_multi_init();
        $hs = [];
        foreach ($nodes as $i => $n) {
            if (in_array((string) ($n['status'] ?? ''), ['disabled', 'limited'], true)) {
                continue;
            }
            $hs[$i] = rbn_curl($panel, 'GET', '/api/node/' . (int) $n['id'], null, $timeout);
            curl_multi_add_handle($mh, $hs[$i]);
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $st === CURLM_OK);
        foreach ($hs as $i => $ch) {
            $raw = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $j = is_string($raw) ? json_decode($raw, true) : null;
            if ($code >= 200 && $code < 300 && is_array($j) && (int) ($j['id'] ?? 0) === (int) $nodes[$i]['id']) {
                foreach (rbn_metric_keys() as $k) {
                    if (array_key_exists($k, $j)) {
                        $nodes[$i][$k] = $j[$k];
                    }
                }
                $nodes[$i]['_live'] = true;
            }
        }
        curl_multi_close($mh);
        return [$nodes, ''];
    }

    // does this node carry real numbers for now (connected, and asked)?
    function rbn_has_metrics(array $n)
    {
        return ($n['status'] ?? '') === 'connected' && (float) ($n['memory_total'] ?? 0) > 0;
    }

    // the actions a node takes with no more than its id
    function rbn_actions()
    {
        return [
            'rc' => 'reconnect',
            'rs' => 'restart',
            'sy' => 'sync',
            'sr' => 'service/restart',
            'su' => 'service/update',
            'ur' => 'usage/reset',
            'hr' => 'host/reboot',
        ];
    }

    // [ok, why]
    function rbn_do(array $panel, $nodeId, $action, $version = '')
    {
        $id = (int) $nodeId;
        if ($action === 'cv') {
            $r = rbn_req($panel, 'POST', "/api/node/{$id}/xray/update", ['version' => (string) $version], 30);
        } elseif ($action === 'dl') {
            $r = rbn_req($panel, 'DELETE', "/api/node/{$id}", null, 30);
        } elseif ($action === 'ds' || $action === 'en') {
            // enabled again it starts over from «connecting», as the panel's own switch does
            $r = rbn_req($panel, 'PUT', "/api/node/{$id}", ['status' => $action === 'ds' ? 'disabled' : 'connecting'], 30);
        } elseif (isset(rbn_actions()[$action])) {
            // the node service updates along its own channel when nothing names one
            $r = rbn_req($panel, 'POST', "/api/node/{$id}/" . rbn_actions()[$action], $action === 'su' ? (object) [] : null, 120);
        } else {
            return [false, 'unknown action'];
        }
        return rbn_ok($r) ? [true, ''] : [false, rbn_why($r)];
    }

    // every node's service, updated along its own channel: [ok, why]
    function rbn_update_all_services(array $panel, array $nodeIds)
    {
        $nodes = array_map(fn($id) => ['id' => (int) $id], array_values(array_filter($nodeIds)));
        if (!$nodes) {
            return [false, panel_texts()['Admin']['RbNodes']['noNodes']];
        }
        $r = rbn_req($panel, 'POST', '/api/nodes/service/update', ['nodes' => $nodes], 30);
        return rbn_ok($r) ? [true, ''] : [false, rbn_why($r)];
    }

    // the Xray versions the panel can put on a node, newest first: [tags, why]
    function rbn_xray_releases(array $panel)
    {
        $r = rbn_req($panel, 'GET', '/api/core/xray/releases?limit=8');
        $tags = rbn_ok($r) && is_array($r['json']) ? ($r['json']['tags'] ?? null) : null;
        if (!is_array($tags)) {
            return [[], rbn_why($r)];
        }
        return [array_values(array_filter($tags, fn($v) => is_string($v) && preg_match('/^v?\d[\w.\-]{0,30}$/', $v))), ''];
    }

    // a new node: [node, why]. Its answer carries the node's certificate and
    // private key - the bundle its installer asks for, given out only now.
    function rbn_add(array $panel, array $d)
    {
        $body = [
            'name' => (string) $d['name'],
            'address' => (string) $d['address'],
            'port' => (int) ($d['port'] ?? 62050),
            'api_port' => (int) ($d['api_port'] ?? 62051),
            'usage_coefficient' => (float) ($d['coef'] ?? 1),
        ];
        $r = rbn_req($panel, 'POST', '/api/node', $body, 40);
        return rbn_ok($r) && is_array($r['json']) ? [$r['json'], ''] : [null, rbn_why($r)];
    }

    // one field of a node changed: [ok, why]
    function rbn_edit(array $panel, $nodeId, $field, $value)
    {
        $map = ['name' => 'name', 'address' => 'address', 'port' => 'port', 'api_port' => 'api_port', 'coef' => 'usage_coefficient'];
        if (!isset($map[$field])) {
            return [false, 'unknown field'];
        }
        $v = in_array($field, ['port', 'api_port'], true) ? (int) $value : ($field === 'coef' ? (float) $value : (string) $value);
        $r = rbn_req($panel, 'PUT', '/api/node/' . (int) $nodeId, [$map[$field] => $v], 30);
        return rbn_ok($r) ? [true, ''] : [false, rbn_why($r)];
    }

    // what a value typed for a field must look like: the value, or null
    function rbn_field_value($field, $text)
    {
        $s = trim(strtr((string) $text, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٫' => '.']));
        switch ($field) {
            case 'name':
                return $s !== '' && mb_strlen($s) <= 60 ? $s : null;
            case 'address':
                return preg_match('/^[A-Za-z0-9.\-:\[\]]{3,253}$/', $s) ? $s : null;
            case 'port':
            case 'api_port':
                return ctype_digit($s) && (int) $s >= 1 && (int) $s <= 65535 ? (int) $s : null;
            case 'coef':
                return is_numeric($s) && (float) $s > 0 && (float) $s <= 100 ? (float) $s : null;
        }
        return null;
    }

    // traffic by day for the last $days days: [['date' => 'Y-m-d', 'bytes' => n], ...] or null
    function rbn_daily(array $panel, $nodeId, $days = 30)
    {
        $start = gmdate('Y-m-d\TH:i:s\Z', time() - (int) $days * 86400);
        $r = rbn_req($panel, 'GET', '/api/node/' . (int) $nodeId . '/usage/daily?granularity=day&start=' . urlencode($start));
        $rows = rbn_ok($r) && is_array($r['json']) ? ($r['json']['usages'] ?? null) : null;
        if (!is_array($rows)) {
            return null;
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['date'])) {
                $out[] = ['date' => substr((string) $row['date'], 0, 10), 'bytes' => (int) ($row['used_traffic'] ?? 0)];
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- words
    function rbn_status_icon($status)
    {
        return [
            'connected' => '🟢', 'connecting' => '🟡', 'error' => '🔴',
            'disabled' => '⚪️', 'limited' => '🟠', 'unreachable' => '🔴',
        ][(string) $status] ?? '⚫️';
    }

    // a node counts as down while it is neither working nor switched off on purpose
    function rbn_is_down($status)
    {
        return !in_array((string) $status, ['connected', 'disabled'], true);
    }

    function rbn_status_name($status)
    {
        $t = panel_texts()['Admin']['RbNodes']['status'];
        return $t[(string) $status] ?? (string) $status;
    }

    function rbn_bytes($b)
    {
        $b = (float) $b;
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $i => $u) {
            if ($b < 1024 || $u === 'TB') {
                return ($i === 0 ? (string) (int) $b : number_format($b, $b < 10 ? 2 : 1)) . ' ' . $u;
            }
            $b /= 1024;
        }
        return '0 B';
    }

    function rbn_duration($seconds)
    {
        $t = panel_texts()['Admin']['RbNodes'];
        $s = max(0, (int) $seconds);
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);
        $m = intdiv($s % 3600, 60);
        if ($d > 0) {
            return sprintf($t['durDH'], $d, $h);
        }
        if ($h > 0) {
            return sprintf($t['durHM'], $h, $m);
        }
        return sprintf($t['durM'], max(1, $m));
    }

    // a moment, in the calendar the bot's reports use
    function rbn_when($ts)
    {
        return function_exists('jdate') ? jdate('Y/m/d H:i', (int) $ts) : date('Y/m/d H:i', (int) $ts);
    }

    function rbn_pct($v)
    {
        return $v === null || $v === '' ? '—' : number_format((float) $v, 1) . '٪';
    }

    // ---------------------------------------------------------------- 🔔
    function rbn_watch_on()
    {
        return (string) feature_setting_value('rbn_watch', 'all', '1') !== '0';
    }

    function rbn_watch_set($on)
    {
        feature_setting_set('rbn_watch', 'all', $on ? '1' : '0');
    }

    // ---------------------------------------------------------------- 🔗
    // One Rebecca panel can be in the bot more than once (two rows on the same
    // address, for other inbounds or other buyers). Such twins are watched
    // once and report once: everything about them is kept under one id, the
    // canonical one - the lowest id of rows on the same address, or of rows
    // whose nodes turned out to be the very same (the watch notices that and
    // remembers it in rbn_twins).

    // a panel's address as one string: scheme://host:port
    function rbn_base(array $panel)
    {
        $u = parse_url(trim((string) $panel['url_panel']));
        $scheme = strtolower((string) ($u['scheme'] ?? 'https'));
        $host = strtolower(rtrim((string) ($u['host'] ?? ''), '.'));
        $port = (int) ($u['port'] ?? ($scheme === 'http' ? 80 : 443));
        return $scheme . '://' . $host . ':' . $port;
    }

    // host[:port], to tell two panels apart at a glance
    function rbn_host(array $panel)
    {
        $u = parse_url(trim((string) $panel['url_panel']));
        return (string) ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : '');
    }

    // [panel id => canonical id] for every Rebecca panel
    function rbn_canon_map($fresh = false)
    {
        static $map = null;
        if ($map !== null && !$fresh) {
            return $map;
        }
        $map = [];
        $byBase = [];
        foreach (rbn_panels() as $p) {
            $base = rbn_base($p);
            $byBase[$base] = $byBase[$base] ?? (int) $p['id'];
            $map[(int) $p['id']] = $byBase[$base];
        }
        // twins found by their nodes: {address group's id: the id it is one with}
        $twins = json_decode((string) feature_setting_value('rbn_twins', 'all', ''), true);
        foreach (is_array($twins) ? $twins : [] as $from => $to) {
            if (isset($map[(int) $to])) {
                foreach ($map as $pid => $c) {
                    if ($c === (int) $from) {
                        $map[$pid] = $map[(int) $to];
                    }
                }
            }
        }
        return $map;
    }

    function rbn_canon(array $panel)
    {
        return rbn_canon_map()[(int) $panel['id']] ?? (int) $panel['id'];
    }

    // every bot panel that is this one Rebecca panel, the canonical one first
    function rbn_twins(array $panel)
    {
        $c = rbn_canon($panel);
        $out = [];
        foreach (rbn_panels() as $p) {
            if (rbn_canon($p) === $c) {
                $out[] = $p;
            }
        }
        // the canonical one is the lowest id of them, so the order is by id
        usort($out, fn($x, $y) => (int) $x['id'] - (int) $y['id']);
        return $out ?: [$panel];
    }

    // «A» or «A / B» when the panel is in the bot twice
    function rbn_names(array $panel)
    {
        return implode(' / ', array_map(fn($p) => (string) $p['name_panel'], rbn_twins($panel)));
    }

    // ---------------------------------------------------------------- 📮
    // Where a panel's watch reports go - one setting per real panel (twins
    // share it): 'group' the report group's error topic, 'bot' the
    // administrators' own chats, 'chat:<id>' a channel or group of its own,
    // 'off' nowhere (the history is kept all the same), 'auto' the report
    // group when there is one, else the main admin. '' not chosen yet: the
    // node screen asks first.
    function rbn_dest(array $panel)
    {
        return (string) feature_setting_value('rbn_dest_' . rbn_canon($panel), 'all', '');
    }

    function rbn_dest_set(array $panel, $dest)
    {
        feature_setting_set('rbn_dest_' . rbn_canon($panel), 'all', (string) $dest);
    }

    function rbn_report_group()
    {
        global $setting;
        $s = is_array($setting ?? null) && isset($setting['Channel_Report']) ? $setting : select("setting", "*", null, null, "select");
        $g = trim((string) ($s['Channel_Report'] ?? ''));
        return $g === '' || $g === '0' ? '' : $g;
    }

    function rbn_dest_label($dest)
    {
        $t = panel_texts()['Admin']['RbNodes'];
        if (strpos((string) $dest, 'chat:') === 0) {
            return strtr($t['destChatLabel'], ['{chat}' => substr($dest, 5)]);
        }
        return ['group' => $t['destGroupLabel'], 'bot' => $t['destBotLabel'], 'off' => $t['destOffLabel'], 'auto' => $t['destAutoLabel']][(string) $dest] ?? $t['destUnset'];
    }

    // [[chat, topic], ...] a report of this panel is sent to
    function rbn_dest_targets(array $panel)
    {
        global $pdo, $adminnumber;
        $dest = rbn_dest($panel);
        if ($dest === 'off') {
            return [];
        }
        if (strpos($dest, 'chat:') === 0) {
            return [[substr($dest, 5), 0]];
        }
        $group = rbn_report_group();
        if (($dest === 'group' || $dest === 'auto' || $dest === '') && $group !== '') {
            // a topic of its own there, made the first time a report goes
            $topic = function_exists('report_topic_id') ? (int) report_topic_id('rbnodereport', panel_texts()['Admin']['RbNodes']['topicName']) : 0;
            return [[$group, $topic]];
        }
        if ($dest === 'bot') {
            $ids = [];
            foreach ($pdo->query("SELECT id_admin FROM admin WHERE rule = 'administrator'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
                if (ctype_digit((string) $id)) {
                    $ids[(string) $id] = true;
                }
            }
            if (!empty($adminnumber)) {
                $ids[(string) $adminnumber] = true;
            }
            return array_map(fn($id) => [$id, 0], array_keys($ids));
        }
        // 'auto' (or not chosen) and no report group: the main admin
        return !empty($adminnumber) ? [[(string) $adminnumber, 0]] : [];
    }

    // a text to every place this panel's reports go: [sent, last error]
    function rbn_alert(array $panel, $text)
    {
        if (!function_exists('telegram')) {
            return [0, 'no telegram'];
        }
        $sent = 0;
        $err = '';
        foreach (rbn_dest_targets($panel) as [$chat, $topic]) {
            $data = ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML'];
            if ($topic > 0) {
                $data['message_thread_id'] = $topic;
            }
            $r = telegram('sendmessage', $data);
            if (is_array($r) && !empty($r['ok'])) {
                $sent++;
            } else {
                $err = is_array($r) ? (string) ($r['description'] ?? '') : '';
            }
        }
        return [$sent, $err];
    }

    // a chat typed in for 'chat:': '-100…', '-…' or '@name' - null otherwise
    function rbn_chat_value($text)
    {
        $s = trim((string) $text);
        if (preg_match('~^(?:https?://)?t\.me/([A-Za-z][A-Za-z0-9_]{3,31})$~', $s, $m)) {
            $s = '@' . $m[1];
        }
        return preg_match('/^(-?\d{5,20}|@[A-Za-z][A-Za-z0-9_]{3,31})$/', $s) ? $s : null;
    }

    // tried first: the bot must be able to post there. [chat id to keep, ''] or [null, why]
    function rbn_chat_try(array $panel, $chat)
    {
        $t = panel_texts()['Admin']['RbNodes'];
        $r = telegram('sendmessage', ['chat_id' => $chat, 'parse_mode' => 'HTML', 'text' => strtr($t['destTestMsg'], [
            '{panel}' => htmlspecialchars(rbn_names($panel)),
            '{host}' => htmlspecialchars(rbn_host($panel)),
        ])]);
        if (!is_array($r) || empty($r['ok'])) {
            return [null, is_array($r) ? (string) ($r['description'] ?? '') : ''];
        }
        // kept by its number, which a renamed channel keeps
        $id = $r['result']['chat']['id'] ?? $chat;
        return [(string) $id, ''];
    }

    // ---------------------------------------------------------------- 🔔
    // cronbot/rbnodes.php: every Rebecca panel's nodes against what they were -
    // once per real panel. Each change is kept. Going down is told at once -
    // but «connecting» only once it has lasted 5 minutes, so a reconnect does
    // not ring twice - and coming back is told only after a going-down was.
    // Staying down is told again when a reminder is due (rbn_rep_due).
    function rbn_watch_tick()
    {
        global $pdo;
        rbn_ensure_schema();
        $watch = rbn_watch_on();
        $load = rbn_load_on();
        if (!$watch && !$load) {
            return;
        }
        $now = time();
        // one look per address (rows on one address are one panel already)
        $groups = [];
        $byBase = [];
        foreach (rbn_panels() as $p) {
            $base = rbn_base($p);
            if (!isset($byBase[$base])) {
                $byBase[$base] = (int) $p['id'];
                $groups[(int) $p['id']] = $p;
            }
        }
        $looked = [];
        foreach ($groups as $gid => $panel) {
            // CPU and RAM only when something looks at them
            $looked[$gid] = $load ? rbn_nodes_live($panel) : rbn_nodes($panel);
        }
        // two addresses whose nodes are the very same: one panel, reached two ways
        $twins = [];
        $byPrint = [];
        foreach ($looked as $gid => [$nodes]) {
            if (!$nodes) {
                continue;
            }
            $print = array_map(fn($n) => (int) $n['id'] . '|' . strtolower((string) $n['name']) . '|' . strtolower((string) ($n['address'] ?? '')), $nodes);
            sort($print);
            $key = sha1(implode("\n", $print));
            if (isset($byPrint[$key])) {
                $twins[$gid] = $byPrint[$key];
            } else {
                $byPrint[$key] = $gid;
            }
        }
        $old = json_decode((string) feature_setting_value('rbn_twins', 'all', ''), true);
        // a twin that cannot be looked at now stays a twin
        foreach (is_array($old) ? $old : [] as $from => $to) {
            if (!isset($twins[(int) $from]) && isset($groups[(int) $from], $groups[(int) $to]) && ($looked[(int) $from][0] === null || $looked[(int) $to][0] === null)) {
                $twins[(int) $from] = (int) $to;
            }
        }
        ksort($twins);
        if (json_encode($twins) !== json_encode(is_array($old) ? $old : [])) {
            feature_setting_set('rbn_twins', 'all', $twins ? json_encode($twins) : '');
        }
        rbn_canon_map(true);
        $get = $pdo->prepare("SELECT * FROM rbn_state WHERE panel_id = ?");
        $put = $pdo->prepare("REPLACE INTO rbn_state (panel_id, node_id, name, status, message, since, alerted, alert_count, alert_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $drop = $pdo->prepare("DELETE FROM rbn_state WHERE panel_id = ? AND node_id = ?");
        foreach ($groups as $gid => $panel) {
            if (isset($twins[$gid])) {
                // its twin speaks for it
                $pdo->prepare("DELETE FROM rbn_state WHERE panel_id = ?")->execute([$gid]);
                continue;
            }
            [$nodes, $why] = $looked[$gid];
            $pid = rbn_canon($panel);
            if ($load && $nodes !== null) {
                rbn_load_check($panel, $pid, $nodes, $now);
            }
            if (!$watch) {
                continue;
            }
            $get->execute([$pid]);
            $known = [];
            foreach ($get->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $known[(int) $row['node_id']] = $row;
            }
            // the panel itself, as node 0: unreachable, or back. The reason as
            // plain text: it is kept, and escaped once more when shown.
            $reason = $nodes === null ? html_entity_decode(strip_tags($why), ENT_QUOTES, 'UTF-8') : '';
            $seen = [0 => ['name' => rbn_names($panel), 'status' => $nodes === null ? 'unreachable' : 'connected', 'message' => $reason]];
            foreach ($nodes ?? [] as $n) {
                $seen[(int) $n['id']] = ['name' => (string) $n['name'], 'status' => (string) ($n['status'] ?? ''), 'message' => trim((string) ($n['message'] ?? ''))];
            }
            foreach ($seen as $nid => $cur) {
                $prev = $known[$nid] ?? null;
                if ($prev === null) {
                    // first sight: what it is now, no news (a panel already unreachable is news)
                    $alerted = 0;
                    $count = 0;
                    $lastAt = 0;
                    if ($nid === 0 && $cur['status'] === 'unreachable') {
                        rbn_event($panel, 0, $cur['name'], 'connected', 'unreachable', $cur['message'], $now, $now, 'down');
                        $alerted = 1;
                        $count = 1;
                        $lastAt = $now;
                    }
                    $put->execute([$pid, $nid, $cur['name'], $cur['status'], $cur['message'], $now, $alerted, $count, $lastAt]);
                    continue;
                }
                $alerted = (int) $prev['alerted'];
                $count = (int) ($prev['alert_count'] ?? 0);
                $lastAt = (int) ($prev['alert_at'] ?? 0);
                $since = (int) $prev['since'];
                // told before the counters existed: that one message counts, from when the outage began
                if ($alerted && $count < 1) {
                    $count = 1;
                }
                if ($alerted && $lastAt <= 0) {
                    $lastAt = $since;
                }
                if ($prev['status'] !== $cur['status']) {
                    $tell = '';
                    if (rbn_is_down($cur['status']) && $cur['status'] !== 'connecting' && !$alerted) {
                        $tell = 'down';
                    } elseif (!rbn_is_down($cur['status']) && $cur['status'] !== 'disabled' && $alerted) {
                        $tell = 'up';
                    }
                    rbn_event($panel, $nid, $cur['name'], $prev['status'], $cur['status'], $cur['message'], $now, $since, $tell);
                    if ($tell === 'down') {
                        $alerted = 1;
                        $count = 1;
                        $lastAt = $now;
                    } elseif (!rbn_is_down($cur['status'])) {
                        $alerted = 0;
                        $count = 0;
                        $lastAt = 0;
                    }
                    // still down in another way: the time it went down still counts
                    $since = rbn_is_down($cur['status']) && rbn_is_down($prev['status']) ? $since : $now;
                } elseif ($cur['status'] === 'connecting' && !$alerted && $now - $since >= 300) {
                    rbn_alert($panel, rbn_alert_text($panel, $nid, $cur['name'], $prev['status'], 'connecting', $cur['message'], $now, $since, false));
                    $alerted = 1;
                    $count = 1;
                    $lastAt = $now;
                }
                // still down - whichever way - and a reminder is due: told again, up to the count the admin set
                if ($alerted && rbn_is_down($cur['status']) && rbn_rep_due('down', $count, $lastAt, $now)) {
                    rbn_alert($panel, rbn_still_text($panel, $nid, $cur['name'], $cur['status'], $cur['message'], $since, $count + 1, $now));
                    $count++;
                    $lastAt = $now;
                }
                $put->execute([$pid, $nid, $cur['name'], $cur['status'], $cur['message'], $since, $alerted, $count, $lastAt]);
            }
            foreach ($known as $nid => $prev) {
                if (!isset($seen[$nid]) && $nodes !== null) {
                    rbn_event($panel, $nid, (string) $prev['name'], $prev['status'], 'deleted', '', $now, (int) $prev['since'], '');
                    $drop->execute([$pid, $nid]);
                }
            }
        }
        // two months of history is plenty
        $pdo->prepare("DELETE FROM rbn_event WHERE at < ?")->execute([$now - 60 * 86400]);
        // a panel removed from the bot leaves nothing behind
        $pdo->query("DELETE FROM rbn_state WHERE panel_id NOT IN (SELECT id FROM marzban_panel WHERE type = 'rebecca')");
        $pdo->query("DELETE FROM rbn_load WHERE panel_id NOT IN (SELECT id FROM marzban_panel WHERE type = 'rebecca')");
    }

    // ---------------------------------------------------------------- 🚨
    // High CPU or RAM on a node: told once it has lasted two looks in a row
    // (about four minutes - a short spike is not news), and told again when it
    // is back 5 points under the limit. One setting for every Rebecca panel of
    // this bot; another bot on the server has its own.
    function rbn_load_on()
    {
        return (string) feature_setting_value('rbn_load', 'all', '1') !== '0';
    }
    function rbn_load_set($on)
    {
        feature_setting_set('rbn_load', 'all', $on ? '1' : '0');
    }
    // the limit for 'cpu' or 'ram', in percent (90 unless the admin set one)
    function rbn_load_limit($what)
    {
        $v = (int) feature_setting_value('rbn_load_' . $what, 'all', '90');
        return $v >= 1 && $v <= 100 ? $v : 90;
    }
    function rbn_load_limit_set($what, $pct)
    {
        $pct = (int) $pct;
        if (!in_array($what, ['cpu', 'ram'], true) || $pct < 1 || $pct > 100) {
            return false;
        }
        feature_setting_set('rbn_load_' . $what, 'all', (string) $pct);
        return true;
    }
    // a percent typed in: 1-100, with or without %, Persian digits too
    function rbn_load_value($text)
    {
        $s = strtr(trim((string) $text), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '%' => '', '٪' => '']);
        $s = trim($s);
        return ctype_digit($s) && (int) $s >= 1 && (int) $s <= 100 ? (int) $s : null;
    }

    // ---------------------------------------------------------------- 🔁
    // Reminders while it lasts: a node that stays down ('down'), a node that
    // stays over its limit ('load'). Each has its own on/off, gap in minutes
    // and the number of messages one outage or one high spell may have in all
    // (the first one counts; 0 = until it is over). On by default - 'down'
    // every 10 minutes, 5 messages; 'load' every 30 minutes, 3 messages - and
    // off means one message and one «back to normal». One setting for every
    // Rebecca panel of this bot; another bot on the server has its own. The
    // watch looks every two minutes, so a gap is met at the next look.
    function rbn_rep_defaults($kind)
    {
        return $kind === 'load' ? ['on' => 1, 'every' => 30, 'max' => 3] : ['on' => 1, 'every' => 10, 'max' => 5];
    }
    function rbn_rep_on($kind)
    {
        return (string) feature_setting_value('rbn_rep_' . $kind, 'all', (string) rbn_rep_defaults($kind)['on']) !== '0';
    }
    function rbn_rep_every($kind)
    {
        $d = rbn_rep_defaults($kind);
        $v = (int) feature_setting_value('rbn_rep_' . $kind . '_every', 'all', (string) $d['every']);
        return $v >= 2 && $v <= 1440 ? $v : $d['every'];
    }
    function rbn_rep_max($kind)
    {
        $d = rbn_rep_defaults($kind);
        $v = (int) feature_setting_value('rbn_rep_' . $kind . '_max', 'all', (string) $d['max']);
        return $v === 0 || ($v >= 2 && $v <= 100) ? $v : $d['max'];
    }
    function rbn_rep_set_on($kind, $on)
    {
        feature_setting_set('rbn_rep_' . $kind, 'all', $on ? '1' : '0');
    }
    // $field 'every' (2-1440 minutes) or 'max' (0, or 2-100 messages)
    function rbn_rep_set($kind, $field, $value)
    {
        $v = (int) $value;
        if (!in_array($kind, ['down', 'load'], true)) {
            return false;
        }
        if ($field === 'every' && $v >= 2 && $v <= 1440) {
            feature_setting_set('rbn_rep_' . $kind . '_every', 'all', (string) $v);
            return true;
        }
        if ($field === 'max' && ($v === 0 || ($v >= 2 && $v <= 100))) {
            // 0 is kept as '0': feature_setting_value() reads an empty string as "never set"
            feature_setting_set('rbn_rep_' . $kind . '_max', 'all', (string) $v);
            return true;
        }
        return false;
    }
    // a number typed in, Persian digits too: 'e' the gap (2-1440), 'n' the count (0 or 2-100) - null when it does not fit
    function rbn_rep_value($what, $text)
    {
        $s = trim(strtr(trim((string) $text), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']));
        if (!ctype_digit($s) || strlen($s) > 4) {
            return null;
        }
        $v = (int) $s;
        if ($what === 'e') {
            return $v >= 2 && $v <= 1440 ? $v : null;
        }
        return $v === 0 || ($v >= 2 && $v <= 100) ? $v : null;
    }
    // is a reminder due now? $count messages so far, the last at $lastAt
    function rbn_rep_due($kind, $count, $lastAt, $now)
    {
        if (!rbn_rep_on($kind)) {
            return false;
        }
        $max = rbn_rep_max($kind);
        if ($max !== 0 && $count >= $max) {
            return false;
        }
        // a little slack: the cron's own jitter must not cost a whole extra round
        return $now - (int) $lastAt >= rbn_rep_every($kind) * 60 - 20;
    }
    function rbn_rep_max_label($max)
    {
        $t = panel_texts()['Admin']['RbNodes'];
        return $max === 0 ? $t['repMaxOpen'] : strtr($t['repMax'], ['{n}' => (int) $max]);
    }
    // «every 10 minutes · 5 messages», or «no reminders»
    function rbn_rep_summary($kind)
    {
        $t = panel_texts()['Admin']['RbNodes'];
        return rbn_rep_on($kind) ? strtr($t['repSumOn'], ['{every}' => rbn_rep_every($kind), '{max}' => rbn_rep_max_label(rbn_rep_max($kind))]) : $t['repSumOff'];
    }
    // «message 2 of 5» for the $n-th message, and a line saying it is the last one
    function rbn_rep_num($kind, $n)
    {
        $t = panel_texts()['Admin']['RbNodes'];
        $max = rbn_rep_max($kind);
        $num = $max === 0 ? strtr($t['repNumOpen'], ['{n}' => (int) $n]) : strtr($t['repNum'], ['{n}' => (int) $n, '{max}' => $max]);
        $last = $max !== 0 && $n >= $max ? strtr($t['repLast'], ['{end}' => $t[$kind === 'load' ? 'repLastLoad' : 'repLastDown']]) : '';
        return [$num, $last];
    }

    // a node (or the panel itself) that is still down: the $n-th message of this outage
    function rbn_still_text(array $panel, $nodeId, $name, $status, $msg, $since, $n, $now)
    {
        $t = panel_texts()['Admin']['RbNodes'];
        [$num, $last] = rbn_rep_num('down', $n);
        return strtr($t[(int) $nodeId === 0 ? 'alertPanelStill' : 'alertStill'], [
            '{num}' => $num,
            '{last}' => $last,
            '{panel}' => htmlspecialchars(rbn_names($panel)),
            '{host}' => htmlspecialchars(rbn_host($panel)),
            '{node}' => htmlspecialchars((string) $name),
            '{status}' => rbn_status_icon($status) . ' ' . rbn_status_name($status),
            '{message}' => $msg !== '' ? "\n💬 <code>" . htmlspecialchars(mb_substr((string) $msg, 0, 300)) . '</code>' : '',
            '{when}' => rbn_when($now),
            '{down}' => rbn_duration((int) $now - (int) $since),
        ]);
    }

    function rbn_load_check(array $panel, $pid, array $nodes, $now)
    {
        global $pdo;
        $get = $pdo->prepare("SELECT node_id, what, state, n, at, since FROM rbn_load WHERE panel_id = ?");
        $get->execute([$pid]);
        $known = [];
        foreach ($get->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $known[(int) $row['node_id']][(string) $row['what']] = $row;
        }
        $put = $pdo->prepare("REPLACE INTO rbn_load (panel_id, node_id, what, state, n, at, since) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $del = $pdo->prepare("DELETE FROM rbn_load WHERE panel_id = ? AND node_id = ? AND what = ?");
        foreach ($nodes as $n) {
            if (!rbn_has_metrics($n)) {
                continue;
            }
            $nid = (int) $n['id'];
            foreach (['cpu' => 'cpu_usage_percent', 'ram' => 'memory_usage_percent'] as $what => $key) {
                $row = $known[$nid][$what] ?? ['state' => 0, 'n' => 0, 'at' => 0, 'since' => 0];
                $state = (int) $row['state'];
                $count = (int) $row['n'];
                $at = (int) $row['at'];
                $since = (int) $row['since'];
                $was = [$state, $count, $at, $since];
                $v = (float) ($n[$key] ?? 0);
                $limit = rbn_load_limit($what);
                if ($v >= $limit) {
                    if ($state === 0) {
                        // over the limit on one look: not news yet
                        $state = 1;
                        $since = $now;
                    } elseif ($state === 1) {
                        // on two looks in a row: told
                        rbn_alert($panel, rbn_load_text($panel, $n, $what, $v, $limit, true, $now));
                        $state = 2;
                        $count = 1;
                        $at = $now;
                    } elseif (rbn_rep_due('load', $count, $at, $now)) {
                        // still over it, and a reminder is due
                        rbn_alert($panel, rbn_load_still_text($panel, $n, $what, $v, $limit, $since, $count + 1, $now));
                        $count++;
                        $at = $now;
                    }
                } elseif ($state === 2) {
                    // back down: told once it is 5 points under, so a value on the edge does not ring twice
                    if ($v < $limit - 5) {
                        rbn_alert($panel, rbn_load_text($panel, $n, $what, $v, $limit, false, $now));
                        $state = 0;
                        $count = 0;
                        $at = 0;
                        $since = 0;
                    }
                } elseif ($state === 1) {
                    $state = 0;
                    $since = 0;
                }
                if ([$state, $count, $at, $since] !== $was) {
                    if ($state === 0) {
                        $del->execute([$pid, $nid, $what]);
                    } else {
                        $put->execute([$pid, $nid, $what, $state, $count, $at, $since]);
                    }
                }
            }
        }
    }

    // the parts the three load messages share
    function rbn_load_fields(array $panel, array $n, $what, $value, $limit, $now)
    {
        $t = panel_texts()['Admin']['RbNodes'];
        $detail = $what === 'ram' && !empty($n['memory_total'])
            ? ' · ' . rbn_bytes($n['memory_used'] ?? 0) . ' / ' . rbn_bytes($n['memory_total'])
            : (!empty($n['cpu_cores']) && $what === 'cpu' ? strtr($t['nodeCores'], ['{n}' => (int) $n['cpu_cores']]) : '');
        return [
            '{panel}' => htmlspecialchars(rbn_names($panel)),
            '{host}' => htmlspecialchars(rbn_host($panel)),
            '{node}' => htmlspecialchars((string) $n['name']),
            '{address}' => htmlspecialchars((string) ($n['address'] ?? '')),
            '{what}' => $t['load' . strtoupper($what)],
            '{value}' => rbn_pct($value),
            '{detail}' => $detail,
            '{limit}' => (int) $limit . '٪',
            '{when}' => rbn_when($now),
        ];
    }

    function rbn_load_text(array $panel, array $n, $what, $value, $limit, $high, $now)
    {
        $t = panel_texts()['Admin']['RbNodes'];
        return strtr($t[$high ? 'loadHigh' : 'loadOk'], rbn_load_fields($panel, $n, $what, $value, $limit, $now));
    }

    // still over the limit: the $num-th message of this spell
    function rbn_load_still_text(array $panel, array $n, $what, $value, $limit, $since, $num, $now)
    {
        $t = panel_texts()['Admin']['RbNodes'];
        [$label, $last] = rbn_rep_num('load', $num);
        return strtr($t['loadStill'], rbn_load_fields($panel, $n, $what, $value, $limit, $now) + [
            '{num}' => $label,
            '{last}' => $last,
            '{dur}' => rbn_duration((int) $now - (int) $since),
        ]);
    }

    // one change, kept under the real panel; $tell 'down' | 'up' reports it as well
    function rbn_event(array $panel, $nodeId, $name, $from, $to, $msg, $at, $since, $tell)
    {
        global $pdo;
        $pdo->prepare("INSERT INTO rbn_event (panel_id, node_id, name, status_from, status_to, message, at) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([rbn_canon($panel), (int) $nodeId, (string) $name, (string) $from, (string) $to, $msg === '' ? null : (string) $msg, (int) $at]);
        if ($tell !== '') {
            rbn_alert($panel, rbn_alert_text($panel, $nodeId, $name, $from, $to, $msg, $at, $since, $tell === 'up'));
        }
    }

    // every report names the panel - each of its names in the bot - and its address
    function rbn_alert_text(array $panel, $nodeId, $name, $from, $to, $msg, $at, $since, $cameBack)
    {
        $t = panel_texts()['Admin']['RbNodes'];
        $key = (int) $nodeId === 0 ? ($cameBack ? 'alertPanelUp' : 'alertPanelDown') : ($cameBack ? 'alertUp' : 'alertDown');
        return strtr($t[$key], [
            '{panel}' => htmlspecialchars(rbn_names($panel)),
            '{host}' => htmlspecialchars(rbn_host($panel)),
            '{node}' => htmlspecialchars((string) $name),
            '{status}' => rbn_status_icon($to) . ' ' . rbn_status_name($to),
            '{message}' => $msg !== '' ? "\n💬 <code>" . htmlspecialchars(mb_substr((string) $msg, 0, 300)) . '</code>' : '',
            '{when}' => rbn_when($at),
            '{down}' => rbn_duration((int) $at - (int) $since),
        ]);
    }

    // the newest changes of a panel (twins share them), newest first
    function rbn_events(array $panel, $limit = 15, $offset = 0)
    {
        global $pdo;
        rbn_ensure_schema();
        $st = $pdo->prepare("SELECT * FROM rbn_event WHERE panel_id = ? ORDER BY at DESC, id DESC LIMIT " . (int) $limit . " OFFSET " . (int) $offset);
        $st->execute([rbn_canon($panel)]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    function rbn_events_count(array $panel)
    {
        global $pdo;
        rbn_ensure_schema();
        $st = $pdo->prepare("SELECT COUNT(*) FROM rbn_event WHERE panel_id = ?");
        $st->execute([rbn_canon($panel)]);
        return (int) $st->fetchColumn();
    }

    // since when a node has been as it is, as the watch saw it (null: not known)
    function rbn_since(array $panel, $nodeId)
    {
        global $pdo;
        rbn_ensure_schema();
        $st = $pdo->prepare("SELECT since FROM rbn_state WHERE panel_id = ? AND node_id = ?");
        $st->execute([rbn_canon($panel), (int) $nodeId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    // one line of the history
    function rbn_event_line(array $e)
    {
        $t = panel_texts()['Admin']['RbNodes'];
        $name = (int) $e['node_id'] === 0 ? $t['panelItself'] : htmlspecialchars((string) $e['name']);
        $line = rbn_status_icon($e['status_to']) . ' <b>' . $name . '</b> — ' . rbn_status_name($e['status_from']) . ' ← ' . rbn_status_name($e['status_to']);
        // the arrow reads right to left, as the Persian text around it
        $line .= "\n    🕒 " . rbn_when($e['at']);
        if (!empty($e['message'])) {
            $line .= ' · <i>' . htmlspecialchars(mb_substr((string) $e['message'], 0, 90)) . '</i>';
        }
        return $line;
    }

    // ---------------------------------------------------------------- 📊
    function rbn_font($bold = false)
    {
        return __DIR__ . '/assets/fonts/Vazirmatn-' . ($bold ? 'Bold' : 'Regular') . '.ttf';
    }

    // Persian for GD, which neither joins Arabic letters nor lays text right
    // to left: each letter in its joined form (Presentation Forms), then the
    // line in the order it is seen. Latin and digits keep their own order.
    function rbn_fa(string $s)
    {
        if (!preg_match('/[\x{0600}-\x{06FF}]/u', $s)) {
            return $s;
        }
        // letter => [isolated, final, initial, medial]; two forms: joins only to the one before it
        static $f = null;
        if ($f === null) {
            $f = [
                'ء' => [0xFE80], 'آ' => [0xFE81, 0xFE82], 'أ' => [0xFE83, 0xFE84], 'ؤ' => [0xFE85, 0xFE86], 'إ' => [0xFE87, 0xFE88],
                'ئ' => [0xFE89, 0xFE8A, 0xFE8B, 0xFE8C], 'ا' => [0xFE8D, 0xFE8E], 'ب' => [0xFE8F, 0xFE90, 0xFE91, 0xFE92],
                'ة' => [0xFE93, 0xFE94], 'ت' => [0xFE95, 0xFE96, 0xFE97, 0xFE98], 'ث' => [0xFE99, 0xFE9A, 0xFE9B, 0xFE9C],
                'ج' => [0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0], 'ح' => [0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4], 'خ' => [0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8],
                'د' => [0xFEA9, 0xFEAA], 'ذ' => [0xFEAB, 0xFEAC], 'ر' => [0xFEAD, 0xFEAE], 'ز' => [0xFEAF, 0xFEB0],
                'س' => [0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4], 'ش' => [0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8], 'ص' => [0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC],
                'ض' => [0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0], 'ط' => [0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4], 'ظ' => [0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8],
                'ع' => [0xFEC9, 0xFECA, 0xFECB, 0xFECC], 'غ' => [0xFECD, 0xFECE, 0xFECF, 0xFED0], 'ف' => [0xFED1, 0xFED2, 0xFED3, 0xFED4],
                'ق' => [0xFED5, 0xFED6, 0xFED7, 0xFED8], 'ك' => [0xFED9, 0xFEDA, 0xFEDB, 0xFEDC], 'ل' => [0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0],
                'م' => [0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4], 'ن' => [0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8], 'ه' => [0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC],
                'و' => [0xFEED, 0xFEEE], 'ى' => [0xFEEF, 0xFEF0], 'ي' => [0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4],
                'پ' => [0xFB56, 0xFB57, 0xFB58, 0xFB59], 'چ' => [0xFB7A, 0xFB7B, 0xFB7C, 0xFB7D], 'ژ' => [0xFB8A, 0xFB8B],
                'ک' => [0xFB8E, 0xFB8F, 0xFB90, 0xFB91], 'گ' => [0xFB92, 0xFB93, 0xFB94, 0xFB95], 'ی' => [0xFBFC, 0xFBFD, 0xFBFE, 0xFBFF],
            ];
        }
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        $n = count($chars);
        $joinsNext = fn($c) => isset($f[$c]) && count($f[$c]) === 4;
        $shaped = [];
        for ($i = 0; $i < $n; $i++) {
            $c = $chars[$i];
            if (!isset($f[$c]) || count($f[$c]) === 1) {
                $shaped[] = $c;
                continue;
            }
            $prev = $i > 0 && $joinsNext($chars[$i - 1]);
            $next = $i + 1 < $n && isset($f[$chars[$i + 1]]) && count($f[$chars[$i + 1]]) > 1 && $joinsNext($c);
            $forms = $f[$c];
            $cp = $prev && $next ? $forms[3] : ($prev ? $forms[1] : ($next ? $forms[2] : $forms[0]));
            $shaped[] = mb_chr($cp, 'UTF-8');
        }
        // runs: Latin/digits stay as they are, the rest turns round; then the runs turn round
        $runs = [];
        $buf = '';
        $ltr = null;
        foreach ($shaped as $c) {
            $isLtr = (bool) preg_match('/[A-Za-z0-9]/', $c) || ($ltr === true && preg_match('/[.:\/\-_%]/', $c));
            if ($ltr !== null && $isLtr !== $ltr) {
                $runs[] = [$ltr, $buf];
                $buf = '';
            }
            $ltr = $isLtr;
            $buf .= $c;
        }
        if ($buf !== '') {
            $runs[] = [$ltr, $buf];
        }
        $out = '';
        foreach (array_reverse($runs) as [$isLtr, $text]) {
            $out .= $isLtr ? $text : implode('', array_reverse(preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY)));
        }
        return $out;
    }

    // a line of text at (x, baseline y); $align 'l' | 'r' | 'c' about x
    function rbn_text($im, $size, $x, $y, $color, $text, $bold = false, $align = 'l')
    {
        $text = rbn_fa((string) $text);
        $box = imagettfbbox($size, 0, rbn_font($bold), $text);
        $w = $box ? abs($box[2] - $box[0]) : 0;
        $x = $align === 'r' ? $x - $w : ($align === 'c' ? $x - intdiv($w, 2) : $x);
        imagettftext($im, $size, 0, (int) $x, (int) $y, $color, rbn_font($bold), $text);
        return $w;
    }

    function rbn_color($im, $hex)
    {
        $hex = ltrim($hex, '#');
        return imagecolorallocate($im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }

    function rbn_rounded($im, $x1, $y1, $x2, $y2, $r, $color)
    {
        $r = (int) min($r, ($x2 - $x1) / 2, ($y2 - $y1) / 2);
        if ($x2 <= $x1 || $y2 <= $y1) {
            return;
        }
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $color);
        }
    }

    // a meter: label, a bar filled to $pct, the value at its end
    function rbn_meter($im, $x, $y, $w, $pct, $label, $value, array $c)
    {
        rbn_text($im, 11, $x, $y, $c['mute'], $label);
        $by = $y + 8;
        rbn_rounded($im, $x, $by, $x + $w, $by + 10, 5, $c['track']);
        if ($pct !== null) {
            $p = max(0, min(100, (float) $pct));
            $fill = $p >= 85 ? $c['bad'] : ($p >= 65 ? $c['warn'] : $c['ok']);
            if ($p > 0) {
                rbn_rounded($im, $x, $by, $x + max(10, (int) round($w * $p / 100)), $by + 10, 5, $fill);
            }
        }
        rbn_text($im, 11, $x + $w, $y, $c['text'], $value, false, 'r');
    }

    function rbn_palette($im)
    {
        return [
            'bg' => rbn_color($im, '0F172A'), 'card' => rbn_color($im, '1E293B'), 'track' => rbn_color($im, '334155'),
            'text' => rbn_color($im, 'F1F5F9'), 'mute' => rbn_color($im, '94A3B8'), 'grid' => rbn_color($im, '263449'),
            'ok' => rbn_color($im, '22C55E'), 'warn' => rbn_color($im, 'F59E0B'), 'bad' => rbn_color($im, 'EF4444'),
            'off' => rbn_color($im, '64748B'), 'accent' => rbn_color($im, '06B6D4'),
            'series' => [rbn_color($im, '06B6D4'), rbn_color($im, 'A78BFA'), rbn_color($im, 'F472B6'), rbn_color($im, 'FBBF24'),
                rbn_color($im, '34D399'), rbn_color($im, 'F87171'), rbn_color($im, '60A5FA'), rbn_color($im, 'C084FC')],
        ];
    }

    function rbn_status_color($status, array $c)
    {
        return ['connected' => $c['ok'], 'connecting' => $c['warn'], 'limited' => $c['warn'], 'disabled' => $c['off']][(string) $status] ?? $c['bad'];
    }

    // A line chart of traffic by day: $series = [name => [Y-m-d => bytes]] over $days
    function rbn_chart($im, $x, $y, $w, $h, array $series, array $days, array $c)
    {
        $max = 1;
        foreach ($series as $pts) {
            foreach ($pts as $v) {
                $max = max($max, (float) $v);
            }
        }
        // a round top, so the scale reads well
        $unit = 1024 ** (int) floor(log(max($max, 1), 1024));
        $top = ceil($max / $unit / 4) * 4 * $unit;
        $padL = 70;
        $plotW = $w - $padL - 10;
        $plotH = $h - 40;
        for ($i = 0; $i <= 4; $i++) {
            $gy = (int) ($y + $plotH - $plotH * $i / 4);
            imageline($im, $x + $padL, $gy, $x + $w - 10, $gy, $c['grid']);
            rbn_text($im, 10, $x + $padL - 8, $gy + 4, $c['mute'], rbn_bytes($top * $i / 4), false, 'r');
        }
        $nd = count($days);
        $step = $nd > 1 ? $plotW / ($nd - 1) : 0;
        foreach ($days as $i => $d) {
            if ($nd <= 10 || $i % (int) ceil($nd / 10) === 0 || $i === $nd - 1) {
                rbn_text($im, 9, (int) ($x + $padL + $i * $step), $y + $plotH + 18, $c['mute'], substr($d, 5), false, 'c');
            }
        }
        imagesetthickness($im, 3);
        $si = 0;
        foreach ($series as $pts) {
            $col = $c['series'][$si % count($c['series'])];
            $prev = null;
            foreach ($days as $i => $d) {
                $px = (int) ($x + $padL + $i * $step);
                $py = (int) ($y + $plotH - $plotH * ((float) ($pts[$d] ?? 0)) / $top);
                if ($prev !== null) {
                    imageline($im, $prev[0], $prev[1], $px, $py, $col);
                }
                imagefilledellipse($im, $px, $py, 7, 7, $col);
                $prev = [$px, $py];
            }
            $si++;
        }
        imagesetthickness($im, 1);
    }

    function rbn_png_path($tag)
    {
        $dir = __DIR__ . '/storage/rbnodes';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // old pictures go
        foreach (glob($dir . '/*.png') ?: [] as $old) {
            if (filemtime($old) < time() - 3600) {
                @unlink($old);
            }
        }
        return $dir . '/' . preg_replace('/[^a-z0-9_]/i', '', $tag) . '_' . bin2hex(random_bytes(4)) . '.png';
    }

    // 📊 every node of a panel in one picture: its state, CPU, RAM, speed,
    // traffic and versions, and the last 14 days of traffic, a line per node.
    // The path of the PNG, or null when GD cannot draw.
    function rbn_report_png(array $panel, array $nodes, array $series = [])
    {
        if (!function_exists('imagettftext') || !is_file(rbn_font())) {
            return null;
        }
        $W = 1280;
        $rowH = 128;
        $chartH = $series ? 360 : 0;
        $H = 120 + max(1, count($nodes)) * ($rowH + 14) + ($chartH ? $chartH + 80 : 0) + 30;
        $im = imagecreatetruecolor($W, $H);
        $c = rbn_palette($im);
        imagefilledrectangle($im, 0, 0, $W, $H, $c['bg']);
        $up = count(array_filter($nodes, fn($n) => ($n['status'] ?? '') === 'connected'));
        rbn_text($im, 24, 40, 56, $c['text'], 'Nodes — ' . (string) $panel['name_panel'], true);
        rbn_text($im, 13, 40, 88, $c['mute'], date('Y-m-d H:i') . '   ·   ' . $up . ' / ' . count($nodes) . ' connected');
        $y = 116;
        if (!$nodes) {
            rbn_text($im, 14, 40, $y + 40, $c['mute'], 'No nodes');
        }
        foreach ($nodes as $n) {
            rbn_rounded($im, 30, $y, $W - 30, $y + $rowH, 14, $c['card']);
            $st = (string) ($n['status'] ?? '');
            imagefilledellipse($im, 58, $y + 30, 16, 16, rbn_status_color($st, $c));
            rbn_text($im, 17, 76, $y + 37, $c['text'], (string) $n['name'], true);
            rbn_text($im, 11, 76, $y + 62, $c['mute'], trim((string) ($n['address'] ?? '') . ':' . (string) ($n['port'] ?? '')) . '   ·   ' . $st);
            $xv = (string) ($n['xray_version'] ?? '');
            $sv = (string) ($n['node_service_version'] ?? '');
            rbn_text($im, 11, 76, $y + 86, $c['mute'], 'Xray ' . ($xv !== '' ? $xv : '—') . ($sv !== '' ? '   ·   node ' . $sv : ''));
            if (!empty($n['message']) && $st !== 'connected') {
                rbn_text($im, 10, 76, $y + 110, $c['bad'], mb_substr((string) $n['message'], 0, 70));
            }
            $cpu = $n['cpu_usage_percent'] ?? null;
            $mem = $n['memory_usage_percent'] ?? null;
            $memTxt = isset($n['memory_used'], $n['memory_total']) ? number_format((float) $mem, 1) . '%  ·  ' . rbn_bytes($n['memory_used']) . ' / ' . rbn_bytes($n['memory_total']) : ($mem !== null ? number_format((float) $mem, 1) . '%' : '—');
            $cores = isset($n['cpu_cores']) && $n['cpu_cores'] ? ' · ' . (int) $n['cpu_cores'] . ' cores' : '';
            rbn_meter($im, 470, $y + 34, 300, $cpu, 'CPU' . $cores, $cpu !== null ? number_format((float) $cpu, 1) . '%' : '—', $c);
            rbn_meter($im, 470, $y + 84, 300, $mem, 'RAM', $memTxt, $c);
            $sx = 820;
            rbn_text($im, 11, $sx, $y + 34, $c['mute'], 'Speed');
            // the font has no arrows: words instead
            rbn_text($im, 13, $sx, $y + 58, $c['text'], ($n['upload_speed'] ?? null) === null ? '—' : 'Up ' . rbn_bytes($n['upload_speed']) . '/s   Down ' . rbn_bytes($n['download_speed'] ?? 0) . '/s');
            rbn_text($im, 11, $sx, $y + 84, $c['mute'], 'Traffic' . (isset($n['usage_coefficient']) && (float) $n['usage_coefficient'] != 1 ? ' (x' . (float) $n['usage_coefficient'] . ')' : ''));
            rbn_text($im, 13, $sx, $y + 108, $c['text'], 'Up ' . rbn_bytes($n['uplink'] ?? 0) . '   Down ' . rbn_bytes($n['downlink'] ?? 0));
            if (isset($n['uptime_seconds']) && $n['uptime_seconds']) {
                rbn_text($im, 11, $W - 50, $y + 34, $c['mute'], 'up ' . rbn_short_uptime((int) $n['uptime_seconds']), false, 'r');
            }
            $y += $rowH + 14;
        }
        if ($chartH) {
            $y += 20;
            rbn_text($im, 17, 40, $y, $c['text'], 'Traffic per day — last 14 days', true);
            $days = [];
            for ($i = 13; $i >= 0; $i--) {
                $days[] = date('Y-m-d', time() - $i * 86400);
            }
            // the legend
            $lx = 40;
            $si = 0;
            foreach (array_keys($series) as $name) {
                $col = $c['series'][$si % count($c['series'])];
                imagefilledrectangle($im, $lx, $y + 18, $lx + 14, $y + 30, $col);
                $lx += 22 + rbn_text($im, 11, $lx + 20, $y + 30, $c['mute'], (string) $name) + 18;
                $si++;
            }
            rbn_chart($im, 30, $y + 50, $W - 60, $chartH, $series, $days, $c);
        }
        $path = rbn_png_path('report');
        imagepng($im, $path);
        imagedestroy($im);
        return $path;
    }

    function rbn_short_uptime($s)
    {
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);
        return $d > 0 ? "{$d}d {$h}h" : $h . 'h ' . intdiv($s % 3600, 60) . 'm';
    }

    // 📈 one node's traffic by day over $days days, as bars. Path or null.
    function rbn_daily_png(array $panel, array $node, array $rows, $days = 30)
    {
        if (!function_exists('imagettftext') || !is_file(rbn_font())) {
            return null;
        }
        $W = 1200;
        $H = 560;
        $im = imagecreatetruecolor($W, $H);
        $c = rbn_palette($im);
        imagefilledrectangle($im, 0, 0, $W, $H, $c['bg']);
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[$r['date']] = ($byDay[$r['date']] ?? 0) + (int) $r['bytes'];
        }
        $list = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', time() - $i * 86400);
            $list[$d] = (int) ($byDay[$d] ?? 0);
        }
        $total = array_sum($list);
        rbn_text($im, 22, 40, 52, $c['text'], (string) $node['name'], true);
        rbn_text($im, 13, 40, 84, $c['mute'], (string) $panel['name_panel'] . '   ·   traffic per day, last ' . $days . ' days   ·   total ' . rbn_bytes($total) . '   ·   avg ' . rbn_bytes($total / max(1, $days)) . '/day');
        $x = 40;
        $y = 120;
        $w = $W - 80;
        $h = $H - 170;
        $max = max(1, max($list));
        $unit = 1024 ** (int) floor(log($max, 1024));
        $top = ceil($max / $unit / 4) * 4 * $unit;
        $padL = 70;
        for ($i = 0; $i <= 4; $i++) {
            $gy = (int) ($y + $h - $h * $i / 4);
            imageline($im, $x + $padL, $gy, $x + $w, $gy, $c['grid']);
            rbn_text($im, 10, $x + $padL - 8, $gy + 4, $c['mute'], rbn_bytes($top * $i / 4), false, 'r');
        }
        $bw = ($w - $padL) / count($list);
        $i = 0;
        foreach ($list as $d => $v) {
            $bx = (int) ($x + $padL + $i * $bw + 3);
            $bh = (int) round($h * $v / $top);
            if ($bh > 0) {
                rbn_rounded($im, $bx, $y + $h - $bh, (int) ($bx + $bw - 6), $y + $h, 4, $c['accent']);
            }
            if ($i % 5 === 0 || $i === count($list) - 1) {
                rbn_text($im, 9, (int) ($bx + $bw / 2 - 3), $y + $h + 18, $c['mute'], substr($d, 5), false, 'c');
            }
            $i++;
        }
        $path = rbn_png_path('daily');
        imagepng($im, $path);
        imagedestroy($im);
        return $path;
    }

    // the report picture of a panel, with its traffic: [path, nodes, why]
    function rbn_build_report(array $panel)
    {
        [$nodes, $why] = rbn_nodes_live($panel);
        if ($nodes === null) {
            return [null, null, $why];
        }
        // a node not asked (off, or did not answer) has no numbers, not zeros
        foreach ($nodes as $i => $n) {
            if (!rbn_has_metrics($n)) {
                foreach (['cpu_usage_percent', 'memory_usage_percent', 'memory_used', 'memory_total', 'upload_speed', 'download_speed'] as $k) {
                    $nodes[$i][$k] = null;
                }
            }
        }
        $series = [];
        foreach (array_slice($nodes, 0, 8) as $n) {
            $rows = rbn_daily($panel, $n['id'], 14);
            if ($rows === null) {
                continue;
            }
            $pts = [];
            foreach ($rows as $r) {
                $pts[$r['date']] = ($pts[$r['date']] ?? 0) + $r['bytes'];
            }
            $series[(string) $n['name']] = $pts;
        }
        return [rbn_report_png($panel, $nodes, $series), $nodes, ''];
    }

    // a picture to a chat, with a caption; the file goes afterwards
    function rbn_send_png($chatId, $path, $caption, $keyboard = null)
    {
        $data = ['chat_id' => $chatId, 'photo' => new CURLFile($path), 'caption' => $caption, 'parse_mode' => 'HTML'];
        if ($keyboard !== null) {
            $data['reply_markup'] = $keyboard;
        }
        $r = telegram('sendphoto', $data);
        @unlink($path);
        return $r;
    }

    // ---------------------------------------------------------------- 🤖
    // The bot's screens: [text, keyboard json]. Buttons carry the panel's id
    // (p) and the node's (n): rbn|hub|p, rbn|nd|p|n, rbn|ask|p|n|a, ...
    function rbn_btn($text, $cb, $style = null)
    {
        $b = ['text' => $text, 'callback_data' => $cb];
        if ($style !== null) {
            $b['style'] = $style;
        }
        return $b;
    }

    function rbn_kb(array $rows)
    {
        return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
    }

    function rbn_hub_screen(array $panel, $note = '')
    {
        $t = panel_texts()['Admin']['RbNodes'];
        $p = (int) $panel['id'];
        // CPU, RAM and speed as they are this moment - asked once, now
        [$nodes, $why] = rbn_nodes_live($panel);
        $head = strtr($t['hubTitle'], ['{panel}' => htmlspecialchars((string) $panel['name_panel']), '{host}' => htmlspecialchars(rbn_host($panel))]);
        $others = array_filter(rbn_twins($panel), fn($x) => (int) $x['id'] !== $p);
        if ($others) {
            $head .= "\n" . strtr($t['hubTwin'], ['{twins}' => htmlspecialchars(implode('، ', array_map(fn($x) => (string) $x['name_panel'], $others)))]);
        }
        $loadOn = rbn_load_on();
        $info = [strtr($t['hubWatch'], ['{state}' => rbn_watch_on() ? $t['on'] : $t['off']])];
        if (rbn_watch_on()) {
            $info[] = strtr($t['hubRepDown'], ['{sum}' => rbn_rep_summary('down')]);
        }
        $info[] = strtr($t['hubLoad'], ['{state}' => $loadOn ? $t['on'] : $t['off'], '{limits}' => $loadOn ? strtr($t['hubLoadLimits'], ['{cpu}' => rbn_load_limit('cpu'), '{ram}' => rbn_load_limit('ram')]) : '']);
        if ($loadOn) {
            $info[] = strtr($t['hubRepLoad'], ['{sum}' => rbn_rep_summary('load')]);
        }
        $info[] = strtr($t['hubDest'], ['{dest}' => htmlspecialchars(rbn_dest_label(rbn_dest($panel)))]);
        $tail = [
            [rbn_btn($t['btnHistory'], "rbn|ev|{$p}|1"), rbn_btn(rbn_watch_on() ? $t['btnWatchOn'] : $t['btnWatchOff'], "rbn|wt|{$p}", rbn_watch_on() ? 'success' : 'danger')],
            [rbn_btn($t['btnDest'], "rbn|dst|{$p}")],
            [rbn_btn($t['btnRefresh'], "rbn|hub|{$p}", 'primary'), rbn_btn($t['btnClose'], 'rbn|close', 'danger')],
        ];
        $loadRow = [rbn_btn($t['btnLoad'], "rbn|ld|{$p}", $loadOn ? 'success' : null)];
        $repRow = [rbn_btn($t['btnRepDown'], "rbn|rp|{$p}|0|down", rbn_rep_on('down') ? 'success' : null)];
        if ($nodes === null) {
            return [($note !== '' ? $note . "\n\n" : '') . $head . "\n\n" . strtr($t['hubError'], ['{why}' => $why]), rbn_kb(array_merge([$loadRow, $repRow], $tail))];
        }
        $up = $down = $off = 0;
        $blocks = [];
        $btns = [];
        foreach ($nodes as $n) {
            $st = (string) ($n['status'] ?? '');
            if ($st === 'connected') {
                $up++;
            } elseif ($st === 'disabled') {
                $off++;
            } else {
                $down++;
            }
            $btns[] = rbn_btn(rbn_status_icon($st) . ' ' . (string) $n['name'], "rbn|nd|{$p}|" . (int) $n['id']);
            $blocks[] = rbn_node_block($n, $t);
        }
        $top = ($note !== '' ? $note . "\n\n" : '') . $head . "\n\n"
            . '<blockquote>' . ($nodes ? strtr($t['hubCounts'], ['{up}' => $up, '{down}' => $down, '{off}' => $off]) . "\n" : '') . implode("\n", $info) . '</blockquote>';
        $foot = "\n\n" . strtr($t['hubUpdated'], ['{when}' => rbn_when(time())]);
        // a message holds 4096 characters: the nodes that do not fit are still a tap away
        $body = '';
        $shown = 0;
        foreach ($blocks as $b) {
            if (mb_strlen($top . $body . $b . $foot) > 3900) {
                break;
            }
            $body .= "\n\n" . $b;
            $shown++;
        }
        if ($shown < count($blocks)) {
            $body .= "\n\n" . strtr($t['hubMore'], ['{n}' => count($blocks) - $shown]);
        }
        $text = $top . ($blocks ? $body : "\n\n" . $t['hubEmpty']) . $foot;
        $rows = array_chunk($btns, 2);
        $rows[] = [rbn_btn($t['btnAdd'], "rbn|add|{$p}", 'success'), rbn_btn($t['btnReport'], "rbn|rep|{$p}", 'primary')];
        if ($nodes) {
            $rows[] = [rbn_btn($t['btnUpdateAll'], "rbn|sua|{$p}")];
        }
        $rows[] = $loadRow;
        $rows[] = $repRow;
        return [$text, rbn_kb(array_merge($rows, $tail))];
    }

    // one node in the hub: its numbers now, or why it has none
    function rbn_node_block(array $n, array $t)
    {
        $st = (string) ($n['status'] ?? '');
        $name = htmlspecialchars((string) $n['name']);
        $addr = htmlspecialchars((string) (($n['address'] ?? '') ?: '—'));
        if ($st === 'connected') {
            $m = rbn_has_metrics($n);
            return strtr($t['hubNode'], [
                '{icon}' => rbn_status_icon($st), '{name}' => $name, '{address}' => $addr,
                '{cpu}' => $m ? rbn_pct($n['cpu_usage_percent'] ?? null) : '—',
                '{ram}' => $m ? rbn_pct($n['memory_usage_percent'] ?? null) : '—',
                '{up}' => $m ? rbn_bytes($n['upload_speed'] ?? 0) : '—',
                '{down}' => $m ? rbn_bytes($n['download_speed'] ?? 0) : '—',
                '{xray}' => htmlspecialchars((string) (($n['xray_version'] ?? '') ?: '—')),
            ]);
        }
        $msg = trim((string) ($n['message'] ?? ''));
        return strtr($t['hubNodeOff'], [
            '{icon}' => rbn_status_icon($st), '{name}' => $name, '{address}' => $addr,
            '{status}' => '<b>' . htmlspecialchars(rbn_status_name($st)) . '</b>',
            '{message}' => $msg !== '' && $st !== 'disabled' ? "\n<i>" . htmlspecialchars(mb_substr($msg, 0, 90)) . '</i>' : '',
        ]);
    }

    // 🚨 the high CPU/RAM alert: on or off, and its two limits
    function rbn_load_screen(array $panel, $note = '')
    {
        $t = panel_texts()['Admin']['RbNodes'];
        $p = (int) $panel['id'];
        $on = rbn_load_on();
        $cpu = rbn_load_limit('cpu');
        $ram = rbn_load_limit('ram');
        $text = ($note !== '' ? $note . "\n\n" : '') . strtr($t['loadTitle'], ['{state}' => $on ? $t['on'] : $t['off'], '{cpu}' => $cpu, '{ram}' => $ram, '{rep}' => rbn_rep_summary('load')]);
        $presets = function ($what, $now) use ($p) {
            $row = [];
            foreach ([70, 80, 85, 90, 95] as $v) {
                $row[] = rbn_btn(($v === $now ? '✅ ' : '') . $v . '٪', "rbn|ldv|{$p}|0|{$what}-{$v}", $v === $now ? 'success' : null);
            }
            return $row;
        };
        $rows = [
            [rbn_btn($on ? $t['loadBtnOn'] : $t['loadBtnOff'], "rbn|ldt|{$p}", $on ? 'success' : 'danger')],
            [rbn_btn($t['loadCpuHead'], "rbn|nop|{$p}")],
            $presets('cpu', $cpu),
            [rbn_btn(strtr($t['loadCustom'], ['{what}' => 'CPU']), "rbn|ldk|{$p}|0|cpu", 'primary')],
            [rbn_btn($t['loadRamHead'], "rbn|nop|{$p}")],
            $presets('ram', $ram),
            [rbn_btn(strtr($t['loadCustom'], ['{what}' => 'RAM']), "rbn|ldk|{$p}|0|ram", 'primary')],
            [rbn_btn(strtr($t['btnRepLoad'], ['{sum}' => rbn_rep_summary('load')]), "rbn|rp|{$p}|0|load", rbn_rep_on('load') ? 'success' : null)],
            [rbn_btn($t['btnBackHub'], "rbn|hub|{$p}", 'danger')],
        ];
        return [$text, rbn_kb($rows)];
    }

    // 🔁 reminders for $kind ('down' | 'load'): on/off, the gap, how many messages in all
    function rbn_repeat_screen(array $panel, $kind, $note = '')
    {
        $t = panel_texts()['Admin']['RbNodes'];
        $p = (int) $panel['id'];
        $kind = $kind === 'load' ? 'load' : 'down';
        $on = rbn_rep_on($kind);
        $every = rbn_rep_every($kind);
        $max = rbn_rep_max($kind);
        $text = ($note !== '' ? $note . "\n\n" : '')
            . strtr($t['repTitle'], ['{what}' => $t['repWhat_' . $kind]]) . "\n\n"
            . $t['repIntro_' . $kind] . "\n\n"
            . strtr($t['repState'], ['{state}' => $on ? $t['on'] : $t['off']])
            . ($on ? strtr($t['repLines'], ['{every}' => $every, '{max}' => rbn_rep_max_label($max)]) : $t['repOffNote'])
            . "\n\n" . $t['repFoot'];
        $pick = fn($field, $value, $label, $now) => rbn_btn(($now ? '✅ ' : '') . $label, "rbn|rpv|{$p}|0|{$kind}-{$field}-{$value}", $now ? 'success' : null);
        $rows = [[rbn_btn($on ? $t['repBtnOn'] : $t['repBtnOff'], "rbn|rpt|{$p}|0|{$kind}", $on ? 'success' : 'danger')]];
        if ($on) {
            $rows[] = [rbn_btn($t['repEveryHead'], "rbn|nop|{$p}")];
            foreach (array_chunk([2, 5, 10, 15, 30, 60], 3) as $chunk) {
                $rows[] = array_map(fn($v) => $pick('e', $v, $v . ' ' . $t['repMin'], $v === $every), $chunk);
            }
            $rows[] = [rbn_btn($t['repCustomEvery'], "rbn|rpk|{$p}|0|{$kind}-e", 'primary')];
            $rows[] = [rbn_btn($t['repMaxHead'], "rbn|nop|{$p}")];
            $rows[] = array_merge(array_map(fn($v) => $pick('n', $v, (string) $v, $v === $max), [2, 3, 5, 10]), [$pick('n', 0, $t['repUnlimitedBtn'], $max === 0)]);
            $rows[] = [rbn_btn($t['repCustomMax'], "rbn|rpk|{$p}|0|{$kind}-n", 'primary')];
        }
        $rows[] = $kind === 'load'
            ? [rbn_btn($t['btnBackLoad'], "rbn|ld|{$p}", 'danger')]
            : [rbn_btn($t['btnBackHub'], "rbn|hub|{$p}", 'danger')];
        return [$text, rbn_kb($rows)];
    }

    // 📮 where this panel's reports go. $first: asked before the nodes are
    // shown, the first time - with «later» instead of «back».
    function rbn_dest_screen(array $panel, $first = false, $note = '')
    {
        $t = panel_texts()['Admin']['RbNodes'];
        $p = (int) $panel['id'];
        $dest = rbn_dest($panel);
        $others = array_filter(rbn_twins($panel), fn($x) => (int) $x['id'] !== $p);
        $text = ($note !== '' ? $note . "\n\n" : '') . ($first ? $t['destFirst'] . "\n\n" : '') . strtr($t['destTitle'], [
            '{panel}' => htmlspecialchars((string) $panel['name_panel']),
            '{host}' => htmlspecialchars(rbn_host($panel)),
            '{twin}' => $others ? strtr($t['destTwinNote'], ['{twins}' => htmlspecialchars(implode('، ', array_map(fn($x) => (string) $x['name_panel'], $others)))]) : '',
            '{dest}' => htmlspecialchars(rbn_dest_label($dest)),
        ]);
        $mark = fn($label, $on) => ($on ? '✅ ' : '') . $label;
        $rows = [];
        if (rbn_report_group() !== '') {
            $rows[] = [rbn_btn($mark($t['destGroup'], $dest === 'group'), "rbn|dset|{$p}|group", $dest === 'group' ? 'success' : null)];
        }
        $rows[] = [rbn_btn($mark($t['destChat'], strpos($dest, 'chat:') === 0), "rbn|dset|{$p}|chat", strpos($dest, 'chat:') === 0 ? 'success' : null)];
        $rows[] = [rbn_btn($mark($t['destBot'], $dest === 'bot'), "rbn|dset|{$p}|bot", $dest === 'bot' ? 'success' : null)];
        $rows[] = [rbn_btn($mark($t['destOff'], $dest === 'off'), "rbn|dset|{$p}|off", $dest === 'off' ? 'success' : null)];
        if ($dest !== '' && $dest !== 'off') {
            $rows[] = [rbn_btn($t['destTestBtn'], "rbn|dtest|{$p}")];
        }
        $rows[] = $first
            ? [rbn_btn($t['destLater'], "rbn|dset|{$p}|auto", 'primary')]
            : [rbn_btn($t['btnBackHub'], "rbn|hub|{$p}", 'danger')];
        return [$text, rbn_kb($rows)];
    }

    // the node screens open with the question, until it has been answered once
    function rbn_open_screen(array $panel, $note = '')
    {
        return rbn_dest($panel) === '' ? rbn_dest_screen($panel, true, $note) : rbn_hub_screen($panel, $note);
    }

    // the text about one node
    function rbn_node_text(array $panel, array $n)
    {
        $t = panel_texts()['Admin']['RbNodes'];
        $st = (string) ($n['status'] ?? '');
        $x = fn($v) => htmlspecialchars((string) (($v ?? '') === '' ? '—' : $v));
        $out = [strtr($t['nodeTitle'], ['{icon}' => rbn_status_icon($st), '{name}' => htmlspecialchars((string) $n['name']), '{status}' => rbn_status_name($st)])];
        if (!empty($n['message']) && $st !== 'connected') {
            $out[] = strtr($t['nodeMessage'], ['{message}' => htmlspecialchars(mb_substr((string) $n['message'], 0, 300))]);
        }
        $out[] = strtr($t['nodeNet'], ['{address}' => $x($n['address'] ?? ''), '{port}' => $x($n['port'] ?? ''), '{api}' => $x($n['api_port'] ?? ''), '{xray}' => $x($n['xray_version'] ?? ''), '{svc}' => $x($n['node_service_version'] ?? '')]);
        if (rbn_has_metrics($n)) {
            $out[] = strtr($t['nodeLoad'], [
                '{cpu}' => rbn_pct($n['cpu_usage_percent'] ?? null),
                '{cores}' => !empty($n['cpu_cores']) ? strtr($t['nodeCores'], ['{n}' => (int) $n['cpu_cores']]) : '',
                '{ram}' => rbn_pct($n['memory_usage_percent'] ?? null),
                '{used}' => rbn_bytes($n['memory_used'] ?? 0),
                '{total}' => rbn_bytes($n['memory_total'] ?? 0),
                '{up}' => rbn_bytes($n['upload_speed'] ?? 0),
                '{down}' => rbn_bytes($n['download_speed'] ?? 0),
                '{uptime}' => !empty($n['uptime_seconds']) ? strtr($t['nodeUptime'], ['{uptime}' => rbn_duration((int) $n['uptime_seconds'])]) : '',
            ]);
        } elseif ($st === 'connected') {
            $out[] = $t['nodeLoadNone'];
        }
        $out[] = strtr($t['nodeTraffic'], ['{up}' => rbn_bytes($n['uplink'] ?? 0), '{down}' => rbn_bytes($n['downlink'] ?? 0), '{coef}' => (float) ($n['usage_coefficient'] ?? 1)]);
        $since = rbn_since($panel, (int) $n['id']);
        if ($since !== null) {
            $out[] = strtr($t['nodeSince'], ['{when}' => rbn_when($since)]);
        }
        return implode("\n", $out);
    }

    function rbn_node_screen(array $panel, $nodeId, $note = '')
    {
        $t = panel_texts()['Admin']['RbNodes'];
        $p = (int) $panel['id'];
        $id = (int) $nodeId;
        [$n, $why] = rbn_node($panel, $id);
        $back = [rbn_btn($t['btnBackHub'], "rbn|hub|{$p}", 'danger')];
        if ($n === null) {
            return [($note !== '' ? $note . "\n\n" : '') . strtr($t['hubError'], ['{why}' => $why]), rbn_kb([[rbn_btn($t['btnRefresh'], "rbn|nd|{$p}|{$id}")], $back])];
        }
        $a = $t['act'];
        $go = fn($k) => rbn_btn($a[$k], in_array($k, ['rc', 'sy', 'en'], true) ? "rbn|do|{$p}|{$id}|{$k}" : "rbn|ask|{$p}|{$id}|{$k}");
        $off = ($n['status'] ?? '') === 'disabled';
        $rows = [
            [$go('rc'), $go('sy')],
            [$go('rs'), $go('sr')],
            [rbn_btn($a['core'], "rbn|core|{$p}|{$id}", 'primary'), $go('su')],
            [rbn_btn($a['ch'], "rbn|ch|{$p}|{$id}", 'primary'), rbn_btn($a['ed'], "rbn|ed|{$p}|{$id}")],
            [$off ? $go('en') : $go('ds'), $go('ur')],
            [$go('hr'), rbn_btn($a['dl'], "rbn|ask|{$p}|{$id}|dl", 'danger')],
            [rbn_btn($t['btnRefresh'], "rbn|nd|{$p}|{$id}"), $back[0]],
        ];
        return [($note !== '' ? $note . "\n\n" : '') . rbn_node_text($panel, $n), rbn_kb($rows)];
    }

    // «are you sure» before an action that interrupts users or cannot be undone
    function rbn_confirm_screen(array $panel, $nodeId, $action, $name)
    {
        $t = panel_texts()['Admin']['RbNodes'];
        $p = (int) $panel['id'];
        $id = (int) $nodeId;
        $text = '⚠️ ' . strtr($t['ask'][$action] ?? '', ['{name}' => htmlspecialchars((string) $name)]);
        return [$text, rbn_kb([
            [rbn_btn($t['btnYes'], "rbn|do|{$p}|{$id}|{$action}", 'danger')],
            [rbn_btn($t['btnCancel'], "rbn|nd|{$p}|{$id}", 'primary')],
        ])];
    }

    function rbn_core_screen(array $panel, array $node)
    {
        $t = panel_texts()['Admin']['RbNodes'];
        $p = (int) $panel['id'];
        $id = (int) $node['id'];
        [$tags, $why] = rbn_xray_releases($panel);
        $back = [rbn_btn($t['btnBackNode'], "rbn|nd|{$p}|{$id}", 'danger')];
        if (!$tags) {
            return [strtr($t['coreNone'], ['{why}' => $why]), rbn_kb([$back])];
        }
        $cur = (string) ($node['xray_version'] ?? '');
        $btns = [];
        foreach ($tags as $tag) {
            $same = ltrim($tag, 'v') === ltrim($cur, 'v');
            $btns[] = rbn_btn(($same ? '✅ ' : '') . $tag, "rbn|cv|{$p}|{$id}|{$tag}", $same ? 'success' : null);
        }
        $rows = array_chunk($btns, 2);
        $rows[] = $back;
        return [strtr($t['coreTitle'], ['{name}' => htmlspecialchars((string) $node['name']), '{xray}' => htmlspecialchars($cur !== '' ? $cur : '—')]), rbn_kb($rows)];
    }

    function rbn_edit_screen(array $panel, array $node)
    {
        $t = panel_texts()['Admin']['RbNodes'];
        $p = (int) $panel['id'];
        $id = (int) $node['id'];
        $f = $t['field'];
        return [strtr($t['editTitle'], ['{name}' => htmlspecialchars((string) $node['name'])]), rbn_kb([
            [rbn_btn($f['name'], "rbn|ef|{$p}|{$id}|name"), rbn_btn($f['address'], "rbn|ef|{$p}|{$id}|address")],
            [rbn_btn($f['port'], "rbn|ef|{$p}|{$id}|port"), rbn_btn($f['api_port'], "rbn|ef|{$p}|{$id}|api_port")],
            [rbn_btn($f['coef'], "rbn|ef|{$p}|{$id}|coef")],
            [rbn_btn($t['btnBackNode'], "rbn|nd|{$p}|{$id}", 'danger')],
        ])];
    }

    // what a node's field holds now, for «الان: ...»
    function rbn_field_now(array $node, $field)
    {
        $key = ['coef' => 'usage_coefficient'][$field] ?? $field;
        return (string) ($node[$key] ?? '');
    }

    function rbn_history_screen(array $panel, $page = 1)
    {
        $t = panel_texts()['Admin']['RbNodes'];
        $p = (int) $panel['id'];
        $per = 10;
        $total = rbn_events_count($panel);
        $back = [rbn_btn($t['btnBackHub'], "rbn|hub|{$p}", 'danger')];
        if ($total === 0) {
            return [$t['historyEmpty'], rbn_kb([$back])];
        }
        $pages = max(1, (int) ceil($total / $per));
        $page = max(1, min($pages, (int) $page));
        $lines = array_map('rbn_event_line', rbn_events($panel, $per, ($page - 1) * $per));
        $nav = [];
        if ($page > 1) {
            $nav[] = rbn_btn($t['prev'], "rbn|ev|{$p}|" . ($page - 1));
        }
        if ($page < $pages) {
            $nav[] = rbn_btn($t['next'], "rbn|ev|{$p}|" . ($page + 1));
        }
        $rows = $nav ? [$nav, $back] : [$back];
        return [strtr($t['historyTitle'], ['{panel}' => htmlspecialchars((string) $panel['name_panel']), '{page}' => $page, '{pages}' => $pages]) . "\n\n" . implode("\n\n", $lines), rbn_kb($rows)];
    }

    // the caption under the report picture: a few words for every node
    function rbn_report_caption(array $panel, array $nodes)
    {
        $t = panel_texts()['Admin']['RbNodes'];
        $up = count(array_filter($nodes, fn($n) => ($n['status'] ?? '') === 'connected'));
        $out = strtr($t['reportCaption'], ['{panel}' => htmlspecialchars((string) $panel['name_panel']), '{when}' => rbn_when(time()), '{up}' => $up, '{total}' => count($nodes)]);
        $shown = 0;
        foreach ($nodes as $n) {
            $st = (string) ($n['status'] ?? '');
            $base = ['{icon}' => rbn_status_icon($st), '{name}' => htmlspecialchars((string) $n['name']), '{address}' => htmlspecialchars((string) (($n['address'] ?? '') ?: '—'))];
            $line = "\n\n" . ($st === 'connected'
                ? strtr($t['reportLine'], $base + [
                    '{cpu}' => rbn_pct($n['cpu_usage_percent'] ?? null),
                    '{ram}' => rbn_pct($n['memory_usage_percent'] ?? null),
                    '{ups}' => ($n['upload_speed'] ?? null) === null ? '—' : rbn_bytes($n['upload_speed']),
                    '{dns}' => ($n['download_speed'] ?? null) === null ? '—' : rbn_bytes($n['download_speed']),
                    '{upl}' => rbn_bytes($n['uplink'] ?? 0),
                    '{dnl}' => rbn_bytes($n['downlink'] ?? 0),
                ])
                : strtr($t['reportLineOff'], $base + ['{status}' => '<b>' . htmlspecialchars(rbn_status_name($st)) . '</b>']));
            // a photo's caption holds 1024 characters, as they show (tags not counted)
            if (mb_strlen(html_entity_decode(strip_tags($out . $line), ENT_QUOTES, 'UTF-8')) > 1000) {
                $out .= "\n\n" . strtr($t['hubMore'], ['{n}' => count($nodes) - $shown]);
                break;
            }
            $out .= $line;
            $shown++;
        }
        return $out;
    }

    // ➕ the summary before a new node is made
    function rbn_add_summary(array $panel, array $d)
    {
        $t = panel_texts()['Admin']['RbNodes'];
        $p = (int) $panel['id'];
        return [strtr($t['addSummary'], [
            '{name}' => htmlspecialchars((string) $d['name']),
            '{address}' => htmlspecialchars((string) $d['address']),
            '{port}' => (int) $d['port'],
            '{api}' => (int) $d['api_port'],
            '{coef}' => (float) $d['coef'],
        ]), rbn_kb([
            [rbn_btn($t['btnCreate'], "rbn|addgo|{$p}", 'success')],
            [rbn_btn($t['btnCancel'], "rbn|hub|{$p}", 'danger')],
        ])];
    }

    // the node's install bundle (certificate + private key) as a file for the admin
    function rbn_send_bundle($chatId, array $node)
    {
        $cert = trim((string) ($node['node_certificate'] ?? ''));
        $key = trim((string) ($node['node_certificate_key'] ?? ''));
        if ($cert === '' || $key === '') {
            return false;
        }
        $dir = __DIR__ . '/storage/rbnodes';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $path = $dir . '/node-' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $node['name']) . '-bundle.pem';
        file_put_contents($path, $cert . "\n" . $key . "\n");
        @chmod($path, 0600);
        telegram('sendDocument', ['chat_id' => $chatId, 'document' => new CURLFile($path), 'caption' => '🔐 ' . (string) $node['name']]);
        @unlink($path);
        return true;
    }
}
