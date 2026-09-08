<?php
// pages/laptops.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

include('../config/config.php');
require_once('../includes/migrate.php');
lts_run_migrations($conn);
include('../assets/phpqrcode/qrlib.php');

function normalize_status($s) {
    $s = trim((string)($s ?? ''));
    $s = strtolower($s);
    $s = str_replace(' ', '_', $s);
    $s = preg_replace('/[^a-z0-9_]/', '', $s);
    return $s === '' ? 'out_for_project' : $s;
}

function set_flash($type, $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

$VALID_STATUSES = ['back_to_school','taken_home','issued','out_for_project','returned'];

$uploadDir = __DIR__ . "/../uploads/laptops/";
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
$qrDir = __DIR__ . "/../qrcodes/";
if (!is_dir($qrDir)) mkdir($qrDir, 0777, true);

function ensure_owner_column($conn) {
    $res = $conn->query("SHOW COLUMNS FROM laptops LIKE 'owner_id'");
    if (!$res || $res->num_rows === 0) {
        $conn->query("ALTER TABLE laptops ADD COLUMN owner_id VARCHAR(255) NULL");
    }
}
ensure_owner_column($conn);

function generateLaptopNumber($conn) {
    $year = date('Y');
    $like = $conn->real_escape_string($year . '-%');
    $res = $conn->query("SELECT laptop_number FROM laptops WHERE laptop_number LIKE '{$like}' ORDER BY laptop_number DESC LIMIT 1");
    if ($res && $res->num_rows > 0) {
        $last = $res->fetch_assoc()['laptop_number'];
        $lastNumber = (int)substr($last, strlen($year) + 1);
        $nextNumber = $lastNumber + 1;
    } else {
        $nextNumber = 1;
    }
    return $year . '-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
}

function generate_laptop_qr_file($laptop_number) {
    if (empty($laptop_number)) {
        return null;
    }

    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$laptop_number);
    $path = 'qrcodes/LAPTOP_' . $safe . '.png';
    $abs  = __DIR__ . '/../' . $path;

    @QRcode::png((string)$laptop_number, $abs, QR_ECLEVEL_L, 4);

    return file_exists($abs) ? $path : null;
}

function log_laptop_status($conn, $laptop_id, $student_id, $action, $staff_name = null) {
    $staff_name = $staff_name ?? ($_SESSION['username'] ?? 'system');
    if ($conn->query("SHOW TABLES LIKE 'logs'")->num_rows > 0) {
        $stmt = $conn->prepare("INSERT INTO logs (laptop_id, student_id, action, staff_name, timestamp) VALUES (?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("iiss", $laptop_id, $student_id, $action, $staff_name);
            $stmt->execute();
            $stmt->close();
        }
    }
}

// ── Derive lab from student class ─────────────────────────────────────────
function get_lab_from_student($conn, $student_id) {
    if (!$student_id) return 'olevel';
    $stmt = $conn->prepare("SELECT class FROM students WHERE student_id = ? LIMIT 1");
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return lts_lab_for_class($r['class'] ?? '');
}

// ========================= QUICK QR / SCAN RETURN HANDLER =========================
if (isset($_POST['scan_qr'])) {
    $qr_input = trim($_POST['qr_input'] ?? '');
    $staff_name = $_SESSION['username'] ?? 'Unknown';

    if ($qr_input === '') {
        set_flash('danger', 'Please scan or enter laptop number or serial.');
        header("Location: laptops.php");
        exit();
    }

    $stmt = $conn->prepare("SELECT * FROM laptops WHERE laptop_number = ? OR serial_number = ? LIMIT 1");
    $stmt->bind_param("ss", $qr_input, $qr_input);
    $stmt->execute();
    $laptop = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$laptop) {
        set_flash('danger', 'Laptop not found.');
        header("Location: laptops.php");
        exit();
    }

    $laptop_id  = (int)$laptop['laptop_id'];
    $student_id = (int)$laptop['student_id'];
    $current_status = normalize_status($laptop['status'] ?? '');

    if (in_array($current_status, ['issued','out_for_project'], true)) {
        $new_status = 'returned';
        $stmt = $conn->prepare("UPDATE laptops SET student_id = NULL, status = ? WHERE laptop_id = ?");
        $stmt->bind_param("si", $new_status, $laptop_id);
        $stmt->execute();
        $stmt->close();
        log_laptop_status($conn, $laptop_id, $student_id, 'Returned', $staff_name);
        $lno = htmlspecialchars($laptop['laptop_number'] ?? $qr_input);
        set_flash('success', "Laptop {$lno} marked as returned.");
    } else {
        set_flash('warning', 'Quick return only works for ISSUED or OUT FOR PROJECT laptops.');
    }
    header("Location: laptops.php");
    exit();
}

