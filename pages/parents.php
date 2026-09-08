<?php
// parents.php
// IMPORTANT: Ensure there is NO whitespace or BOM before this opening tag.

ob_start();
session_start();

// --- AUTH ---
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// --- CONFIG ---
// Make sure config.php creates a $conn mysqli connection (e.g., $conn = new mysqli(...);)
include_once(__DIR__ . '/../config/config.php');

// ---------- PROCESS ACTIONS (MUST RUN BEFORE ANY HTML OUTPUT / INCLUDES) ----------

// Helper: safe retrieval
function input_val($key) {
    return isset($_REQUEST[$key]) ? trim($_REQUEST[$key]) : '';
}

// ADD parent
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_parent'])) {
    $name = input_val('name');
    $contact = input_val('contact');

    $stmt = $conn->prepare("INSERT INTO parents (name, contact) VALUES (?, ?)");
    if ($stmt) {
        $stmt->bind_param("ss", $name, $contact);
        if ($stmt->execute()) {
            header("Location: parents.php?msg=added");
            exit();
        } else {
            header("Location: parents.php?msg=add_error");
            exit();
        }
    } else {
        header("Location: parents.php?msg=add_error");
        exit();
    }
}

// EDIT parent
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_parent'])) {
    $id = intval($_POST['parent_id'] ?? 0);
    $name = input_val('name');
    $contact = input_val('contact');

    $stmt = $conn->prepare("UPDATE parents SET name = ?, contact = ? WHERE parent_id = ?");
    if ($stmt) {
        $stmt->bind_param("ssi", $name, $contact, $id);
        if ($stmt->execute()) {
            header("Location: parents.php?msg=updated");
            exit();
        } else {
            header("Location: parents.php?msg=update_error");
            exit();
        }
    } else {
        header("Location: parents.php?msg=update_error");
        exit();
    }
}

// DELETE parent
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM parents WHERE parent_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }
    header("Location: parents.php?msg=deleted");
    exit();
}

// ---------- PREPARE DATA FOR DISPLAY ----------

$parents = $conn->query("SELECT * FROM parents ORDER BY parent_id DESC");

// For edit form
$edit_parent = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM parents WHERE parent_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $edit_parent = $res->fetch_assoc();
    }
}

?>
<?php $page_title = "Parents"; include('../includes/header.php'); ?>
<?php
    // flash messages
    if (isset($_GET['msg'])) {
        $m = $_GET['msg'];
        if ($m === 'added') {
            echo '<div class="alert alert-success">Parent added successfully.</div>';
        } elseif ($m === 'updated') {
            echo '<div class="alert alert-success">Parent updated successfully.</div>';
        } elseif ($m === 'deleted') {
            echo '<div class="alert alert-success">Parent deleted successfully.</div>';
        } elseif ($m === 'add_error' || $m === 'update_error') {
            echo '<div class="alert alert-danger">An error occurred. Please try again.</div>';
        }
    }
    ?>

    <h2 style="margin-bottom:14px;"><?php echo $edit_parent ? "Edit Parent" : "Add New Parent"; ?></h2>

    <div class="form-card">
      <form method="POST" class="row g-3" autocomplete="off">
          <input type="hidden" name="parent_id" value="<?php echo htmlspecialchars($edit_parent['parent_id'] ?? ''); ?>">

          <div class="col-md-4 position-relative">
              <label class="form-label">Parent Name</label>
              <div class="input-with-icon">
                <i class="fa fa-user icon"></i>
                <input type="text" name="name" class="form-control" placeholder="Full name" value="<?php echo htmlspecialchars($edit_parent['name'] ?? ''); ?>" required>
              </div>
          </div>

          <div class="col-md-4 position-relative">
              <label class="form-label">Contact</label>
              <div class="input-with-icon">
                <i class="fa fa-phone icon"></i>
                <input type="text" name="contact" class="form-control" placeholder="+1 555 555 555" value="<?php echo htmlspecialchars($edit_parent['contact'] ?? ''); ?>">
              </div>
          </div>

          <div class="col-12 d-flex gap-2" style="margin-top:6px;">
            <?php if ($edit_parent): ?>
                <button type="submit" name="edit_parent" class="btn btn-primary">Update Parent</button>
                <a href="parents.php" class="btn btn-secondary-custom">Cancel</a>
            <?php else: ?>
                <button type="submit" name="add_parent" class="btn btn-primary">Add Parent</button>
            <?php endif; ?>
          </div>
      </form>
    </div>

    <div class="mb-3" style="max-width:420px;">
        <input type="text" id="parentsFilter" class="form-control"
               placeholder="🔍  Filter parents by name or contact…"
               oninput="filterParents(this.value)">
    </div>

    <h2 style="margin-bottom:12px;">All Parents</h2>

    <div class="table-wrapper">
    <div class="table-responsive">
        <table class="table align-middle" id="parentsTable">
            <thead>
            <tr>
                <th style="width:90px;">S/N</th>
                <th>Name</th>
                <th>Contact</th>
                <th style="width:160px;">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php 
            $sn = 1;
            if ($parents && $parents->num_rows > 0):
                while ($row = $parents->fetch_assoc()): 
            ?>
            <tr>
                <td><?php echo $sn++; ?></td>
                <td><?php echo htmlspecialchars($row['name']); ?></td>
                <td><?php echo htmlspecialchars($row['contact']); ?></td>
                <td class="actions">
                    <!-- Wrap the two action links in a horizontal flex container so Edit and Delete appear on the same line -->
                    <div style="display:flex; gap:8px; align-items:center;">
                        <a class="edit-btn" href="parents.php?edit=<?php echo intval($row['parent_id']); ?>"><i class="fa fa-pen"></i> Edit</a>
                        <a class="delete-btn" href="parents.php?delete=<?php echo intval($row['parent_id']); ?>" onclick="return confirm('Are you sure you want to delete this parent?');"><i class="fa fa-trash"></i> Delete</a>
                    </div>
                </td>
            </tr>
            <?php 
                endwhile;
            else:
            ?>
            <tr>
                <td colspan="4" class="text-center" style="padding:18px 12px;">No parents found.</td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
function filterParents(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('#parentsTable tbody tr').forEach(function(row) {
        row.style.display = (!q || row.innerText.toLowerCase().includes(q)) ? '' : 'none';
    });
}
</script>
<?php include('../includes/footer.php'); ?>
