<?php
// students.php
// IMPORTANT: make sure there is NO whitespace or BOM before this PHP opening tag.

ob_start();
session_start();

// --- AUTH CHECK ---
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// --- CONFIG ---
include_once(__DIR__ . '/../config/config.php'); // ensure this defines $conn as a mysqli instance

// ---------- HELPERS ----------
function validate_image_file($file, &$error, $max_size = 2097152) { // 2 MB
    if (!isset($file) || $file['error'] !== 0) {
        $error = 'No file uploaded or upload error.';
        return false;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','gif','webp'];
    if (!in_array($ext, $allowed)) {
        $error = 'Invalid file type. Allowed: jpg, jpeg, png, gif, webp.';
        return false;
    }
    if ($file['size'] > $max_size) {
        $error = 'File too large. Maximum allowed is 2 MB.';
        return false;
    }
    return true;
}

// ---------- PROCESS ACTIONS (do BEFORE any HTML output) ----------
$flash = '';
$flash_class = ''; // 'success' or 'danger'

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
    // Collect and sanitize inputs
    $name = trim($_POST['name'] ?? '');
    $class = trim($_POST['class'] ?? '');
    $stream = trim($_POST['stream'] ?? '');
    $parent_id = (isset($_POST['parent_id']) && $_POST['parent_id'] !== '') ? intval($_POST['parent_id']) : null;

    // passport: optional server-side. If provided, validate + save.
    $passport_filename = null;
    if (isset($_FILES['passport']) && $_FILES['passport']['error'] === 0) {
        $err = '';
        if (!validate_image_file($_FILES['passport'], $err)) {
            $flash = 'Passport upload error: ' . $err;
            $flash_class = 'danger';
        } else {
            $ext = strtolower(pathinfo($_FILES['passport']['name'], PATHINFO_EXTENSION));
            $passport_filename = uniqid('passport_', true) . '.' . $ext;
            $passport_dir = __DIR__ . '/../uploads/passports/';
            if (!is_dir($passport_dir)) mkdir($passport_dir, 0755, true);
            if (!move_uploaded_file($_FILES['passport']['tmp_name'], $passport_dir . $passport_filename)) {
                $flash = 'Failed to move passport upload to server.';
                $flash_class = 'danger';
                $passport_filename = null;
            }
        }
    }

    // Insert into DB if no flash
    if ($flash === '') {
        // Two cases: parent_id is null -> use NULL in SQL, else bind it
        if ($parent_id === null) {
            $sql = "INSERT INTO students (name, class, stream, parent_id, passport)
                    VALUES (?, ?, ?, NULL, NULLIF(?, ''))";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $flash = 'Database prepare error: ' . $conn->error;
                $flash_class = 'danger';
            } else {
                // types: name,class,stream,passport => s s s s => "ssss"
                $ptype = "ssss";
                // ensure passport_filename is '' if null so NULLIF will convert it to NULL
                $pf_for_bind = $passport_filename ?? '';
                $stmt->bind_param($ptype, $name, $class, $stream, $pf_for_bind);
                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: students.php?msg=added");
                    exit();
                } else {
                    $flash = 'Database insert error: ' . $stmt->error;
                    $flash_class = 'danger';
                    $stmt->close();
                    if (!empty($passport_filename) && file_exists(__DIR__ . '/../uploads/passports/' . $passport_filename)) {
                        @unlink(__DIR__ . '/../uploads/passports/' . $passport_filename);
                    }
                }
            }
        } else {
            $sql = "INSERT INTO students (name, class, stream, parent_id, passport)
                    VALUES (?, ?, ?, ?, NULLIF(?, ''))";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $flash = 'Database prepare error: ' . $conn->error;
                $flash_class = 'danger';
            } else {
                // types: name,class,stream,parent_id,passport => s s s i s => "sssis"
                $ptype = "sssis";
                $pf_for_bind = $passport_filename ?? '';
                $stmt->bind_param($ptype, $name, $class, $stream, $parent_id, $pf_for_bind);
                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: students.php?msg=added");
                    exit();
                } else {
                    $flash = 'Database insert error: ' . $stmt->error;
                    $flash_class = 'danger';
                    $stmt->close();
                    if (!empty($passport_filename) && file_exists(__DIR__ . '/../uploads/passports/' . $passport_filename)) {
                        @unlink(__DIR__ . '/../uploads/passports/' . $passport_filename);
                    }
                }
            }
        }
    }
}

