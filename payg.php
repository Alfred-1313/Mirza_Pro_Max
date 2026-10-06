<?php
// ⏱ اکانت ساعتی (pay as you go): a service paid for as it is used - by the
// minute and/or by the gigabyte - out of the wallet of its language. Every
// setting is per language (🌐 وضعیت قابلیت‌ها (هر زبان) ← ⏱ اکانت ساعتی, and
// the web panel's ⏱ page), every price per user group.
//
// Money: cronbot/payg.php runs every minute and takes what the time (and the
// traffic, read from the panel every two minutes) has cost since the last
// run - never before it has passed, never below zero. What is smaller than the
// wallet's smallest coin is carried to the next run, so a minute of a
// 10,000-toman hour is 166.67 and the hour is 10,000 exactly.
//
// Panel: the service's own expiry and volume there are set to what the wallet
// still covers, so even with the bot down nobody uses more than they have.
// When the wallet runs out the service is switched off; after the admin's
// grace period it is deleted, unless the wallet was topped up in between.
//
// Required by function.php, so every entry point (bot, crons, web panel) has it.

if (!function_exists('payg_ensure_schema')) {
    // payg_service: one row per hourly service, its money state and what the
    // panel was last told. payg_charge: what was taken, one row per service
    // per hour (📊 and the web panel add them up per language).
    function payg_ensure_schema()
    {
        static $done = false;
        global $pdo;
        if ($done || !($pdo instanceof PDO)) {
            return;
        }
        $done = true;
        $pdo->query("CREATE TABLE IF NOT EXISTS payg_service (
            id_invoice VARCHAR(100) NOT NULL PRIMARY KEY,
            user_id VARCHAR(100) NOT NULL,
            lang VARCHAR(10) NOT NULL,
            currency VARCHAR(10) NOT NULL,
            panel VARCHAR(500) NOT NULL,
            username VARCHAR(200) NOT NULL,
            status VARCHAR(20) NOT NULL,
            created_at INT NOT NULL DEFAULT 0,
            started_at INT NOT NULL DEFAULT 0,
            billed_until INT NOT NULL DEFAULT 0,
            billed_bytes BIGINT NOT NULL DEFAULT 0,
            carry DECIMAL(24,8) NOT NULL DEFAULT 0,
            charged DECIMAL(20,2) NOT NULL DEFAULT 0,
            seen_charged DECIMAL(20,2) NOT NULL DEFAULT 0,
            stopped_at INT NOT NULL DEFAULT 0,
            ended_at INT NOT NULL DEFAULT 0,
            warned VARCHAR(200) NOT NULL DEFAULT '',
            cap_expire INT NOT NULL DEFAULT 0,
            cap_limit BIGINT NOT NULL DEFAULT 0,
            polled_at INT NOT NULL DEFAULT 0,
            KEY payg_user (user_id),
            KEY payg_status (status))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->query("CREATE TABLE IF NOT EXISTS payg_charge (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_invoice VARCHAR(100) NOT NULL,
            user_id VARCHAR(100) NOT NULL,
            lang VARCHAR(10) NOT NULL,
            currency VARCHAR(10) NOT NULL,
            hour_at INT NOT NULL,
            amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            seconds INT NOT NULL DEFAULT 0,
            bytes BIGINT NOT NULL DEFAULT 0,
            UNIQUE KEY payg_charge_hour (id_invoice, hour_at),
            KEY payg_charge_lang (lang))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
        // invoice.currency, which an hourly invoice is written with
        if (function_exists('wallet_ensure_schema')) {
            wallet_ensure_schema();
        }
        // invoice.payg: 1 = an hourly service (its own screen, no renewal, out
        // of the ⏱/📦 warnings); a converted one goes back to 0
        try {
            $has = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoice' AND COLUMN_NAME = 'payg'")->fetchColumn();
            if (!$has) {
                $pdo->exec("ALTER TABLE invoice ADD payg TINYINT NOT NULL DEFAULT 0");
            }
        } catch (Exception $e) {
            error_log('payg column: ' . $e->getMessage());
        }
    }
}

if (!function_exists('report_topic_id')) {
    // The report group's topic for $key - made the first time a report needs
    // it, so a bot whose group was set up before the topic existed gets it too
    // (the ones made when the group is set are buyreport, otherservice, ...).
    // '' without a report group, or when Telegram would not make one.
    function report_topic_id($key, $name)
    {
        global $pdo;
        $setting = select("setting", "*", null, null, "select");
        $group = (string) ($setting['Channel_Report'] ?? '');
        if ($group === '' || $group === '0') {
            return '';
        }
        $have = function () use ($key) {
            clearSelectCache('topicid');
            $row = select("topicid", "*", "report", $key, "select");
            $id = is_array($row) ? (string) $row['idreport'] : '';
            return [$row, ($id !== '' && $id !== '0') ? $id : ''];
        };
        [$row, $id] = $have();
        if ($id !== '') {
            return $id;
        }
        // two reports at the same moment must not make two topics
        $lock = 'mirza_topic_' . md5((string) $key);
        $got = (int) $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lock) . ", 10)")->fetchColumn();
        try {
            [$row, $id] = $have();
            if ($id !== '') {
                return $id;
            }
            $made = telegram('createForumTopic', ['chat_id' => $group, 'name' => $name]);
            $id = (string) ($made['result']['message_thread_id'] ?? '');
            if ($id === '') {
                return '';
            }
            if (is_array($row)) {
                update("topicid", "idreport", $id, "report", $key);
            } else {
                $pdo->prepare("INSERT INTO topicid (idreport, report) VALUES (?, ?)")->execute([$id, $key]);
            }
            clearSelectCache('topicid');
            return $id;
        } finally {
            if ($got) {
                $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lock) . ")");
            }
        }
    }
    // a new report group: these topics are made again, in it, when next needed
    function report_topic_reset(array $keys)
    {
        global $pdo;
        if (!$keys) {
            return;
        }
        $pdo->prepare("UPDATE topicid SET idreport = '0' WHERE report IN (" . implode(',', array_fill(0, count($keys), '?')) . ")")->execute(array_values($keys));
        clearSelectCache('topicid');
    }
    // a message to that topic; nothing without a report group. A topic deleted
    // by hand in the group is made again once.
    function report_to_topic($key, $name, $text, $markup = null)
    {
        $setting = select("setting", "*", null, null, "select");
        $group = (string) ($setting['Channel_Report'] ?? '');
        if ($group === '' || $group === '0') {
            return;
        }
        for ($try = 0; $try < 2; $try++) {
            $data = ['chat_id' => $group, 'text' => $text, 'parse_mode' => 'HTML'];
            $thread = report_topic_id($key, $name);
            if ($thread !== '') {
                $data['message_thread_id'] = $thread;
            }
            if ($markup !== null) {
                $data['reply_markup'] = $markup;
            }
            $res = telegram('sendmessage', $data);
            if (!empty($res['ok']) || $thread === '' || stripos((string) ($res['description'] ?? ''), 'thread not found') === false) {
                return;
            }
            report_topic_reset([$key]);
        }
    }
}

if (!function_exists('payg_cfg')) {
    // the panel types whose volume, expiry and on/off the bot can set and read back
    function payg_types()
    {
        return ['marzban', 'rebecca', 'x-ui_single'];
    }
    function payg_groups()
    {
        return ['f', 'n', 'n2'];
    }
    function payg_group_of($userRow)
    {
        $g = is_array($userRow) ? (string) ($userRow['agent'] ?? 'f') : 'f';
        return in_array($g, payg_groups(), true) ? $g : 'f';
    }
    function payg_price_keys()
    {
        return ['t_f', 't_n', 't_n2', 'v_f', 'v_n', 'v_n2'];
    }
    // $lang's settings, with their defaults. A price is '' (not set: for a
    // reseller group, "the same as a customer's"; for customers, no charge),
    // 'u' (unlimited: no charge for that side) or a number in $lang's currency
    // - per hour or per minute for time, per gigabyte for volume.
    function payg_cfg($lang)
    {
        $g = fn($k, $d) => (string) feature_setting_value($k, $lang, $d);
        $warn = [];
        foreach (preg_split('/[\s,]+/', $g('payg_warn', ''), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (is_numeric($w) && (float) $w > 0) {
                $warn[] = (float) $w;
            }
        }
        $warn = array_values(array_unique($warn, SORT_REGULAR));
        rsort($warn);
        $tprice = $vprice = [];
        foreach (payg_groups() as $grp) {
            $tprice[$grp] = $g("payg_tprice_{$grp}", '');
            $vprice[$grp] = $g("payg_vprice_{$grp}", '');
        }
        return [
            'on' => $g('payg_on', '0') === '1',
            'panels' => array_values(array_filter(array_map('trim', explode(',', $g('payg_panels', ''))), 'strlen')),
            'unit' => $g('payg_unit', 'hour') === 'minute' ? 'minute' : 'hour',
            'tprice' => $tprice,
            'vprice' => $vprice,
            'minbal' => max(0, (float) $g('payg_minbal', '0')),
            'max' => max(1, (int) $g('payg_max', '1')),
            'start' => $g('payg_start', 'create') === 'connect' ? 'connect' : 'create',
            'minage' => max(0, (int) $g('payg_minage', '0')),
            'delmode' => $g('payg_delmode', 'remove') === 'keep' ? 'keep' : 'remove',
            'warn' => $warn,
            'grace' => max(0, (int) $g('payg_grace', '24')),
            'convert' => $g('payg_convert', '1') === '1',
        ];
    }
    // a group's own price, or a customer's when it has none of its own
    function payg_price_raw(array $cfg, $dim, $group)
    {
        $map = $dim === 't' ? $cfg['tprice'] : $cfg['vprice'];
        $v = (string) ($map[$group] ?? '');
        if ($v === '' && $group !== 'f') {
            $v = (string) ($map['f'] ?? '');
        }
        return $v;
    }
    function payg_price_num($raw)
    {
        return (is_numeric($raw) && (float) $raw > 0) ? (float) $raw : null;
    }
    // what a second and a byte cost a group: ps / pb, null = that side is free
    function payg_rates(array $cfg, $group)
    {
        $t = payg_price_num(payg_price_raw($cfg, 't', $group));
        $v = payg_price_num(payg_price_raw($cfg, 'v', $group));
        return [
            'unit' => $cfg['unit'],
            'tprice' => $t,
            'vprice' => $v,
            'ps' => $t === null ? null : $t / ($cfg['unit'] === 'minute' ? 60 : 3600),
            'pb' => $v === null ? null : $v / 1073741824,
        ];
    }
    // the group whose time AND volume are both free - it would be a free,
    // unlimited service, which is never allowed; null when there is none
    function payg_free_group(array $cfg)
    {
        foreach (payg_groups() as $grp) {
            $r = payg_rates($cfg, $grp);
            if ($r['ps'] === null && $r['pb'] === null) {
                return $grp;
            }
        }
        return null;
    }
    // $lang's monthly plans on a panel - what a service there can be turned into
    function payg_panel_products($lang, $panelName, $agent = null)
    {
        global $pdo;
        $sql = "SELECT * FROM product WHERE (Location = ? OR Location = '/all') AND (FIND_IN_SET(?, lang) OR lang = 'all' OR lang IS NULL OR lang = '')";
        $params = [(string) $panelName, (string) $lang];
        if ($agent !== null) {
            $sql .= " AND agent = ?";
            $params[] = (string) $agent;
        }
        $st = $pdo->prepare($sql . " ORDER BY CAST(price_product AS DECIMAL(20,2))");
        $st->execute($params);
        // only plans priced in $lang's own currency: an hourly service is
        // paid from that wallet, and so is what it turns into
        $cur = currency_for_lang($lang);
        return array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($p) => (trim((string) ($p['currency'] ?? '')) ?: currency_default_code()) === $cur));
    }
    // why a panel cannot carry $lang's hourly services: 'type' (the bot cannot
    // set its volume and expiry), 'noProduct' (no monthly plan to turn one
    // into), or null when it can
    function payg_panel_problem($lang, $panel)
    {
        if (!is_array($panel) || !in_array((string) $panel['type'], payg_types(), true)) {
            return 'type';
        }
        if (!payg_panel_products($lang, $panel['name_panel'])) {
            return 'noProduct';
        }
        return null;
    }
    // on, with a panel, and no group served for free - else null
    function payg_live($lang)
    {
        $cfg = payg_cfg($lang);
        if (!$cfg['on'] || !$cfg['panels'] || payg_free_group($cfg) !== null) {
            return null;
        }
        return $cfg;
    }
    function payg_lang_has($value, $lang)
    {
        if ($value === null || $value === '' || $value === 'all') {
            return true;
        }
        return in_array($lang, array_map('trim', explode(',', (string) $value)), true);
    }
    // the panels a customer is offered: on $lang's list, active, of a type the
    // bot can drive, with a monthly plan, and open to them (their group, their
    // language, not hidden from them)
    function payg_user_panels($userRow)
    {
        $lang = (string) ($userRow['lang'] ?? 'fa') ?: 'fa';
        $cfg = payg_live($lang);
        if ($cfg === null) {
            return [];
        }
        $agent = payg_group_of($userRow);
        $out = [];
        foreach ($cfg['panels'] as $code) {
            $p = select("marzban_panel", "*", "code_panel", $code, "select");
            if (!is_array($p) || ($p['status'] ?? '') !== 'active' || payg_panel_problem($lang, $p) !== null) {
                continue;
            }
            if (!in_array((string) ($p['agent'] ?? 'all'), ['all', $agent], true) || !payg_lang_has($p['lang'] ?? 'all', $lang)) {
                continue;
            }
            $hidden = json_decode((string) ($p['hide_user'] ?? ''), true);
            if (is_array($hidden) && in_array((string) $userRow['id'], array_map('strval', $hidden), true)) {
                continue;
            }
            $out[] = $p;
        }
        return $out;
    }
    // ⏱ in 🔐 خرید اشتراک: on for this customer's language, with a panel for them
    function payg_offer($userRow)
    {
        return is_array($userRow) && payg_user_panels($userRow) !== [];
    }
}

