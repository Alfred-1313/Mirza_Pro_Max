<?php
// The report group's topics, made once.
//
// Telegram gives a bot no way to list a group's topics or read their names,
// so the bot keeps its own record of every topic it makes there:
// storage/report_topics.json - group, topic id, which report it is for. It
// is a file, not a table, so it outlives a database restore: a fresh install
// that set the group up before the old backup was put back still knows the
// topics it made, and can take them back out.
//
// Setting the group (again) uses what is there: the topic each report already
// has, or one this bot made for it in that group before, and makes only the
// ones that are missing. Copies the bot made beyond the one in use are
// deleted. Topics the bot did not make are never touched.

if (!function_exists('report_topics_core')) {
    // the topics made when the group is set; the others (⏱, 🎁 ...) are made
    // by report_topic_id() the first time a report needs them
    function report_topics_core($tx)
    {
        $r = $tx['Admin']['report'];
        return [
            'buyreport' => $r['btnPurchaseReports'],
            'otherservice' => $r['btnServicePurchase'],
            'reporttest' => $r['btnTestAccount'],
            'otherreport' => $r['btnOther'],
            'errorreport' => $r['btnErrors'],
            'paymentreport' => $r['btnFinancial'],
            'porsantreport' => $tx['Admin']['affiliates']['titleTopic'],
            'reportnight' => $r['reportNight'],
            'reportcron' => $r['reportCron'],
            'backupfile' => $r['btnBackup'],
        ];
    }

    function report_topic_ledger_path()
    {
        return __DIR__ . '/storage/report_topics.json';
    }
    function report_topic_ledger()
    {
        $j = json_decode((string) @file_get_contents(report_topic_ledger_path()), true);
        return is_array($j['entries'] ?? null) ? $j['entries'] : [];
    }
    function report_topic_ledger_write(array $entries)
    {
        $path = report_topic_ledger_path();
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        $ok = @file_put_contents($path, json_encode(['entries' => array_values($entries)], JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
        if ($ok) {
            // table.php runs as root; the webhook (www-data) writes it too
            @chmod($path, 0664);
            @chown($path, fileowner(__DIR__));
            @chgrp($path, filegroup(__DIR__));
        }
        return $ok;
    }
    function report_topic_ledger_add($chat, $id, $key)
    {
        $chat = (string) $chat;
        $id = (int) $id;
        if ($chat === '' || $id <= 0) {
            return;
        }
        $entries = report_topic_ledger();
        foreach ($entries as $e) {
            if ((string) $e['chat'] === $chat && (int) $e['id'] === $id) {
                return;
            }
        }
        $entries[] = ['chat' => $chat, 'id' => $id, 'key' => (string) $key, 'at' => time()];
        report_topic_ledger_write($entries);
    }
    function report_topic_ledger_drop($chat, $id)
    {
        $entries = array_filter(report_topic_ledger(), static fn($e) => !((string) $e['chat'] === (string) $chat && (int) $e['id'] === (int) $id));
        report_topic_ledger_write($entries);
    }
    // the topics the reports use now, in the group they were made in
    function report_topics_active()
    {
        global $pdo;
        $out = [];
        try {
            foreach ($pdo->query("SELECT report, idreport FROM topicid")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ((int) $row['idreport'] > 0) {
                    $out[$row['report']] = (int) $row['idreport'];
                }
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }
    // what the bot made before this record existed: the topics in use, in the
    // group set now - recorded once, so later copies can be told apart
    function report_topic_ledger_seed()
    {
        $setting = select("setting", "*", null, null, "select");
        $group = (string) ($setting['Channel_Report'] ?? '');
        if ($group === '' || $group === '0') {
            return;
        }
        foreach (report_topics_active() as $key => $id) {
            report_topic_ledger_add($group, $id, $key);
        }
    }

    // Is topic $id there in $chat? 'yes', 'no', or 'unknown' (Telegram did not
    // say). Asked with reopenForumTopic: an open topic answers "not
    // modified", a closed one is opened again - a report topic should be open
    // anyway - and a deleted one is not found. Nothing is renamed or sent.
    function report_topic_state($chat, $id)
    {
        $r = telegram('reopenForumTopic', ['chat_id' => $chat, 'message_thread_id' => (int) $id]);
        if (!empty($r['ok'])) {
            return 'yes';
        }
        $d = strtoupper((string) ($r['description'] ?? ''));
        if (strpos($d, 'TOPIC_NOT_MODIFIED') !== false) {
            return 'yes';
        }
        if (strpos($d, 'TOPIC_ID_INVALID') !== false || strpos($d, 'THREAD NOT FOUND') !== false
            || strpos($d, 'TOPIC_DELETED') !== false || strpos($d, 'MESSAGE_ID_INVALID') !== false) {
            return 'no';
        }
        return 'unknown';
    }

    // createForumTopic, waiting out one "Too Many Requests" - making ten topics
    // in a row is what runs into it, and a stop half way used to leave a set
    // that the next try made again in full
    function report_topic_create($chat, $name)
    {
        for ($try = 0; $try < 3; $try++) {
            $r = telegram('createForumTopic', ['chat_id' => $chat, 'name' => $name]);
            $after = (int) ($r['parameters']['retry_after'] ?? 0);
            if (!empty($r['ok']) || ($r['error_code'] ?? 0) != 429 || $after > 40) {
                return $r;
            }
            sleep(max(1, $after));
        }
        return $r;
    }

    // The group's topics for $group: the ones there are used, only the missing
    // ones are made, then the bot's extra copies go. Returns
    // ['made' => n, 'kept' => n, 'removed' => n, 'error' => null|Telegram's words].
    function report_topics_setup($group, $tx)
    {
        global $pdo;
        $group = (string) $group;
        $setting = select("setting", "*", null, null, "select");
        $sameGroup = (string) ($setting['Channel_Report'] ?? '') === $group;
        report_topic_ledger_seed();
        $active = report_topics_active();
        $ledger = report_topic_ledger();
        $res = ['made' => 0, 'kept' => 0, 'removed' => 0, 'error' => null];
        foreach (report_topics_core($tx) as $key => $name) {
            // the one in use first, then any other this bot made for it here
            $cands = [];
            if ($sameGroup && !empty($active[$key])) {
                $cands[] = $active[$key];
            }
            foreach ($ledger as $e) {
                if ((string) $e['chat'] === $group && $e['key'] === $key && !in_array((int) $e['id'], $cands, true)) {
                    $cands[] = (int) $e['id'];
                }
            }
            $have = 0;
            foreach ($cands as $c) {
                $st = report_topic_state($group, $c);
                if ($st === 'no') {
                    report_topic_ledger_drop($group, $c);
                    continue;
                }
                $have = $c;
                break;
            }
            if ($have > 0) {
                $res['kept']++;
            } else {
                $made = report_topic_create($group, $name);
                $have = (int) ($made['result']['message_thread_id'] ?? 0);
                if ($have <= 0) {
                    $res['error'] = (string) ($made['description'] ?? 'createForumTopic failed');
                    return $res;
                }
                report_topic_ledger_add($group, $have, $key);
                $res['made']++;
            }
            report_topic_ledger_add($group, $have, $key);
            if (($active[$key] ?? 0) !== $have) {
                update("topicid", "idreport", $have, "report", $key);
                $active[$key] = $have;
            }
        }
        clearSelectCache('topicid');
        // the topics made on demand (⏱ اکانت ساعتی, 🎁 کانفیگ رایگان ...) stay
        // when it is the same group; a new group gets them made there, once
        if (!$sameGroup) {
            $lazy = array_values(array_diff(array_keys(report_topics_active()), array_keys(report_topics_core($tx))));
            report_topic_reset($lazy);
        }
        $res['removed'] = report_topics_dedupe($group);
        return $res;
    }

    // Deletes the bot's own extra copies in $group (default: the group set
    // now) - topics it made for a report that now uses another one. Asks
    // Telegram nothing when there are none. A report whose topic is gone
    // takes one of its copies instead of losing it. Returns how many went.
    function report_topics_dedupe($group = null)
    {
        if ($group === null) {
            $setting = select("setting", "*", null, null, "select");
            $group = (string) ($setting['Channel_Report'] ?? '');
        }
        $group = (string) $group;
        if ($group === '' || $group === '0') {
            return 0;
        }
        $active = report_topics_active();
        $extras = [];
        foreach (report_topic_ledger() as $e) {
            if ((string) $e['chat'] === $group && isset($active[$e['key']]) && (int) $e['id'] !== $active[$e['key']]) {
                $extras[$e['key']][] = (int) $e['id'];
            }
        }
        $removed = 0;
        foreach ($extras as $key => $ids) {
            if (report_topic_state($group, $active[$key]) === 'no') {
                // the one in use was deleted by hand: a copy takes its place
                foreach ($ids as $i => $id) {
                    if (report_topic_state($group, $id) !== 'no') {
                        update("topicid", "idreport", $id, "report", $key);
                        clearSelectCache('topicid');
                        report_topic_ledger_drop($group, $active[$key]);
                        unset($ids[$i]);
                        break;
                    }
                }
            }
            foreach ($ids as $id) {
                $r = telegram('deleteForumTopic', ['chat_id' => $group, 'message_thread_id' => $id]);
                $d = strtoupper((string) ($r['description'] ?? ''));
                if (!empty($r['ok']) || strpos($d, 'TOPIC_ID_INVALID') !== false || strpos($d, 'THREAD NOT FOUND') !== false || strpos($d, 'TOPIC_DELETED') !== false) {
                    report_topic_ledger_drop($group, $id);
                    if (!empty($r['ok'])) {
                        $removed++;
                    }
                }
            }
        }
        return $removed;
    }
}