// ========================= ADD LAPTOP =========================
if (isset($_POST['add_laptop'])) {
    $student_id    = intval($_POST['student_id'] ?? 0);
    $model         = trim($_POST['model'] ?? '');
    $core          = trim($_POST['core'] ?? '');
    $generation    = trim($_POST['generation'] ?? '');
    $ram           = trim($_POST['ram'] ?? '');
    $rom           = trim($_POST['rom'] ?? '');
    $serial_number = trim($_POST['serial_number'] ?? '');
    $owner_id      = trim($_POST['pc_no'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');
    $status        = normalize_status($_POST['status'] ?? 'out_for_project');
    if (!in_array($status, $VALID_STATUSES, true)) $status = 'out_for_project';

    // Lab: prefer explicit POST value, otherwise derive from student class
    $lab_post = trim($_POST['lab'] ?? '');
    $lab = in_array($lab_post, ['olevel','alevel'], true) ? $lab_post : get_lab_from_student($conn, $student_id);

    if (!$student_id || $serial_number === '') {
        set_flash('danger', 'Please select a student and provide a serial number.');
        header("Location: laptops.php");
        exit();
    }

    $check = $conn->prepare("SELECT laptop_id FROM laptops WHERE serial_number = ?");
    $check->bind_param("s", $serial_number);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
        $check->close();
        set_flash('danger', 'Serial number already exists.');
        header("Location: laptops.php");
        exit();
    }
    $check->close();

    $laptop_number = generateLaptopNumber($conn);

    $image1 = $image2 = $image3 = null;
    for ($i = 1; $i <= 3; $i++) {
        if (!empty($_FILES["laptop_image{$i}"]['name'])) {
            ${"image{$i}"} = time() . "_{$i}_" . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES["laptop_image{$i}"]['name']));
            move_uploaded_file($_FILES["laptop_image{$i}"]['tmp_name'], $uploadDir . ${"image{$i}"});
        }
    }

    $qr_file = generate_laptop_qr_file($laptop_number);

    $stmt = $conn->prepare("INSERT INTO laptops
        (laptop_number, student_id, model, core, generation, ram, rom, serial_number, owner_id, status, lab, qr_code_path, laptop_image1, laptop_image2, laptop_image3, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        set_flash('danger', 'DB prepare failed: ' . $conn->error);
        header("Location: laptops.php");
        exit();
    }
    $stmt->bind_param(
        "sissssssssssssss",
        $laptop_number, $student_id, $model, $core, $generation, $ram, $rom,
        $serial_number, $owner_id, $status, $lab, $qr_file, $image1, $image2, $image3, $notes
    );

    if ($stmt->execute()) {
        $laptop_id = $conn->insert_id;
        $stmt->close();
        log_laptop_status($conn, $laptop_id, $student_id, ucwords(str_replace('_',' ',$status)));
        set_flash('success', 'Laptop added. Laptop #: ' . htmlspecialchars($laptop_number) . ' — Lab: ' . lts_lab_label($lab));
        header("Location: laptops.php");
        exit();
    } else {
        $err = $stmt->error;
        $stmt->close();
        set_flash('danger', 'Failed to add laptop: ' . $err);
        header("Location: laptops.php");
        exit();
    }
}

// ========================= DELETE LAPTOP =========================
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM laptops WHERE laptop_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        set_flash('success', 'Laptop deleted.');
    }
    header("Location: laptops.php");
    exit();
}

// ========================= FETCH FOR EDIT =========================
$editLaptop = null;
if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $res = $conn->query("SELECT * FROM laptops WHERE laptop_id = $id LIMIT 1");
    if ($res && $res->num_rows > 0) $editLaptop = $res->fetch_assoc();
}

