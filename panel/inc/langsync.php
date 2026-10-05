<?php
// What the bot keeps per language (products, categories, texts, referral
// plans, ...) the web panel edits in the very same place, through the bot's
// own helpers - a value set here is the value the bot uses, and the other way
// round. These are the bits every such page shares.

// the languages the bot speaks (panel_langs), with their names
function web_langs(): array
{
    global $textbotlang;
    $out = [];
    foreach (panel_langs() as $code) {
        $out[$code] = $textbotlang['bottext']['langs'][$code] ?? $code;
    }
    return $out;
}

// ?lang=, one of the bot's languages
function web_lang_pick(string $param = 'lang', string $default = 'fa'): string
{
    $v = (string) ($_GET[$param] ?? $_POST[$param] ?? '');
    return in_array($v, panel_langs(), true) ? $v : $default;
}

// a lang column ('all', '', null, 'fa,en') as a list of codes; empty = every language
function web_lang_list($value): array
{
    if ($value === null || $value === '' || $value === 'all') {
        return [];
    }
    return array_values(array_intersect(array_map('trim', explode(',', (string) $value)), panel_langs()));
}

// the ticked boxes as the column stores them, the way the bot's picker does:
// none ticked, «all», or every language is 'all', otherwise the codes
function web_lang_value($posted): string
{
    $posted = array_map('strval', (array) $posted);
    if (in_array('all', $posted, true)) {
        return 'all';
    }
    $picked = array_values(array_intersect(panel_langs(), $posted));
    return ($picked && count($picked) < count(panel_langs())) ? implode(',', $picked) : 'all';
}

// does a row whose lang column is $value show to $lang
function web_lang_has($value, string $lang): bool
{
    $l = web_lang_list($value);
    return !$l || in_array($lang, $l, true);
}

function web_lang_label($value): string
{
    global $textbotlang;
    $l = web_lang_list($value);
    if (!$l) {
        return $textbotlang['panel']['langAll'];
    }
    $names = web_langs();
    return implode(' · ', array_map(fn($c) => $names[$c] ?? $c, $l));
}

// the language boxes of a form: «all languages» or any of the bot's languages
function web_lang_checkboxes(string $name, $value, string $idPrefix): string
{
    global $textbotlang;
    $picked = web_lang_list($value);
    $h = '<div class="lang-chips" data-lang-group="' . htmlspecialchars($idPrefix) . '">';
    $h .= '<label class="lang-chip"><input type="checkbox" name="' . htmlspecialchars($name) . '[]" value="all" id="' . htmlspecialchars($idPrefix) . '_all"'
        . (!$picked ? ' checked' : '') . '> ' . htmlspecialchars($textbotlang['panel']['langAll']) . '</label>';
    foreach (web_langs() as $code => $label) {
        $h .= '<label class="lang-chip"><input type="checkbox" name="' . htmlspecialchars($name) . '[]" value="' . $code . '" id="' . htmlspecialchars($idPrefix . '_' . $code) . '"'
            . (in_array($code, $picked, true) ? ' checked' : '') . '> ' . htmlspecialchars($label) . '</label>';
    }
    return $h . '</div>';
}

// the language tabs over a page; $url builds a tab's link from its code ('' = all),
// $counts (code => n) puts how many there are beside a tab's name
function web_lang_tabs(string $current, callable $url, bool $withAll = false, array $counts = []): string
{
    global $textbotlang;
    $tabs = $withAll ? ['' => $textbotlang['panel']['langAllTab']] + web_langs() : web_langs();
    $h = '<div class="lang-tabs fade-up">';
    foreach ($tabs as $code => $label) {
        $on = (string) $current === (string) $code;
        $n = isset($counts[$code]) ? ' <small style="opacity:.75">(' . number_format((int) $counts[$code]) . ')</small>' : '';
        $h .= '<a href="' . htmlspecialchars($url((string) $code)) . '" class="lang-tab' . ($on ? ' on' : '') . '">' . htmlspecialchars($label) . $n . '</a>';
    }
    return $h . '</div>';
}

// A sum over a sales table (invoice, Payment_report) for one language tab, or
// for all of them ($lang '') - one figure per currency, since tomans and
// dollars never add up. $sql has {lang} where the tab's condition goes
// (stats_lang_where on $alias, the way the bot's 📊 splits sales).
function web_sum_by_currency(string $sql, array $params, string $alias, string $lang = ''): array
{
    global $pdo;
    $out = [];
    foreach ($lang !== '' ? [$lang] : panel_langs() as $l) {
        [$w, $p] = stats_lang_where($l, $alias);
        $code = currency_for_lang($l);
        $out[$code] = ($out[$code] ?? 0) + (float) db_query($pdo, str_replace('{lang}', $w, $sql), array_merge($params, $p))->fetchColumn();
    }
    return $out;
}

// ...shown one currency to a line
function web_money_lines(array $byCurrency): string
{
    $lines = [];
    foreach ($byCurrency as $code => $sum) {
        $lines[] = htmlspecialchars(money($sum, $code));
    }
    return $lines ? implode('<br>', $lines) : '0';
}

// Telegram reads the text as HTML: its own tags only, a bare «<» breaks the
// message and it would not arrive at all
function web_tg_html_ok(string $s): bool
{
    $s = preg_replace('~</?(?:b|strong|i|em|u|ins|s|strike|del|code|pre|blockquote|span|tg-spoiler|tg-emoji|a)(?:\s+[a-z-]+(?:="[^"<>]*")?)*\s*>~i', '', $s);
    return strpos($s, '<') === false;
}

