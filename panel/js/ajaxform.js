/* Saving without leaving the page.
 *
 * A form that POSTs used to reload the whole page: the scroll went back to the
 * top, every block rose in again, and a 160px-tall save bar was all the answer
 * you got. Now the form is sent in the background, the server answers as it
 * always did (a redirect to the same page, with its flash message), and
 * - the message comes up as a toast;
 * - the form you saved is swapped for the server's fresh copy, in place (its
 *   «changed» tags, its defaults), the rest of the page untouched, the scroll
 *   where it was;
 * - a form that only holds choices (data-ajax="quiet") is not even swapped:
 *   what you picked is already on screen.
 * Anything this cannot do safely (the answer is another page, the number of
 * forms changed because a row was added or deleted, the session ended) goes the
 * old way - the page loads - with the message carried over to it.
 * A save can also change what the page shows OUTSIDE the form - a product added
 * from a dialog belongs in the list behind it, a counter moves, a preview
 * changes. The answer is compared with the page on screen without its forms; if
 * they differ the page is reloaded where it stands (same address, same scroll)
 * so it never shows a list that is one save behind.
 *
 * Opt out: data-ajax="off" on the form. data-autosave: save when a choice
 * changes (radio, checkbox, select) - no button to press.
 */
(function () {
    'use strict';
    if (!window.fetch || !window.DOMParser || !window.FormData) return;

    var KEY = 'panelToast';
    var SKIP = 'form[data-ajax="off"], form[enctype="multipart/form-data"], form[action*="login"], form[action*="logout"]';
    var busy = new WeakSet();
    var queued = new WeakSet();

    function stash(list) {
        try { sessionStorage.setItem(KEY, JSON.stringify(list)); } catch (e) {}
    }
    // a message that came with a page the old way
    try {
        var kept = JSON.parse(sessionStorage.getItem(KEY) || 'null');
        sessionStorage.removeItem(KEY);
        if (kept && kept.length && window.toast) {
            kept.forEach(function (m) { window.toast(m[0], m[1]); });
        }
    } catch (e) {}

    // the same page loaded again after a save that changed its list: back to
    // where it was (the address the server sent may differ a little from the one
    // on screen, which is a new page load, and a new page starts at the top)
    var SKEY = 'panelScroll';
    try {
        var held = JSON.parse(sessionStorage.getItem(SKEY) || 'null');
        sessionStorage.removeItem(SKEY);
        if (held && held.p === location.pathname && held.y > 0 && Date.now() - held.t < 20000) {
            var scrollBack = function () { window.scrollTo(0, held.y); };
            if (document.readyState === 'complete') scrollBack(); else window.addEventListener('load', scrollBack);
        }
    } catch (e) {}

    var TYPES = { 'notice-ok': 'ok', 'notice-no': 'no', 'notice-warn': 'warn' };

    function noticesOf(doc) {
        var out = [];
        Object.keys(TYPES).forEach(function (cls) {
            doc.querySelectorAll('.content .' + cls).forEach(function (n) {
                var msg = n.textContent.trim();
                if (msg) out.push([msg, TYPES[cls]]);
            });
        });
        return out;
    }

    function show(list) {
        if (!window.toast) return;
        // one answer at a time: a second save replaces the first one's toast
        document.querySelectorAll('.toast[data-ajax]').forEach(function (t) { t.remove(); });
        list.forEach(function (m) {
            var el = window.toast(m[0], m[1], m[1] === 'ok' ? 2600 : 5000);
            if (el) el.setAttribute('data-ajax', '1');
        });
    }

    function formsOf(doc) {
        return Array.prototype.slice.call(doc.querySelectorAll('.content form'));
    }

    function pathnameOf(url) {
        try { return new URL(url, location.href).pathname; } catch (e) { return String(url); }
    }

    // the same address, bar the #hash
    function sameAddress(a, b) {
        try {
            var x = new URL(a, location.href), y = new URL(b, location.href);
            return x.origin === y.origin && x.pathname === y.pathname && x.search === y.search;
        } catch (e) { return false; }
    }

    // What a page says outside its forms - its lists, tables, counters - as one
    // line of text. Forms are left out because the saved one is swapped by hand
    // and the others are compared by shape; so are the messages (they differ on
    // purpose). Flag emoji are dropped: the page turns them into pictures after
    // it loads, so the live page never has them and the server's copy does.
    function outsideSig(root) {
        var c = root.querySelector('.content');
        if (!c) return '';
        var clone = c.cloneNode(true);
        clone.querySelectorAll('form, script, style, noscript, .notice, .toast, [data-ajax-ignore]').forEach(function (n) { n.remove(); });
        return clone.textContent.replace(/\uD83C[\uDDE6-\uDDFF]/g, '').replace(/\s+/g, ' ').trim();
    }

    // the fields a form holds, by name: the same set means the same form, whatever
    // default parameter the redirect added to the address (?kind=panel, ?gw=card)
    function shapeOf(f) {
        var names = [];
        f.querySelectorAll('[name]').forEach(function (el) { names.push(el.name); });
        return names.sort().join('|');
    }

    function setBusy(f, on, submitter) {
        f.classList.toggle('is-busy', on);
        f.setAttribute('aria-busy', on ? 'true' : 'false');
        if (submitter && submitter.tagName === 'BUTTON') submitter.disabled = on;
    }

    function restoreFocus(form, name, value) {
        if (!name) return;
        var cand = form.querySelectorAll('[name="' + (window.CSS && CSS.escape ? CSS.escape(name) : name) + '"]');
        for (var i = 0; i < cand.length; i++) {
            if (value === undefined || cand[i].value === value) { try { cand[i].focus({ preventScroll: true }); } catch (e) {} return; }
        }
    }

    function send(f, submitter) {
        // a second pick while the first is on its way: sent right after it, with what is on screen then
        if (busy.has(f)) { queued.add(f); return; }
        busy.add(f);
        var first = f;
        var quiet = f.getAttribute('data-ajax') === 'quiet';
        var forms = formsOf(document);
        var at = forms.indexOf(f);
        var before = outsideSig(document);
        var ae = document.activeElement;
        var focusName = ae && f.contains(ae) ? ae.name : '';
        var focusValue = ae && f.contains(ae) ? ae.value : undefined;
        var data;
        try { data = submitter ? new FormData(f, submitter) : new FormData(f); } catch (e) { data = new FormData(f); }
        setBusy(f, true, submitter);
        if (window._lb) window._lb.start();

        // getAttribute: a field called «action» (or «method») hides form.action
        fetch(new URL(f.getAttribute('action') || location.href, location.href).href, {
            method: 'POST', body: data, credentials: 'same-origin', redirect: 'follow',
            headers: { 'X-Requested-With': 'fetch' }
        }).then(function (res) {
            return res.text().then(function (html) { return { res: res, html: html }; });
        }).then(function (r) {
            var res = r.res;
            var doc = new DOMParser().parseFromString(r.html, 'text/html');
            var notes = noticesOf(doc);
            // the session ended, or the answer is not a panel page: show it as it is
            if (res.status >= 400 || /login(\.php)?(\?|$)/.test(res.url) || !doc.querySelector('.content')) {
                document.open(); document.write(r.html); document.close();
                return;
            }
            var next = formsOf(doc);
            var same = at >= 0 && next.length === forms.length && next[at] && next[at].id === f.id
                && pathnameOf(res.url) === pathnameOf(location.href) && shapeOf(next[at]) === shapeOf(f)
                && outsideSig(doc) === before;
            if (!same) {
                // another page, rows came or went, or the list behind the form
                // changed: the page loads, the message follows it. The same
                // address is reloaded in place so the scroll stays where it was.
                stash(notes);
                if (sameAddress(res.url, location.href)) {
                    location.reload();
                } else {
                    if (pathnameOf(res.url) === location.pathname) {
                        try { sessionStorage.setItem(SKEY, JSON.stringify({ p: location.pathname, y: window.pageYOffset, t: Date.now() })); } catch (e) {}
                    }
                    location.href = res.url;
                }
                return;
            }
            if (!quiet) {
                var fresh = document.importNode(next[at], true);
                fresh.classList.remove('fade-up');
                fresh.classList.add('no-rise');
                f.replaceWith(fresh);
                restoreFocus(fresh, focusName, focusValue);
                f = fresh;
            }
            show(notes);
            document.dispatchEvent(new CustomEvent('ajaxform:saved', { detail: { form: f } }));
        }).catch(function () {
            show([[(window.t && window.t('jsSaveFailed')) || 'Connection problem - nothing was saved. Try again.', 'no']]);
        }).then(function () {
            busy.delete(first);
            setBusy(f, false, submitter && document.contains(submitter) ? submitter : null);
            if (window._lb) window._lb.done();
            if (queued.has(first)) {
                queued.delete(first);
                if (document.contains(first)) send(first, null);
            }
        });
    }

    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (e.defaultPrevented || !f || f.tagName !== 'FORM') return;
        if ((f.getAttribute('method') || '').toLowerCase() !== 'post' || f.matches(SKIP)) return;
        var tg = f.getAttribute('target');
        if (tg && tg !== '_self') return;
        if (!f.closest('.content')) return;
        e.preventDefault();
        send(f, e.submitter || null);
    });

    // a form of choices: pick, and it is saved - so it has no button to press
    document.querySelectorAll('form[data-autosave] .modal-foot').forEach(function (el) { el.hidden = true; });
    var timers = new WeakMap();
    document.addEventListener('change', function (e) {
        var f = e.target && e.target.closest ? e.target.closest('form[data-autosave]') : null;
        if (!f || e.target.type === 'text' || e.target.tagName === 'TEXTAREA') return;
        clearTimeout(timers.get(f));
        timers.set(f, setTimeout(function () {
            if (f.requestSubmit) f.requestSubmit(); else HTMLFormElement.prototype.submit.call(f);
        }, 60));
    });
})();