// ========================= UPDATE LAPTOP =========================
if (isset($_POST['update_laptop'])) {
    $laptop_id     = intval($_POST['laptop_id'] ?? 0);
    $student_id    = intval($_POST['student_id'] ?? 0);
    $model         = trim($_POST['model'] ?? '');
    $core          = trim($_POST['core'] ?? '');
    $generation    = trim($_POST['generation'] ?? '');
    $ram           = trim($_POST['ram'] ?? '');
    $rom           = trim($_POST['rom'] ?? '');
    $serial_number = trim($_POST['serial_number'] ?? '');
    $owner_id      = trim($_POST['pc_no'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');
    $status        = normalize_status($_POST['status'] ?? 'out_for_project');
    if (!in_array($status, $VALID_STATUSES, true)) $status = 'out_for_project';

    $lab_post = trim($_POST['lab'] ?? '');
    $lab = in_array($lab_post, ['olevel','alevel'], true) ? $lab_post : get_lab_from_student($conn, $student_id);

    $res = $conn->query("SELECT * FROM laptops WHERE laptop_id = $laptop_id LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        set_flash('danger', 'Laptop not found.');
        header("Location: laptops.php");
        exit();
    }
    $existing = $res->fetch_assoc();

    $image1 = $existing['laptop_image1'];
    $image2 = $existing['laptop_image2'];
    $image3 = $existing['laptop_image3'];
    for ($i = 1; $i <= 3; $i++) {
        if (!empty($_FILES["laptop_image{$i}"]['name'])) {
            ${"image{$i}"} = time() . "_{$i}_" . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES["laptop_image{$i}"]['name']));
            move_uploaded_file($_FILES["laptop_image{$i}"]['tmp_name'], $uploadDir . ${"image{$i}"});
        }
    }

    $qr_file = generate_laptop_qr_file($existing['laptop_number']) ?: $existing['qr_code_path'];

    $stmt = $conn->prepare("UPDATE laptops SET student_id=?, model=?, core=?, generation=?, ram=?, rom=?, serial_number=?, owner_id=?, status=?, lab=?, qr_code_path=?, laptop_image1=?, laptop_image2=?, laptop_image3=?, notes=? WHERE laptop_id=?");
    if (!$stmt) {
        set_flash('danger', 'DB prepare failed: ' . $conn->error);
        header("Location: laptops.php");
        exit();
    }

    $stmt->bind_param(
        "issssssssssssssi",
        $student_id, $model, $core, $generation, $ram, $rom,
        $serial_number, $owner_id, $status, $lab, $qr_file, $image1, $image2, $image3, $notes, $laptop_id
    );

    if ($stmt->execute()) {
        $stmt->close();
        if ($status !== ($existing['status'] ?? '')) {
            log_laptop_status($conn, $laptop_id, $student_id, ucwords(str_replace('_',' ',$status)));
        }
        set_flash('success', 'Laptop updated. Lab: ' . lts_lab_label($lab));
        header("Location: laptops.php");
        exit();
    } else {
        $err = $stmt->error;
        $stmt->close();
        set_flash('danger', 'Failed to update laptop: ' . $err);
        header("Location: laptops.php");
        exit();
    }
}

// ========================= FETCH LAPTOPS & STUDENTS =========================
$laptops = $conn->query("SELECT l.*, s.name AS student_name, s.class AS student_class, s.stream AS student_stream FROM laptops l LEFT JOIN students s ON l.student_id = s.student_id ORDER BY l.laptop_id DESC");

// Auto-regenerate only missing or broken QR codes when needed.
if ($laptops && $laptops->num_rows > 0) {
    $rows_for_qr = $laptops->fetch_all(MYSQLI_ASSOC);
    foreach ($rows_for_qr as $lr) {
        $lnum  = trim((string)($lr['laptop_number'] ?? ''));
        $lpath = trim((string)($lr['qr_code_path'] ?? ''));
        if ($lnum === '') continue;

        $absStored = $lpath !== '' ? __DIR__ . '/../' . ltrim($lpath, '/\\') : '';
        if ($lpath !== '' && file_exists($absStored)) {
            continue;
        }

        $expected = generate_laptop_qr_file($lnum);
        if ($expected !== null) {
            $lid = intval($lr['laptop_id']);
            $esc = $conn->real_escape_string($expected);
            $conn->query("UPDATE laptops SET qr_code_path='$esc' WHERE laptop_id=$lid");
        }
    }
    $laptops->data_seek(0);
}

