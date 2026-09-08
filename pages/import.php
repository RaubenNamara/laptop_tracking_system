<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
include('../config/config.php');
require_once('../includes/migrate.php');
require_once('../includes/xlsx_reader.php');
lts_run_migrations($conn);

$type     = $_POST['type'] ?? $_GET['type'] ?? 'students';
$valid    = ['students','parents','laptops'];
if (!in_array($type, $valid, true)) $type = 'students';

$flash = null;
$report = null;

/**
 * Read the uploaded file (CSV or XLSX) into [headers, rows].
 */
function read_uploaded($tmpPath, $originalName) {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'xlsx' || $ext === 'xls') {
        $rows = lts_read_xlsx($tmpPath);
        if (!$rows) return [null, []];
        $headers = array_shift($rows);
        return [$headers, $rows];
    }
    // default: CSV
    $h = fopen($tmpPath, 'r');
    if (!$h) return [null, []];
    $bom = fread($h, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($h);
    $headers = fgetcsv($h);
    $rows = [];
    while (($r = fgetcsv($h)) !== false) $rows[] = $r;
    fclose($h);
    return [$headers, $rows];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file']) && $_FILES['file']['error'] === 0) {
    $tmp = $_FILES['file']['tmp_name'];
    $orig = $_FILES['file']['name'] ?? '';
    try {
        [$headers, $allRows] = read_uploaded($tmp, $orig);
        if (!$headers) {
            $flash = ['danger', 'Could not read the uploaded file or it is empty.'];
        } else {
            $headers = array_map(fn($h) => strtolower(trim((string)$h)), $headers);
            $imported = 0; $skipped = 0; $errors = []; $rownum = 1;
            $conn->begin_transaction();

            if ($type === 'students') {
                if (!in_array('name', $headers, true)) throw new Exception("Missing required column: name");
                $stmt = $conn->prepare("INSERT INTO students (name, class, stream, parent_id) VALUES (?, ?, ?, ?)");
                foreach ($allRows as $row) {
                    $rownum++;
                    $row = array_pad($row, count($headers), '');
                    $r = array_combine($headers, array_map(fn($v) => trim((string)$v), array_slice($row, 0, count($headers))));
                    if (empty($r['name'])) { $skipped++; continue; }
                    $name = $r['name']; $class = $r['class'] ?? ''; $stream = $r['stream'] ?? '';
                    $pid = null;
                    if (!empty($r['parent_id']) && ctype_digit($r['parent_id'])) {
                        $pid = (int)$r['parent_id'];
                    } elseif (!empty($r['parent_name'])) {
                        $pname = $r['parent_name']; $pcontact = $r['parent_contact'] ?? '';
                        $find = $conn->prepare("SELECT parent_id FROM parents WHERE name = ? LIMIT 1");
                        $find->bind_param('s', $pname); $find->execute();
                        $f = $find->get_result();
                        if ($f && $row2 = $f->fetch_assoc()) { $pid = (int)$row2['parent_id']; }
                        else {
                            $ins = $conn->prepare("INSERT INTO parents (name, contact) VALUES (?, ?)");
                            $ins->bind_param('ss', $pname, $pcontact); $ins->execute();
                            $pid = (int)$ins->insert_id; $ins->close();
                        }
                        $find->close();
                    }
                    $stmt->bind_param('sssi', $name, $class, $stream, $pid);
                    if ($stmt->execute()) $imported++; else $errors[] = "Row $rownum: " . $stmt->error;
                }
                $stmt->close();
            }
            elseif ($type === 'parents') {
                if (!in_array('name', $headers, true)) throw new Exception("Missing required column: name");
                $stmt = $conn->prepare("INSERT INTO parents (name, contact) VALUES (?, ?)");
                foreach ($allRows as $row) {
                    $rownum++;
                    $row = array_pad($row, count($headers), '');
                    $r = array_combine($headers, array_map(fn($v) => trim((string)$v), array_slice($row, 0, count($headers))));
                    if (empty($r['name'])) { $skipped++; continue; }
                    $name = $r['name']; $contact = $r['contact'] ?? '';
                    $stmt->bind_param('ss', $name, $contact);
                    if ($stmt->execute()) $imported++; else $errors[] = "Row $rownum: " . $stmt->error;
                }
                $stmt->close();
            }
            elseif ($type === 'laptops') {
                if (!in_array('laptop_number', $headers, true) || !in_array('serial_number', $headers, true)) {
                    throw new Exception("Missing required columns: laptop_number, serial_number");
                }
                $stmt = $conn->prepare("INSERT INTO laptops (laptop_number, serial_number, model, status, notes) VALUES (?, ?, ?, ?, ?)");
                foreach ($allRows as $row) {
                    $rownum++;
                    $row = array_pad($row, count($headers), '');
                    $r = array_combine($headers, array_map(fn($v) => trim((string)$v), array_slice($row, 0, count($headers))));
                    if (empty($r['laptop_number']) || empty($r['serial_number'])) { $skipped++; continue; }
                    $ln = $r['laptop_number']; $sn = $r['serial_number'];
                    $model = $r['model'] ?? ''; $status = $r['status'] ?? 'back_to_school'; $notes = $r['notes'] ?? '';
                    $stmt->bind_param('sssss', $ln, $sn, $model, $status, $notes);
                    if ($stmt->execute()) $imported++; else $errors[] = "Row $rownum: " . $stmt->error;
                }
                $stmt->close();
            }

            $conn->commit();
            $report = ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
            $flash = ['success', "Import complete. {$imported} added, {$skipped} skipped" . (count($errors) ? ", " . count($errors) . " errors." : ".")];
        }
    } catch (Throwable $e) {
        if ($conn->errno === 0) @$conn->rollback();
        $flash = ['danger', 'Import failed: ' . $e->getMessage()];
    }
}

