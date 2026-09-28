<?php
#-----------------------------#
// Remnawave (docs.rw), API 3.x - checked live against a 3.4.4 panel.
//
// Login: an API token (panel -> API Tokens), in the panel's password, like
// Rebecca. The admin's own login cannot be used - the panel answers API calls
// made with it "you must create own API-token". A token lives as many days as
// it was made with; the panel's info screen shows until when.
//
// Users are made in the panel's internal squads: the ones set with «تنظیم
// پروتکل و اینباند» (panel.proxies), a product's own (product.inbounds), or
// every squad of the panel when neither is set.
#-----------------------------#
function rw_panel_row($location)
{
    return is_array($location) ? $location : select("marzban_panel", "*", "name_panel", $location, "select");
}
// the panel's address, also when the admin pasted the page they log in on
function rw_base($panel)
{
    $url = rtrim(trim((string) $panel['url_panel']), '/');
    $url = preg_replace('~/(auth|login|dashboard|api)(/.*)?$~i', '', $url);
    return rtrim($url, '/');
}
function rw_is_api_token($s)
{
    return (bool) preg_match('/^eyJ[\w-]*\.[\w-]+\.[\w-]+$/', trim((string) $s));
}
// the API token, or ['error'] when what was saved is not one
function rw_token($panel)
{
    $token = trim((string) $panel['password_panel']);
    return rw_is_api_token($token) ? $token : ['error' => 'Remnawave needs an API token (panel -> API Tokens)'];
}
// when the token stops working (0 when it does not say)
function rw_token_expiry($panel)
{
    $parts = explode('.', trim((string) $panel['password_panel']));
    $claims = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
    return (int) ($claims['exp'] ?? 0);
}
// One API call. Same shape as CurlRequest's answers: ['status', 'body',
// 'error'?]; no token is a 401 with why.
function rw_request($location, $method, $path, $body = null)
{
    $panel = rw_panel_row($location);
    if (!is_array($panel)) {
        return ['status' => null, 'body' => null, 'error' => 'Panel Not Found'];
    }
    $token = rw_token($panel);
    if (is_array($token)) {
        return ['status' => 401, 'body' => json_encode(['message' => $token['error']])];
    }
    $req = new CurlRequest(rw_base($panel) . '/api' . $path);
    $req->setHeaders(['accept: application/json', 'Content-Type: application/json']);
    $req->setBearerToken($token);
    $payload = $body === null ? null : json_encode($body);
    if ($method === 'GET') {
        return $req->get();
    }
    if ($method === 'POST') {
        return $req->post($payload ?? '{}');
    }
    if ($method === 'PATCH') {
        return $req->PATCH($payload);
    }
    return $req->delete();
}
function rw_ok($res)
{
    $code = (int) ($res['status'] ?? 0);
    return empty($res['error']) && $code >= 200 && $code < 300;
}
// what went wrong, in the panel's own words
function rw_error_text($res)
{
    if (!empty($res['error'])) {
        return (string) $res['error'];
    }
    $body = json_decode((string) ($res['body'] ?? ''), true);
    $msg = is_array($body) ? (string) ($body['message'] ?? '') : '';
    if (is_array($body) && !empty($body['errors'][0]['message'])) {
        $msg .= ($msg !== '' ? ': ' : '') . $body['errors'][0]['message'];
    }
    return $msg !== '' ? $msg : 'error code : ' . ($res['status'] ?? '?');
}
// a time for the panel: no end is 2099; never in the past - the panel refuses
// that on an edit, and a minute from now is what was meant
function rw_iso($timestamp)
{
    $timestamp = (int) $timestamp;
    if ($timestamp <= 0) {
        return '2099-12-31T23:59:59.000Z';
    }
    return gmdate('Y-m-d\TH:i:s.000\Z', max($timestamp, time() + 60));
}
function rw_ts($iso)
{
    $t = $iso ? strtotime((string) $iso) : false;
    return ($t === false || (int) gmdate('Y', $t) >= 2099) ? 0 : $t;
}
function rw_reset_to_rw($reset)
{
    return ['day' => 'DAY', 'week' => 'WEEK', 'month' => 'MONTH'][strtolower((string) $reset)] ?? 'NO_RESET';
}
function rw_reset_to_bot($reset)
{
    return ['DAY' => 'day', 'WEEK' => 'week', 'MONTH' => 'month', 'MONTH_ROLLING' => 'month'][(string) $reset] ?? 'no_reset';
}
#-----------------------------#
function getuser_remnawave($username_account, $location)
{
    return rw_request($location, 'GET', '/users/by-username/' . rawurlencode((string) $username_account));
}
// the user as the panel has it, or null
function rw_user($username_account, $location)
{
    $res = getuser_remnawave($username_account, $location);
    if (!rw_ok($res)) {
        return null;
    }
    $user = json_decode((string) $res['body'], true)['response'] ?? null;
    return is_array($user) ? $user : null;
}
// its connection links (vless://...), one a config
function rw_links($location, $username_account)
{
    $res = rw_request($location, 'GET', '/subscriptions/by-username/' . rawurlencode((string) $username_account));
    if (!rw_ok($res)) {
        return [];
    }
    $links = json_decode((string) $res['body'], true)['response']['links'] ?? [];
    return is_array($links) ? array_values(array_filter($links, 'is_string')) : [];
}
// every squad of the panel: [uuid => name]
function rw_all_squads($location)
{
    $res = rw_request($location, 'GET', '/internal-squads');
    $out = [];
    foreach ((array) (json_decode((string) ($res['body'] ?? ''), true)['response']['internalSquads'] ?? []) as $sq) {
        if (is_array($sq) && !empty($sq['uuid'])) {
            $out[(string) $sq['uuid']] = (string) ($sq['name'] ?? $sq['uuid']);
        }
    }
    return $out;
}
function rw_uuid_list($json)
{
    $list = is_array($json) ? $json : json_decode((string) $json, true);
    if (!is_array($list)) {
        return [];
    }
    return array_values(array_filter(array_map('strval', $list), fn($u) => (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $u)));
}
// the squads a new service goes in: the product's, the panel's, or all
function rw_squads_for($panel, $product = null)
{
    $list = is_array($product) ? rw_uuid_list($product['inbounds'] ?? null) : [];
    if (!$list) {
        $list = rw_uuid_list($panel['proxies'] ?? null);
    }
    return $list ?: array_keys(rw_all_squads($panel));
}
// «تنظیم پروتکل و اینباند»: squad names (split by commas), or the username of
// a panel user whose squads are copied. ['uuids' => [], 'names' => []], or
// null when it is neither.
function rw_squads_from_text($text, $location)
{
    $all = rw_all_squads($location);
    $byName = [];
    foreach ($all as $uuid => $name) {
        $byName[mb_strtolower(trim($name))] = $uuid;
    }
    $parts = array_values(array_filter(array_map('trim', preg_split('/[,،\n]+/u', (string) $text)), 'strlen'));
    $picked = [];
    foreach ($parts as $p) {
        if (!isset($byName[mb_strtolower($p)])) {
            $picked = [];
            break;
        }
        $picked[] = $byName[mb_strtolower($p)];
    }
    if (!$picked && count($parts) === 1) {
        $user = rw_user($parts[0], $location);
        foreach ((array) ($user['activeInternalSquads'] ?? []) as $sq) {
            if (is_array($sq) && !empty($sq['uuid'])) {
                $picked[] = (string) $sq['uuid'];
            }
        }
    }
    if (!$picked) {
        return null;
    }
    $picked = array_values(array_unique($picked));
    return ['uuids' => $picked, 'names' => array_map(fn($u) => $all[$u] ?? $u, $picked)];
}
#-----------------------------#
// DataUser's shape, from the panel's user. The panel marks an account expired
// or used up on its own clock, a little later - the bot does not wait for it.
function rw_user_output(array $user, $panel, $invoice, $domainhosts)
{
    $used = (int) ($user['userTraffic']['usedTrafficBytes'] ?? 0);
    $limit = (int) ($user['trafficLimitBytes'] ?? 0);
    $expire = rw_ts($user['expireAt'] ?? null);
    $status = ['ACTIVE' => 'active', 'DISABLED' => 'disabled', 'LIMITED' => 'limited', 'EXPIRED' => 'expired'][(string) ($user['status'] ?? '')] ?? 'active';
    if ($status === 'active' && $expire > 0 && $expire <= time()) {
        $status = 'expired';
    } elseif ($status === 'active' && $limit > 0 && $used >= $limit) {
        $status = 'limited';
    }
    $online = null;
    if (!empty($user['userTraffic']['onlineAt'])) {
        $dt = new DateTime((string) $user['userTraffic']['onlineAt'], new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Tehran'));
        $online = $dt->format('Y/m/d H:i:s');
    }
    $sub = (string) ($user['subscriptionUrl'] ?? '');
    if ($invoice != false) {
        $sub = "https://$domainhosts/sub/" . $invoice['id_invoice'];
    }
    return [
        'status' => $status,
        'username' => $user['username'],
        'data_limit' => $limit,
        'expire' => $expire,
        'online_at' => $online,
        'used_traffic' => $used,
        'links' => rw_links($panel, $user['username']),
        'subscription_url' => $sub,
        'sub_updated_at' => null,
        'sub_last_user_agent' => null,
        'uuid' => null,
        'data_limit_reset' => rw_reset_to_bot($user['trafficLimitStrategy'] ?? ''),
    ];
}
function adduser_remnawave($location, $data_limit, $username_ac, $timestamp, $name_product, $note = '', $data_limit_reset = 'no_reset', $limitip = null)
{
    $panel = rw_panel_row($location);
    $product = select('product', "*", "name_product", $name_product, "select");
    $data = [
        'username' => (string) $username_ac,
        'trafficLimitBytes' => max(0, (int) $data_limit),
        'trafficLimitStrategy' => rw_reset_to_rw($data_limit_reset),
        'expireAt' => rw_iso($timestamp),
        'description' => (string) $note,
        'activeInternalSquads' => rw_squads_for($panel, is_array($product) ? $product : null),
    ];
    // the panel's device limit stands in for the IP limit the others have
    if ($limitip !== null && (int) $limitip > 0 && ($panel['limit_in_panel'] ?? '') == "1") {
        $data['hwidDeviceLimit'] = (int) $limitip;
    }
    return rw_request($panel, 'POST', '/users', $data);
}
// Modifyuser takes Marzban's field names from every caller: turned into the
// panel's here. An expired or used-up account this fixes is active again.
function Modifyuser_remnawave($location, $username_account, array $data)
{
    $body = ['username' => (string) $username_account];
    if (array_key_exists('data_limit', $data)) {
        $body['trafficLimitBytes'] = max(0, (int) $data['data_limit']);
    }
    if (array_key_exists('expire', $data)) {
        $body['expireAt'] = rw_iso((int) $data['expire']);
    }
    if (isset($data['status'])) {
        $body['status'] = strtolower((string) $data['status']) === 'disabled' ? 'DISABLED' : 'ACTIVE';
    }
    if (isset($data['note'])) {
        $body['description'] = (string) $data['note'];
    }
    if (isset($data['data_limit_reset_strategy'])) {
        $body['trafficLimitStrategy'] = rw_reset_to_rw($data['data_limit_reset_strategy']);
    }
    if (!isset($body['status']) && (isset($body['trafficLimitBytes']) || isset($body['expireAt']))) {
        $cur = rw_user($username_account, $location);
        if (is_array($cur) && in_array($cur['status'] ?? '', ['LIMITED', 'EXPIRED'], true)) {
            $limit = $body['trafficLimitBytes'] ?? (int) ($cur['trafficLimitBytes'] ?? 0);
            $used = (int) ($cur['userTraffic']['usedTrafficBytes'] ?? 0);
            $end = isset($body['expireAt']) ? strtotime($body['expireAt']) : strtotime((string) ($cur['expireAt'] ?? ''));
            if (($limit == 0 || $used < $limit) && $end > time()) {
                $body['status'] = 'ACTIVE';
            }
        }
    }
    return rw_request($location, 'PATCH', '/users', $body);
}
// the calls that take the panel's user id
function rw_user_action($location, $username_account, $method, $suffix)
{
    $user = rw_user($username_account, $location);
    if ($user === null || !isset($user['id'])) {
        return ['status' => 404, 'body' => json_encode(['message' => 'User not found'])];
    }
    return rw_request($location, $method, '/users/' . (int) $user['id'] . $suffix);
}
function removeuser_remnawave($location, $username_account)
{
    return rw_user_action($location, $username_account, 'DELETE', '');
}
function ResetUserDataUsage_remnawave($username_account, $location)
{
    return rw_user_action($location, $username_account, 'POST', '/actions/reset-traffic');
}
function revoke_sub_remnawave($username_account, $location)
{
    return rw_user_action($location, $username_account, 'POST', '/actions/revoke');
}
function Get_System_Stats_remnawave($location)
{
    return rw_request($location, 'GET', '/system/stats');
}
function rw_version($location)
{
    $res = rw_request($location, 'GET', '/system/metadata');
    return (string) (json_decode((string) ($res['body'] ?? ''), true)['response']['version'] ?? '');
}