$students_arr = [];
$res = $conn->query("SELECT * FROM students ORDER BY name ASC");
if ($res && $res->num_rows > 0) $students_arr = $res->fetch_all(MYSQLI_ASSOC);

// Build JS student data with class for lab auto-detection
$js_students = json_encode(array_map(function($s){
    return ['id' => intval($s['student_id']), 'name' => $s['name'], 'class' => $s['class'] ?? ''];
}, $students_arr));
?>
<?php $page_title = "Laptops"; include('../includes/header.php'); ?>

<style>
.lab-badge-sm {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 700;
}
.lab-badge-sm.olevel { background: rgba(59,130,246,.12); color: #2563eb; }
.lab-badge-sm.alevel { background: rgba(139,92,246,.12); color: #7c3aed; }
.lab-auto-note { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; }
</style>

<div class="flash-wrapper">
<?php if (!empty($_SESSION['flash'])):
    $f = $_SESSION['flash'];
    $bs = $f['type'] === 'success' ? 'success' : ($f['type'] === 'danger' ? 'danger' : 'warning');
    ?>
    <div id="flashAlert" class="alert alert-<?= htmlspecialchars($bs) ?> alert-dismissible fade show" role="alert" style="margin:0 0 14px 0;">
        <?= htmlspecialchars($f['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['flash']); endif; ?>
</div>

<!-- Add / Edit form -->
<section class="card-form form-elegant mb-3">
  <h4 class="mb-3"><?= $editLaptop ? "Edit Laptop" : "Add New Laptop" ?></h4>

  <form method="POST" enctype="multipart/form-data" class="row g-3">
    <input type="hidden" name="laptop_id" value="<?= htmlspecialchars($editLaptop['laptop_id'] ?? '') ?>">

    <div class="col-md-6">
      <label class="form-label">Student</label>
      <div class="input-with-icon d-flex gap-2 align-items-center">
        <i class="fa fa-user icon" style="left:10px;"></i>
        <select id="studentSelect" class="form-select" name="student_id" required style="flex:1;"
                onchange="autoSetLab(this.value)">
          <option value="">Select Student</option>
          <?php foreach ($students_arr as $s): ?>
            <option value="<?= intval($s['student_id']) ?>"
              data-class="<?= htmlspecialchars($s['class'] ?? '') ?>"
              <?= ($editLaptop && intval($editLaptop['student_id']) === intval($s['student_id'])) ? 'selected' : '' ?>>
              <?= htmlspecialchars($s['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button id="openStudentSearchBtn" type="button" class="btn btn-outline-primary" title="Search student"><i class="fa fa-search"></i></button>
      </div>
    </div>

    <div class="col-md-6">
      <label class="form-label">Model</label>
      <div class="input-with-icon">
        <i class="fa fa-laptop icon"></i>
        <input class="form-control" type="text" name="model" value="<?= htmlspecialchars($editLaptop['model'] ?? '') ?>">
      </div>
    </div>

    <div class="col-md-4">
      <label class="form-label">Core</label>
      <div class="input-with-icon">
        <i class="fa fa-microchip icon"></i>
        <input class="form-control" type="text" name="core" value="<?= htmlspecialchars($editLaptop['core'] ?? '') ?>">
      </div>
    </div>

    <div class="col-md-4">
      <label class="form-label">Generation</label>
      <div class="input-with-icon">
        <i class="fa fa-hashtag icon"></i>
        <input class="form-control" type="text" name="generation" value="<?= htmlspecialchars($editLaptop['generation'] ?? '') ?>">
      </div>
    </div>

    <div class="col-md-4">
      <label class="form-label">RAM</label>
      <div class="input-with-icon">
        <i class="fa fa-memory icon"></i>
        <input class="form-control" type="text" name="ram" value="<?= htmlspecialchars($editLaptop['ram'] ?? '') ?>">
      </div>
    </div>

    <div class="col-md-4">
      <label class="form-label">ROM</label>
      <div class="input-with-icon">
        <i class="fa fa-hdd icon"></i>
        <input class="form-control" type="text" name="rom" value="<?= htmlspecialchars($editLaptop['rom'] ?? '') ?>">
      </div>
    </div>

    <div class="col-md-4">
      <label class="form-label">Serial Number <span class="text-danger">*</span></label>
      <div class="input-with-icon">
        <i class="fa fa-barcode icon"></i>
        <input class="form-control" type="text" name="serial_number" required value="<?= htmlspecialchars($editLaptop['serial_number'] ?? '') ?>">
      </div>
    </div>

    <div class="col-md-4">
      <label class="form-label">PC No</label>
      <div class="input-with-icon">
        <i class="fa fa-desktop icon"></i>
        <input class="form-control" type="text" name="pc_no" placeholder="PC Number / Owner ID"
               value="<?= htmlspecialchars(!empty($editLaptop['owner_id']) ? $editLaptop['owner_id'] : ($editLaptop['laptop_number'] ?? '')) ?>">
      </div>
    </div>

    <!-- Lab allocation field -->
    <div class="col-md-6">
      <label class="form-label"><i class="fas fa-building-columns"></i> &nbsp;Cyber Lab</label>
      <div class="input-with-icon">
        <i class="fa fa-building icon"></i>
        <?php $cur_lab = $editLaptop['lab'] ?? 'olevel'; ?>
        <select class="form-select" name="lab" id="labSelect">
          <option value="olevel" <?= $cur_lab === 'olevel' ? 'selected' : '' ?>>O-Level Cyber Lab (S.1, S.3, S.4)</option>
          <option value="alevel" <?= $cur_lab === 'alevel' ? 'selected' : '' ?>>A-Level Cyber Lab (S.2, S.5, S.6)</option>
        </select>
      </div>
      <div class="lab-auto-note" id="labAutoNote">
        <?php if ($editLaptop): ?>
          Currently: <strong><?= lts_lab_label($cur_lab) ?></strong>. Auto-updates when student class changes.
        <?php else: ?>
          Auto-set from student's class. You can override manually.
        <?php endif; ?>
      </div>
    </div>

    <div class="col-md-6">
      <label class="form-label">Status</label>
      <div class="input-with-icon">
        <i class="fa fa-info-circle icon"></i>
        <?php $es = normalize_status($editLaptop['status'] ?? 'out_for_project'); ?>
        <select class="form-select" name="status" required>
          <option value="" disabled <?= !$editLaptop ? 'selected' : '' ?>>Select Status</option>
          <option value="back_to_school" <?= $es === 'back_to_school' ? 'selected' : '' ?>>Back to School</option>
          <option value="taken_home" <?= $es === 'taken_home' ? 'selected' : '' ?>>Taken Home</option>
          <option value="issued" <?= $es === 'issued' ? 'selected' : '' ?>>Issued</option>
          <option value="out_for_project" <?= $es === 'out_for_project' ? 'selected' : '' ?>>Out for Project</option>
          <option value="returned" <?= $es === 'returned' ? 'selected' : '' ?>>Returned</option>
        </select>
      </div>
    </div>

    <div class="col-md-6">
      <label class="form-label">Accessories</label>
      <div class="input-with-icon">
        <i class="fa fa-sticky-note icon"></i>
        <textarea class="form-control" name="notes" rows="4"><?= htmlspecialchars($editLaptop['notes'] ?? '') ?></textarea>
      </div>
    </div>

    <div class="col-md-2">
      <label class="form-label">Laptop pic-1</label>
      <div class="file-input">
        <label class="file-label" for="f1"><i class="fa fa-upload"></i> Choose</label>
        <input id="f1" type="file" name="laptop_image1" accept="image/*" style="display:none;">
        <div class="file-name" id="f1name">No file chosen</div>
      </div>
    </div>
    <div class="col-md-2">
      <label class="form-label">Laptop pic-2</label>
      <div class="file-input">
        <label class="file-label" for="f2"><i class="fa fa-upload"></i> Choose</label>
        <input id="f2" type="file" name="laptop_image2" accept="image/*" style="display:none;">
        <div class="file-name" id="f2name">No file chosen</div>
      </div>
    </div>
    <div class="col-md-2">
      <label class="form-label">Laptop pic-3</label>
      <div class="file-input">
        <label class="file-label" for="f3"><i class="fa fa-upload"></i> Choose</label>
        <input id="f3" type="file" name="laptop_image3" accept="image/*" style="display:none;">
        <div class="file-name" id="f3name">No file chosen</div>
      </div>
    </div>

    <div class="col-12 action-row mt-2">
      <?php if ($editLaptop): ?>
        <button type="submit" name="update_laptop" class="btn btn-primary">Update Laptop</button>
        <a href="laptops.php" class="btn btn-secondary">Cancel</a>
      <?php else: ?>
        <button type="submit" name="add_laptop" class="btn btn-success">Add Laptop</button>
      <?php endif; ?>
    </div>
  </form>
</section>

<!-- Table filter -->
<div class="mb-3 d-flex gap-2 flex-wrap align-items-center">
    <input id="searchInput" class="form-control" type="text" style="max-width:360px;"
           placeholder="🔍  Filter by student, serial, model, lab, status…"
           oninput="filterLaptops(this.value)">
    <a href="labs.php" class="btn btn-outline" style="white-space:nowrap;">
        <i class="fas fa-building-columns"></i> Manage Labs
    </a>
</div>

<!-- Laptops table -->
<section class="table-card mb-3">
    <div class="table-responsive" id="tableWrapper">
        <table id="laptopsTable" class="table">
            <thead>
                <tr>
                    <th>S/N</th>
                    <th>Lab</th>
                    <th>Student</th>
                    <th>Class</th>
                    <th>Model</th>
                    <th>Core</th>
                    <th>Gen</th>
                    <th>RAM</th>
                    <th>ROM</th>
                    <th>PC No</th>
                    <th>Serial</th>
                    <th>Status</th>
                    <th>QR Code</th>
                    <th>Images</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            <?php if ($laptops && $laptops->num_rows > 0):
                $sn = 1;
                while ($row = $laptops->fetch_assoc()):
                    $lab_val = $row['lab'] ?? 'olevel';
                    $lab_lbl = $lab_val === 'alevel' ? 'A-Level' : 'O-Level';
                    $st = normalize_status($row['status'] ?? 'out_for_project');
                    $badgeClass = 'status-out-for-project';
                    if ($st === 'issued') $badgeClass = 'status-issued';
                    elseif ($st === 'returned') $badgeClass = 'status-returned';
                    elseif ($st === 'back_to_school') $badgeClass = 'status-back-to-school';
                    elseif ($st === 'taken_home') $badgeClass = 'status-taken-home';
                    $label = ucwords(str_replace('_', ' ', $st));
                    $class_parts = array_filter([$row['student_class'] ?? '', $row['student_stream'] ?? '']);
            ?>
                <tr>
                    <td data-label="S/N"><?= $sn++ ?></td>
                    <td data-label="Lab">
                        <span class="lab-badge-sm <?= htmlspecialchars($lab_val) ?>">
                            <?= htmlspecialchars($lab_lbl) ?>
                        </span>
                    </td>
                    <td data-label="Student"><?= htmlspecialchars($row['student_name'] ?? '') ?></td>
                    <td data-label="Class"><?= $class_parts ? htmlspecialchars(implode(' ', $class_parts)) : '—' ?></td>
                    <td data-label="Model"><?= htmlspecialchars($row['model']) ?></td>
                    <td data-label="Core"><?= htmlspecialchars($row['core'] ?? '—') ?></td>
                    <td data-label="Gen"><?= htmlspecialchars($row['generation'] ?? '—') ?></td>
                    <td data-label="RAM"><?= htmlspecialchars($row['ram'] ?? '—') ?></td>
                    <td data-label="ROM"><?= htmlspecialchars($row['rom'] ?? '—') ?></td>
                    <td data-label="PC No"><?= htmlspecialchars((!empty($row['owner_id']) && trim($row['owner_id']) !== '') ? $row['owner_id'] : ($row['laptop_number'] ?? '—')) ?></td>
                    <td data-label="Serial"><?= htmlspecialchars($row['serial_number']) ?></td>
                    <td data-label="Status">
                        <span class="status-badge <?= $badgeClass ?>"><?= htmlspecialchars($label) ?></span>
                    </td>
                    <td data-label="QR Code">
                        <?php if (!empty($row['qr_code_path']) && file_exists(__DIR__ . '/../' . $row['qr_code_path'])): ?>
                            <img src="../<?= htmlspecialchars($row['qr_code_path']) ?>" alt="QR" class="qr">
                        <?php else: echo '—'; endif; ?>
                    </td>
                    <td data-label="Images">
                        <?php if (!empty($row['laptop_image1']) && file_exists(__DIR__ . '/../uploads/laptops/' . $row['laptop_image1'])): ?>
                            <img src="../uploads/laptops/<?= htmlspecialchars($row['laptop_image1']) ?>" class="laptop-thumb" alt="img1">
                        <?php endif; ?>
                        <?php if (!empty($row['laptop_image2']) && file_exists(__DIR__ . '/../uploads/laptops/' . $row['laptop_image2'])): ?>
                            <img src="../uploads/laptops/<?= htmlspecialchars($row['laptop_image2']) ?>" class="laptop-thumb" alt="img2">
                        <?php endif; ?>
                        <?php if (!empty($row['laptop_image3']) && file_exists(__DIR__ . '/../uploads/laptops/' . $row['laptop_image3'])): ?>
                            <img src="../uploads/laptops/<?= htmlspecialchars($row['laptop_image3']) ?>" class="laptop-thumb" alt="img3">
                        <?php endif; ?>
                    </td>
                    <td data-label="Actions" class="text-center">
                        <div class="action-icons">
                            <a href="laptops.php?edit=<?= intval($row['laptop_id']) ?>" class="btn btn-sm btn-warning" title="Edit">
                                <i class="fa fa-pen"></i>
                            </a>
                            <a href="laptops.php?delete=<?= intval($row['laptop_id']) ?>" class="btn btn-sm btn-danger" title="Delete"
                               onclick="return confirm('Delete this laptop?');">
                                <i class="fa fa-trash"></i>
                            </a>
                            <?php $to = ($lab_val === 'olevel') ? 'alevel' : 'olevel'; ?>
                            <a href="labs.php?transfer=<?= intval($row['laptop_id']) ?>&to=<?= $to ?>"
                               class="btn btn-sm btn-outline" title="Transfer lab"
                               onclick="return confirm('Transfer to <?= $to === 'alevel' ? 'A-Level' : 'O-Level' ?> Lab?');">
                                <i class="fas fa-arrows-left-right"></i>
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="15" class="text-center">No laptops found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="after-table"></div>

<script>
// ── Class → lab mapping ───────────────────────────────────────────────────
const A_LEVEL_CLASSES = ['s.2','s2','senior2','s.5','s5','senior5','s.6','s6','senior6'];

function labForClass(cls) {
    return A_LEVEL_CLASSES.includes((cls || '').toLowerCase().replace(/\s+/g,''))
        ? 'alevel' : 'olevel';
}

function autoSetLab(studentId) {
    const sel = document.getElementById('studentSelect');
    const opt = sel ? sel.querySelector('option[value="' + studentId + '"]') : null;
    const cls = opt ? (opt.dataset.class || '') : '';
    const lab = labForClass(cls);
    const labSel = document.getElementById('labSelect');
    if (labSel) labSel.value = lab;
    const note = document.getElementById('labAutoNote');
    if (note) {
        const lbl = lab === 'alevel' ? 'A-Level Cyber Lab' : 'O-Level Cyber Lab';
        note.textContent = 'Auto-set to ' + lbl + ' (class: ' + (cls || 'unknown') + '). You can override below.';
    }
}

function filterLaptops(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('#laptopsTable tbody tr').forEach(function(row) {
        row.style.display = (!q || row.innerText.toLowerCase().includes(q)) ? '' : 'none';
    });
}
</script>
<?php include('../includes/footer.php'); ?>