$page_title = 'Import (Excel / CSV)';
include('../includes/header.php');
?>
<style>
.imp-hero {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    border-radius: 18px; padding: 22px 24px; color: #fff;
    box-shadow: 0 10px 28px -10px rgba(79,70,229,.4);
    margin-bottom: 18px; display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
}
.imp-hero .ico { width: 56px; height: 56px; border-radius: 14px; background: rgba(255,255,255,.18); display:inline-flex; align-items:center; justify-content:center; font-size: 22px; flex-shrink: 0; }
.imp-hero .body { flex: 1; min-width: 240px; }
.imp-hero h2 { font-size: 22px; font-weight: 700; margin: 0; color: #fff; letter-spacing: -.01em; }
.imp-hero p  { margin: 4px 0 0; color: rgba(255,255,255,.8); font-size: 13.5px; }

.type-switch { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
.type-switch a {
    flex: 1; min-width: 140px;
    padding: 14px 16px; border-radius: 14px;
    background: var(--bg-elev); border: 1.5px solid var(--border); color: var(--text);
    text-decoration: none; transition: all .15s ease;
    display: flex; align-items: center; gap: 12px;
}
.type-switch a:hover { border-color: var(--primary); transform: translateY(-1px); }
.type-switch a.on { border-color: var(--primary); background: var(--primary-soft); color: var(--primary); }
.type-switch .icn { width: 36px; height: 36px; border-radius: 10px; background: var(--bg-soft); display: inline-flex; align-items: center; justify-content: center; font-size: 14px; }
.type-switch a.on .icn { background: var(--primary); color: #fff; }
.type-switch .ttl { font-weight: 700; font-size: 14px; }
.type-switch .sub { font-size: 11.5px; color: var(--text-muted); }
.type-switch a.on .sub { color: var(--primary); opacity: .85; }

.imp-grid { display: grid; grid-template-columns: 1.4fr 1fr; gap: 18px; }
@media (max-width: 992px) { .imp-grid { grid-template-columns: 1fr; } }

.imp-card {
    background: var(--bg-elev); border: 1px solid var(--border);
    border-radius: 18px; padding: 22px;
    box-shadow: 0 4px 12px -6px rgba(15,23,42,.08);
}
.imp-card h3 { font-size: 15px; font-weight: 700; margin: 0 0 14px; color: var(--text); letter-spacing: -.01em; }

/* Drag & drop zone */
.dropzone {
    position: relative; border: 2px dashed var(--border); border-radius: 16px;
    padding: 36px 20px; text-align: center; cursor: pointer;
    background: var(--bg-soft); transition: all .15s ease;
}
.dropzone:hover, .dropzone.drag { border-color: var(--primary); background: var(--primary-soft); }
.dropzone .dz-ico {
    width: 64px; height: 64px; border-radius: 16px; margin: 0 auto 12px;
    background: var(--bg-elev); border: 1px solid var(--border);
    display:inline-flex; align-items:center; justify-content:center;
    font-size: 26px; color: var(--primary);
    box-shadow: 0 4px 12px -4px rgba(79,70,229,.18);
}
.dropzone .dz-title { font-size: 15px; font-weight: 700; color: var(--text); margin: 0 0 4px; }
.dropzone .dz-sub   { font-size: 12.5px; color: var(--text-muted); }
.dropzone .dz-types { display: inline-flex; gap: 6px; margin-top: 10px; flex-wrap: wrap; justify-content: center; }
.dropzone .dz-types span { background: var(--bg-elev); border: 1px solid var(--border); color: var(--text-muted); padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; }
.dropzone input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.file-chip {
    display: none; align-items: center; gap: 10px; padding: 12px 14px;
    background: var(--primary-soft); border: 1px solid var(--primary); border-radius: 12px;
    margin-top: 14px; color: var(--primary);
}
.file-chip.show { display: flex; }
.file-chip .name { flex: 1; font-weight: 600; font-size: 13.5px; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.file-chip .size { font-size: 12px; color: var(--text-muted); }
.file-chip button { background: transparent; border: 0; color: var(--danger); cursor: pointer; padding: 4px; }

.btn-go {
    width: 100%; margin-top: 16px; padding: 13px 18px; border: 0; cursor: pointer;
    background: linear-gradient(135deg, #4f46e5, #7c3aed); color: #fff; border-radius: 12px;
    font-weight: 700; font-size: 14px; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    box-shadow: 0 8px 22px -8px rgba(79,70,229,.55); transition: transform .12s ease, filter .12s ease;
}
.btn-go:hover { transform: translateY(-1px); filter: brightness(1.05); }
.btn-go:disabled { opacity: .55; cursor: not-allowed; transform: none; }

/* Result mini stats */
.imp-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 14px; }
.imp-stats .s { background: var(--bg-soft); border: 1px solid var(--border); border-radius: 12px; padding: 12px; text-align: center; }
.imp-stats .s .n { font-size: 22px; font-weight: 800; letter-spacing: -.02em; }
.imp-stats .s .l { font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: .06em; margin-top: 2px; }
.imp-stats .s.s-ok  .n { color: var(--success); }
.imp-stats .s.s-skip .n { color: var(--warning); }
.imp-stats .s.s-err  .n { color: var(--danger); }

.col-list { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0 4px; }
.col-list code {
    background: var(--bg-soft); padding: 4px 10px; border-radius: 8px;
    font-size: 12px; color: var(--text); font-family: 'SF Mono', monospace;
    border: 1px solid var(--border);
}
.col-list code.req { color: var(--primary); border-color: var(--primary); background: var(--primary-soft); font-weight: 700; }

.tip-list { padding-left: 18px; margin: 8px 0 0; color: var(--text-muted); font-size: 13px; }
.tip-list li { margin-bottom: 6px; }

.dl-template {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 14px; border-radius: 10px; text-decoration: none;
    background: var(--bg-soft); border: 1px solid var(--border);
    color: var(--text); font-weight: 600; font-size: 13px; margin-top: 8px;
    transition: all .15s ease;
}
.dl-template:hover { background: var(--primary-soft); color: var(--primary); border-color: var(--primary); }
</style>

<div class="imp-hero">
    <div class="ico"><i class="fas fa-file-arrow-up"></i></div>
    <div class="body">
        <h2>Bulk Upload — Excel or CSV</h2>
        <p>Add many students, parents or laptops in one go. Excel (.xlsx/.xls) and CSV files are supported.</p>
    </div>
    <a href="dashboard.php" class="btn btn-outline" style="background: rgba(255,255,255,.14); color:#fff; border-color: rgba(255,255,255,.3);"><i class="fas fa-arrow-left"></i> Dashboard</a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash[0] ?>"><i class="fas fa-circle-info"></i> <?= htmlspecialchars($flash[1]) ?></div>
<?php endif; ?>

<div class="type-switch">
    <a href="?type=students" class="<?= $type==='students'?'on':'' ?>">
        <span class="icn"><i class="fas fa-user-graduate"></i></span>
        <div><div class="ttl">Students</div><div class="sub">Names, class &amp; parent links</div></div>
    </a>
    <a href="?type=parents" class="<?= $type==='parents'?'on':'' ?>">
        <span class="icn"><i class="fas fa-users"></i></span>
        <div><div class="ttl">Parents</div><div class="sub">Names &amp; phone numbers</div></div>
    </a>
    <a href="?type=laptops" class="<?= $type==='laptops'?'on':'' ?>">
        <span class="icn"><i class="fas fa-laptop"></i></span>
        <div><div class="ttl">Laptops</div><div class="sub">Inventory &amp; serial numbers</div></div>
    </a>
</div>

<div class="imp-grid">
    <!-- LEFT: drop zone -->
    <div class="imp-card">
        <h3>Upload your file</h3>
        <form method="POST" enctype="multipart/form-data" id="impForm">
            <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">

            <div class="dropzone" id="dz">
                <div class="dz-ico"><i class="fas fa-cloud-arrow-up"></i></div>
                <div class="dz-title">Drag &amp; drop your file here</div>
                <div class="dz-sub">or click anywhere in this box to browse</div>
                <div class="dz-types"><span>.xlsx</span><span>.xls</span><span>.csv</span></div>
                <input type="file" name="file" id="fileInput" accept=".csv,.xlsx,.xls,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            </div>

            <div class="file-chip" id="fileChip">
                <i class="fas fa-file-lines"></i>
                <div class="name" id="fileName">—</div>
                <div class="size" id="fileSize"></div>
                <button type="button" id="fileClear" title="Remove"><i class="fas fa-xmark"></i></button>
            </div>

            <button type="submit" class="btn-go" id="goBtn" disabled>
                <i class="fas fa-bolt"></i> Upload &amp; Import
            </button>
        </form>

        <?php if ($report): ?>
            <div class="imp-stats">
                <div class="s s-ok"><div class="n"><?= $report['imported'] ?></div><div class="l">Imported</div></div>
                <div class="s s-skip"><div class="n"><?= $report['skipped'] ?></div><div class="l">Skipped</div></div>
                <div class="s s-err"><div class="n"><?= count($report['errors']) ?></div><div class="l">Errors</div></div>
            </div>
            <?php if (!empty($report['errors'])): ?>
                <details style="margin-top:12px;">
                    <summary style="cursor:pointer; color: var(--danger); font-weight:600; font-size:13px;">View error details</summary>
                    <ul class="tip-list" style="max-height:200px; overflow:auto;"><?php foreach ($report['errors'] as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
                </details>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- RIGHT: instructions + template -->
    <div class="imp-card">
        <h3>Expected columns</h3>
        <?php if ($type === 'students'): ?>
            <div class="col-list">
                <code class="req">name</code>
                <code>class</code>
                <code>stream</code>
                <code>parent_id</code>
                <code>parent_name</code>
                <code>parent_contact</code>
            </div>
            <p class="muted" style="font-size:12.5px;">Columns marked in indigo are required. If <code>parent_name</code> is provided, the system will find or create the parent automatically.</p>
            <a class="dl-template" href="data:text/csv;charset=utf-8,name,class,stream,parent_name,parent_contact%0AJohn%20Doe,S2,Sciences,Mary%20Doe,%2B256700000001%0AJane%20Smith,S3,Arts,Paul%20Smith,%2B256700000002" download="students_template.csv">
                <i class="fas fa-file-arrow-down"></i> Download CSV template
            </a>
        <?php elseif ($type === 'parents'): ?>
            <div class="col-list">
                <code class="req">name</code>
                <code class="req">contact</code>
            </div>
            <p class="muted" style="font-size:12.5px;">Phone numbers should include the country code, e.g. <code>+256700000000</code>.</p>
            <a class="dl-template" href="data:text/csv;charset=utf-8,name,contact%0AMary%20Doe,%2B256700000001%0APaul%20Smith,%2B256700000002" download="parents_template.csv">
                <i class="fas fa-file-arrow-down"></i> Download CSV template
            </a>
        <?php else: ?>
            <div class="col-list">
                <code class="req">laptop_number</code>
                <code class="req">serial_number</code>
                <code>model</code>
                <code>status</code>
                <code>notes</code>
            </div>
            <p class="muted" style="font-size:12.5px;">Status options: <code>back_to_school</code>, <code>issued</code>, <code>returned</code>, <code>out_for_project</code>, <code>taken_home</code>.</p>
            <a class="dl-template" href="data:text/csv;charset=utf-8,laptop_number,serial_number,model,status,notes%0A2026-001,SN-A001,Dell%20Latitude%205420,back_to_school,New%0A2026-002,SN-A002,HP%20EliteBook%20840,back_to_school," download="laptops_template.csv">
                <i class="fas fa-file-arrow-down"></i> Download CSV template
            </a>
        <?php endif; ?>

        <h3 style="margin-top:18px;">Tips</h3>
        <ul class="tip-list">
            <li><b>Excel users:</b> just upload your <code>.xlsx</code> file directly — no need to save as CSV.</li>
            <li>The first row must be the column headers shown above.</li>
            <li>Phone numbers: prefer international format (<code>+256…</code>); local <code>07xx…</code> numbers are auto-converted.</li>
            <li>Rows missing required fields are skipped (counted in the report).</li>
        </ul>
    </div>
</div>

<script>
(function(){
    const dz = document.getElementById('dz');
    const fi = document.getElementById('fileInput');
    const chip = document.getElementById('fileChip');
    const name = document.getElementById('fileName');
    const size = document.getElementById('fileSize');
    const clear = document.getElementById('fileClear');
    const go = document.getElementById('goBtn');

    function fmt(n){ if(n<1024) return n+' B'; if(n<1048576) return (n/1024).toFixed(1)+' KB'; return (n/1048576).toFixed(1)+' MB'; }
    function refresh(){
        const f = fi.files[0];
        if (f) { name.textContent = f.name; size.textContent = fmt(f.size); chip.classList.add('show'); go.disabled = false; }
        else   { chip.classList.remove('show'); go.disabled = true; }
    }
    fi.addEventListener('change', refresh);
    clear.addEventListener('click', () => { fi.value = ''; refresh(); });

    ['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('drag'); }));
    ['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('drag'); }));
    dz.addEventListener('drop', e => { if (e.dataTransfer.files.length) { fi.files = e.dataTransfer.files; refresh(); } });
})();
</script>

<?php include('../includes/footer.php'); ?>