// Delete student
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    // fetch passport filename
    $sel = $conn->prepare("SELECT passport FROM students WHERE student_id = ?");
    if ($sel) {
        $sel->bind_param("i", $id);
        $sel->execute();
        $res = $sel->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            if (!empty($row['passport'])) {
                $passport_path = __DIR__ . '/../uploads/passports/' . $row['passport'];
                if (file_exists($passport_path)) @unlink($passport_path);
            }
        }
        $sel->close();
    }

    // delete record
    $del = $conn->prepare("DELETE FROM students WHERE student_id = ?");
    if ($del) {
        $del->bind_param("i", $id);
        $del->execute();
        $del->close();
    }
    header("Location: students.php?msg=deleted");
    exit();
}

// ---------- PREPARE DATA FOR HTML ----------
// Fetch parents into an array (so we can both render the select and provide JS data)
$parents_arr = [];
$resp = $conn->query("SELECT * FROM parents ORDER BY name ASC");
if ($resp && $resp->num_rows > 0) $parents_arr = $resp->fetch_all(MYSQLI_ASSOC);

$students = $conn->query("SELECT s.*, p.name AS parent_name FROM students s LEFT JOIN parents p ON s.parent_id = p.parent_id ORDER BY s.name ASC");

// JSON for client-side parent modal
$js_parents = json_encode(array_map(function($p){ return ['id' => intval($p['parent_id']), 'name' => $p['name']]; }, $parents_arr));
?>
<?php $page_title = "Students"; include('../includes/header.php'); ?>
<?php
if ($flash !== '') {
    echo '<div class="alert alert-' . ($flash_class === 'danger' ? 'danger' : 'success') . '">' . htmlspecialchars($flash) . '</div>';
}
if (isset($_GET['msg'])) {
    $m = $_GET['msg'];
    if ($m === 'added') echo '<div class="alert alert-success">Student added successfully.</div>';
    if ($m === 'deleted') echo '<div class="alert alert-success">Student deleted successfully.</div>';
}
?>

<style>
    .student-action-group {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-start;
    }

    .student-action-group .btn {
        min-width: 92px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 0.46rem 0.8rem;
        border-radius: 8px;
        font-size: 0.82rem;
        line-height: 1.2;
        white-space: nowrap;
    }

    .student-action-group .btn-warning {
        background: #f59e0b;
        border-color: #f59e0b;
    }

    .student-action-group .btn-danger {
        background: #dc3545;
        border-color: #dc3545;
    }
</style>

