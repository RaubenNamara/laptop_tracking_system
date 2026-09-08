/* table-tools.js — auto-enhances every table on the page:
   - injects a search box + CSV export button + row counter above each .table
   - filters rows live as you type
   - exports the visible (filtered) rows to CSV
   - global '/' shortcut focuses the first search box; 'Esc' clears it
   - exposes window.ltsToast(msg, type) for toast notifications
*/
(function () {
    'use strict';

    // ---------- Toasts ----------
    function ensureToastHost() {
        var host = document.getElementById('lts-toasts');
        if (!host) {
            host = document.createElement('div');
            host.id = 'lts-toasts';
            host.className = 'lts-toasts';
            document.body.appendChild(host);
        }
        return host;
    }
    window.ltsToast = function (msg, type) {
        type = type || 'info';
        var host = ensureToastHost();
        var t = document.createElement('div');
        t.className = 'lts-toast lts-toast-' + type;
        var icon = ({success:'fa-circle-check', danger:'fa-circle-exclamation', warning:'fa-triangle-exclamation', info:'fa-circle-info'})[type] || 'fa-circle-info';
        t.innerHTML = '<i class="fas ' + icon + '"></i><span>' + String(msg).replace(/</g,'&lt;') + '</span><button class="lts-toast-x" aria-label="Close">&times;</button>';
        host.appendChild(t);
        requestAnimationFrame(function(){ t.classList.add('show'); });
        var hide = function(){ t.classList.remove('show'); setTimeout(function(){ t.remove(); }, 250); };
        t.querySelector('.lts-toast-x').addEventListener('click', hide);
        setTimeout(hide, 4500);
    };

    // ---------- Table enhancements ----------
    function csvEscape(v) {
        v = (v == null ? '' : String(v)).replace(/\s+/g, ' ').trim();
        if (/[",\n]/.test(v)) v = '"' + v.replace(/"/g, '""') + '"';
        return v;
    }

    function exportTableCsv(table, filename) {
        var rows = [];
        var headers = [];
        table.querySelectorAll('thead th').forEach(function(th){ headers.push(csvEscape(th.innerText)); });
        if (headers.length) rows.push(headers.join(','));
        table.querySelectorAll('tbody tr').forEach(function(tr){
            if (tr.style.display === 'none') return;
            var row = [];
            tr.querySelectorAll('td').forEach(function(td, i){
                // Skip last "Actions" column — usually icons, not useful in CSV
                if (i === tr.children.length - 1 && /action/i.test(headers[headers.length-1] || '')) return;
                row.push(csvEscape(td.innerText));
            });
            rows.push(row.join(','));
        });
        var blob = new Blob(["\uFEFF" + rows.join('\n')], {type: 'text/csv;charset=utf-8;'});
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url; a.download = filename; document.body.appendChild(a); a.click();
        setTimeout(function(){ URL.revokeObjectURL(url); a.remove(); }, 200);
        if (window.ltsToast) ltsToast('Exported ' + filename, 'success');
    }

    function filterTable(table, q) {
        q = (q || '').toLowerCase().trim();
        var visible = 0, total = 0;
        table.querySelectorAll('tbody tr').forEach(function(tr){
            total++;
            if (!q) { tr.style.display = ''; visible++; return; }
            var text = tr.innerText.toLowerCase();
            if (text.indexOf(q) !== -1) { tr.style.display = ''; visible++; }
            else { tr.style.display = 'none'; }
        });
        return { visible: visible, total: total };
    }

    function enhance(table) {
        if (table.dataset.ltsEnhanced) return;
        if (table.rows.length < 2) return; // empty table — skip
        table.dataset.ltsEnhanced = '1';

        // Wrapper: insert toolbar BEFORE the table-wrapper/responsive container, fallback to table parent
        var wrapper = table.closest('.table-wrapper, .table-responsive, .table-card, .table-wrap') || table.parentElement;
        if (!wrapper) return;

        var bar = document.createElement('div');
        bar.className = 'tbl-toolbar';
        var fileSlug = (document.title || 'export').split('·')[0].trim().toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'') || 'export';
        bar.innerHTML =
            '<div class="tbl-search">' +
                '<i class="fas fa-search"></i>' +
                '<input type="search" placeholder="Search this table…  ( press / to focus )" autocomplete="off">' +
            '</div>' +
            '<div class="tbl-meta"><span class="tbl-count">0</span></div>' +
            '<button type="button" class="tbl-export" title="Export visible rows to CSV">' +
                '<i class="fas fa-file-csv"></i><span>Export CSV</span>' +
            '</button>';
        wrapper.parentNode.insertBefore(bar, wrapper);

        var input = bar.querySelector('input');
        var count = bar.querySelector('.tbl-count');
        var btn   = bar.querySelector('.tbl-export');

        function refresh() {
            var r = filterTable(table, input.value);
            count.textContent = (r.visible === r.total)
                ? r.total + ' row' + (r.total === 1 ? '' : 's')
                : r.visible + ' of ' + r.total + ' rows';
        }
        input.addEventListener('input', refresh);
        btn.addEventListener('click', function(){ exportTableCsv(table, fileSlug + '-' + new Date().toISOString().slice(0,10) + '.csv'); });
        refresh();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('table.table').forEach(enhance);

        // Global '/' to focus the first search box, Esc to clear
        document.addEventListener('keydown', function (e) {
            var tag = (e.target && e.target.tagName) || '';
            var typing = /^(INPUT|TEXTAREA|SELECT)$/i.test(tag) || (e.target && e.target.isContentEditable);
            if (e.key === '/' && !typing) {
                var first = document.querySelector('.tbl-search input');
                if (first) { e.preventDefault(); first.focus(); first.select(); }
            } else if (e.key === 'Escape' && typing && e.target.matches('.tbl-search input')) {
                e.target.value = ''; e.target.dispatchEvent(new Event('input'));
            }
        });
    });
})();