if (!function_exists('payg_money')) {
    // the smallest amount a wallet in $code holds: 1 toman, 0.01 dollar
    function payg_minor($code)
    {
        $c = currency_get($code ?: currency_default_code());
        return pow(10, -max(0, min(2, (int) $c['decimals'])));
    }
    // the least a wallet must hold to keep a service going: a minute of its
    // time and a megabyte of its traffic (never less than the smallest coin).
    // Below it the service stops; to start or come back it needs this much.
    function payg_need(array $rates, $cur)
    {
        $n = 0.0;
        if ($rates['ps'] !== null) {
            $n += 60 * $rates['ps'];
        }
        if ($rates['pb'] !== null) {
            $n += 1048576 * $rates['pb'];
        }
        return max($n, payg_minor($cur));
    }
    // a price, also one below the currency's own coin: a dollar price can be
    // $0.0015 a minute, so up to four decimals; a toman one smaller than a
    // toman reads «≈ 167 تومان» rather than 166.6667
    function payg_money($amount, $code)
    {
        $a = (float) $amount;
        $cur = currency_get($code ?: currency_default_code());
        $dec = (int) $cur['decimals'];
        if (abs($a - round($a, $dec)) < 1e-9) {
            return money($a, $code);
        }
        if ($dec === 0) {
            return '≈ ' . money(round($a), $code);
        }
        $num = rtrim(rtrim(number_format($a, 4, '.', ','), '0'), '.');
        if ($num === '0') {
            $num = rtrim(rtrim(number_format($a, 6, '.', ','), '0'), '.');
        }
        if ($cur['symbol'] === '') {
            return $num;
        }
        return $cur['symbol_position'] === 'before' ? $cur['symbol'] . $num : $num . ' ' . $cur['symbol'];
    }
    // Persian and Arabic digits as ASCII - its own, since botapi.php's
    // convertPersianNumbersToEnglish() is not loaded in the web panel
    function payg_digits($text)
    {
        return strtr((string) $text, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
    // an amount the admin typed: «10000», «۱۰,۰۰۰», «0.05», and for tomans
    // «50000 ریال» (a tenth of it). null when it is not a positive amount.
    function payg_parse_amount($text, $code)
    {
        $t = trim(payg_digits($text));
        $rial = (bool) preg_match('/(ریال|rial|irr)/iu', $t);
        $t = preg_replace('/(ریال|تومان|تومن|rial|toman|irr|irt|usd|dollars?|دلار|\$)/iu', '', $t);
        $t = str_replace([',', '٬', '،', ' ', "\u{200C}"], '', $t);
        $t = str_replace('٫', '.', $t);
        if (!preg_match('/^(\d+(\.\d{1,6})?|\.\d{1,6})$/', $t)) {
            return null;
        }
        $v = (float) $t;
        if ($rial && strtoupper((string) $code) === 'IRT') {
            $v = $v / 10;
        }
        return $v > 0 ? $v : null;
    }
    // «2 روز و 5 ساعت», «35 دقیقه» - the two largest parts
    function payg_duration_text($seconds, $tx)
    {
        $s = max(0, (int) $seconds);
        $w = $tx['users']['payg'];
        $parts = [];
        foreach ([86400 => 'unitDay', 3600 => 'unitHour', 60 => 'unitMinute'] as $len => $key) {
            if ($s >= $len) {
                $n = intdiv($s, $len);
                // «1 day», «2 days» - one word in Persian for both
                $parts[] = $n . ' ' . ($n === 1 ? ($w[$key . '1'] ?? $w[$key]) : $w[$key]);
                $s %= $len;
            }
            if (count($parts) === 2) {
                break;
            }
        }
        return $parts ? implode($w['and'], $parts) : ('0 ' . $w['unitMinute']);
    }
}

if (!function_exists('payg_wallet')) {
    // what the owner has in $cur: the wallet in use, or one set aside after a
    // language switch (with 💱 on, the one in use, converted)
    function payg_wallet($uid, $cur)
    {
        $row = select("user", "*", "id", (string) $uid, "select", ['cache' => false]);
        if (!is_array($row)) {
            return 0.0;
        }
        $active = currency_for_user($row);
        if ($cur === $active) {
            return max(0, (float) $row['Balance']);
        }
        if (wallet_convert_on()) {
            $conv = wallet_convert_amount(max(0, (float) $row['Balance']), $active, $cur);
            return $conv === null ? 0.0 : max(0, (float) $conv);
        }
        return max(0, (float) (wallet_stash($row)[$cur] ?? 0));
    }
    // takes up to $amount out of the owner's $cur wallet, never below zero -
    // in one guarded statement, so a top-up or a purchase landing at the same
    // moment is never lost. Returns what was actually taken, in $cur.
    function payg_wallet_take($uid, $cur, $amount)
    {
        global $pdo;
        $dec = (int) currency_get($cur)['decimals'];
        for ($try = 0; $try < 4; $try++) {
            $row = select("user", "*", "id", (string) $uid, "select", ['cache' => false]);
            if (!is_array($row)) {
                return 0.0;
            }
            $active = currency_for_user($row);
            $bal = max(0, (float) $row['Balance']);
            if ($cur === $active || wallet_convert_on()) {
                $want = $cur === $active ? (float) $amount : (float) (wallet_convert_amount($amount, $cur, $active) ?? 0);
                $take = round(min($want, $bal), (int) currency_get($active)['decimals']);
                if ($take <= 0) {
                    return 0.0;
                }
                $st = $pdo->prepare("UPDATE user SET Balance = Balance - ? WHERE id = ? AND Balance >= ?");
                $st->execute([$take, (string) $uid, $take]);
                clearSelectCache('user');
                if ($st->rowCount() > 0) {
                    return $cur === $active ? $take : round((float) (wallet_convert_amount($take, $active, $cur) ?? 0), $dec);
                }
                continue;
            }
            $w = wallet_stash($row);
            $have = (float) ($w[$cur] ?? 0);
            $take = round(min((float) $amount, $have), $dec);
            if ($take <= 0) {
                return 0.0;
            }
            $w[$cur] = round($have - $take, 2);
            if ($w[$cur] == 0) {
                unset($w[$cur]);
            }
            $old = (string) ($row['wallets'] ?? '');
            $st = $pdo->prepare("UPDATE user SET wallets = ? WHERE id = ? AND COALESCE(wallets, '') = ?");
            $st->execute([empty($w) ? '{}' : json_encode($w), (string) $uid, $old]);
            clearSelectCache('user');
            if ($st->rowCount() > 0) {
                return $take;
            }
        }
        return 0.0;
    }
}

if (!function_exists('payg_row')) {
    function payg_row($idInvoice)
    {
        global $pdo;
        payg_ensure_schema();
        $st = $pdo->prepare("SELECT * FROM payg_service WHERE id_invoice = ?");
        $st->execute([(string) $idInvoice]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($r) ? $r : null;
    }
    // the owner's services still running or waiting to be (what 🔢 counts)
    function payg_open_rows($uid)
    {
        global $pdo;
        payg_ensure_schema();
        $st = $pdo->prepare("SELECT * FROM payg_service WHERE user_id = ? AND status IN ('waiting', 'active', 'stopped')");
        $st->execute([(string) $uid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    function payg_set($idInvoice, array $fields)
    {
        global $pdo;
        if (!$fields) {
            return;
        }
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fields)));
        $pdo->prepare("UPDATE payg_service SET $sets WHERE id_invoice = ?")->execute(array_merge(array_values($fields), [(string) $idInvoice]));
    }
    // one service at a time: the cron, a 🔄 and a 🗑 at the same moment would
    // each take the same minutes otherwise
    function payg_locked($idInvoice, callable $fn)
    {
        global $pdo;
        $name = 'mirza_payg_' . md5((string) $idInvoice);
        $got = (int) $pdo->query("SELECT GET_LOCK(" . $pdo->quote($name) . ", 15)")->fetchColumn();
        if (!$got) {
            return null;
        }
        try {
            return $fn();
        } finally {
            $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($name) . ")");
        }
    }
    function payg_username($uid)
    {
        global $pdo;
        $st = $pdo->prepare("SELECT COUNT(*) FROM invoice WHERE username = ?");
        for ($n = 1; ; $n++) {
            $name = "payg_{$uid}_{$n}";
            $st->execute([$name]);
            if ((int) $st->fetchColumn() === 0) {
                return $name;
            }
        }
    }
    // «🛍 سرویس‌های من»'s button for an hourly service; null for any other
    function payg_list_label($row, $tx)
    {
        if (!is_array($row) || (int) ($row['payg'] ?? 0) !== 1) {
            return null;
        }
        return strtr($tx['users']['payg']['listLabel'], ['{username}' => (string) $row['username']]);
    }
    // the service buttons an hourly service has no use for - renewals and
    // extras would be overwritten by the next minute's caps, a hand-made on/off
    // would undo a stop, a move would leave its bill behind
    function payg_blocked_action($datain)
    {
        foreach (['extend_', 'extends_', 'Extra_volume_', 'Extra_time_', 'confirmaextra', 'changestatus_', 'confirmaccountdisable_', 'transfer_', 'confrimtransfers_',
            'changeloc_', 'changelocselectlo-', 'confirmchangeloccha_', 'changelink_', 'confirmchange_', 'removeserviceuser_', 'removeauto-', 'confirmremoveservices-'] as $p) {
            if (strpos((string) $datain, $p) === 0) {
                return true;
            }
        }
        return false;
    }
    // the invoice behind a callback, when it is an hourly one
    function payg_invoice_of($idInvoice)
    {
        payg_ensure_schema();
        $inv = select("invoice", "*", "id_invoice", (string) $idInvoice, "select", ['cache' => false]);
        return (is_array($inv) && (int) ($inv['payg'] ?? 0) === 1) ? $inv : null;
    }
}

if (!function_exists('payg_panel_set')) {
    // the panel's own expiry (0 = none), volume (bytes, 0 = no limit) and
    // on/off for a service - in each panel type's own words
    function payg_panel_set($panel, $username, $expire, $limit, $enabled)
    {
        global $ManagePanel;
        if (!is_object($ManagePanel) && class_exists('ManagePanel')) {
            $ManagePanel = new ManagePanel();
        }
        if (!is_object($ManagePanel) || !is_array($panel)) {
            return false;
        }
        $type = (string) $panel['type'];
        if ($type === 'x-ui_single') {
            $cfg = ['totalGB' => (int) $limit, 'expiryTime' => (int) $expire > 0 ? (int) $expire * 1000 : 0, 'enable' => (bool) $enabled];
        } elseif ($type === 'rebecca') {
            $cfg = ['expire' => (int) $expire > 0 ? (int) $expire : null, 'data_limit' => (int) $limit, 'status' => $enabled ? 'active' : 'disabled'];
        } else {
            $cfg = ['expire' => (int) $expire, 'data_limit' => (int) $limit, 'status' => $enabled ? 'active' : 'disabled'];
        }
        $r = $ManagePanel->Modifyuser($username, $panel['name_panel'], $cfg);
        return is_array($r) && !empty($r['status']);
    }
    // ['ok' => panel answered, 'gone' => no such user there, 'used' => bytes,
    // 'online' => it has connected at least once]
    function payg_panel_data($panel, $username)
    {
        global $ManagePanel;
        if (!is_object($ManagePanel) && class_exists('ManagePanel')) {
            $ManagePanel = new ManagePanel();
        }
        $d = (is_object($ManagePanel) && is_array($panel)) ? $ManagePanel->DataUser($panel['name_panel'], $username) : null;
        if (!is_array($d)) {
            return ['ok' => false, 'gone' => false, 'used' => 0, 'online' => false];
        }
        if (strcasecmp(trim((string) ($d['msg'] ?? '')), 'User not found') === 0) {
            return ['ok' => true, 'gone' => true, 'used' => 0, 'online' => false];
        }
        if (($d['status'] ?? '') === 'Unsuccessful') {
            return ['ok' => false, 'gone' => false, 'used' => 0, 'online' => false];
        }
        $online = $d['online_at'] ?? null;
        return [
            'ok' => true,
            'gone' => false,
            'used' => max(0, (int) ($d['used_traffic'] ?? 0)),
            'online' => $online !== null && $online !== '' && $online !== 'offline',
            'data' => $d,
        ];
    }
    // [expire, volume] that the wallet covers from now: the time it buys, the
    // bytes on top of what is used - each as if the whole wallet went to it
    function payg_caps(array $rates, $avail, $used, $now)
    {
        $avail = max(0, (float) $avail);
        $expire = $rates['ps'] !== null ? (int) $now + (int) floor($avail / $rates['ps']) : 0;
        $limit = 0;
        if ($rates['pb'] !== null) {
            // 0 is «no limit» to a panel - an empty wallet must stop it instead
            $limit = max((int) $used + (int) floor($avail / $rates['pb']), (int) $used, 1);
        }
        return [$expire, $limit];
    }
    // tells the panel what the wallet covers now, when that has moved (a
    // top-up, another purchase) - not every minute, since a charge moves the
    // wallet and the clock by the same amount
    function payg_sync_caps(array $svc, array $rates, $avail, $used, $force = false)
    {
        // nothing priced at all (an admin's slip): the panel keeps what it has
        if ($rates['ps'] === null && $rates['pb'] === null) {
            return $svc;
        }
        [$expire, $limit] = payg_caps($rates, $avail, $used, time());
        $moved = abs($expire - (int) $svc['cap_expire']) > 120
            || ($rates['pb'] !== null && abs($limit - (int) $svc['cap_limit']) > 10485760);
        if (!$force && !$moved) {
            return $svc;
        }
        $panel = select("marzban_panel", "*", "name_panel", $svc['panel'], "select");
        if (payg_panel_set($panel, $svc['username'], $expire, $limit, true)) {
            payg_set($svc['id_invoice'], ['cap_expire' => $expire, 'cap_limit' => $limit]);
            $svc['cap_expire'] = $expire;
            $svc['cap_limit'] = $limit;
        }
        return $svc;
    }
}

if (!function_exists('payg_create')) {
    // A new hourly service for $uid on the panel $code: the checks (on, the
    // panel offered to them, the 💰 minimum, the 🔢 count, the panel's room),
    // the panel user with what the wallet covers, the invoice («🛍 سرویس‌های
    // من») and its row. ['ok' => true, ...] or ['error' => why, ...].
    function payg_create($uid, $code)
    {
        global $pdo, $ManagePanel;
        payg_ensure_schema();
        if (!is_object($ManagePanel) && class_exists('ManagePanel')) {
            $ManagePanel = new ManagePanel();
        }
        $u = select("user", "*", "id", (string) $uid, "select", ['cache' => false]);
        if (!is_array($u)) {
            return ['error' => 'off'];
        }
        $lang = (string) ($u['lang'] ?? 'fa') ?: 'fa';
        $cfg = payg_live($lang);
        if ($cfg === null) {
            return ['error' => 'off'];
        }
        $panel = null;
        foreach (payg_user_panels($u) as $p) {
            if ((string) $p['code_panel'] === (string) $code) {
                $panel = $p;
            }
        }
        if ($panel === null) {
            return ['error' => 'off'];
        }
        $rates = payg_rates($cfg, payg_group_of($u));
        $cur = currency_for_lang($lang);
        $have = payg_wallet($uid, $cur);
        $need = max($cfg['minbal'], payg_need($rates, $cur));
        if ($have + 1e-9 < $need) {
            return ['error' => 'lowbal', 'need' => $need, 'have' => $have, 'cur' => $cur];
        }
        if (count(payg_open_rows($uid)) >= $cfg['max']) {
            return ['error' => 'max', 'max' => $cfg['max']];
        }
        if (is_numeric($panel['limit_panel'] ?? '')) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM invoice WHERE Service_location = ? AND Status IN ('active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold')");
            $st->execute([$panel['name_panel']]);
            if ((int) $st->fetchColumn() >= (int) $panel['limit_panel']) {
                return ['error' => 'full'];
            }
        }
        $now = time();
        $waiting = $cfg['start'] === 'connect';
        [$expire, $limit] = payg_caps($rates, $have, 0, $now);
        // waiting for the first connection: no expiry yet - the clock starts
        // when the panel first reports one
        if ($waiting) {
            $expire = 0;
        }
        $username = payg_username($uid);
        $out = $ManagePanel->createUser($panel['name_panel'], 'customvolume', $username, [
            'expire' => $expire,
            'data_limit' => $limit,
            'from_id' => $uid,
            'username' => (string) ($u['username'] ?? ''),
            'type' => 'payg',
        ]);
        if (!is_array($out) || empty($out['username'])) {
            $msg = is_array($out) ? ($out['msg'] ?? '') : '';
            payg_report_error($uid, $u, $panel, is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE));
            return ['error' => 'panel'];
        }
        // a panel set to count from the first connection (on_hold) would move
        // the expiry on its own: say it exactly
        payg_panel_set($panel, $out['username'], $expire, $limit, true);
        $id = bin2hex(random_bytes(4));
        $tx = lang_tab_texts($lang);
        $pdo->prepare("INSERT INTO invoice (id_user, id_invoice, username, time_sell, Service_location, name_product, price_product, Volume, Service_time, Status, notifctions, payg, currency) VALUES (?, ?, ?, ?, ?, ?, '0', '0', '0', 'active', ?, 1, ?)")
            ->execute([(string) $uid, $id, $out['username'], (string) $now, $panel['name_panel'], $tx['users']['payg']['serviceName'], json_encode(['volume' => false, 'time' => false]), $cur]);
        clearSelectCache('invoice');
        $pdo->prepare("INSERT INTO payg_service (id_invoice, user_id, lang, currency, panel, username, status, created_at, started_at, billed_until, cap_expire, cap_limit, polled_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$id, (string) $uid, $lang, $cur, $panel['name_panel'], $out['username'], $waiting ? 'waiting' : 'active', $now, $waiting ? 0 : $now, $now, $expire, $limit, $now]);
        $svc = payg_row($id);
        payg_report('created', $svc);
        return ['ok' => true, 'id_invoice' => $id, 'username' => $out['username'], 'out' => $out, 'panel' => $panel, 'svc' => $svc, 'rates' => $rates, 'cfg' => $cfg];
    }
    // the panel would not make it: to the report group's ❌ errors, as a
    // failed purchase is
    function payg_report_error($uid, $u, $panel, $msg)
    {
        $setting = select("setting", "*", null, null, "select");
        if (strlen((string) ($setting['Channel_Report'] ?? '')) === 0) {
            return;
        }
        $row = select("topicid", "*", "report", "errorreport", "select");
        $data = [
            'chat_id' => $setting['Channel_Report'],
            'text' => sprintf(panel_texts()['Admin']['reportgroup']['errorSubscriptionCreate'], htmlspecialchars((string) $msg), $uid, is_array($u) ? ($u['username'] ?? '') : '', $panel['name_panel']),
            'parse_mode' => 'HTML',
        ];
        if (is_array($row) && (string) $row['idreport'] !== '' && (string) $row['idreport'] !== '0') {
            $data['message_thread_id'] = $row['idreport'];
        }
        telegram('sendmessage', $data);
    }
}

if (!function_exists('payg_bill')) {
    // Takes what has been used since the last time - the seconds (only up to
    // where the panel stopped the service) and, when $used is given, the bytes
    // the panel reports on top of what was billed. What the wallet cannot
    // cover is let go - nobody owes below zero - and 'exhausted' says so, as
    // it does once the wallet no longer buys a minute (payg_need).
    // ['taken' => amount, 'exhausted' => bool, 'left' => wallet after]
    function payg_bill(array $svc, $used = null)
    {
        global $pdo;
        $u = select("user", "*", "id", (string) $svc['user_id'], "select", ['cache' => false]);
        $rates = payg_rates(payg_cfg($svc['lang']), payg_group_of($u));
        if ($svc['status'] !== 'active') {
            return ['taken' => 0.0, 'exhausted' => false, 'left' => payg_wallet($svc['user_id'], $svc['currency'])];
        }
        $now = time();
        $from = (int) $svc['billed_until'];
        $to = $now;
        if ((int) $svc['cap_expire'] > 0 && (int) $svc['cap_expire'] < $to) {
            $to = max($from, (int) $svc['cap_expire']);
        }
        $seconds = max(0, $to - $from);
        $cost = $rates['ps'] !== null ? $seconds * $rates['ps'] : 0.0;
        $bytes = 0;
        if ($used !== null) {
            // below what was billed: the panel's counter was reset - from it
            $bytes = max(0, (int) $used - (int) $svc['billed_bytes']);
            if ($rates['pb'] !== null) {
                $cost += $bytes * $rates['pb'];
            }
        }
        $due = (float) $svc['carry'] + $cost;
        $minor = payg_minor($svc['currency']);
        $want = floor(($due + 1e-9) / $minor) * $minor;
        $taken = $want > 0 ? payg_wallet_take($svc['user_id'], $svc['currency'], $want) : 0.0;
        $owed = max(0, $due - $taken);
        $left = payg_wallet($svc['user_id'], $svc['currency']);
        // what is still owed waits for the next run while the wallet has it
        // (a fraction of a coin, or a sum too small to convert yet); a wallet
        // that cannot pay it any more is let off
        $short = $owed > $left + 1e-9;
        $fields = ['billed_until' => $now, 'carry' => round($short ? 0 : $owed, 8)];
        if ($used !== null) {
            $fields['billed_bytes'] = (int) $used;
        }
        payg_set($svc['id_invoice'], $fields);
        if ($taken > 0) {
            $pdo->prepare("UPDATE payg_service SET charged = charged + ? WHERE id_invoice = ?")->execute([$taken, $svc['id_invoice']]);
            $pdo->prepare("INSERT INTO payg_charge (id_invoice, user_id, lang, currency, hour_at, amount, seconds, bytes) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount), seconds = seconds + VALUES(seconds), bytes = bytes + VALUES(bytes)")
                ->execute([$svc['id_invoice'], $svc['user_id'], $svc['lang'], $svc['currency'], (int) (floor($now / 3600) * 3600), $taken, $seconds, $bytes]);
        }
        return ['taken' => $taken, 'exhausted' => $short || $left + 1e-9 < payg_need($rates, $svc['currency']), 'left' => $left];
    }
    // settles what is owed up to now (with the panel's latest volume) -
    // before a 🗑, a ⏩ and on 🔄. 'data' is what the panel said.
    function payg_settle(array $svc)
    {
        $panel = select("marzban_panel", "*", "name_panel", $svc['panel'], "select");
        $d = is_array($panel) ? payg_panel_data($panel, $svc['username']) : null;
        if ($svc['status'] !== 'active') {
            return ['taken' => 0.0, 'exhausted' => false, 'left' => payg_wallet($svc['user_id'], $svc['currency']), 'data' => $d];
        }
        $used = null;
        if (is_array($d) && $d['ok'] && !$d['gone']) {
            $used = $d['used'];
            payg_set($svc['id_invoice'], ['polled_at' => time()]);
        }
        return payg_bill($svc, $used) + ['data' => $d];
    }
}

if (!function_exists('payg_stop')) {
    // the wallet ran out: off on the panel, its owner told how long it waits
    // for a top-up (⌛) - or, with no grace, deleted right away
    function payg_stop(array $svc)
    {
        $cfg = payg_cfg($svc['lang']);
        if ($cfg['grace'] <= 0) {
            payg_delete($svc, 'grace');
            return;
        }
        $panel = select("marzban_panel", "*", "name_panel", $svc['panel'], "select");
        payg_panel_set($panel, $svc['username'], (int) $svc['cap_expire'], (int) $svc['cap_limit'], false);
        payg_set($svc['id_invoice'], ['status' => 'stopped', 'stopped_at' => time(), 'carry' => 0]);
        $svc = payg_row($svc['id_invoice']);
        $tx = payer_texts($svc['user_id']);
        bottext_extras_key_hint('users.payg.stopped');
        sendmessage($svc['user_id'], strtr($tx['users']['payg']['stopped'], ['{username}' => htmlspecialchars($svc['username']), '{grace}' => payg_duration_text($cfg['grace'] * 3600, $tx)]), payg_topup_kb($tx), 'HTML');
        payg_report('stopped', $svc);
    }
    // topped up within ⌛: on again, the clock running from now (the stopped
    // time is never charged)
    function payg_resume(array $svc)
    {
        $u = select("user", "*", "id", (string) $svc['user_id'], "select", ['cache' => false]);
        $rates = payg_rates(payg_cfg($svc['lang']), payg_group_of($u));
        payg_set($svc['id_invoice'], ['status' => 'active', 'stopped_at' => 0, 'billed_until' => time(), 'warned' => '']);
        $svc = payg_row($svc['id_invoice']);
        payg_sync_caps($svc, $rates, payg_wallet($svc['user_id'], $svc['currency']), (int) $svc['billed_bytes'], true);
        $tx = payer_texts($svc['user_id']);
        bottext_extras_key_hint('users.payg.resumed');
        sendmessage($svc['user_id'], strtr($tx['users']['payg']['resumed'], ['{username}' => htmlspecialchars($svc['username'])]), null, 'HTML');
        payg_report('resumed', $svc);
    }
    // $by: 'user' (🗑 - kept off in «🛍 سرویس‌های من» or removed, by the
    // admin's 🗑 setting), 'grace' (⌛ ran out), 'admin', 'gone' (deleted on
    // the panel or by another of the bot's tools - nothing left to remove),
    // 'purge' (a kept one, now removed for good by its owner)
    function payg_delete(array $svc, $by)
    {
        global $ManagePanel, $pdo;
        if (!is_object($ManagePanel) && class_exists('ManagePanel')) {
            $ManagePanel = new ManagePanel();
        }
        $cfg = payg_cfg($svc['lang']);
        $keep = $by === 'user' && $cfg['delmode'] === 'keep';
        $panel = select("marzban_panel", "*", "name_panel", $svc['panel'], "select");
        if ($by !== 'gone') {
            if ($keep) {
                payg_panel_set($panel, $svc['username'], (int) $svc['cap_expire'], (int) $svc['cap_limit'], false);
            } elseif (is_object($ManagePanel) && is_array($panel)) {
                $ManagePanel->RemoveUser($panel['name_panel'], $svc['username']);
            }
        }
        payg_set($svc['id_invoice'], ['status' => $keep ? 'ended' : 'deleted', 'ended_at' => time()]);
        if (!$keep) {
            $pdo->prepare("UPDATE invoice SET Status = ? WHERE id_invoice = ? AND Status IN ('active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold', 'disablebyadmin')")
                ->execute([$by === 'gone' ? 'disabled' : 'payg_deleted', $svc['id_invoice']]);
            clearSelectCache('invoice');
        }
        $svc = payg_row($svc['id_invoice']);
        if ($by === 'grace' || $by === 'admin') {
            $tx = payer_texts($svc['user_id']);
            $key = $by === 'grace' ? 'deletedGrace' : 'deletedAdmin';
            bottext_extras_key_hint('users.payg.' . $key);
            sendmessage($svc['user_id'], strtr($tx['users']['payg'][$key], ['{username}' => htmlspecialchars($svc['username']), '{charged}' => money((float) $svc['charged'], $svc['currency'])]), null, 'HTML');
        }
        if ($by !== 'purge') {
            payg_report($keep ? 'ended' : 'deleted_' . $by, $svc);
        }
        return $svc;
    }
    // can its owner delete it yet (⏳ حداقل عمر)? [yes, seconds still to go]
    function payg_can_delete(array $svc)
    {
        $left = (int) $svc['created_at'] + payg_cfg($svc['lang'])['minage'] * 60 - time();
        return [$left <= 0 || $svc['status'] === 'stopped', max(0, $left)];
    }
}

if (!function_exists('payg_convert')) {
    // ⏩ into one of the panel's monthly plans: what the hourly side owes up
    // to now, then the plan's price, then the same panel user (same config)
    // given the plan's volume and days from now - an ordinary service after.
    function payg_convert(array $svc, $codeProduct)
    {
        global $pdo, $ManagePanel;
        if (!is_object($ManagePanel) && class_exists('ManagePanel')) {
            $ManagePanel = new ManagePanel();
        }
        if (!payg_cfg($svc['lang'])['convert'] || !in_array($svc['status'], ['waiting', 'active'], true)) {
            return ['error' => 'off'];
        }
        $u = select("user", "*", "id", (string) $svc['user_id'], "select", ['cache' => false]);
        $product = null;
        foreach (payg_panel_products($svc['lang'], $svc['panel'], payg_group_of($u)) as $p) {
            if ((string) $p['code_product'] === (string) $codeProduct) {
                $product = $p;
            }
        }
        $pcur = is_array($product) ? (trim((string) ($product['currency'] ?? '')) ?: currency_default_code()) : '';
        if ($product === null || $pcur !== $svc['currency']) {
            return ['error' => 'product'];
        }
        payg_settle($svc);
        $price = (float) $product['price_product'];
        $have = payg_wallet($svc['user_id'], $svc['currency']);
        if ($have + 1e-9 < $price) {
            return ['error' => 'lowbal', 'need' => $price, 'have' => $have];
        }
        $taken = $price > 0 ? payg_wallet_take($svc['user_id'], $svc['currency'], $price) : 0.0;
        if ($taken + 1e-9 < $price) {
            if ($taken > 0) {
                wallet_credit($svc['user_id'], $taken, $svc['currency']);
            }
            return ['error' => 'lowbal', 'need' => $price, 'have' => payg_wallet($svc['user_id'], $svc['currency'])];
        }
        $panel = select("marzban_panel", "*", "name_panel", $svc['panel'], "select");
        $days = (int) $product['Service_time'];
        $expire = $days > 0 ? time() + $days * 86400 : 0;
        $limit = (int) round((float) $product['Volume_constraint'] * 1073741824);
        if (!payg_panel_set($panel, $svc['username'], $expire, $limit, true)) {
            if ($taken > 0) {
                wallet_credit($svc['user_id'], $taken, $svc['currency']);
            }
            return ['error' => 'panel'];
        }
        $ManagePanel->ResetUserDataUsage($svc['username'], $svc['panel']);
        $pdo->prepare("UPDATE invoice SET payg = 0, name_product = ?, price_product = ?, Volume = ?, Service_time = ?, time_sell = ?, Status = 'active', notifctions = ? WHERE id_invoice = ?")
            ->execute([$product['name_product'], (string) $product['price_product'], (string) $product['Volume_constraint'], (string) $product['Service_time'], (string) time(), json_encode(['volume' => false, 'time' => false]), $svc['id_invoice']]);
        clearSelectCache('invoice');
        payg_set($svc['id_invoice'], ['status' => 'converted', 'ended_at' => time()]);
        $svc = payg_row($svc['id_invoice']);
        payg_report('converted', $svc, ['{product}' => htmlspecialchars((string) $product['name_product']), '{price}' => money($price, $svc['currency'])]);
        return ['ok' => true, 'product' => $product, 'svc' => $svc];
    }
}

if (!function_exists('payg_tick')) {
    // One service, once a minute (cronbot/payg.php).
    function payg_tick(array $svc)
    {
        return payg_locked($svc['id_invoice'], function () use ($svc) {
            $svc = payg_row($svc['id_invoice']);
            if ($svc === null || !in_array($svc['status'], ['waiting', 'active', 'stopped'], true)) {
                return;
            }
            $now = time();
            // the invoice tells what the bot's other tools did to it: switched
            // off by an admin (👤 مدیریت کاربر) - no time runs; removed - over
            $inv = select("invoice", "*", "id_invoice", $svc['id_invoice'], "select", ['cache' => false]);
            $invStatus = is_array($inv) ? (string) $inv['Status'] : '';
            if ($invStatus === 'disablebyadmin') {
                payg_set($svc['id_invoice'], ['billed_until' => $now]);
                return;
            }
            if (!in_array($invStatus, ['active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold'], true)) {
                payg_delete($svc, 'gone');
                return;
            }
            $panel = select("marzban_panel", "*", "name_panel", $svc['panel'], "select");
            if (!is_array($panel)) {
                return;
            }
            $u = select("user", "*", "id", (string) $svc['user_id'], "select", ['cache' => false]);
            $cfg = payg_cfg($svc['lang']);
            $rates = payg_rates($cfg, payg_group_of($u));
            if ($svc['status'] === 'waiting') {
                if ($now - (int) $svc['polled_at'] < 120) {
                    return;
                }
                $d = payg_panel_data($panel, $svc['username']);
                payg_set($svc['id_invoice'], ['polled_at' => $now]);
                if ($d['ok'] && $d['gone']) {
                    payg_delete($svc, 'gone');
                    return;
                }
                if ($d['ok'] && ($d['used'] > 0 || $d['online'])) {
                    // its first connection: the clock starts now, the traffic
                    // it took to get here is on the house
                    payg_set($svc['id_invoice'], ['status' => 'active', 'started_at' => $now, 'billed_until' => $now, 'billed_bytes' => $d['used']]);
                    $svc = payg_row($svc['id_invoice']);
                    payg_sync_caps($svc, $rates, payg_wallet($svc['user_id'], $svc['currency']), $d['used'], true);
                    payg_report('started', $svc);
                }
                return;
            }
            if ($svc['status'] === 'stopped') {
                $have = payg_wallet($svc['user_id'], $svc['currency']);
                if ($have + 1e-9 >= max($cfg['minbal'], payg_need($rates, $svc['currency']))) {
                    payg_resume($svc);
                    return;
                }
                $endAt = (int) $svc['stopped_at'] + $cfg['grace'] * 3600;
                if ($now >= $endAt) {
                    payg_delete($svc, 'grace');
                    return;
                }
                // two hours before (when ⌛ is longer than that): once more
                if ($cfg['grace'] > 2 && $endAt - $now <= 7200 && strpos((string) $svc['warned'], 'soon') === false) {
                    payg_set($svc['id_invoice'], ['warned' => trim($svc['warned'] . ',soon', ',')]);
                    $tx = payer_texts($svc['user_id']);
                    bottext_extras_key_hint('users.payg.deleteSoon');
                    sendmessage($svc['user_id'], strtr($tx['users']['payg']['deleteSoon'], ['{username}' => htmlspecialchars($svc['username']), '{left}' => payg_duration_text((int) ceil(($endAt - $now) / 60) * 60, $tx)]), payg_topup_kb($tx), 'HTML');
                }
                return;
            }
            // active: the volume every two minutes when it is priced, and
            // every ten to notice a service deleted on the panel by hand
            $used = null;
            if ($now - (int) $svc['polled_at'] >= ($rates['pb'] !== null ? 120 : 600)) {
                $d = payg_panel_data($panel, $svc['username']);
                payg_set($svc['id_invoice'], ['polled_at' => $now]);
                if ($d['ok'] && $d['gone']) {
                    payg_bill($svc, null);
                    payg_delete(payg_row($svc['id_invoice']), 'gone');
                    return;
                }
                if ($d['ok']) {
                    $used = $d['used'];
                }
            }
            $r = payg_bill($svc, $used);
            $svc = payg_row($svc['id_invoice']);
            if ($r['exhausted']) {
                payg_stop($svc);
                return;
            }
            payg_warn($svc, $cfg, $rates, $r['left']);
            payg_sync_caps($svc, $rates, $r['left'], $used ?? (int) $svc['billed_bytes']);
        });
    }
    // ⚠️ the wallet went under one of the admin's amounts: told once per
    // amount (the lowest one crossed), told again after a top-up above it
    function payg_warn(array $svc, array $cfg, array $rates, $left)
    {
        $done = array_filter(explode(',', (string) $svc['warned']), 'strlen');
        $keep = array_values(array_filter($done, fn($w) => $w === 'soon' || (is_numeric($w) && $left <= (float) $w + 1e-9)));
        $crossed = array_values(array_filter($cfg['warn'], fn($w) => $left <= $w + 1e-9));
        $new = array_values(array_filter($crossed, fn($w) => !in_array((string) $w, $keep, true)));
        if ($new) {
            $tx = payer_texts($svc['user_id']);
            bottext_extras_key_hint('users.payg.warnLow');
            sendmessage($svc['user_id'], strtr($tx['users']['payg']['warnLow'], [
                '{username}' => htmlspecialchars($svc['username']),
                '{balance}' => money($left, $svc['currency']),
                '{estimate}' => payg_estimate_text($rates, $left, $tx),
            ]), payg_topup_kb($tx), 'HTML');
            foreach ($crossed as $w) {
                $keep[] = (string) $w;
            }
        }
        $warned = implode(',', array_values(array_unique($keep)));
        if ($warned !== (string) $svc['warned']) {
            payg_set($svc['id_invoice'], ['warned' => $warned]);
        }
    }
    // every hourly service still running, waiting or within ⌛ - the ones
    // billed longest ago first, and no longer than the cron's own minute
    function payg_cron($budget = 50)
    {
        global $pdo;
        payg_ensure_schema();
        $start = time();
        foreach ($pdo->query("SELECT * FROM payg_service WHERE status IN ('waiting', 'active', 'stopped') ORDER BY billed_until, polled_at")->fetchAll(PDO::FETCH_ASSOC) as $svc) {
            if (time() - $start >= $budget) {
                break;
            }
            try {
                payg_tick($svc);
            } catch (Throwable $e) {
                error_log('payg tick ' . $svc['id_invoice'] . ': ' . $e->getMessage());
            }
        }
    }
}

if (!function_exists('payg_rates_text')) {
    // the price lines a customer reads: «⏱ زمان: هر ساعت 10,000 تومان»
    function payg_rates_text(array $rates, $cur, $tx)
    {
        $w = $tx['users']['payg'];
        $lines = [];
        $lines[] = $rates['tprice'] === null ? $w['rateTimeFree'] : strtr($rates['unit'] === 'minute' ? $w['rateMinute'] : $w['rateHour'], ['{price}' => payg_money($rates['tprice'], $cur)]);
        $lines[] = $rates['vprice'] === null ? $w['rateVolFree'] : strtr($w['rateGb'], ['{price}' => payg_money($rates['vprice'], $cur)]);
        return implode("\n", $lines);
    }
    // «با موجودی فعلی حدوداً 3 روز و 4 ساعت دیگه کار می‌کنه» - by time when
    // time is priced, by volume when only volume is
    function payg_estimate_text(array $rates, $left, $tx)
    {
        $w = $tx['users']['payg'];
        if ($rates['ps'] !== null) {
            return strtr($w['estimateTime'], ['{left}' => payg_duration_text((int) floor(max(0, $left) / $rates['ps']), $tx)]);
        }
        if ($rates['pb'] !== null) {
            return strtr($w['estimateVolume'], ['{left}' => formatBytes((int) floor(max(0, $left) / $rates['pb']))]);
        }
        return '';
    }
    function payg_topup_kb($tx)
    {
        return json_encode(['inline_keyboard' => [[['text' => $tx['users']['payg']['btnTopup'], 'callback_data' => 'Add_Balance', 'style' => 'success']]]]);
    }
    // the first screen of 🔐 خرید اشتراک when ⏱ is on
    function payg_type_kb($tx)
    {
        $w = $tx['users']['payg'];
        return json_encode(['inline_keyboard' => [
            [['text' => $w['btnNormal'], 'callback_data' => 'buytype_normal', 'style' => 'primary']],
            [['text' => $w['btnPayg'], 'callback_data' => 'buytype_payg', 'style' => 'success']],
            [['text' => $w['btnBack'], 'callback_data' => 'backuser', 'style' => 'danger']],
        ]]);
    }
    function payg_panels_kb(array $panels, $tx)
    {
        $rows = [];
        foreach ($panels as $p) {
            $rows[] = [['text' => (string) $p['name_panel'], 'callback_data' => 'paygpanel_' . $p['code_panel'], 'style' => 'primary']];
        }
        $rows[] = [['text' => $tx['users']['payg']['btnBack'], 'callback_data' => 'buy', 'style' => 'danger']];
        return json_encode(['inline_keyboard' => $rows]);
    }
    // what an hourly service there would cost this customer, before it is made
    function payg_offer_screen(array $userRow, array $panel, $tx, $onlyPanel = false)
    {
        $w = $tx['users']['payg'];
        $lang = (string) ($userRow['lang'] ?? 'fa') ?: 'fa';
        $cfg = payg_cfg($lang);
        $rates = payg_rates($cfg, payg_group_of($userRow));
        $cur = currency_for_lang($lang);
        $left = payg_wallet($userRow['id'], $cur);
        $text = strtr($w['offer'], [
            '{panel}' => htmlspecialchars((string) $panel['name_panel']),
            '{rates}' => payg_rates_text($rates, $cur, $tx),
            '{start}' => $cfg['start'] === 'connect' ? $w['startConnect'] : $w['startCreate'],
            '{balance}' => money($left, $cur),
            '{need}' => money(max($cfg['minbal'], payg_need($rates, $cur)), $cur),
            '{estimate}' => payg_estimate_text($rates, $left, $tx),
        ]);
        $rows = [[['text' => $w['btnCreate'], 'callback_data' => 'paygmk_' . $panel['code_panel'], 'style' => 'success']]];
        $rows[] = [['text' => $w['btnBack'], 'callback_data' => $onlyPanel ? 'buy' : 'buytype_payg', 'style' => 'danger']];
        return [$text, json_encode(['inline_keyboard' => $rows])];
    }
    // the message a new one is delivered with (sendMessageService, kind payg)
    function payg_created_text(array $svc, array $out, array $panel, $tx)
    {
        $w = $tx['users']['payg'];
        $u = select("user", "*", "id", (string) $svc['user_id'], "select", ['cache' => false]);
        $rates = payg_rates(payg_cfg($svc['lang']), payg_group_of($u));
        $left = payg_wallet($svc['user_id'], $svc['currency']);
        $sub = ($panel['sublink'] ?? '') === 'onsublink' ? (string) ($out['subscription_url'] ?? '') : '';
        $links = '';
        if (($panel['config'] ?? '') === 'onconfig' && is_array($out['configs'] ?? null)) {
            foreach ($out['configs'] as $link) {
                $links .= "\n" . $link;
            }
        }
        return strtr($w['created'], [
            '{username}' => '<code>' . htmlspecialchars($svc['username']) . '</code>',
            '{location}' => htmlspecialchars((string) $panel['name_panel']),
            '{rates}' => payg_rates_text($rates, $svc['currency'], $tx),
            '{start}' => $svc['status'] === 'waiting' ? $w['startConnect'] : $w['startCreate'],
            '{balance}' => money($left, $svc['currency']),
            '{estimate}' => payg_estimate_text($rates, $left, $tx),
            '{config}' => "<code>{$sub}</code>",
            '{links}' => $links,
            '{links2}' => $sub,
        ]);
    }
    // the service screen in «🛍 سرویس‌های من»: [text, keyboard]. $since: what
    // was taken since the owner last looked (🔄), null when not asked
    function payg_service_screen(array $svc, $tx, $panelData = null, $since = null)
    {
        $w = $tx['users']['payg'];
        $u = select("user", "*", "id", (string) $svc['user_id'], "select", ['cache' => false]);
        $cfg = payg_cfg($svc['lang']);
        $rates = payg_rates($cfg, payg_group_of($u));
        $left = payg_wallet($svc['user_id'], $svc['currency']);
        $status = ['waiting' => $w['statusWaiting'], 'active' => $w['statusActive'], 'stopped' => $w['statusStopped']][$svc['status']] ?? $w['statusEnded'];
        $started = (int) $svc['started_at'];
        $endAt = $svc['status'] === 'active' ? time() : ((int) $svc['stopped_at'] ?: ((int) $svc['ended_at'] ?: time()));
        $used = (is_array($panelData) && $panelData['ok'] && !$panelData['gone']) ? $panelData['used'] : (int) $svc['billed_bytes'];
        $text = strtr($w['info'], [
            '{status}' => $status,
            '{username}' => htmlspecialchars($svc['username']),
            '{panel}' => htmlspecialchars($svc['panel']),
            '{started}' => $started > 0 ? format_datetime('Y/m/d H:i', $started, $svc['lang']) : $w['notStarted'],
            '{time}' => $started > 0 ? payg_duration_text(max(0, $endAt - $started), $tx) : $w['notStarted'],
            '{volume}' => formatBytes($used),
            '{charged}' => money((float) $svc['charged'], $svc['currency']),
            '{rates}' => payg_rates_text($rates, $svc['currency'], $tx),
            '{balance}' => money($left, $svc['currency']),
            '{estimate}' => $svc['status'] === 'active' ? payg_estimate_text($rates, $left, $tx) : '',
        ]);
        if ($since !== null) {
            $text .= "\n\n" . strtr($w['sinceLast'], ['{amount}' => money(max(0, (float) $since), $svc['currency'])]);
        }
        $id = $svc['id_invoice'];
        $rows = [];
        if ($svc['status'] !== 'ended') {
            $rows[] = [['text' => $w['btnRefresh'], 'callback_data' => "paygref_{$id}", 'style' => 'primary']];
            $rows[] = [
                ['text' => $tx['users']['status']['linksub'], 'callback_data' => "subscriptionurl_{$id}"],
                ['text' => $tx['users']['status']['config'], 'callback_data' => "config_{$id}"],
            ];
            if ($cfg['convert'] && in_array($svc['status'], ['active', 'waiting'], true) && payg_panel_products($svc['lang'], $svc['panel'], payg_group_of($u))) {
                $rows[] = [['text' => $w['btnConvert'], 'callback_data' => "paygconv_{$id}", 'style' => 'success']];
            }
            $rows[] = [['text' => $w['btnDelete'], 'callback_data' => "paygdel_{$id}", 'style' => 'danger']];
        } else {
            $rows[] = [['text' => $w['btnRemoveFully'], 'callback_data' => "paygrm_{$id}", 'style' => 'danger']];
        }
        $rows[] = [['text' => $tx['users']['status']['backlist'], 'callback_data' => 'backorder', 'style' => 'danger']];
        return [$text, json_encode(['inline_keyboard' => $rows])];
    }
    // 🗑's question: [text, keyboard]
    function payg_delete_confirm(array $svc, $tx)
    {
        $w = $tx['users']['payg'];
        $keep = payg_cfg($svc['lang'])['delmode'] === 'keep';
        $text = strtr($keep ? $w['deleteConfirmKeep'] : $w['deleteConfirm'], ['{username}' => htmlspecialchars($svc['username'])]);
        return [$text, json_encode(['inline_keyboard' => [
            [['text' => $w['btnDeleteYes'], 'callback_data' => 'paygdelok_' . $svc['id_invoice'], 'style' => 'danger']],
            [['text' => $w['btnNo'], 'callback_data' => 'product_' . $svc['id_invoice'], 'style' => 'primary']],
        ]])];
    }
    // ⏩'s list: the panel's plans for this customer's group, in its currency
    function payg_convert_list(array $svc, $tx)
    {
        $w = $tx['users']['payg'];
        $u = select("user", "*", "id", (string) $svc['user_id'], "select", ['cache' => false]);
        $rows = [];
        foreach (payg_panel_products($svc['lang'], $svc['panel'], payg_group_of($u)) as $p) {
            $pcur = trim((string) ($p['currency'] ?? '')) ?: currency_default_code();
            if ($pcur !== $svc['currency']) {
                continue;
            }
            $rows[] = [['text' => $p['name_product'] . ' - ' . money((float) $p['price_product'], $pcur), 'callback_data' => 'paygcp_' . $svc['id_invoice'] . '_' . $p['code_product'], 'style' => 'primary']];
        }
        $text = $rows ? strtr($w['convertPick'], ['{balance}' => money(payg_wallet($svc['user_id'], $svc['currency']), $svc['currency'])]) : $w['convertNoProduct'];
        $rows[] = [['text' => $w['btnBack'], 'callback_data' => 'product_' . $svc['id_invoice'], 'style' => 'danger']];
        return [$text, json_encode(['inline_keyboard' => $rows])];
    }
    function payg_convert_confirm(array $svc, array $product, $tx)
    {
        $w = $tx['users']['payg'];
        $days = (int) $product['Service_time'];
        $gb = (float) $product['Volume_constraint'];
        $text = strtr($w['convertConfirm'], [
            '{username}' => htmlspecialchars($svc['username']),
            '{product}' => htmlspecialchars((string) $product['name_product']),
            '{price}' => money((float) $product['price_product'], $svc['currency']),
            '{volume}' => $gb > 0 ? volume_num($gb) . ' ' . $tx['common']['units']['gigabyte'] : $tx['users']['status']['unlimited'],
            '{days}' => $days > 0 ? $days . ' ' . $w['unitDay'] : $tx['users']['status']['unlimited'],
        ]);
        return [$text, json_encode(['inline_keyboard' => [
            [['text' => $w['btnConvertYes'], 'callback_data' => 'paygcpok_' . $svc['id_invoice'] . '_' . $product['code_product'], 'style' => 'success']],
            [['text' => $w['btnNo'], 'callback_data' => 'paygconv_' . $svc['id_invoice'], 'style' => 'danger']],
        ]])];
    }
}

if (!function_exists('payg_report')) {
    // to the report group's ⏱ topic - every event, with whose and in which
    // language's plan
    function payg_report($event, $svc, array $vars = [])
    {
        if (!is_array($svc)) {
            return;
        }
        $pt = panel_texts();
        $r = $pt['Admin']['Payg'];
        $tpl = $r['report_' . $event] ?? null;
        if ($tpl === null) {
            return;
        }
        $u = select("user", "*", "id", (string) $svc['user_id'], "select");
        $code = in_array($svc['lang'], panel_langs(), true) ? $svc['lang'] : 'fa';
        $text = strtr($tpl, $vars + [
            '{id}' => (string) $svc['user_id'],
            '{tg}' => (is_array($u) && !empty($u['username']) && $u['username'] !== 'none') ? '@' . htmlspecialchars($u['username']) : '',
            '{service}' => htmlspecialchars((string) $svc['username']),
            '{panel}' => htmlspecialchars((string) $svc['panel']),
            '{charged}' => money((float) $svc['charged'], $svc['currency']),
        ]) . "\n\n" . sprintf($pt['Admin']['reportgroup']['userLangLine'], $pt['bottext']['langs'][$code] ?? $code);
        report_to_topic('paygreport', $r['topicName'], $text);
    }
    // 📊 and the web panel: what $lang's hourly services are doing - those
    // open now, and what was taken (all of it, or between two times)
    function payg_stats($lang, $from = null, $to = null)
    {
        global $pdo;
        payg_ensure_schema();
        $st = $pdo->prepare("SELECT COUNT(*) FROM payg_service WHERE lang = ? AND status IN ('waiting', 'active', 'stopped')");
        $st->execute([$lang]);
        $open = (int) $st->fetchColumn();
        $sql = "SELECT COALESCE(SUM(amount), 0) s, COUNT(DISTINCT id_invoice) n FROM payg_charge WHERE lang = ?";
        $params = [$lang];
        if ($from !== null) {
            $sql .= " AND hour_at BETWEEN ? AND ?";
            $params[] = (int) floor($from / 3600) * 3600;
            $params[] = (int) $to;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st = $pdo->prepare("SELECT COUNT(*) FROM payg_service WHERE lang = ?");
        $st->execute([$lang]);
        return ['open' => $open, 'income' => (float) ($row['s'] ?? 0), 'billed' => (int) ($row['n'] ?? 0), 'ever' => (int) $st->fetchColumn()];
    }
    // the ⏱ line under 📊 آمار ربات; '' for a language that never had one
    function payg_stats_line($lang, $from = null, $to = null)
    {
        $s = payg_stats($lang, $from, $to);
        if ($s['ever'] === 0) {
            return '';
        }
        return "\n" . strtr(panel_texts()['Admin']['Payg']['statsLine'], [
            '{open}' => number_format($s['open']),
            '{income}' => money($s['income'], currency_for_lang($lang)),
        ]);
    }
}

if (!function_exists('payg_admin_screen')) {
    function payg_admin_texts()
    {
        return panel_texts()['Admin']['Payg'];
    }
    function payg_group_names()
    {
        $r = payg_admin_texts();
        return ['f' => $r['groupF'], 'n' => $r['groupN'], 'n2' => $r['groupN2']];
    }
    function payg_lang_name($lang)
    {
        return panel_texts()['bottext']['langs'][$lang] ?? $lang;
    }
    // «2 روز و 3 ساعت», «45 دقیقه» in the panel's Persian
    function payg_admin_duration($seconds)
    {
        return payg_duration_text($seconds, panel_texts());
    }
    // a price as the panel shows it: «10,000 تومان / ساعت», «نامحدود»,
    // «مثل کاربر عادی»
    function payg_admin_price_text(array $cfg, $dim, $group, $cur)
    {
        $r = payg_admin_texts();
        $raw = (string) (($dim === 't' ? $cfg['tprice'] : $cfg['vprice'])[$group] ?? '');
        if ($raw === '' && $group !== 'f') {
            return $r['priceSame'];
        }
        if (payg_price_num($raw) === null) {
            return $r['priceFree'];
        }
        $per = $dim === 'v' ? $r['perGb'] : ($cfg['unit'] === 'minute' ? $r['perMinute'] : $r['perHour']);
        return payg_money((float) $raw, $cur) . ' ' . $per;
    }
    // what keeps $lang's hourly accounts from being offered, each in words
    function payg_admin_problems($lang)
    {
        $r = payg_admin_texts();
        $cfg = payg_cfg($lang);
        $out = [];
        if (!$cfg['panels']) {
            $out[] = $r['probNoPanel'];
        }
        foreach ($cfg['panels'] as $code) {
            $p = select("marzban_panel", "*", "code_panel", $code, "select");
            $why = is_array($p) ? payg_panel_problem($lang, $p) : 'gone';
            if ($why !== null) {
                $out[] = strtr($r['probPanel_' . $why], ['{panel}' => is_array($p) ? htmlspecialchars((string) $p['name_panel']) : htmlspecialchars((string) $code)]);
            }
        }
        $free = payg_free_group($cfg);
        if ($free !== null) {
            $out[] = strtr($r['probFree'], ['{group}' => payg_group_names()[$free]]);
        }
        return $out;
    }
    // 🌐 وضعیت قابلیت‌ها (هر زبان) ← ⏱ اکانت ساعتی, for one language
    function payg_admin_screen($lang)
    {
        $r = payg_admin_texts();
        $cfg = payg_cfg($lang);
        $cur = currency_for_lang($lang);
        $names = payg_group_names();
        $panelNames = [];
        foreach ($cfg['panels'] as $code) {
            $p = select("marzban_panel", "*", "code_panel", $code, "select");
            $panelNames[] = is_array($p) ? htmlspecialchars((string) $p['name_panel']) : htmlspecialchars((string) $code);
        }
        $prices = function ($dim) use ($cfg, $cur, $names) {
            $l = [];
            foreach (payg_groups() as $g) {
                $l[] = $names[$g] . ': ' . payg_admin_price_text($cfg, $dim, $g, $cur);
            }
            return implode("\n", $l);
        };
        $stats = payg_stats($lang);
        $problems = payg_admin_problems($lang);
        $minage = $cfg['minage'] > 0 ? payg_admin_duration($cfg['minage'] * 60) : $r['minageNone'];
        $grace = $cfg['grace'] > 0 ? payg_admin_duration($cfg['grace'] * 3600) : $r['graceNone'];
        $text = strtr($r['caption'], [
            '{lang}' => payg_lang_name($lang),
            '{state}' => $cfg['on'] ? $r['stateOn'] : $r['stateOff'],
            '{problems}' => $problems ? "\n" . implode("\n", $problems) . "\n" : '',
            '{panels}' => $panelNames ? implode('، ', $panelNames) : $r['none'],
            '{unit}' => $cfg['unit'] === 'minute' ? $r['unitMinute'] : $r['unitHour'],
            '{tprices}' => $prices('t'),
            '{vprices}' => $prices('v'),
            '{minbal}' => $cfg['minbal'] > 0 ? money($cfg['minbal'], $cur) : $r['minbalAuto'],
            '{max}' => (string) $cfg['max'],
            '{start}' => $cfg['start'] === 'connect' ? $r['startConnect'] : $r['startCreate'],
            '{minage}' => $minage,
            '{delmode}' => $cfg['delmode'] === 'keep' ? $r['delKeep'] : $r['delRemove'],
            '{warn}' => $cfg['warn'] ? implode('، ', array_map(fn($a) => money($a, $cur), $cfg['warn'])) : $r['none'],
            '{grace}' => $grace,
            '{convert}' => $cfg['convert'] ? $r['convertOn'] : $r['convertOff'],
            '{open}' => number_format($stats['open']),
            '{income}' => money($stats['income'], $cur),
        ]);
        $btn = fn($t, $cb, $style = 'primary') => ['text' => $t, 'callback_data' => $cb, 'style' => $style];
        $rows = [];
        $rows[] = panel_lang_tabs($lang, "paygsec:%s", null);
        $rows[] = [$btn(strtr($r['btnState'], ['{state}' => $cfg['on'] ? $r['stateOn'] : $r['stateOff']]), "paygtog:{$lang}:on", $cfg['on'] ? 'success' : 'danger')];
        $rows[] = [$btn($r['btnPanels'], "paygpan:{$lang}")];
        $rows[] = [$btn(strtr($r['btnUnit'], ['{v}' => $cfg['unit'] === 'minute' ? $r['unitMinute'] : $r['unitHour']]), "paygtog:{$lang}:unit")];
        $rows[] = [$btn($r['btnTimePrice'], "paygprice:{$lang}:t"), $btn($r['btnVolPrice'], "paygprice:{$lang}:v")];
        $rows[] = [$btn(strtr($r['btnMinbal'], ['{v}' => $cfg['minbal'] > 0 ? money($cfg['minbal'], $cur) : $r['minbalAuto']]), "paygask:{$lang}:minbal")];
        $rows[] = [$btn(strtr($r['btnMax'], ['{v}' => (string) $cfg['max']]), "paygask:{$lang}:max")];
        $rows[] = [$btn(strtr($r['btnStart'], ['{v}' => $cfg['start'] === 'connect' ? $r['startConnect'] : $r['startCreate']]), "paygtog:{$lang}:start")];
        $rows[] = [$btn(strtr($r['btnMinage'], ['{v}' => $minage]), "paygask:{$lang}:minage")];
        $rows[] = [$btn(strtr($r['btnDelmode'], ['{v}' => $cfg['delmode'] === 'keep' ? $r['delKeep'] : $r['delRemove']]), "paygtog:{$lang}:delmode")];
        $rows[] = [$btn($r['btnWarn'], "paygask:{$lang}:warn"), $btn(strtr($r['btnGrace'], ['{v}' => $grace]), "paygask:{$lang}:grace")];
        $rows[] = [$btn(strtr($r['btnConvert'], ['{v}' => $cfg['convert'] ? $r['convertOn'] : $r['convertOff']]), "paygtog:{$lang}:convert", $cfg['convert'] ? 'success' : 'danger')];
        $rows[] = [$btn(strtr($r['btnList'], ['{n}' => number_format($stats['open'])]), "payglist:{$lang}:1")];
        $rows[] = [$btn($r['btnDelivery'], "cfgdeliv|list|{$lang}|h"), $btn($r['btnTexts'], "bt_group|{$lang}|payg")];
        $rows[] = [$btn($r['back'], "fls_lang:{$lang}", 'danger')];
        return [$text, json_encode(['inline_keyboard' => $rows])];
    }
    // 💵 / 📦: one row per group - the price, ♾ for free, ↩️ for the customers' price
    function payg_admin_price_screen($lang, $dim)
    {
        $r = payg_admin_texts();
        $cfg = payg_cfg($lang);
        $cur = currency_for_lang($lang);
        $names = payg_group_names();
        $text = strtr($dim === 't' ? $r['timeCaption'] : $r['volCaption'], [
            '{lang}' => payg_lang_name($lang),
            '{currency}' => currency_get($cur)['title'] ?? $cur,
            '{unit}' => $cfg['unit'] === 'minute' ? $r['unitMinute'] : $r['unitHour'],
        ]);
        $rows = [];
        foreach (payg_groups() as $g) {
            $rows[] = [['text' => $names[$g] . ': ' . payg_admin_price_text($cfg, $dim, $g, $cur), 'callback_data' => "paygask:{$lang}:{$dim}_{$g}", 'style' => 'primary']];
            $line = [['text' => $r['btnFree'], 'callback_data' => "paygset:{$lang}:{$dim}_{$g}:u"]];
            if ($g !== 'f') {
                $line[] = ['text' => $r['btnSame'], 'callback_data' => "paygset:{$lang}:{$dim}_{$g}:s"];
            }
            $rows[] = $line;
        }
        $rows[] = [['text' => $r['back'], 'callback_data' => "paygsec:{$lang}", 'style' => 'danger']];
        return [$text, json_encode(['inline_keyboard' => $rows])];
    }
    // 🖥: every panel, ✅ when on $lang's list, with why it cannot be when not
    function payg_admin_panels_screen($lang)
    {
        $r = payg_admin_texts();
        $cfg = payg_cfg($lang);
        $panels = select("marzban_panel", "*", null, null, "fetchAll");
        $rows = [];
        foreach (is_array($panels) ? $panels : [] as $p) {
            $on = in_array((string) $p['code_panel'], $cfg['panels'], true);
            $why = payg_panel_problem($lang, $p);
            $label = ($on ? '✅ ' : '▫️ ') . $p['name_panel'] . ($why !== null ? ' — ' . $r['why_' . $why] : '');
            $rows[] = [['text' => $label, 'callback_data' => "paygpt:{$lang}:{$p['code_panel']}", 'style' => $on ? 'success' : ($why !== null ? 'danger' : 'primary')]];
        }
        $rows[] = [['text' => $r['back'], 'callback_data' => "paygsec:{$lang}", 'style' => 'danger']];
        return [strtr($r['panelsCaption'], ['{lang}' => payg_lang_name($lang)]), json_encode(['inline_keyboard' => $rows])];
    }
    // 📋 $lang's open hourly services, 15 a page; a tap asks to delete one
    function payg_admin_list_screen($lang, $page = 1)
    {
        global $pdo;
        payg_ensure_schema();
        $r = payg_admin_texts();
        $per = 15;
        $page = max(1, (int) $page);
        $st = $pdo->prepare("SELECT COUNT(*) FROM payg_service WHERE lang = ? AND status IN ('waiting', 'active', 'stopped')");
        $st->execute([$lang]);
        $total = (int) $st->fetchColumn();
        $st = $pdo->prepare("SELECT * FROM payg_service WHERE lang = ? AND status IN ('waiting', 'active', 'stopped') ORDER BY created_at DESC LIMIT " . (($page - 1) * $per) . ", {$per}");
        $st->execute([$lang]);
        $icons = ['waiting' => '⏸', 'active' => '✅', 'stopped' => '⛔️'];
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $rows[] = [['text' => ($icons[$s['status']] ?? '') . " {$s['username']} · {$s['user_id']} · " . money((float) $s['charged'], $s['currency']), 'callback_data' => "paygadel:{$s['id_invoice']}", 'style' => 'primary']];
        }
        $nav = [];
        if ($page > 1) {
            $nav[] = ['text' => $r['prev'], 'callback_data' => "payglist:{$lang}:" . ($page - 1)];
        }
        if ($page * $per < $total) {
            $nav[] = ['text' => $r['next'], 'callback_data' => "payglist:{$lang}:" . ($page + 1)];
        }
        if ($nav) {
            $rows[] = $nav;
        }
        $rows[] = [['text' => $r['back'], 'callback_data' => "paygsec:{$lang}", 'style' => 'danger']];
        return [strtr($total ? $r['listCaption'] : $r['listEmpty'], ['{lang}' => payg_lang_name($lang), '{n}' => number_format($total)]), json_encode(['inline_keyboard' => $rows])];
    }
    // the calculator under a typed price: per minute, hour, day and 30 days
    // for time; per megabyte and 10, 50, 100 gigabytes for volume
    function payg_admin_calc($lang, $key, $value)
    {
        $r = payg_admin_texts();
        $cfg = payg_cfg($lang);
        $cur = currency_for_lang($lang);
        [$dim, $group] = explode('_', $key, 2);
        $vars = ['{group}' => payg_group_names()[$group] ?? $group, '{lang}' => payg_lang_name($lang)];
        if ($dim === 't') {
            $perHour = $cfg['unit'] === 'minute' ? $value * 60 : $value;
            $vars += [
                '{price}' => payg_money($value, $cur) . ' ' . ($cfg['unit'] === 'minute' ? $r['perMinute'] : $r['perHour']),
                '{minute}' => payg_money($perHour / 60, $cur),
                '{hour}' => payg_money($perHour, $cur),
                '{day}' => payg_money($perHour * 24, $cur),
                '{month}' => payg_money($perHour * 720, $cur),
            ];
            return strtr($r['calcTime'], $vars);
        }
        $vars += [
            '{price}' => payg_money($value, $cur) . ' ' . $r['perGb'],
            '{mb}' => payg_money($value / 1024, $cur),
            '{g10}' => payg_money($value * 10, $cur),
            '{g50}' => payg_money($value * 50, $cur),
            '{g100}' => payg_money($value * 100, $cur),
        ];
        return strtr($r['calcVol'], $vars);
    }
    // a setting the admin typed, checked: ['ok' => value to store] or ['error' => text]
    function payg_admin_parse($lang, $key, $text)
    {
        $r = payg_admin_texts();
        $cur = currency_for_lang($lang);
        $t = trim(payg_digits($text));
        $num = fn($v) => rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
        if (in_array($key, payg_price_keys(), true) || $key === 'minbal') {
            if ($key === 'minbal' && preg_match('/^0+$/', $t)) {
                return ['ok' => '0'];
            }
            $v = payg_parse_amount($t, $cur);
            return $v === null ? ['error' => $r['badAmount']] : ['ok' => $num($v)];
        }
        if ($key === 'max') {
            return (ctype_digit($t) && (int) $t >= 1 && (int) $t <= 100) ? ['ok' => (string) (int) $t] : ['error' => $r['badCount']];
        }
        if ($key === 'minage' || $key === 'grace') {
            // a number - minutes for ⏳, hours for ⌛ - or with its unit
            if (!preg_match('/^(\d+(?:\.\d+)?)\s*(دقیقه|min|m|ساعت|hours?|h|روز|days?|d)?$/iu', $t, $m)) {
                return ['error' => $r['badDuration']];
            }
            $n = (float) $m[1];
            $unit = mb_strtolower($m[2] ?? '');
            if (in_array($unit, ['روز', 'day', 'days', 'd'], true)) {
                $minutes = $n * 1440;
            } elseif (in_array($unit, ['ساعت', 'hour', 'hours', 'h'], true)) {
                $minutes = $n * 60;
            } elseif ($unit === '') {
                $minutes = $key === 'grace' ? $n * 60 : $n;
            } else {
                $minutes = $n;
            }
            if ($key === 'grace') {
                $hours = (int) round($minutes / 60);
                return $hours <= 8760 ? ['ok' => (string) $hours] : ['error' => $r['badDuration']];
            }
            $minutes = (int) round($minutes);
            return $minutes <= 525600 ? ['ok' => (string) $minutes] : ['error' => $r['badDuration']];
        }
        if ($key === 'warn') {
            if (preg_match('/^0+$/', $t)) {
                return ['ok' => ''];
            }
            $out = [];
            foreach (preg_split('/[\s,،]+/u', $t, -1, PREG_SPLIT_NO_EMPTY) as $part) {
                $v = payg_parse_amount($part, $cur);
                if ($v === null) {
                    return ['error' => $r['badWarn']];
                }
                $out[] = $num($v);
            }
            return $out ? ['ok' => implode(',', array_slice(array_values(array_unique($out)), 0, 6))] : ['error' => $r['badWarn']];
        }
        return ['error' => $r['badAmount']];
    }
    // stores one setting; a price that would leave a group with both sides
    // marked ♾ is refused (a free, unlimited service). null, or why not.
    function payg_admin_set($lang, $key, $value)
    {
        $r = payg_admin_texts();
        if (in_array($key, payg_price_keys(), true)) {
            [$dim, $group] = explode('_', $key, 2);
            $cfg = payg_cfg($lang);
            $cfg[$dim === 't' ? 'tprice' : 'vprice'][$group] = (string) $value;
            foreach (payg_groups() as $g) {
                if (payg_price_raw($cfg, 't', $g) === 'u' && payg_price_raw($cfg, 'v', $g) === 'u') {
                    return strtr($r['bothFree'], ['{group}' => payg_group_names()[$g]]);
                }
            }
            if ($cfg['on'] && payg_free_group($cfg) !== null) {
                return strtr($r['bothFree'], ['{group}' => payg_group_names()[payg_free_group($cfg)]]);
            }
            feature_setting_set(($dim === 't' ? 'payg_tprice_' : 'payg_vprice_') . $group, $lang, (string) $value);
            return null;
        }
        $map = ['minbal' => 'payg_minbal', 'max' => 'payg_max', 'minage' => 'payg_minage', 'warn' => 'payg_warn', 'grace' => 'payg_grace'];
        if (!isset($map[$key])) {
            return $r['badAmount'];
        }
        feature_setting_set($map[$key], $lang, (string) $value);
        return null;
    }
    // the on/off and either/or rows; null, or why it cannot be switched
    function payg_admin_toggle($lang, $what)
    {
        $cfg = payg_cfg($lang);
        if ($what === 'on') {
            if (!$cfg['on']) {
                $problems = payg_admin_problems($lang);
                if ($problems) {
                    return strip_tags(payg_admin_texts()['cannotOn'] . "\n\n" . implode("\n", $problems));
                }
            }
            feature_setting_set('payg_on', $lang, $cfg['on'] ? '0' : '1');
        } elseif ($what === 'unit') {
            feature_setting_set('payg_unit', $lang, $cfg['unit'] === 'minute' ? 'hour' : 'minute');
        } elseif ($what === 'start') {
            feature_setting_set('payg_start', $lang, $cfg['start'] === 'connect' ? 'create' : 'connect');
        } elseif ($what === 'delmode') {
            feature_setting_set('payg_delmode', $lang, $cfg['delmode'] === 'keep' ? 'remove' : 'keep');
        } elseif ($what === 'convert') {
            feature_setting_set('payg_convert', $lang, $cfg['convert'] ? '0' : '1');
        }
        return null;
    }
    // 🖥 one panel on or off $lang's list; null, or why it cannot be on it
    function payg_admin_panel_toggle($lang, $code)
    {
        $r = payg_admin_texts();
        $cfg = payg_cfg($lang);
        $list = $cfg['panels'];
        if (in_array((string) $code, $list, true)) {
            $list = array_values(array_diff($list, [(string) $code]));
            if (!$list && $cfg['on']) {
                return $r['lastPanel'];
            }
        } else {
            $p = select("marzban_panel", "*", "code_panel", $code, "select");
            $why = is_array($p) ? payg_panel_problem($lang, $p) : 'gone';
            if ($why !== null) {
                return strip_tags(strtr($r['probPanel_' . $why], ['{panel}' => is_array($p) ? (string) $p['name_panel'] : (string) $code]));
            }
            $list[] = (string) $code;
        }
        feature_setting_set('payg_panels', $lang, implode(',', $list));
        return null;
    }
}
