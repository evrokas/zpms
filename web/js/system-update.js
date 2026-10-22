/* Update from git (system_update.zetem). Talks to the JSON endpoints of
 * web/system_update.php; every string comes from window.zpmsText. The server
 * does the checking -- nothing here is trusted. */
(function () {
    'use strict';
    var root = document.getElementById('su-root');
    if (!root) return;
    var T = window.zpmsText || {};
    var $ = function (id) { return document.getElementById(id); };
    var branchEl = $('su-branch'), fwEl = $('su-fw'), fwBranchEl = $('su-fw-branch'), fwWrap = $('su-fw-branch-wrap');
    var schemaEl = $('su-schema'), updateShEl = $('su-update-sh');
    var statusEl = $('su-status'), logEl = $('su-log');
    var btnRefresh = $('su-refresh'), btnPreview = $('su-preview'), btnRun = $('su-run');
    var timer = null;

    function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; }
    function say(text, cls) { statusEl.textContent = text; statusEl.className = 'su-status' + (cls ? ' ' + cls : ''); }
    function busy(on) { [btnRefresh, btnPreview, btnRun].forEach(function (b) { if (b) b.disabled = on; }); }

    function call(method, url, body) {
        var init = { method: method, headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf() }, credentials: 'same-origin' };
        if (body) init.body = body;
        return fetch(url, init).then(function (r) { return r.json().catch(function () { return { ok: false }; }).then(function (j) { j.httpStatus = r.status; return j; }); });
    }

    function formData(extra) {
        var fd = new FormData();
        fd.append('branch', branchEl.value);
        if (fwEl && fwEl.checked) { fd.append('fw', '1'); fd.append('fw_branch', fwBranchEl.value); }
        ['su-backup', 'su-update-sh', 'su-dictionary'].forEach(function (id) {
            var el = $(id);
            if (el && el.checked) fd.append({ 'su-backup': 'backup', 'su-update-sh': 'update_sh', 'su-dictionary': 'dictionary' }[id], '1');
        });
        if (schemaEl && schemaEl.checked && updateShEl.checked) fd.append('apply_schema', '1');
        if (extra) Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
        return fd;
    }

    function fill(select, branches, current) {
        if (!select) return;
        var keep = select.value || current;
        select.textContent = '';
        (branches || []).forEach(function (b) {
            var o = document.createElement('option');
            o.value = b; o.textContent = b;
            if (b === keep) o.selected = true;
            select.appendChild(o);
        });
    }

    function syncVisibility() {
        if (fwWrap) fwWrap.hidden = !(fwEl && fwEl.checked);
        if (schemaEl) {
            schemaEl.disabled = !updateShEl.checked;
            if (schemaEl.disabled) schemaEl.checked = false;
        }
    }

    function showLog(text) {
        var atEnd = logEl.scrollTop + logEl.clientHeight >= logEl.scrollHeight - 30;
        logEl.textContent = text || '';
        if (atEnd) logEl.scrollTop = logEl.scrollHeight;
    }

    function poll(quiet) {
        call('GET', root.dataset.urlStatus).then(function (j) {
            if (!j.ok) { say(T.sysUpdNetwork || '', 'is-bad'); stopPolling(); busy(false); return; }
            showLog(j.log);
            if (j.running) { say(T.sysUpdRunning || '', ''); busy(true); return; }
            stopPolling(); busy(false);
            if (quiet === true) return;
            var res = j.last && j.last.result;
            if (res === 'success') say(T.sysUpdSuccess || '', 'is-ok');
            else if (res) say(T.sysUpdFailed || '', 'is-bad');
            else say(T.sysUpdIdle || '', '');
        }).catch(function () { say(T.sysUpdNetwork || '', 'is-bad'); stopPolling(); busy(false); });
    }
    function startPolling() { if (!timer) { timer = setInterval(poll, 2000); } poll(); }
    function stopPolling() { if (timer) { clearInterval(timer); timer = null; } }

    if (fwEl) fwEl.addEventListener('change', syncVisibility);
    if (updateShEl) updateShEl.addEventListener('change', syncVisibility);
    syncVisibility();

    btnRefresh.addEventListener('click', function () {
        busy(true); say(T.sysUpdFetching || '', '');
        call('GET', root.dataset.urlBranches).then(function (j) {
            busy(false);
            if (!j.ok) { say(j.error === 'not_set_up' ? (T.sysUpdNotSetUp || '') : (j.detail || T.sysUpdFailed || ''), 'is-bad'); return; }
            fill(branchEl, j.repos.app && j.repos.app.branches, j.repos.app && j.repos.app.current);
            if (fwBranchEl) fill(fwBranchEl, j.repos.fw && j.repos.fw.branches, j.repos.fw && j.repos.fw.current);
            say(branchEl.options.length ? '' : (T.sysUpdNoBranches || ''), '');
        }).catch(function () { busy(false); say(T.sysUpdNetwork || '', 'is-bad'); });
    });

    btnPreview.addEventListener('click', function () {
        busy(true); say(T.sysUpdPreviewing || '', ''); showLog('');
        call('POST', root.dataset.urlPreview, formData()).then(function (j) {
            busy(false);
            showLog(j.output || j.detail || '');
            say('', '');
            if (!j.ok && !j.output) say(j.detail || T.sysUpdFailed || '', 'is-bad');
        }).catch(function () { busy(false); say(T.sysUpdNetwork || '', 'is-bad'); });
    });

    btnRun.addEventListener('click', function () {
        var schema = schemaEl && schemaEl.checked && updateShEl.checked;
        if (!window.confirm(schema ? (T.sysUpdConfirmSchema || '') : (T.sysUpdConfirm || ''))) return;
        busy(true); showLog('');
        call('POST', root.dataset.urlRun, formData({ confirm: '1' })).then(function (j) {
            if (!j.ok) { busy(false); say(j.detail || T.sysUpdFailed || '', 'is-bad'); return; }
            say(T.sysUpdRunning || '', '');
            startPolling();
        }).catch(function () { busy(false); say(T.sysUpdNetwork || '', 'is-bad'); });
    });

    // opened while an update is already running: follow it
    if (root.dataset.running === '1') { busy(true); startPolling(); }
    else { poll(true); }
})();