<!-- Add Student Form -->
<div class="card mb-4 shadow-sm">
  <div class="card-header bg-primary text-white"><h5 class="mb-0">Add New Student</h5></div>
  <div class="card-body">
    <form method="POST" enctype="multipart/form-data" class="row g-3">
      <div class="col-md-6 input-with-icon">
        <span class="input-icon"><i class="fas fa-user"></i></span>
        <input type="text" name="name" class="form-control" placeholder="Student Name" required>
      </div>

      <div class="col-md-3 input-with-icon">
        <span class="input-icon"><i class="fas fa-school"></i></span>
        <input type="text" name="class" class="form-control" placeholder="Class">
      </div>

      <div class="col-md-3 input-with-icon">
        <span class="input-icon"><i class="fas fa-stream"></i></span>
        <input type="text" name="stream" class="form-control" placeholder="Stream">
      </div>

      <div class="col-md-3">
        <label class="form-label">Passport Photo (optional)</label>
        <input type="file" name="passport" class="form-control" accept="image/*">
        <div class="file-name">No file chosen</div>
      </div>

      <div class="col-md-3">
        <label class="form-label">Parent</label>
        <div class="d-flex gap-2 align-items-center">
          <select id="parentSelect" name="parent_id" class="form-select" style="flex:1;">
            <option value="">Select Parent</option>
            <?php
            if (!empty($parents_arr)) {
                foreach ($parents_arr as $p) {
                    echo '<option value="'.htmlspecialchars($p['parent_id']).'">'.htmlspecialchars($p['name']).'</option>';
                }
            }
            ?>
          </select>
          <button id="openParentSearchBtn" type="button" class="btn btn-outline-primary" title="Search parent"><i class="fa fa-search"></i></button>
        </div>
      </div>

      <div class="col-12">
        <button type="submit" name="add_student" class="btn btn-primary"><i class="fas fa-plus"></i> Add Student</button>
      </div>
    </form>
  </div>
</div>

<!-- Parent Search Modal -->
<div class="modal fade" id="parentSearchModal" tabindex="-1" aria-labelledby="parentSearchModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa fa-search"></i> Search Parent</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <input id="parentModalSearch" type="search" class="form-control" placeholder="Type parent name to search (press Enter or click a row)">
        </div>
        <ul id="parentList" class="parent-list">
          <!-- JS will populate -->
        </ul>
      </div>
      <div class="modal-footer">
        <button id="parentSelectBtn" type="button" class="btn btn-primary" disabled>Select</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<div class="mb-3" style="max-width:420px;">
    <input type="text" id="studentsFilter" class="form-control"
           placeholder="🔍  Filter by name, class or stream…"
           oninput="filterStudents(this.value)">
</div>

<!-- Students Table -->
<div class="card shadow-sm">
  <div class="card-header bg-primary text-white"><h5 class="mb-0">All Students</h5></div>
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="studentsTable">
      <thead class="table-primary">
        <tr>
          <th>S/N</th>
          <th>Passport</th>
          <th>Name</th>
          <th>Class</th>
          <th>Stream</th>
          <th>Parent</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $sn = 1;
        if ($students && $students->num_rows > 0):
            while ($row = $students->fetch_assoc()):
        ?>
        <tr>
          <td><?php echo $sn++; ?></td>
          <td>
            <?php if (!empty($row['passport'])): ?>
              <img src="../uploads/passports/<?php echo htmlspecialchars($row['passport']); ?>" class="passport-img" alt="Passport">
            <?php else: ?>N/A<?php endif; ?>
          </td>
          <td><?php echo htmlspecialchars($row['name']); ?></td>
          <td><?php echo htmlspecialchars($row['class']); ?></td>
          <td><?php echo htmlspecialchars($row['stream']); ?></td>
          <td><?php echo htmlspecialchars($row['parent_name']); ?></td>
          <td>
            <div class="student-action-group">
              <a class="btn btn-warning btn-sm" href="edit_student.php?id=<?php echo intval($row['student_id']); ?>">
                <i class="fas fa-edit"></i> Edit
              </a>
              <a class="btn btn-danger btn-sm" href="students.php?delete=<?php echo intval($row['student_id']); ?>" onclick="return confirm('Are you sure?');">
                <i class="fas fa-trash"></i> Delete
              </a>
            </div>
          </td>
        </tr>
        <?php
            endwhile;
        else:
            echo '<tr><td colspan="7" class="text-center">No students found.</td></tr>';
        endif;
        ?>
      </tbody>
    </table>
  </div>
</div>
<script>
function filterStudents(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('#studentsTable tbody tr').forEach(function(row) {
        row.style.display = (!q || row.innerText.toLowerCase().includes(q)) ? '' : 'none';
    });
}
</script>
<?php include('../includes/footer.php'); ?>
