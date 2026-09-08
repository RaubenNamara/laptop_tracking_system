<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}
include('../config/config.php');
require_once('../includes/migrate.php');
lts_run_migrations($conn);

$page_title = 'Quick Scan';
include('../includes/header.php');
?>
<style>
.scan-wrap {
    display: grid;
    grid-template-columns: 1.05fr 1fr;
    gap: 22px;
    max-width: 1100px;
    margin: 0 auto;
    align-items: start;
    transition: grid-template-columns 0.35s ease;
}
/* When scanner is hidden, result column centers */
.scan-wrap.single-col {
    grid-template-columns: 1fr;
    max-width: 650px;
}
@media (max-width: 992px) {
    .scan-wrap { grid-template-columns: 1fr; max-width: 600px; }
    .scan-wrap.single-col { max-width: 600px; }
}

.scan-stage {
    background: var(--bg-elev);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 24px 28px;
    box-shadow: var(--shadow);
    transition: all 0.3s ease;
}
.scan-stage .stage-head {
    display:flex; justify-content: space-between; align-items: center; margin-bottom: 16px;
}
.scan-stage .stage-head h3 { margin: 0; font-size: 17px; }
#reader, #manual-pane {
    width: 100%;
    background: #000;
    border-radius: var(--radius);
    overflow: hidden;
    aspect-ratio: 1;
    max-height: 480px;
    position: relative;
}
#manual-pane {
    background: var(--bg-soft);
    aspect-ratio: auto;
    padding: 24px;
    display: none;
    flex-direction: column; gap: 12px; justify-content: center;
}
#manual-pane.show { display: flex; }
#reader video { object-fit: cover; }
#reader__scan_region img, #reader__dashboard_section_swaplink { display: none !important; }

.scan-mode {
    display: inline-flex;
    border: 1px solid var(--border);
    border-radius: 999px;
    padding: 3px;
    background: var(--bg-soft);
}
.scan-mode button {
    padding: 6px 14px;
    border-radius: 999px;
    border: 0;
    background: transparent;
    color: var(--text-muted);
    font-size: 13px; font-weight: 600; cursor: pointer;
}
.scan-mode button.active { background: var(--bg-elev); color: var(--primary); box-shadow: var(--shadow-sm); }

.scan-helper { color: var(--text-muted); font-size: 13px; margin-top: 12px; text-align: center; }
.scan-status { font-size: 13px; padding: 10px 14px; border-radius: 10px; margin-top: 10px; display: none; }
.scan-status.show { display: block; }
.scan-status.info  { background: rgba(14,165,233,.10); color: var(--info); }
.scan-status.error { background: rgba(239,68,68,.10);  color: var(--danger); }

#laptop-card { display: none; }
#laptop-card.show { display: block; animation: fadeUp .25s ease; }

.field-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    padding: 12px 0;
    border-bottom: 1px dashed var(--border-soft);
    font-size: 14px;
}
.field-row .k {
    color: var(--text-muted);
    font-weight: 600;
    flex-shrink: 0;
    min-width: 80px;
}
.field-row .v {
    color: var(--text);
    font-weight: 600;
    text-align: right;
    word-break: break-word;
    flex: 1;
}

.action-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 10px;
    margin-top: 18px;
}
.action-grid .btn { padding: 14px 12px; font-size: 14px; }
@media (max-width: 576px) {
    .action-grid { grid-template-columns: 1fr 1fr; }
    .action-grid .btn { padding: 18px 10px; font-size: 15px; }
}

.empty-scan {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    color: var(--text-muted);
    padding: 60px 24px;
    min-height: 320px;
}
.empty-scan .ico {
    width: 72px;
    height: 72px;
    border-radius: 24px;
    background: var(--primary-soft);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    margin-bottom: 16px;
}

.due-input {
    margin-top: 16px;
    padding: 14px 16px;
    background: var(--bg-soft);
    border-radius: 12px;
}

/* Usage history */
.history-block { margin-top: 20px; }
.history-block .h-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
}
.history-block .h-head h4 {
    margin: 0;
    font-size: 14px;
    font-weight: 700;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px;
}
.history-block .h-head h4 i { color: var(--primary); }
.h-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 14px;
}
.h-stats .s {
    background: var(--bg-soft);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 10px 6px;
    text-align: center;
}
.h-stats .s .n {
    font-size: 16px;
    font-weight: 800;
    color: var(--text);
    letter-spacing: -.02em;
}
.h-stats .s .l {
    font-size: 10px;
    color: var(--text-muted);
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: .04em;
    margin-top: 2px;
}

