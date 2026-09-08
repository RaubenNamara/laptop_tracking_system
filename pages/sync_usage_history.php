<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    die('Unauthorized');
}

include('../config/config.php');

// This script syncs existing logs into the usage_log table (run once)
header('Content-Type: text/plain');

echo "Syncing usage history...\n\n";

// Get all logs ordered chronologically
$logs = $conn->query("
    SELECT l.*, s.student_id as sid
    FROM logs l
    LEFT JOIN students s ON l.student_id = s.student_id
    ORDER BY l.timestamp ASC
");

$processed = 0;
$skipped = 0;
$last_issue = []; // Track last issue per laptop-student pair

while ($log = $logs->fetch_assoc()) {
    $laptop_id = $log['laptop_id'];
    $student_id = $log['student_id'];
    $action = strtolower($log['action']);
    $timestamp = $log['timestamp'];
    
    // Skip invalid actions
    $valid_actions = ['issued', 'returned', 'out_for_project', 'back_to_school', 'taken_home'];
    if (!in_array($action, $valid_actions)) {
        $skipped++;
        continue;
    }
    
    // Calculate duration for return-type actions
    $duration = null;
    $pair_key = $laptop_id . '_' . $student_id;
    
    if (in_array($action, ['returned', 'back_to_school'])) {
        if (isset($last_issue[$pair_key])) {
            $duration = round((strtotime($timestamp) - strtotime($last_issue[$pair_key])) / 60);
            // Reset after return
            unset($last_issue[$pair_key]);
        }
    } elseif (in_array($action, ['issued', 'taken_home', 'out_for_project'])) {
        // Mark as last issue for this pair
        $last_issue[$pair_key] = $timestamp;
    }
    
    // Insert into usage_log
    $date = date('Y-m-d', strtotime($timestamp));
    $time = date('H:i:s', strtotime($timestamp));
    
    $stmt = $conn->prepare("
        INSERT INTO laptop_usage_log 
            (laptop_id, student_id, action, usage_date, usage_time, duration_minutes, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE action = VALUES(action)
    ");
    
    $stmt->bind_param("iisssss", $laptop_id, $student_id, $action, $date, $time, $duration, $timestamp);
    
    if ($stmt->execute()) {
        $processed++;
    } else {
        echo "Error on log ID {$log['id']}: " . $stmt->error . "\n";
        $skipped++;
    }
    
    $stmt->close();
}

echo "\nDone!\n";
echo "Processed: $processed\n";
echo "Skipped: $skipped\n";
echo "Total logs: " . ($processed + $skipped) . "\n";