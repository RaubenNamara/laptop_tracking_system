<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
include('../config/config.php');
require_once('../includes/sms.php');
lts_ensure_sms_tables($conn);

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $keys = ['sms_provider','sms_from','sms_account_sid','sms_auth_token','sms_at_username','sms_at_api_key'];
    foreach ($keys as $k) {
        if (isset($_POST[$k])) {
            $v = trim($_POST[$k]);
            // Don't overwrite secrets with masked placeholder
            if (in_array($k, ['sms_auth_token','sms_at_api_key']) && $v === '••••••••') continue;
            lts_set_setting($conn, $k, $v);
        }
    }
    $flash = ['success', 'Settings saved.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test'])) {
    $to  = trim($_POST['test_to'] ?? '');
    $msg = trim($_POST['test_msg'] ?? "Test SMS from St. Mark's Laptop Tracking System.");
    $res = lts_send_sms($conn, $to, $msg);
    $flash = $res['success']
        ? ['success', 'Test SMS sent to ' . htmlspecialchars($to)]
        : ['danger',  'Test failed: ' . htmlspecialchars($res['message'])];
}

$cfg = [
    'sms_provider'    => lts_get_setting($conn, 'sms_provider', 'disabled') ?? 'disabled',
    'sms_from'        => lts_get_setting($conn, 'sms_from', "School") ?? 'School',
    'sms_account_sid' => lts_get_setting($conn, 'sms_account_sid', '') ?? '',
    'sms_auth_token'  => lts_get_setting($conn, 'sms_auth_token',  '') ?? '',
    'sms_at_username' => lts_get_setting($conn, 'sms_at_username', '') ?? '',
    'sms_at_api_key'  => lts_get_setting($conn, 'sms_at_api_key',  '') ?? '',
];

// Recent log
$log = [];
$rs = $conn->query("SELECT * FROM notifications_log ORDER BY created_at DESC LIMIT 25");
if ($rs) while ($r = $rs->fetch_assoc()) $log[] = $r;

$page_title = 'SMS Settings';
include('../includes/header.php');
?>
<div class="page-header">
    <div>
        <h2 class="title">SMS Settings</h2>
        <p class="subtitle">Configure how parent notifications and overdue alerts are sent.</p>
    </div>
    <a href="overdue.php" class="btn btn-outline"><i class="fas fa-triangle-exclamation"></i> Overdue</a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?php echo $flash[0]; ?>"><i class="fas fa-circle-info"></i> <?php echo htmlspecialchars($flash[1]); ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-8">
        <form method="POST" class="card-soft elev">
            <div class="card-head"><h3>Provider</h3></div>

            <label class="form-label">SMS provider</label>
            <select name="sms_provider" class="form-select" id="providerSelect">
                <option value="disabled"      <?php echo $cfg['sms_provider']==='disabled'      ? 'selected' : ''; ?>>Disabled (no SMS will be sent)</option>
                <option value="twilio"        <?php echo $cfg['sms_provider']==='twilio'        ? 'selected' : ''; ?>>Twilio</option>
                <option value="africastalking" <?php echo $cfg['sms_provider']==='africastalking' ? 'selected' : ''; ?>>Africa's Talking</option>
            </select>

            <label class="form-label mt-3">From / Sender ID</label>
            <input type="text" name="sms_from" class="form-control" value="<?php echo htmlspecialchars($cfg['sms_from']); ?>" placeholder="e.g. SCHOOL or +256…">
            <p class="form-help">For Twilio, use your purchased phone number. For Africa's Talking, use your registered alphanumeric sender ID.</p>

            <div id="twilioFields" style="display:<?php echo $cfg['sms_provider']==='twilio' ? 'block' : 'none'; ?>;">
                <div class="divider"></div>
                <h4 style="font-size:15px;">Twilio credentials</h4>
                <label class="form-label">Account SID</label>
                <input type="text" name="sms_account_sid" class="form-control" value="<?php echo htmlspecialchars($cfg['sms_account_sid']); ?>" placeholder="ACxxxxxxxx…">
                <label class="form-label mt-3">Auth Token</label>
                <input type="password" name="sms_auth_token" class="form-control" value="<?php echo $cfg['sms_auth_token'] ? '••••••••' : ''; ?>">
                <p class="form-help">Stored encrypted at-rest is recommended in production. Leave the masked value to keep the existing secret.</p>
            </div>

            <div id="atFields" style="display:<?php echo $cfg['sms_provider']==='africastalking' ? 'block' : 'none'; ?>;">
                <div class="divider"></div>
                <h4 style="font-size:15px;">Africa's Talking credentials</h4>
                <label class="form-label">Username</label>
                <input type="text" name="sms_at_username" class="form-control" value="<?php echo htmlspecialchars($cfg['sms_at_username']); ?>" placeholder="e.g. sandbox or your live username">
                <label class="form-label mt-3">API Key</label>
                <input type="password" name="sms_at_api_key" class="form-control" value="<?php echo $cfg['sms_at_api_key'] ? '••••••••' : ''; ?>">
            </div>

            <div class="d-flex gap-2 mt-4">
                <button name="save" value="1" class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save settings</button>
                <a href="overdue.php" class="btn btn-outline">Cancel</a>
            </div>
        </form>

        <form method="POST" class="card-soft mt-3">
            <div class="card-head"><h3>Send test message</h3></div>
            <label class="form-label">Recipient phone</label>
            <input type="tel" name="test_to" class="form-control" placeholder="+256700000000" required>
            <label class="form-label mt-3">Message</label>
            <textarea name="test_msg" class="form-control" rows="3">Test SMS from St. Mark's Laptop Tracking System.</textarea>
            <button name="test" value="1" class="btn btn-accent mt-3" type="submit"><i class="fas fa-paper-plane"></i> Send test</button>
        </form>
    </div>

    <div class="col-12 col-lg-4">
        <div class="card-soft elev">
            <div class="card-head"><h3>Recent messages</h3></div>
            <?php if (empty($log)): ?>
                <p class="muted mb-0">No messages yet.</p>
            <?php else: ?>
                <div style="max-height:520px; overflow:auto;">
                    <?php foreach ($log as $l): ?>
                        <div style="padding:10px 0; border-bottom:1px dashed var(--border-soft);">
                            <div class="d-flex justify-content-between align-items-center">
                                <strong class="text-small"><?php echo htmlspecialchars($l['recipient']); ?></strong>
                                <span class="badge-pill <?php echo $l['status']==='sent' ? 'badge-ok' : ($l['status']==='failed' ? 'badge-bad' : 'badge-muted'); ?>">
                                    <?php echo htmlspecialchars($l['status']); ?>
                                </span>
                            </div>
                            <div class="text-small" style="margin-top:4px;"><?php echo htmlspecialchars($l['message']); ?></div>
                            <div class="text-small muted" style="margin-top:4px;">
                                <?php echo htmlspecialchars($l['provider']); ?> · <?php echo htmlspecialchars($l['created_at']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include('../includes/footer.php'); ?>

<script>
document.getElementById('providerSelect').addEventListener('change', function () {
    document.getElementById('twilioFields').style.display = this.value === 'twilio' ? 'block' : 'none';
    document.getElementById('atFields').style.display     = this.value === 'africastalking' ? 'block' : 'none';
});
</script>