.history-list {
    max-height: 320px;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--bg-elev);
}
.history-list .row {
    display: flex;
    gap: 12px;
    padding: 10px 12px;
    border-bottom: 1px solid var(--border-soft);
}
.history-list .row:last-child { border-bottom: 0; }
.history-list .ico {
    width: 32px;
    height: 32px;
    border-radius: 10px;
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    color: #fff;
}
.history-list .ico-issued        { background: linear-gradient(135deg, #fbbf24, #f59e0b); }
.history-list .ico-returned      { background: linear-gradient(135deg, #38bdf8, #0ea5e9); }
.history-list .ico-out_for_project{ background: linear-gradient(135deg, #a78bfa, #8b5cf6); }
.history-list .ico-back_to_school{ background: linear-gradient(135deg, #34d399, #10b981); }
.history-list .ico-taken_home    { background: linear-gradient(135deg, #f87171, #ef4444); }
.history-list .body { flex: 1; min-width: 0; }
.history-list .ttl  { font-weight: 600; font-size: 13px; color: var(--text); }
.history-list .meta { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }
.history-empty { padding: 22px 12px; text-align: center; color: var(--text-muted); font-size: 13px; }

.lab-badge-scan {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700;
}
.lab-badge-scan.olevel { background: rgba(59,130,246,.12); color: #2563eb; }
.lab-badge-scan.alevel { background: rgba(139,92,246,.12); color: #7c3aed; }

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}
</style>

<div class="page-header">
    <div>
        <h2 class="title">Quick Scan</h2>
        <p class="subtitle">Point the camera at a laptop's QR code, or enter the serial manually.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="overdue.php" class="btn btn-outline"><i class="fas fa-triangle-exclamation"></i> Overdue</a>
        <a href="laptops.php" class="btn btn-outline"><i class="fas fa-laptop"></i> All Laptops</a>
    </div>
</div>

<div class="scan-wrap" id="scanWrap">
    <div class="scan-stage" id="scanStage">
        <div class="stage-head">
            <h3><i class="fas fa-camera me-2" style="color:var(--primary)"></i> Scanner</h3>
            <div class="scan-mode" role="tablist">
                <button id="modeCamera" class="active" type="button">Camera</button>
                <button id="modeManual" type="button">Manual</button>
            </div>
        </div>

        <div id="reader"></div>
        <div id="manual-pane">
            <label class="form-label">Laptop number or serial</label>
            <div class="d-flex gap-2">
                <input type="text" id="manualInput" class="form-control" placeholder="e.g. 2025-007 or LP-XYZ-001" autofocus>
                <button class="btn btn-primary" id="manualSubmit"><i class="fas fa-arrow-right"></i></button>
            </div>
            <p class="form-help">Tip: any keyboard wedge / barcode reader works here too — just scan into this field.</p>
        </div>

        <div id="scanStatus" class="scan-status info"></div>
        <p class="scan-helper">
            <i class="fas fa-circle-info"></i>
            On mobile, the back camera is preferred. Allow camera access when prompted.
        </p>
    </div>

    <div class="scan-stage" id="resultStage">
        <div class="stage-head">
            <h3><i class="fas fa-laptop me-2" style="color:var(--accent)"></i> Laptop details</h3>
            <button id="rescanBtn" class="btn btn-ghost btn-sm" style="display:none;">
                <i class="fas fa-rotate"></i> Scan another
            </button>
        </div>

        <div id="emptyResult" class="empty-scan">
            <div class="ico"><i class="fas fa-qrcode"></i></div>
            <div style="font-weight:700; color:var(--text); margin-bottom:6px;">Waiting for scan…</div>
            <div>Scan a laptop's QR code to see its details and update its status.</div>
        </div>

        <div id="laptop-card">
            <div class="field-row"><span class="k">Laptop #</span><span class="v" id="laptop-number">—</span></div>
            <div class="field-row"><span class="k">Model</span><span class="v" id="laptop-model">—</span></div>
            <div class="field-row"><span class="k">Serial</span><span class="v" id="laptop-serial">—</span></div>
            <div class="field-row"><span class="k">Student</span><span class="v" id="laptop-student">—</span></div>
            <div class="field-row"><span class="k">Class</span><span class="v" id="laptop-class">—</span></div>
            <div class="field-row"><span class="k">Stream</span><span class="v" id="laptop-stream">—</span></div>
            <div class="field-row"><span class="k">Status</span><span class="v"><span id="laptop-status" class="badge-pill">—</span></span></div>
            <div class="field-row"><span class="k">Lab</span><span class="v"><span id="laptop-lab" class="lab-badge-scan olevel">—</span></span></div>
            <div class="field-row"><span class="k">Notes</span><span class="v" id="laptop-notes">—</span></div>

            <div class="due-input" id="dueInputWrap">
                <label class="form-label">Return by (when issuing)</label>
                <input type="datetime-local" id="dueDate" class="form-control">
                <p class="form-help">Optional — used by overdue alerts. Leave empty to skip.</p>
            </div>

            <div class="action-grid">
                <button id="btn-issued" class="btn btn-warning" data-status="issued"><i class="fas fa-paper-plane"></i> Issue</button>
                <button id="btn-returned" class="btn btn-info" data-status="returned"><i class="fas fa-rotate-left"></i> Return</button>
                <button id="btn-out-project" class="btn btn-primary" data-status="out_for_project"><i class="fas fa-diagram-project"></i> For Project</button>
                <button id="btn-back-school" class="btn btn-success" data-status="back_to_school"><i class="fas fa-school"></i> In School</button>
                <button id="btn-taken-home" class="btn btn-danger" data-status="taken_home"><i class="fas fa-house"></i> Home</button>
            </div>

            <!-- Usage history -->
            <div class="history-block">
                <div class="h-head">
                    <h4><i class="fas fa-clock-rotate-left"></i> Usage history</h4>
                    <span class="text-soft" id="historyCount" style="font-size:12px;"></span>
                </div>
                <div class="h-stats" id="historyStats">
                    <div class="s"><div class="n" id="hsTotal">0</div><div class="l">Total</div></div>
                    <div class="s"><div class="n" id="hsIssued">0</div><div class="l">Issued</div></div>
                    <div class="s"><div class="n" id="hsReturned">0</div><div class="l">Returned</div></div>
                    <div class="s"><div class="n" id="hsHome">0</div><div class="l">Home</div></div>
                </div>
                <div class="history-list" id="historyList">
                    <div class="history-empty">No activity recorded yet for this laptop.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include('../includes/footer.php'); ?>

<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.7/html5-qrcode.min.js"></script>
<script>
const STATUS_LABELS = {
    issued: 'Issued', returned: 'Returned', out_for_project: 'Out for Project',
    back_to_school: 'Back to School', taken_home: 'Taken Home'
};
const ALL_BTNS = ['btn-issued','btn-returned','btn-out-project','btn-back-school','btn-taken-home'];

let scanner = null;
let currentLaptopId = null;
let currentStudentId = null;

const els = {
    scanWrap:  document.getElementById('scanWrap'),
    scanStage: document.getElementById('scanStage'),
    reader:    document.getElementById('reader'),
    manual:    document.getElementById('manual-pane'),
    modeCam:   document.getElementById('modeCamera'),
    modeMan:   document.getElementById('modeManual'),
    manualInp: document.getElementById('manualInput'),
    manualBtn: document.getElementById('manualSubmit'),
    status:    document.getElementById('scanStatus'),
    empty:     document.getElementById('emptyResult'),
    card:      document.getElementById('laptop-card'),
    rescan:    document.getElementById('rescanBtn'),
    dueWrap:   document.getElementById('dueInputWrap'),
    dueDate:   document.getElementById('dueDate'),
};

function setStatus(text, kind = 'info') {
    if (!text) { els.status.classList.remove('show'); return; }
    els.status.textContent = text;
    els.status.className = 'scan-status show ' + kind;
}

function adjustButtons(status) {
    const s = (status || '').toLowerCase().replace(/\s+/g,'_');
    ALL_BTNS.forEach(id => document.getElementById(id).style.display = 'none');
    const show = (...ids) => ids.forEach(id => document.getElementById(id).style.display = 'inline-flex');
    switch (s) {
        case 'back_to_school':  show('btn-issued','btn-out-project','btn-taken-home'); break;
        case 'taken_home':      show('btn-back-school'); break;
        case 'issued':          show('btn-returned','btn-out-project','btn-taken-home'); break;
        case 'returned':        show('btn-issued','btn-out-project','btn-taken-home'); break;
        case 'out_for_project': show('btn-returned','btn-back-school'); break;
        default:                ALL_BTNS.forEach(id => document.getElementById(id).style.display = 'inline-flex');
    }
    const showDue = ['btn-issued','btn-out-project'].some(id => document.getElementById(id).style.display !== 'none');
    els.dueWrap.style.display = showDue ? 'block' : 'none';
}

function extractSerial(text) {
    text = (text || '').trim();
    try {
        const obj = JSON.parse(text);
        if (obj && (obj.serial || obj.serial_number || obj.serialNo)) return String(obj.serial || obj.serial_number || obj.serialNo).trim();
    } catch(e) {}
    const li = text.toLowerCase().indexOf('serial:');
    if (li !== -1) return text.substring(li + 7).split(/\s*\||\n/)[0].trim();
    try {
        const url = new URL(text);
        if (url.searchParams.has('serial')) return url.searchParams.get('serial').trim();
        const parts = url.pathname.split('/').filter(Boolean);
        if (parts.length) return parts[parts.length - 1].trim();
    } catch(e) {}
    if (text.includes('|')) {
        for (const p of text.split('|').map(p=>p.trim())) {
            if (/serial/i.test(p)) return p.split(':').slice(1).join(':').trim();
        }
    }
    return text;
}

function startScanner() {
    if (scanner) try { scanner.clear(); } catch(e){}
    scanner = new Html5QrcodeScanner('reader', {
        fps: 12, qrbox: { width: 260, height: 260 },
        rememberLastUsedCamera: true,
        showTorchButtonIfSupported: true,
        videoConstraints: { facingMode: { ideal: 'environment' } }
    }, false);
    scanner.render(onScan, () => {});
}

function onScan(decoded) {
    const serial = extractSerial(decoded);
    setStatus('Scanned: ' + serial, 'info');
    fetchLaptop(serial);
    if (scanner) scanner.clear().catch(()=>{});
}

function fetchLaptop(serial) {
    setStatus('Looking up ' + serial + '…', 'info');
    fetch('fetch_laptop.php?serial=' + encodeURIComponent(serial))
        .then(r => r.text()).then(t => { try { return JSON.parse(t); } catch(e) { return { success:false, message: 'Bad response' }; } })
        .then(data => {
            if (!data.success) {
                setStatus(data.message || 'Laptop not found', 'error');
                return;
            }
            const L = data.laptop;
            currentLaptopId  = L.laptop_id ?? null;
            currentStudentId = L.student_id ?? null;
            document.getElementById('laptop-number').textContent  = L.laptop_number ?? '—';
            document.getElementById('laptop-model').textContent   = L.model ?? '—';
            document.getElementById('laptop-serial').textContent  = L.serial_number ?? '—';
            document.getElementById('laptop-student').textContent = L.student_name ?? 'No student assigned';
            document.getElementById('laptop-class').textContent   = L.student_class || '—';
            document.getElementById('laptop-stream').textContent  = L.student_stream || '—';
            const labEl = document.getElementById('laptop-lab');
            const labVal = L.lab || 'olevel';
            const labLbl = labVal === 'alevel' ? 'A-Level Cyber Lab' : 'O-Level Cyber Lab';
            labEl.textContent = labLbl;
            labEl.className = 'lab-badge-scan ' + labVal;
            document.getElementById('laptop-notes').textContent   = L.notes || 'No notes';

            const st = L.status || '';
            const stEl = document.getElementById('laptop-status');
            stEl.textContent = STATUS_LABELS[st] || st;
            stEl.className   = 'badge-pill badge-' + st;
            adjustButtons(st);

            renderHistory(data.history || [], data.stats || {});

            // Hide scanner & center result
            els.scanStage.style.display = 'none';
            els.scanWrap.classList.add('single-col');
            els.empty.style.display = 'none';
            els.card.classList.add('show');
            els.rescan.style.display = 'inline-flex';
            setStatus('', 'info');
            
            if (scanner) {
                scanner.clear().catch(()=>{});
                scanner = null;
            }
        })
        .catch(err => setStatus('Network error: ' + err.message, 'error'));
}

const ACTION_ICONS = {
    issued: 'fa-paper-plane', returned: 'fa-rotate-left',
    out_for_project: 'fa-diagram-project', back_to_school: 'fa-school',
    taken_home: 'fa-house'
};
function fmtTime(ts) {
    if (!ts) return '';
    const d = new Date(ts.replace(' ', 'T'));
    if (isNaN(d.getTime())) return ts;
    return d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}
function renderHistory(history, stats) {
    document.getElementById('hsTotal').textContent    = stats.total ?? history.length;
    document.getElementById('hsIssued').textContent   = stats.issued ?? 0;
    document.getElementById('hsReturned').textContent = stats.returned ?? 0;
    document.getElementById('hsHome').textContent     = stats.taken_home ?? 0;
    document.getElementById('historyCount').textContent = history.length
        ? 'Showing last ' + history.length + ' action' + (history.length === 1 ? '' : 's')
        : '';

    const list = document.getElementById('historyList');
    if (!history.length) {
        list.innerHTML = '<div class="history-empty">No activity recorded yet for this laptop.</div>';
        return;
    }
    list.innerHTML = history.map(h => {
        const action = (h.action || '').toLowerCase();
        const label  = STATUS_LABELS[action] || action.replace(/_/g, ' ');
        const icon   = ACTION_ICONS[action] || 'fa-circle-dot';
        const who    = h.student_name ? ' · ' + h.student_name : '';
        const staff  = h.staff_name ? ' · by ' + h.staff_name : '';
        return `
            <div class="row">
                <div class="ico ico-${action}"><i class="fas ${icon}"></i></div>
                <div class="body">
                    <div class="ttl">${label}${who}</div>
                    <div class="meta">${fmtTime(h.timestamp)}${staff}</div>
                </div>
            </div>`;
    }).join('');
}

function updateStatus(status) {
    if (!currentLaptopId) { setStatus('Scan a laptop first', 'error'); return; }
    const due = els.dueDate.value || null;
    fetch('update_status.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ laptop_id: currentLaptopId, status, due_date: due })
    }).then(r => r.json()).then(data => {
        if (!data.success) throw new Error(data.message || 'Failed');
        const stEl = document.getElementById('laptop-status');
        stEl.textContent = STATUS_LABELS[status] || status;
        stEl.className = 'badge-pill badge-' + status;
        adjustButtons(status);

        return fetch('logs_update.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ laptop_id: currentLaptopId, student_id: currentStudentId, action: status, note: '' })
        }).then(r => r.json()).then(log => {
            const sms = log.sms_sent ? ' SMS sent to parent.' : '';
            setStatus('Updated to ' + (STATUS_LABELS[status] || status) + '.' + sms, 'info');
            const serial = document.getElementById('laptop-serial').textContent;
            if (serial && serial !== '—') {
                fetch('fetch_laptop.php?serial=' + encodeURIComponent(serial))
                    .then(r => r.json())
                    .then(d => { if (d.success) renderHistory(d.history || [], d.stats || {}); })
                    .catch(()=>{});
            }
        });
    }).catch(e => setStatus('Update failed: ' + e.message, 'error'));
}

ALL_BTNS.forEach(id => document.getElementById(id).addEventListener('click', e => updateStatus(e.currentTarget.dataset.status)));

els.modeCam.addEventListener('click', () => {
    els.modeCam.classList.add('active'); els.modeMan.classList.remove('active');
    els.reader.style.display = 'block'; els.manual.classList.remove('show');
    startScanner();
});
els.modeMan.addEventListener('click', () => {
    els.modeMan.classList.add('active'); els.modeCam.classList.remove('active');
    els.reader.style.display = 'none'; els.manual.classList.add('show');
    if (scanner) try { scanner.clear(); } catch(e){}
    setTimeout(() => els.manualInp.focus(), 50);
});
els.manualBtn.addEventListener('click', () => {
    const v = els.manualInp.value.trim(); if (!v) return;
    fetchLaptop(v);
});
els.manualInp.addEventListener('keydown', e => { if (e.key === 'Enter') els.manualBtn.click(); });

els.rescan.addEventListener('click', () => {
    currentLaptopId = currentStudentId = null;
    els.card.classList.remove('show');
    els.empty.style.display = 'flex';
    els.rescan.style.display = 'none';
    els.scanStage.style.display = 'block';
    els.scanWrap.classList.remove('single-col');
    setStatus('', 'info');
    if (els.modeCam.classList.contains('active')) startScanner();
    else els.manualInp.focus();
});

startScanner();
</script>
