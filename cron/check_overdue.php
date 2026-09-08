<?php
/**
 * Overdue check + auto SMS — run via cron.
 *
 *   * /30 * * * *  /usr/bin/php /path/to/laptop_tracking_system/cron/check_overdue.php >> /var/log/lts_overdue.log 2>&1
 *
 * Sends one SMS per overdue laptop per 12-hour window so parents aren't spammed.
 */

require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/migrate.php';
require_once __DIR__ . '/../includes/sms.php';
lts_run_migrations($conn);

$conn->query("CREATE TABLE IF NOT EXISTS overdue_alerts (
    alert_id   INT AUTO_INCREMENT PRIMARY KEY,
    laptop_id  INT NOT NULL,
    sent_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (laptop_id), INDEX (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$rs = $conn->query("
    SELECT l.laptop_id, l.laptop_number, l.due_date, s.student_id, s.name AS student_name, p.contact AS parent_contact
    FROM laptops l
    LEFT JOIN students s ON s.student_id = l.student_id
    LEFT JOIN parents  p ON p.parent_id  = s.parent_id
    WHERE l.status IN ('issued','out_for_project') AND l.due_date IS NOT NULL AND l.due_date < NOW()
");

$sent = 0; $skipped = 0;
while ($r = $rs->fetch_assoc()) {
    if (empty($r['parent_contact'])) { $skipped++; continue; }
    $check = $conn->prepare("SELECT alert_id FROM overdue_alerts WHERE laptop_id = ? AND sent_at > DATE_SUB(NOW(), INTERVAL 12 HOUR) LIMIT 1");
    $check->bind_param('i', $r['laptop_id']); $check->execute();
    if ($check->get_result()->num_rows > 0) { $skipped++; $check->close(); continue; }
    $check->close();

    $msg = "St. Mark's Laptop Tracking: Laptop #" . $r['laptop_number']
         . " issued to " . ($r['student_name'] ?? 'student')
         . " was due on " . date('d M Y', strtotime($r['due_date']))
         . ". Please ensure it is returned promptly.";
    $res = lts_send_sms($conn, $r['parent_contact'], $msg, (int)$r['laptop_id'], (int)($r['student_id'] ?? 0));
    if ($res['success']) {
        $sent++;
        $ins = $conn->prepare("INSERT INTO overdue_alerts (laptop_id) VALUES (?)");
        $ins->bind_param('i', $r['laptop_id']);
        $ins->execute(); $ins->close();
    } else {
        $skipped++;
    }
}

echo date('Y-m-d H:i:s') . " — overdue check: sent={$sent}, skipped={$skipped}\n";