// shared look of the above, and «all» vs a language being exclusive
function web_lang_assets(): string
{
    return <<<'HTML'
<style>
.lang-tabs{display:flex;gap:4px;margin-bottom:14px;background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:5px;overflow-x:auto}
.lang-tab{padding:8px 16px;border-radius:7px;font-size:.84rem;font-weight:600;white-space:nowrap;text-decoration:none;color:var(--mute)}
.lang-tab.on{background:var(--acs);color:var(--ach);font-weight:700}
.lang-chips{display:flex;flex-wrap:wrap;gap:8px}
.lang-chip{display:flex;align-items:center;gap:6px;padding:7px 12px;border:1px solid var(--bd);border-radius:8px;font-size:.84rem;cursor:pointer;background:var(--sf2)}
.lang-chip input{margin:0}
.set-row{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 0;border-top:1px solid var(--bd)}
.set-row:first-child{border-top:0}
.set-row .set-label{font-weight:600;font-size:.88rem}
.set-row .set-hint{font-size:.76rem;color:var(--mute);margin-top:3px;line-height:1.8}
.set-row .set-ctl{min-width:220px;max-width:320px;flex-shrink:0}
.set-off{opacity:.55}
@media (max-width:640px){.set-row{flex-direction:column;align-items:stretch}.set-row .set-ctl{min-width:0;max-width:none}}
.kb-grid{display:flex;flex-direction:column;gap:6px;max-width:460px}
.kb-row{display:flex;gap:6px}
.kb-chip{flex:1;text-align:center;padding:10px 6px;border-radius:9px;color:#fff;font-size:.84rem;cursor:grab;user-select:none;-webkit-user-select:none;touch-action:manipulation}
.kb-chip.plain{background:var(--sf3);color:var(--text)}
.kb-chip.off{opacity:.45}
.kb-chip.sel{outline:3px solid var(--warn);outline-offset:1px}
.kb-slot,.kb-newrow{flex:1;border:2px dashed var(--bd);border-radius:9px;display:flex;align-items:center;justify-content:center;color:var(--mute);cursor:pointer;min-height:40px;font-size:.84rem}
/* a phone: tabs wrap instead of hiding past the edge, and every table row
   becomes a card - its name on top, each field under its column's name -
   instead of a table four phones wide to swipe through (the tables the
   script below marks .stk-wrap: a .tbl-xl one, not a .no-stack list) */
@media (max-width:640px){
.lang-tabs,.bt-groups{flex-wrap:wrap;overflow-x:visible!important}
.tbl-wrap.stk-wrap{overflow:visible!important;background:none!important}
.card>.tbl-wrap.stk-wrap{padding:12px 12px 2px}
.stk-wrap>table{min-width:0!important}
.stk-wrap>table thead{display:none}
.stk-wrap>table,.stk-wrap>table tbody{display:block;width:100%}
.stk-wrap>table tr{display:grid;grid-template-columns:1fr 1fr;column-gap:12px;border:1px solid var(--bd);border-radius:10px;padding:2px 12px 6px;margin-bottom:10px;background:var(--sf2)}
.stk-wrap>table td{display:block;min-width:0;border:0!important;padding:7px 0!important;white-space:normal!important;text-align:right!important}
.stk-wrap>table td[data-label]::before{content:attr(data-label);display:block;font-size:.7rem;font-weight:700;color:var(--dim);margin-bottom:4px}
.stk-wrap>table td.stk-title,.stk-wrap>table td.stk-wide{grid-column:1/-1}
.stk-wrap>table td.stk-title{order:-1;font-size:.92rem;border-bottom:1px solid var(--bd)!important;padding:9px 0!important}
.stk-wrap>table td .select{max-width:100%}
.stk-wrap>table td.stk-title::before,.stk-wrap>table td[data-label="#"]{display:none}
.stk-wrap>table tbody tr:hover td{background:none}
}
</style>
<script>
document.addEventListener('change', function (e) {
  var box = e.target;
  if (!box.matches || !box.matches('.lang-chips input[type=checkbox]')) return;
  var group = box.closest('.lang-chips');
  var boxes = group.querySelectorAll('input[type=checkbox]');
  if (box.value === 'all' && box.checked) {
    boxes.forEach(function (b) { if (b !== box) b.checked = false; });
  } else if (box.value !== 'all' && box.checked) {
    boxes.forEach(function (b) { if (b.value === 'all') b.checked = false; });
  }
  var any = Array.prototype.some.call(boxes, function (b) { return b.checked; });
  if (!any) boxes.forEach(function (b) { if (b.value === 'all') b.checked = true; });
  group.dispatchEvent(new CustomEvent('langchange', { bubbles: true }));
});
// the card a table row becomes on a phone: each cell named after its column,
// the row's own name (its first .cs cell) as the card's title, and a cell with
// a text to type (or a row of boxes) the card's full width - the small ones
// (emoji, side, colour, a switch) two to a line
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.tbl-wrap > table.tbl-xl:not(.no-stack)').forEach(function (t) {
    t.parentElement.classList.add('stk-wrap');
    var heads = Array.prototype.map.call(t.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
    t.querySelectorAll('tbody tr').forEach(function (tr) {
      var col = 0, title = tr.querySelector('td.cs');
      if (title) title.classList.add('stk-title');
      Array.prototype.forEach.call(tr.children, function (td) {
        var span = parseInt(td.getAttribute('colspan') || '1', 10);
        if (span === 1 && heads[col]) td.setAttribute('data-label', heads[col]);
        if (span > 1 || td.querySelector('textarea, .lang-chips, input.input:not([style*="width"])')) td.classList.add('stk-wide');
        col += span;
      });
    });
  });
});
</script>
HTML;
}
