<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

include('../config/config.php');
require_once('../includes/migrate.php');
lts_run_migrations($conn);

// ─── Aggregate counts ────────────────────────────────────────────────────────
$sql = "
    SELECT
      (SELECT COUNT(*) FROM students) AS total_students,
      (SELECT COUNT(*) FROM laptops)  AS total_laptops,
      (SELECT COUNT(*) FROM laptops WHERE status = 'issued')          AS issued_laptops,
      (SELECT COUNT(*) FROM laptops WHERE status = 'returned')        AS returned_laptops,
      (SELECT COUNT(*) FROM laptops WHERE status = 'out_for_project') AS out_for_project,
      (SELECT COUNT(*) FROM laptops WHERE status = 'back_to_school')  AS back_to_school,
      (SELECT COUNT(*) FROM laptops WHERE status = 'taken_home')      AS taken_home,
      (SELECT COUNT(*) FROM parents) AS total_parents,
      (SELECT COUNT(*) FROM users)   AS total_staff,
      (SELECT COUNT(*) FROM laptops WHERE status IN ('issued','out_for_project','taken_home') AND due_date IS NOT NULL AND due_date < NOW()) AS overdue_count
";
$res = $conn->query($sql);
$row = $res ? $res->fetch_assoc() : [];

$total_students   = (int)($row['total_students']   ?? 0);
$total_laptops    = (int)($row['total_laptops']    ?? 0);
$issued_laptops   = (int)($row['issued_laptops']   ?? 0);
$returned_laptops = (int)($row['returned_laptops'] ?? 0);
$out_for_project  = (int)($row['out_for_project']  ?? 0);
$back_to_school   = (int)($row['back_to_school']   ?? 0);
$taken_home       = (int)($row['taken_home']       ?? 0);
$total_parents    = (int)($row['total_parents']    ?? 0);
$total_staff      = (int)($row['total_staff']      ?? 0);
$overdue_count    = (int)($row['overdue_count']    ?? 0);

// Lab breakdown counts
$olevel_count = 0; $alevel_count = 0;
$olevel_issued_dash = 0; $alevel_issued_dash = 0;
$lab_res = $conn->query("SELECT lab, COUNT(*) AS total, SUM(status='issued') AS iss FROM laptops GROUP BY lab");
if ($lab_res) while ($lr = $lab_res->fetch_assoc()) {
    if ($lr['lab'] === 'olevel') { $olevel_count = (int)$lr['total']; $olevel_issued_dash = (int)$lr['iss']; }
    else { $alevel_count = (int)$lr['total']; $alevel_issued_dash = (int)$lr['iss']; }
}
// Laptops physically in school: total minus all off-campus statuses
$in_school = $total_laptops - $issued_laptops - $out_for_project - $taken_home;

