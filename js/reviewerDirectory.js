/**
 * @file plugins/generic/reviewerDirectory/js/reviewerDirectory.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Reviewer directory page: tabs, search, ORCID filter, column picker, sorting and CSV
 * export. Events are delegated, since the script can run before the page body exists.
 */
(function () {
    var LS_COLS = 'rdVisibleCols';
    function $(sel, root) { return (root || document).querySelector(sel); }
    function all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function dirRows() { return all('#rd-table-directory tr[data-rd-row]'); }

    // -------- Tabs --------
    function activateTab(name) {
        all('.rd-tab-btn').forEach(function (b) { b.classList.toggle('rd-active', b.getAttribute('data-tab') === name); });
        all('.rd-panel').forEach(function (p) { p.classList.toggle('rd-active', p.getAttribute('data-panel') === name); });
    }

    // -------- Search and filter --------
    function applyFilter() {
        var box = $('#rd-search');
        var orcidOnly = $('#rd-orcid');
        var terms = (box ? box.value.trim().toLowerCase() : '').split(/\s+/).filter(Boolean);
        var wantOrcid = orcidOnly ? orcidOnly.checked : false;
        var shown = 0;
        dirRows().forEach(function (tr) {
            var hay = tr.getAttribute('data-search') || '';
            var hasOrcid = tr.getAttribute('data-orcid') === '1';
            var match = terms.every(function (t) { return hay.indexOf(t) !== -1; });
            if (wantOrcid && !hasOrcid) match = false;
            tr.style.display = match ? '' : 'none';
            if (match) shown++;
        });
        var counter = $('#rd-shown');
        if (counter) counter.textContent = shown;
    }

    // -------- Column picker --------
    function applyColumn(key, visible) {
        all('.rd-col-' + key).forEach(function (el) { el.classList.toggle('rd-hidden', !visible); });
    }
    function saveCols() {
        var state = {};
        all('.rd-colpicker input[data-col-key]').forEach(function (cb) { state[cb.getAttribute('data-col-key')] = cb.checked; });
        try { localStorage.setItem(LS_COLS, JSON.stringify(state)); } catch (e) {}
    }
    function loadCols() {
        var state = null;
        try { state = JSON.parse(localStorage.getItem(LS_COLS) || 'null'); } catch (e) {}
        all('.rd-colpicker input[data-col-key]').forEach(function (cb) {
            var key = cb.getAttribute('data-col-key');
            if (state && Object.prototype.hasOwnProperty.call(state, key)) {
                cb.checked = !!state[key];
            }
            applyColumn(key, cb.checked);
        });
    }

    // -------- Sorting --------
    function sortBy(th) {
        var table = th.closest('table');
        var idx = parseInt(th.getAttribute('data-col'), 10);
        var type = th.getAttribute('data-sort');
        var asc = !th.classList.contains('rd-asc');
        all('thead th', table).forEach(function (h) { h.classList.remove('rd-asc', 'rd-desc'); });
        th.classList.add(asc ? 'rd-asc' : 'rd-desc');
        var tbody = table.querySelector('tbody');
        var trs = all('tr[data-rd-row]', table);
        trs.sort(function (a, b) {
            var ac = a.children[idx], bc = b.children[idx];
            var av = ac.getAttribute('data-val'); if (av === null) av = ac.textContent.trim();
            var bv = bc.getAttribute('data-val'); if (bv === null) bv = bc.textContent.trim();
            if (type === 'num') { return asc ? (parseFloat(av) || 0) - (parseFloat(bv) || 0) : (parseFloat(bv) || 0) - (parseFloat(av) || 0); }
            return asc ? String(av).localeCompare(bv) : String(bv).localeCompare(av);
        });
        trs.forEach(function (tr) { tbody.appendChild(tr); });
    }

    // -------- CSV export (opens in spreadsheet applications) --------
    function cellText(td) {
            var t = td.getAttribute('data-export');
        if (t === null) t = td.textContent.replace(/\s+/g, ' ').trim();
        return t;
    }
    function exportTable(table, filename) {
        var ths = all('thead th', table);
        var visibleIdx = [];
        ths.forEach(function (th, i) { if (!th.classList.contains('rd-hidden')) visibleIdx.push(i); });
        var lines = [];
        lines.push(visibleIdx.map(function (i) { return csv(ths[i].textContent.trim()); }).join(';'));
        all('tbody tr', table).forEach(function (tr) {
            if (tr.style.display === 'none') return;
            lines.push(visibleIdx.map(function (i) { return csv(cellText(tr.children[i])); }).join(';'));
        });
        var content = 'sep=;\r\n' + lines.join('\r\n');
        download(filename, '﻿' + content);
    }
    function csv(v) { v = String(v == null ? '' : v); return /[";\r\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }
    function download(filename, text) {
        var blob = new Blob([text], { type: 'text/csv;charset=utf-8;' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        document.body.appendChild(a); a.click();
        setTimeout(function () { document.body.removeChild(a); URL.revokeObjectURL(a.href); }, 100);
    }

    // -------- Events (delegated) --------
    document.addEventListener('input', function (e) { if (e.target && e.target.id === 'rd-search') applyFilter(); });
    document.addEventListener('change', function (e) {
        if (e.target && e.target.id === 'rd-orcid') applyFilter();
        if (e.target && e.target.getAttribute && e.target.getAttribute('data-col-key')) {
            applyColumn(e.target.getAttribute('data-col-key'), e.target.checked); saveCols();
        }
    });
    document.addEventListener('click', function (e) {
        var t = e.target;
        var th = t.closest ? t.closest('th[data-sort]') : null;
        if (th) { sortBy(th); return; }
        var tabBtn = t.closest ? t.closest('.rd-tab-btn') : null;
        if (tabBtn) { activateTab(tabBtn.getAttribute('data-tab')); return; }
        var exp = t.closest ? t.closest('[data-export-table]') : null;
        if (exp) {
            var table = $('#' + exp.getAttribute('data-export-table'));
            if (table) exportTable(table, exp.getAttribute('data-export-name') || 'export.csv');
        }
    });

    function init() {
        if (!$('.rd-wrapper')) return;
        loadCols();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
        window.addEventListener('load', init);
    } else { init(); }
})();
