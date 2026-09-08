<?php
// edit_student.php
// IMPORTANT: ensure there's NO whitespace or BOM before this opening tag.

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

include('../config/config.php');
include('../includes/sidebar.php');

// --- get student id ---
$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($student_id <= 0) {
    die('Invalid student id.');
}

// fetch student
$student_stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
if (!$student_stmt) {
    die('Prepare failed: ' . htmlspecialchars($conn->error));
}
$student_stmt->bind_param("i", $student_id);
$student_stmt->execute();
$student_res = $student_stmt->get_result();
if ($student_res->num_rows === 0) {
    $student_stmt->close();
    die('Student not found.');
}
$student = $student_res->fetch_assoc();
$student_stmt->close();

// fetch parents for the select
$parents_res = $conn->query("SELECT parent_id, name FROM parents ORDER BY name");

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_student'])) {
    // sanitize inputs
    $name = trim($_POST['name'] ?? '');
    $class = trim($_POST['class'] ?? '');
    $stream = trim($_POST['stream'] ?? '');
    $parent_input = isset($_POST['parent_id']) ? $_POST['parent_id'] : '';

    // determine parent_id or null
    $parent_id = null;
    if ($parent_input !== '' && $parent_input !== null) {
        $parent_id = intval($parent_input);
        if ($parent_id <= 0) $parent_id = null;
    }

    // basic validation
    if ($name === '') $errors[] = 'Student name is required.';

    // verify parent exists if provided
    if ($parent_id !== null) {
        $pchk = $conn->prepare("SELECT parent_id FROM parents WHERE parent_id = ?");
        if (!$pchk) {
            $errors[] = 'Prepare failed: ' . $conn->error;
        } else {
            $pchk->bind_param("i", $parent_id);
            $pchk->execute();
            $pchk_res = $pchk->get_result();
            if (!$pchk_res || $pchk_res->num_rows === 0) {
                $errors[] = 'Selected parent does not exist. Please pick a valid parent or leave empty.';
            }
            $pchk->close();
        }
    }

    // handle passport upload
    $new_passport_filename = $student['passport']; // keep old by default
    if (isset($_FILES['passport']) && $_FILES['passport']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['passport']['error'] === UPLOAD_ERR_OK) {
            $allowed_ext = ['jpg','jpeg','png','gif','webp'];
            $ext = strtolower(pathinfo($_FILES['passport']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_ext)) {
                $errors[] = 'Passport must be an image (jpg/jpeg/png/gif/webp).';
            } else {
                $upload_dir = __DIR__ . '/../uploads/passports/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $new_passport_filename = uniqid('passport_', true) . '.' . $ext;
                $dest = $upload_dir . $new_passport_filename;
                if (!move_uploaded_file($_FILES['passport']['tmp_name'], $dest)) {
                    $errors[] = 'Failed to move uploaded passport file.';
                    // revert filename so we don't delete old on failure
                    $new_passport_filename = $student['passport'];
                } else {
                    // delete old file if exists and different
                    if (!empty($student['passport']) && $student['passport'] !== $new_passport_filename) {
                        $old = $upload_dir . $student['passport'];
                        if (file_exists($old) && is_file($old)) {
                            @unlink($old);
                        }
                    }
                }
            }
        } else {
            $errors[] = 'Error uploading passport file.';
        }
    }

    // perform update if no errors
    if (empty($errors)) {
        if ($parent_id === null) {
            // parent unset -> set parent_id = NULL
            $stmt = $conn->prepare("UPDATE students 
                                    SET name = ?, class = ?, stream = ?, parent_id = NULL, passport = ?
                                    WHERE student_id = ?");
            if (!$stmt) {
                $errors[] = 'Prepare failed: ' . $conn->error;
            } else {
                // types: name(s) class(s) stream(s) passport(s) student_id(i) => "ssssi"
                $stmt->bind_param("ssssi", $name, $class, $stream, $new_passport_filename, $student_id);
            }
        } else {
            // parent provided
            $stmt = $conn->prepare("UPDATE students 
                                    SET name = ?, class = ?, stream = ?, parent_id = ?, passport = ?
                                    WHERE student_id = ?");
            if (!$stmt) {
                $errors[] = 'Prepare failed: ' . $conn->error;
            } else {
                // types: name(s) class(s) stream(s) parent_id(i) passport(s) student_id(i) => "sssisi"
                $stmt->bind_param("sssisi", $name, $class, $stream, $parent_id, $new_passport_filename, $student_id);
            }
        }

        if (empty($errors) && isset($stmt) && $stmt) {
            if ($stmt->execute()) {
                $success = 'Student record updated successfully.';
                // reload student data
                $s2 = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
                if ($s2) {
                    $s2->bind_param("i", $student_id);
                    $s2->execute();
                    $s2_res = $s2->get_result();
                    if ($s2_res && $s2_res->num_rows > 0) {
                        $student = $s2_res->fetch_assoc();
                    }
                    $s2->close();
                }
            } else {
                $errors[] = 'Update failed: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
}
?>
<?php $page_title = "Edit Student"; include('../includes/header.php'); ?>
<a href="students.php" class="btn btn-secondary mb-3"><i class="fas fa-arrow-left"></i> Back to students</a>

    <h3>Edit Student</h3>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Student Name</label>
            <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($student['name']); ?>" required>
        </div>

        <div class="col-md-3">
            <label class="form-label">Class</label>
            <input type="text" name="class" class="form-control" value="<?php echo htmlspecialchars($student['class']); ?>">
        </div>

        <div class="col-md-3">
            <label class="form-label">Stream</label>
            <input type="text" name="stream" class="form-control" value="<?php echo htmlspecialchars($student['stream']); ?>">
        </div>

        <div class="col-md-3">
            <label class="form-label">Parent</label>
            <select name="parent_id" class="form-select">
                <option value="">(No parent / unset)</option>
                <?php
                if ($parents_res && $parents_res->num_rows > 0) {
                    $parents_res->data_seek(0);
                    while ($p = $parents_res->fetch_assoc()) {
                        $sel = ($student['parent_id'] !== null && $student['parent_id'] == $p['parent_id']) ? 'selected' : '';
                        echo "<option value=\"" . intval($p['parent_id']) . "\" $sel>" . htmlspecialchars($p['name']) . "</option>";
                    }
                }
                ?>
            </select>
        </div>

        <div class="col-md-3">
            <label class="form-label">Passport</label><br>
            <?php if (!empty($student['passport']) && file_exists(__DIR__ . '/../uploads/passports/' . $student['passport'])): ?>
                <img src="../uploads/passports/<?php echo htmlspecialchars($student['passport']); ?>" alt="Passport" class="passport-img mb-2"><br>
            <?php endif; ?>
            <input type="file" name="passport" class="form-control" accept="image/*">
            <div class="file-name">No file chosen</div>
            <div class="form-text">Leave empty to keep current passport.</div>
        </div>

        <div class="col-12">
            <button type="submit" name="update_student" class="btn btn-primary"><i class="fas fa-save"></i> Update Student</button>
        </div>
    </form>
<?php include('../includes/footer.php'); ?>