// SMS metrics (last 24h) — gentle proof that auto-notify is alive
$sms_today = 0;
$rs = $conn->query("SELECT COUNT(*) c FROM notifications_log WHERE status = 'sent' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
if ($rs) $sms_today = (int)$rs->fetch_assoc()['c'];

// Week deltas with safe column fallback
function week_delta(mysqli $conn, string $table, array $candidate_cols): int {
    foreach ($candidate_cols as $col) {
        $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($col) . "'");
        if ($r && $r->num_rows) {
            $q = $conn->query("SELECT COUNT(*) AS c FROM `$table` WHERE `$col` >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
            if ($q) return (int)$q->fetch_assoc()['c'];
            return 0;
        }
    }
    return 0;
}
$d_students = week_delta($conn, 'students', ['created_at', 'date_added', 'added_at']);
$d_laptops  = week_delta($conn, 'laptops',  ['created_at', 'date_added', 'added_at']);
$d_parents  = week_delta($conn, 'parents',  ['created_at', 'date_added', 'added_at']);

function action_week(mysqli $conn, array $actions): int {
    if (empty($actions)) return 0;
    $in = "'" . implode("','", array_map([$conn,'real_escape_string'], $actions)) . "'";
    $q  = $conn->query("SELECT COUNT(*) AS c FROM logs WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND action IN ($in)");
    return $q ? (int)$q->fetch_assoc()['c'] : 0;
}
$d_issued   = action_week($conn, ['Issued Out', 'Issued']);
$d_returned = action_week($conn, ['Returned']);
$d_outproj  = action_week($conn, ['Out for Project']);
$d_home     = action_week($conn, ['Taken Home']);

// Last 14 days bar series
$days = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $days[$d] = ['Issued Out' => 0, 'Returned' => 0];
}
$ar = $conn->query("SELECT action, DATE(timestamp) AS d, COUNT(*) AS c
                    FROM logs
                    WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
                    GROUP BY DATE(timestamp), action");
if ($ar) while ($r = $ar->fetch_assoc()) {
    if (isset($days[$r['d']]) && isset($days[$r['d']][$r['action']])) {
        $days[$r['d']][$r['action']] = (int)$r['c'];
    }
}
$labels       = array_map(fn($d) => date('d M', strtotime($d)), array_keys($days));
$ser_issued   = array_map(fn($x) => $x['Issued Out'], $days);
$ser_returned = array_map(fn($x) => $x['Returned'],   $days);

// Recent activity feed (last 8 entries)
$activity = [];
$ra = $conn->query("SELECT lg.action, lg.staff_name, lg.timestamp,
                           l.laptop_number,
                           s.name AS student_name
                    FROM logs lg
                    LEFT JOIN laptops l ON l.laptop_id = lg.laptop_id
                    LEFT JOIN students s ON s.student_id = lg.student_id
                    ORDER BY lg.timestamp DESC
                    LIMIT 8");
if ($ra) while ($r = $ra->fetch_assoc()) $activity[] = $r;

// Greeting
$h = (int)date('G');
$greet = $h < 12 ? 'Good morning' : ($h < 18 ? 'Good afternoon' : 'Good evening');
$username = $_SESSION['username'] ?? 'Admin';

// % helpers
$pct = function ($num, $den) {
    if ($den <= 0) return 0;
    return max(0, min(100, round(($num / $den) * 100)));
};
$pct_issued    = $pct($issued_laptops,  max(1, $total_laptops));
$pct_returned  = $pct($returned_laptops, max(1, $issued_laptops + $returned_laptops));
$pct_outproj   = $pct($out_for_project, max(1, $total_laptops));
$pct_at_school = $pct($in_school, max(1, $total_laptops));

$page_title = 'Dashboard';
include('../includes/header.php');
?>
<style>
/* ============================================================
   Premium Dashboard — refined slate / indigo palette
   ============================================================ */
:root {
    --d-hero-from: #0f172a;
    --d-hero-to:   #1e1b4b;
    --d-accent:    #818cf8;
    --d-accent-2:  #34d399;
    --d-warning:   #fbbf24;
    --d-danger:    #f87171;
}
[data-theme="dark"] {
    --d-hero-from: #020617;
    --d-hero-to:   #1e1b4b;
}

.dash {
    display: flex; flex-direction: column; gap: 18px;
    max-width: 1400px; margin: 0 auto;
}

/* ── Hero ───────────────────────────────────────────── */
.dash-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(135deg, var(--d-hero-from) 0%, var(--d-hero-to) 100%);
    border-radius: 22px;
    padding: 28px 30px;
    color: #fff;
    box-shadow: 0 20px 40px -20px rgba(15,23,42,.45);
}
.dash-hero::before {
    content: ""; position: absolute; right: -90px; top: -90px;
    width: 320px; height: 320px; border-radius: 50%;
    background: radial-gradient(circle at center, rgba(129,140,248,.35), transparent 70%);
    pointer-events: none;
}
.dash-hero::after {
    content: ""; position: absolute; left: -60px; bottom: -120px;
    width: 280px; height: 280px; border-radius: 50%;
    background: radial-gradient(circle at center, rgba(52,211,153,.18), transparent 70%);
    pointer-events: none;
}
.hero-top { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 18px; position: relative; z-index: 1; }
.hero-greet .eyebrow { color: rgba(255,255,255,.65); font-size: 12px; text-transform: uppercase; letter-spacing: .12em; font-weight: 600; margin-bottom: 6px; }
.hero-greet h1 { font-size: 30px; font-weight: 700; margin: 0; letter-spacing: -.02em; line-height: 1.15; }
.hero-greet h1 .wave { display: inline-block; animation: wave 2.6s ease-in-out infinite; transform-origin: 70% 70%; }
@keyframes wave { 0%,60%,100%{transform:rotate(0)} 10%{transform:rotate(14deg)} 20%{transform:rotate(-8deg)} 30%{transform:rotate(14deg)} 40%{transform:rotate(-4deg)} 50%{transform:rotate(10deg)} }
.hero-greet .sub { color: rgba(255,255,255,.7); font-size: 14px; margin-top: 6px; }
.hero-actions { display: flex; gap: 8px; flex-wrap: wrap; position: relative; z-index: 1; }
.hero-btn {
    background: rgba(255,255,255,.1); color: #fff; border: 1px solid rgba(255,255,255,.18);
    padding: 9px 16px; border-radius: 10px; font-weight: 600; font-size: 13px; text-decoration: none;
    display: inline-flex; align-items: center; gap: 8px; transition: all .15s ease; backdrop-filter: blur(6px);
}
.hero-btn:hover { background: rgba(255,255,255,.18); color: #fff; transform: translateY(-1px); }
.hero-btn.primary { background: #fff; color: #0f172a; border-color: #fff; }
.hero-btn.primary:hover { background: rgba(255,255,255,.92); color: #0f172a; }
.hero-btn .pip { background: var(--d-danger); color: #fff; font-size: 11px; padding: 1px 7px; border-radius: 999px; font-weight: 700; }

/* Hero search */
.hero-search { margin-top: 22px; position: relative; z-index: 1; max-width: 640px; }
.hero-search form {
    display: flex; align-items: center; gap: 10px;
    background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.18);
    border-radius: 14px; padding: 6px 6px 6px 16px; backdrop-filter: blur(6px);
}
.hero-search i.fa-magnifying-glass { color: rgba(255,255,255,.7); }
.hero-search input {
    flex: 1; background: transparent; border: 0; outline: 0; color: #fff;
    font-size: 14px; padding: 10px 4px; min-width: 100px;
}
.hero-search input::placeholder { color: rgba(255,255,255,.55); }
.hero-search button {
    background: #fff; color: #0f172a; border: 0; border-radius: 10px;
    padding: 9px 18px; font-weight: 700; font-size: 13px; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
}

/* Hero KPI strip */
.hero-kpis {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px;
    margin-top: 22px; position: relative; z-index: 1;
}
@media (max-width: 900px) { .hero-kpis { grid-template-columns: repeat(2, 1fr); } }
.kpi {
    background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.14);
    border-radius: 14px; padding: 14px 16px; backdrop-filter: blur(8px);
}
.kpi .l { color: rgba(255,255,255,.65); font-size: 11.5px; text-transform: uppercase; letter-spacing: .08em; font-weight: 600; }
.kpi .v { color: #fff; font-size: 26px; font-weight: 700; line-height: 1.05; margin-top: 4px; letter-spacing: -.02em; }
.kpi .t { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; font-weight: 600; margin-top: 6px; padding: 2px 8px; border-radius: 999px; }
.kpi .t.up   { background: rgba(52,211,153,.18); color: #6ee7b7; }
.kpi .t.warn { background: rgba(251,191,36,.18); color: #fcd34d; }
.kpi .t.bad  { background: rgba(248,113,113,.18); color: #fca5a5; }
.kpi .t.flat { background: rgba(255,255,255,.10); color: rgba(255,255,255,.7); }

/* ── Main grid ─────────────────────────────────────── */
.main-grid {
    display: grid; grid-template-columns: 2fr 1fr; gap: 18px;
}
@media (max-width: 1100px) { .main-grid { grid-template-columns: 1fr; } }

.card {
    background: var(--bg-elev); border: 1px solid var(--border);
    border-radius: 18px; padding: 20px;
    box-shadow: 0 1px 3px rgba(15,23,42,.05);
}
.card-head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 16px; gap: 10px; flex-wrap: wrap;
}
.card-head h3 { font-size: 15px; font-weight: 700; margin: 0; color: var(--text); letter-spacing: -.01em; }
.card-head .sub { font-size: 12.5px; color: var(--text-muted); margin-top: 2px; }
.card-head .right { display: flex; gap: 6px; align-items: center; }
.card-head .seg {
    display: inline-flex; background: var(--bg-soft); padding: 3px; border-radius: 10px;
    border: 1px solid var(--border);
}
.card-head .seg button {
    border: 0; background: transparent; padding: 6px 12px; border-radius: 8px;
    font-size: 12px; font-weight: 600; color: var(--text-muted); cursor: pointer;
}
.card-head .seg button.on { background: var(--bg-elev); color: var(--text); box-shadow: 0 1px 2px rgba(15,23,42,.06); }

/* Status overview cards */
.status-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
@media (max-width: 720px) { .status-grid { grid-template-columns: repeat(2, 1fr); } }

.s-card {
    position: relative; overflow: hidden; padding: 16px; border-radius: 14px;
    background: var(--bg-elev); border: 1px solid var(--border);
    text-decoration: none; color: var(--text);
    transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
    display: flex; flex-direction: column; min-height: 130px;
}
.s-card:hover { transform: translateY(-2px); box-shadow: 0 12px 24px -10px rgba(15,23,42,.18); color: var(--text); border-color: var(--border-strong, var(--border)); }
.s-card .top { display: flex; align-items: center; justify-content: space-between; }
.s-card .ico {
    width: 38px; height: 38px; border-radius: 10px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 16px;
}
.s-card .pill {
    font-size: 11px; padding: 3px 8px; border-radius: 999px; font-weight: 700;
    background: var(--bg-soft); color: var(--text-muted);
    display: inline-flex; align-items: center; gap: 4px;
}
.s-card .lbl { font-size: 12.5px; font-weight: 600; color: var(--text-muted); margin-top: 12px; }
.s-card .v   { font-size: 28px; font-weight: 700; letter-spacing: -.02em; color: var(--text); margin-top: 2px; line-height: 1; }
.s-card .bar { height: 4px; border-radius: 999px; background: var(--bg-soft); overflow: hidden; margin-top: 12px; }
.s-card .bar > span { display:block; height: 100%; border-radius: 999px; transition: width .4s ease; }

.s-card.s-issued   .ico { background: rgba(99,102,241,.12); color: #6366f1; }
.s-card.s-issued   .bar > span { background: linear-gradient(90deg, #818cf8, #6366f1); }
.s-card.s-returned .ico { background: rgba(52,211,153,.14); color: #10b981; }
.s-card.s-returned .bar > span { background: linear-gradient(90deg, #6ee7b7, #10b981); }
.s-card.s-out      .ico { background: rgba(251,191,36,.16); color: #d97706; }
.s-card.s-out      .bar > span { background: linear-gradient(90deg, #fcd34d, #d97706); }
.s-card.s-school   .ico { background: rgba(56,189,248,.14); color: #0284c7; }
.s-card.s-school   .bar > span { background: linear-gradient(90deg, #7dd3fc, #0284c7); }

/* Chart card */
.chart-wrap { position: relative; height: 280px; }
.chart-legend { display: flex; gap: 16px; align-items: center; font-size: 12.5px; color: var(--text-muted); flex-wrap: wrap; }
.chart-legend .li { display:inline-flex; align-items:center; gap:6px; font-weight: 600; }
.chart-legend .dot { width: 10px; height: 10px; border-radius: 3px; }

/* Right column */
.rc { display: flex; flex-direction: column; gap: 18px; }
.donut-card .donut-wrap { position: relative; max-width: 220px; margin: 8px auto 12px; }
.donut-center {
    position: absolute; inset: 0; display: flex; flex-direction: column;
    align-items: center; justify-content: center; pointer-events: none;
}
.donut-center .v { font-size: 28px; font-weight: 700; color: var(--text); letter-spacing: -.02em; }
.donut-center .l { font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .08em; }
.donut-legend { display: flex; flex-direction: column; gap: 10px; font-size: 13px; }
.donut-legend .item { display: flex; align-items: center; gap: 10px; }
.donut-legend .dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
.donut-legend .item .lbl { color: var(--text-muted); flex: 1; font-weight: 500; }
.donut-legend .item .v { font-weight: 700; color: var(--text); }

/* Activity feed */
.feed { display: flex; flex-direction: column; gap: 0; }
.feed .row {
    display: flex; align-items: flex-start; gap: 12px; padding: 12px 0;
    border-bottom: 1px solid var(--border);
}
.feed .row:last-child { border-bottom: 0; }
.feed .ico {
    width: 34px; height: 34px; border-radius: 10px;
    display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
    font-size: 13px;
}
.feed .ico.a-issued   { background: rgba(99,102,241,.12);  color: #6366f1; }
.feed .ico.a-returned { background: rgba(52,211,153,.14);  color: #10b981; }
.feed .ico.a-out      { background: rgba(251,191,36,.16);  color: #d97706; }
.feed .ico.a-home     { background: rgba(244,114,182,.14); color: #db2777; }
.feed .ico.a-school   { background: rgba(56,189,248,.14);  color: #0284c7; }
.feed .body { flex: 1; min-width: 0; }
.feed .body .l1 { font-size: 13.5px; color: var(--text); font-weight: 600; line-height: 1.3; }
.feed .body .l1 b { color: var(--text); }
.feed .body .l2 { font-size: 11.5px; color: var(--text-muted); margin-top: 3px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.feed .body .l2 .tag { background: var(--bg-soft); padding: 1px 7px; border-radius: 999px; font-weight: 600; }
.feed-empty { text-align: center; padding: 30px 0; color: var(--text-muted); font-size: 13px; }

/* SMS notice card */
.sms-notice {
    background: linear-gradient(135deg, rgba(16,185,129,.08), rgba(99,102,241,.08));
    border: 1px solid rgba(16,185,129,.20);
    border-radius: 14px; padding: 14px 16px;
    display: flex; align-items: center; gap: 12px;
}
[data-theme="dark"] .sms-notice {
    background: linear-gradient(135deg, rgba(16,185,129,.12), rgba(99,102,241,.12));
    border-color: rgba(16,185,129,.25);
}
.sms-notice .ico { width: 40px; height: 40px; border-radius: 10px; background: rgba(16,185,129,.14); color: #10b981; display:inline-flex; align-items:center; justify-content:center; font-size: 16px; flex-shrink: 0; }
.sms-notice .text { flex: 1; }
.sms-notice .t1 { font-size: 13.5px; font-weight: 700; color: var(--text); }
.sms-notice .t2 { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.sms-notice a { color: var(--primary); font-weight: 600; text-decoration: none; }
.sms-notice a:hover { text-decoration: underline; }
</style>

<div class="dash">

  <!-- Hero -->
  <div class="dash-hero">
    <div class="hero-top">
      <div class="hero-greet">
        <div class="eyebrow">St. Mark's College • Laptop Tracking</div>
        <h1><?= $greet ?>, <?= htmlspecialchars($username) ?> <span class="wave">👋</span></h1>
        <div class="sub">Here's what's happening across the lab today.</div>
      </div>
      <div class="hero-actions">
        <a href="overdue.php" class="hero-btn"><i class="fas fa-bell"></i> Overdue
          <?php if ($overdue_count > 0): ?><span class="pip"><?= $overdue_count ?></span><?php endif; ?>
        </a>
        <a href="import.php" class="hero-btn"><i class="fas fa-file-import"></i> Bulk Import</a>
        <a href="scan.php" class="hero-btn primary"><i class="fas fa-qrcode"></i> Quick Scan</a>
      </div>
    </div>

    <div class="hero-search">
      <form method="GET" action="student_details.php">
        <i class="fas fa-magnifying-glass"></i>
        <input type="search" name="query" placeholder="Search by laptop number, student name, or QR code…">
        <button type="submit"><i class="fas fa-magnifying-glass"></i> Search</button>
      </form>
    </div>

    <div class="hero-kpis">
      <div class="kpi">
        <div class="l">Total Laptops</div>
        <div class="v"><?= $total_laptops ?></div>
        <div class="t flat"><i class="fas fa-laptop"></i> <?= $d_laptops ?> added this week</div>
      </div>
      <div class="kpi">
        <div class="l">Currently Issued</div>
        <div class="v"><?= $issued_laptops + $out_for_project + $taken_home ?></div>
        <div class="t up"><i class="fas fa-arrow-up"></i> <?= $d_issued + $d_outproj + $d_home ?> this week</div>
      </div>
      <div class="kpi">
        <div class="l">Overdue</div>
        <div class="v"><?= $overdue_count ?></div>
        <div class="t <?= $overdue_count ? 'bad' : 'up' ?>"><i class="fas fa-<?= $overdue_count ? 'triangle-exclamation' : 'check' ?>"></i> <?= $overdue_count ? 'needs attention' : 'all on time' ?></div>
      </div>
      <div class="kpi">
        <div class="l">SMS Sent (24h)</div>
        <div class="v"><?= $sms_today ?></div>
        <div class="t up"><i class="fas fa-paper-plane"></i> Auto-notify on</div>
      </div>
    </div>
  </div>

  <!-- Auto-SMS confirmation strip -->
  <div class="sms-notice">
    <div class="ico"><i class="fas fa-comment-sms"></i></div>
    <div class="text">
      <div class="t1">Parents are notified automatically</div>
      <div class="t2">Every time a laptop is issued, returned, taken home or out for a project, an SMS is sent to the parent — date and time are captured automatically. <a href="sms_settings.php">Manage SMS settings</a></div>
    </div>
  </div>

  <!-- Status overview -->
  <div class="card">
    <div class="card-head">
      <div>
        <h3>Laptop Status Overview</h3>
        <div class="sub">Live snapshot of where every laptop is right now.</div>
      </div>
    </div>
    <div class="status-grid">
      <a href="issued.php" class="s-card s-issued">
        <div class="top">
          <span class="ico"><i class="fas fa-paper-plane"></i></span>
          <span class="pill"><i class="fas fa-arrow-up"></i> <?= $d_issued ?>/wk</span>
        </div>
        <div class="lbl">Issued in Lab</div>
        <div class="v"><?= $issued_laptops ?></div>
        <div class="bar"><span style="width: <?= $pct_issued ?>%"></span></div>
      </a>
      <a href="returned.php" class="s-card s-returned">
        <div class="top">
          <span class="ico"><i class="fas fa-rotate-left"></i></span>
          <span class="pill"><i class="fas fa-arrow-up"></i> <?= $d_returned ?>/wk</span>
        </div>
        <div class="lbl">Returned</div>
        <div class="v"><?= $returned_laptops ?></div>
        <div class="bar"><span style="width: <?= $pct_returned ?>%"></span></div>
      </a>
      <a href="out.php" class="s-card s-out">
        <div class="top">
          <span class="ico"><i class="fas fa-diagram-project"></i></span>
          <span class="pill"><i class="fas fa-arrow-up"></i> <?= $d_outproj ?>/wk</span>
        </div>
        <div class="lbl">Out for Project</div>
        <div class="v"><?= $out_for_project ?></div>
        <div class="bar"><span style="width: <?= $pct_outproj ?>%"></span></div>
      </a>
      <a href="back_to_school.php" class="s-card s-school">
        <div class="top">
          <span class="ico"><i class="fas fa-school"></i></span>
          <span class="pill"><i class="fas fa-house"></i> <?= $taken_home ?> at home</span>
        </div>
        <div class="lbl">Back at School</div>
        <div class="v"><?= $in_school ?></div>
        <div class="bar"><span style="width: <?= $pct_at_school ?>%"></span></div>
      </a>
    </div>
  </div>

  <!-- Main grid: chart + right column -->
  <!-- Lab allocation strip -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
  <a href="labs.php?lab=olevel" style="text-decoration:none;">
    <div style="background:var(--bg-elev);border:2px solid #3b82f6;border-radius:16px;padding:18px 22px;display:flex;align-items:center;gap:16px;transition:transform .15s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
      <div style="width:46px;height:46px;border-radius:12px;background:rgba(59,130,246,.14);color:#3b82f6;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;"><i class="fas fa-computer"></i></div>
      <div style="flex:1;">
        <div style="font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);">O-Level Cyber Lab</div>
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;">Classes: S.1 &middot; S.3 &middot; S.4</div>
        <div style="display:flex;gap:20px;">
          <div><span style="font-size:22px;font-weight:800;color:var(--text);"><?= $olevel_count ?></span><span style="font-size:11px;color:var(--text-muted);margin-left:4px;">total</span></div>
          <div><span style="font-size:22px;font-weight:800;color:#3b82f6;"><?= $olevel_issued_dash ?></span><span style="font-size:11px;color:var(--text-muted);margin-left:4px;">issued</span></div>
        </div>
      </div>
      <i class="fas fa-arrow-right" style="color:var(--text-muted);"></i>
    </div>
  </a>
  <a href="labs.php?lab=alevel" style="text-decoration:none;">
    <div style="background:var(--bg-elev);border:2px solid #8b5cf6;border-radius:16px;padding:18px 22px;display:flex;align-items:center;gap:16px;transition:transform .15s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
      <div style="width:46px;height:46px;border-radius:12px;background:rgba(139,92,246,.14);color:#8b5cf6;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;"><i class="fas fa-desktop"></i></div>
      <div style="flex:1;">
        <div style="font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);">A-Level Cyber Lab</div>
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;">Classes: S.2 &middot; S.5 &middot; S.6</div>
        <div style="display:flex;gap:20px;">
          <div><span style="font-size:22px;font-weight:800;color:var(--text);"><?= $alevel_count ?></span><span style="font-size:11px;color:var(--text-muted);margin-left:4px;">total</span></div>
          <div><span style="font-size:22px;font-weight:800;color:#8b5cf6;"><?= $alevel_issued_dash ?></span><span style="font-size:11px;color:var(--text-muted);margin-left:4px;">issued</span></div>
        </div>
      </div>
      <i class="fas fa-arrow-right" style="color:var(--text-muted);"></i>
    </div>
  </a>
</div>

<div class="main-grid">

    <!-- Activity chart -->
    <div class="card">
      <div class="card-head">
        <div>
          <h3>Activity (last 14 days)</h3>
          <div class="sub">Issued out vs returned</div>
        </div>
        <div class="chart-legend">
          <span class="li"><span class="dot" style="background:#6366f1"></span> Issued</span>
          <span class="li"><span class="dot" style="background:#10b981"></span> Returned</span>
        </div>
      </div>
      <div class="chart-wrap"><canvas id="activityChart"></canvas></div>
    </div>

    <div class="rc">

      <!-- Donut card -->
      <div class="card donut-card">
        <div class="card-head">
          <div>
            <h3>Inventory Distribution</h3>
            <div class="sub">By current status</div>
          </div>
        </div>
        <div class="donut-wrap">
          <canvas id="donutChart"></canvas>
          <div class="donut-center">
            <div class="v"><?= $total_laptops ?></div>
            <div class="l">Total</div>
          </div>
        </div>
        <div class="donut-legend">
          <div class="item"><span class="dot" style="background:#0284c7"></span><span class="lbl">In School</span><span class="v"><?= $in_school ?></span></div>
          <div class="item"><span class="dot" style="background:#6366f1"></span><span class="lbl">Issued in Lab</span><span class="v"><?= $issued_laptops ?></span></div>
          <div class="item"><span class="dot" style="background:#d97706"></span><span class="lbl">Out for Project</span><span class="v"><?= $out_for_project ?></span></div>
          <div class="item"><span class="dot" style="background:#db2777"></span><span class="lbl">Taken Home</span><span class="v"><?= $taken_home ?></span></div>
          <div class="item"><span class="dot" style="background:#10b981"></span><span class="lbl">Returned</span><span class="v"><?= $returned_laptops ?></span></div>
        </div>
      </div>

      <!-- Recent activity feed -->
      <div class="card">
        <div class="card-head">
          <div>
            <h3>Recent Activity</h3>
            <div class="sub">Latest laptop transactions</div>
          </div>
          <a href="logs.php" class="hero-btn" style="background: var(--bg-soft); color: var(--text); border-color: var(--border); padding: 6px 12px; font-size: 12px;">View all</a>
        </div>
        <div class="feed">
          <?php if (empty($activity)): ?>
            <div class="feed-empty"><i class="fas fa-inbox" style="font-size:24px; opacity:.4; display:block; margin-bottom:8px;"></i> No activity yet — scan a laptop to get started.</div>
          <?php else: foreach ($activity as $a):
              $act = $a['action'];
              $cls = 'a-issued';
              $ico = 'fa-paper-plane';
              if ($act === 'Returned')           { $cls = 'a-returned'; $ico = 'fa-rotate-left'; }
              elseif ($act === 'Out for Project') { $cls = 'a-out';      $ico = 'fa-diagram-project'; }
              elseif ($act === 'Taken Home')      { $cls = 'a-home';     $ico = 'fa-house'; }
              elseif ($act === 'Back to School')  { $cls = 'a-school';   $ico = 'fa-school'; }
              $when = strtotime($a['timestamp']);
              $diff = time() - $when;
              if ($diff < 60)        $rel = 'just now';
              elseif ($diff < 3600)  $rel = floor($diff/60) . 'm ago';
              elseif ($diff < 86400) $rel = floor($diff/3600) . 'h ago';
              else                   $rel = date('d M, H:i', $when);
          ?>
            <div class="row">
              <div class="ico <?= $cls ?>"><i class="fas <?= $ico ?>"></i></div>
              <div class="body">
                <div class="l1"><?= htmlspecialchars($a['student_name'] ?? 'Unknown student') ?> &middot; <?= htmlspecialchars($act) ?></div>
                <div class="l2">
                  <?php if (!empty($a['laptop_number'])): ?><span class="tag">#<?= htmlspecialchars($a['laptop_number']) ?></span><?php endif; ?>
                  <span><i class="far fa-clock"></i> <?= $rel ?></span>
                  <?php if (!empty($a['staff_name'])): ?><span><i class="far fa-user"></i> <?= htmlspecialchars($a['staff_name']) ?></span><?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function(){
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  const grid   = isDark ? 'rgba(255,255,255,.07)' : 'rgba(15,23,42,.06)';
  const tick   = isDark ? 'rgba(255,255,255,.55)' : 'rgba(15,23,42,.55)';
  Chart.defaults.font.family = "Inter, -apple-system, system-ui, sans-serif";
  Chart.defaults.color = tick;

  // --- Activity chart ---
  const aCtx = document.getElementById('activityChart').getContext('2d');
  const gIss = aCtx.createLinearGradient(0, 0, 0, 280);
  gIss.addColorStop(0, 'rgba(99,102,241,.55)'); gIss.addColorStop(1, 'rgba(99,102,241,0)');
  const gRet = aCtx.createLinearGradient(0, 0, 0, 280);
  gRet.addColorStop(0, 'rgba(16,185,129,.45)'); gRet.addColorStop(1, 'rgba(16,185,129,0)');
  new Chart(aCtx, {
    type: 'line',
    data: {
      labels: <?= json_encode(array_values($labels)) ?>,
      datasets: [
        { label: 'Issued',   data: <?= json_encode(array_values($ser_issued)) ?>,
          borderColor: '#6366f1', backgroundColor: gIss, tension: .35, fill: true,
          borderWidth: 2.5, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#6366f1' },
        { label: 'Returned', data: <?= json_encode(array_values($ser_returned)) ?>,
          borderColor: '#10b981', backgroundColor: gRet, tension: .35, fill: true,
          borderWidth: 2.5, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#10b981' }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0f172a', padding: 10, cornerRadius: 8, displayColors: true } },
      scales: {
        x: { grid: { display: false }, ticks: { font: { size: 11 } } },
        y: { grid: { color: grid, drawBorder: false }, ticks: { font: { size: 11 }, precision: 0 }, beginAtZero: true }
      },
      interaction: { mode: 'index', intersect: false }
    }
  });

  // --- Donut chart ---
  const dCtx = document.getElementById('donutChart').getContext('2d');
  new Chart(dCtx, {
    type: 'doughnut',
    data: {
      labels: ['Back at School', 'Issued in Lab', 'Out for Project', 'Taken Home', 'Returned'],
      datasets: [{
        data: [<?= $back_to_school ?>, <?= $issued_laptops ?>, <?= $out_for_project ?>, <?= $taken_home ?>, <?= $returned_laptops ?>],
        backgroundColor: ['#0284c7', '#6366f1', '#d97706', '#db2777', '#10b981'],
        borderWidth: 0, cutout: '72%', spacing: 2
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: true,
      plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0f172a', padding: 10, cornerRadius: 8 } }
    }
  });
})();
</script>

<?php include('../includes/footer.php'); ?>
