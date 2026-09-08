<?php
/**
 * Lightweight idempotent migrations.
 * Include this from any page that depends on the new schema.
 */
if (!function_exists('lts_lab_for_class')) {
    /**
     * Determine lab from student class.
     * S.2, S.5, S.6 → A-Level Cyber Lab ('alevel')
     * S.1, S.3, S.4 (and everything else) → O-Level Cyber Lab ('olevel')
     */
    function lts_lab_for_class(string $class): string {
        $c = strtolower(trim(preg_replace('/\s+/', '', $class)));
        if (in_array($c, ['s.2','s2','senior2','s2', 's.5','s5','senior5', 's.6','s6','senior6'], true)) {
            return 'alevel';
        }
        return 'olevel';
    }
}

if (!function_exists('lts_lab_label')) {
    function lts_lab_label(string $lab): string {
        return $lab === 'alevel' ? 'A-Level Cyber Lab' : 'O-Level Cyber Lab';
    }
}

if (!function_exists('lts_run_migrations')) {
    function lts_run_migrations(mysqli $conn): void {
        // settings + notifications_log live in sms.php
        require_once __DIR__ . '/sms.php';
        lts_ensure_sms_tables($conn);

        // Check if action_time column exists in logs table
        $r = $conn->query("SHOW COLUMNS FROM logs LIKE 'action_time'");
        if ($r && $r->num_rows === 0) {
            $conn->query("ALTER TABLE logs ADD COLUMN action_time DATETIME NULL AFTER action");
        }

        // laptops.due_date — when a laptop must be returned (used by overdue)
        $r = $conn->query("SHOW COLUMNS FROM laptops LIKE 'due_date'");
        if ($r && $r->num_rows === 0) {
            $conn->query("ALTER TABLE laptops ADD COLUMN due_date DATETIME NULL AFTER status");
        }
        // laptops.issued_at — last issued timestamp
        $r = $conn->query("SHOW COLUMNS FROM laptops LIKE 'issued_at'");
        if ($r && $r->num_rows === 0) {
            $conn->query("ALTER TABLE laptops ADD COLUMN issued_at DATETIME NULL AFTER due_date");
        }
        // laptops.returned_at — last returned timestamp
        $r = $conn->query("SHOW COLUMNS FROM laptops LIKE 'returned_at'");
        if ($r && $r->num_rows === 0) {
            $conn->query("ALTER TABLE laptops ADD COLUMN returned_at DATETIME NULL AFTER issued_at");
        }

        // Hardware spec + asset columns used by the laptops page
        $extra_cols = [
            'core'          => "VARCHAR(64) NULL",
            'generation'    => "VARCHAR(64) NULL",
            'ram'           => "VARCHAR(64) NULL",
            'rom'           => "VARCHAR(64) NULL",
            'owner_id'      => "VARCHAR(255) NULL",
            'qr_code_path'  => "VARCHAR(255) NULL",
            'laptop_image1' => "VARCHAR(255) NULL",
            'laptop_image2' => "VARCHAR(255) NULL",
            'laptop_image3' => "VARCHAR(255) NULL",
        ];
        foreach ($extra_cols as $col => $def) {
            $r = $conn->query("SHOW COLUMNS FROM laptops LIKE '" . $col . "'");
            if ($r && $r->num_rows === 0) {
                $conn->query("ALTER TABLE laptops ADD COLUMN `{$col}` {$def}");
            }
        }

        // ── LAB ALLOCATION COLUMN ──────────────────────────────────────
        // 'olevel' = O-Level Cyber Lab (S.1, S.3, S.4)
        // 'alevel' = A-Level Cyber Lab (S.2, S.5, S.6)
        $r = $conn->query("SHOW COLUMNS FROM laptops LIKE 'lab'");
        if ($r && $r->num_rows === 0) {
            $conn->query("ALTER TABLE laptops ADD COLUMN lab VARCHAR(16) NOT NULL DEFAULT 'olevel' AFTER status");
            // Backfill existing rows from the joined student class
            $conn->query("
                UPDATE laptops l
                JOIN students s ON l.student_id = s.student_id
                SET l.lab = CASE
                    WHEN LOWER(REPLACE(s.class,' ','')) IN ('s.2','s2','senior2','s.5','s5','senior5','s.6','s6','senior6')
                    THEN 'alevel'
                    ELSE 'olevel'
                END
            ");
        }
        // ── END LAB COLUMN ────────────────────────────────────────────

        // ── LAPTOP USAGE LOG TABLE ──────────────────────────────────────
        // Reports/analytics pages expect: laptop_id, student_id, action,
        // usage_date, usage_time, duration_minutes, condition_notes, staff_id.
        $r = $conn->query("SHOW TABLES LIKE 'laptop_usage_log'");
        if (!$r || $r->num_rows === 0) {
            $conn->query("
                CREATE TABLE laptop_usage_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    laptop_id INT,
                    student_id INT NULL,
                    action VARCHAR(32) NULL,
                    usage_date DATE NULL,
                    usage_time TIME NULL,
                    duration_minutes INT DEFAULT 0,
                    condition_notes TEXT NULL,
                    staff_id INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
        } else {
            // Table exists (possibly from an older schema with login_time/
            // logout_time/activity) — add any columns newer code relies on.
            $usage_cols = [
                'student_id'       => "INT NULL AFTER laptop_id",
                'action'           => "VARCHAR(32) NULL AFTER student_id",
                'usage_date'       => "DATE NULL AFTER action",
                'usage_time'       => "TIME NULL AFTER usage_date",
                'duration_minutes' => "INT DEFAULT 0",
                'condition_notes'  => "TEXT NULL",
                'staff_id'         => "INT NULL",
                'created_at'       => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
            ];
            foreach ($usage_cols as $col => $def) {
                $rc = $conn->query("SHOW COLUMNS FROM laptop_usage_log LIKE '" . $col . "'");
                if ($rc && $rc->num_rows === 0) {
                    $conn->query("ALTER TABLE laptop_usage_log ADD COLUMN `{$col}` {$def}");
                }
            }
            // Backfill usage_date/usage_time from legacy login_time column, if present
            $rlt = $conn->query("SHOW COLUMNS FROM laptop_usage_log LIKE 'login_time'");
            if ($rlt && $rlt->num_rows > 0) {
                $conn->query("
                    UPDATE laptop_usage_log
                    SET usage_date = DATE(login_time), usage_time = TIME(login_time)
                    WHERE usage_date IS NULL AND login_time IS NOT NULL
                ");
            }
        }
        // ── END LAPTOP USAGE LOG TABLE ───────────────────────────────────

        // Indexes for hot queries (dashboard, overdue, status pages) — create once, ignore if present
        $idx = [
            'idx_laptops_status'          => "CREATE INDEX idx_laptops_status ON laptops(status)",
            'idx_laptops_due_date'        => "CREATE INDEX idx_laptops_due_date ON laptops(due_date)",
            'idx_laptops_issued_at'       => "CREATE INDEX idx_laptops_issued_at ON laptops(issued_at)",
            'idx_laptops_student_id'      => "CREATE INDEX idx_laptops_student_id ON laptops(student_id)",
            'idx_laptops_lab'             => "CREATE INDEX idx_laptops_lab ON laptops(lab)",
            'idx_laptops_status_lab'      => "CREATE INDEX idx_laptops_status_lab ON laptops(status, lab)",
            'idx_students_name'           => "CREATE INDEX idx_students_name ON students(name)",
            'idx_students_class_stream'   => "CREATE INDEX idx_students_class_stream ON students(class, stream)",
            'idx_logs_laptop_id'          => "CREATE INDEX idx_logs_laptop_id ON logs(laptop_id)",
            'idx_logs_action_time'        => "CREATE INDEX idx_logs_action_time ON logs(action_time)",
            'idx_logs_action_timestamp'   => "CREATE INDEX idx_logs_action_timestamp ON logs(action, timestamp)",
            'idx_usage_laptop_id'         => "CREATE INDEX idx_usage_laptop_id ON laptop_usage_log(laptop_id)",
            'idx_usage_student_id'        => "CREATE INDEX idx_usage_student_id ON laptop_usage_log(student_id)",
            'idx_usage_date'              => "CREATE INDEX idx_usage_date ON laptop_usage_log(usage_date)",
        ];
        foreach ($idx as $name => $sql) {
            $table = 'laptops';
            if (strpos($name, 'students_') === 0) {
                $table = 'students';
            } elseif (strpos($name, 'logs_') === 0) {
                $table = 'logs';
            } elseif (strpos($name, 'usage_') === 0) {
                $table = 'laptop_usage_log';
            }

            $exists = $conn->query("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = '{$table}' AND index_name = '{$name}' LIMIT 1");
            if (!$exists || $exists->num_rows === 0) {
                $createSql = str_replace('CREATE INDEX ', 'CREATE INDEX IF NOT EXISTS ', $sql);
                @$conn->query($createSql);
            }
        }

        // Backfill: normalize legacy human-label actions stored in logs.action
        $backfill = [
            'Issued Out'      => 'issued',
            'Issued out'      => 'issued',
            'Issued'          => 'issued',
            'Returned'        => 'returned',
            'Out for Project' => 'out_for_project',
            'Out For Project' => 'out_for_project',
            'Back to School'  => 'back_to_school',
            'Back To School'  => 'back_to_school',
            'Taken Home'      => 'taken_home',
        ];
        foreach ($backfill as $old => $new) {
            $u = $conn->prepare("UPDATE logs SET action = ? WHERE action = ?");
            if ($u) { $u->bind_param('ss', $new, $old); @$u->execute(); $u->close(); }
        }

        // Auto-create writable directories
        $base = __DIR__ . '/..';
        foreach (['/uploads', '/uploads/laptops', '/uploads/passports', '/qrcodes'] as $d) {
            if (!is_dir($base . $d)) @mkdir($base . $d, 0777, true);
        }

        // Default the auto-SMS-to-parent setting to ON
        $r = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'auto_notify_parents' LIMIT 1");
        if ($r && $r->num_rows === 0) {
            $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('auto_notify_parents', '1')");
        }
    }
}
