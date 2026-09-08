<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../assets/phpqrcode/qrlib.php';

$root = dirname(__DIR__);
$res = $conn->query("SELECT laptop_id, laptop_number, qr_code_path FROM laptops ORDER BY laptop_id ASC");
if (!$res) {
    fwrite(STDERR, "DB query failed: " . $conn->error . PHP_EOL);
    exit(1);
}

$count = 0;
while ($row = $res->fetch_assoc()) {
    $lnum = trim((string)($row['laptop_number'] ?? ''));
    if ($lnum === '') {
        continue;
    }

    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $lnum);
    $expected = 'qrcodes/LAPTOP_' . $safe . '.png';
    $absPath = $root . '/' . $expected;
    $storedPath = trim((string)($row['qr_code_path'] ?? ''));
    $storedAbs = $storedPath !== '' ? $root . '/' . ltrim($storedPath, '/\\') : '';

    if ($storedPath !== '' && file_exists($storedAbs) && file_exists($absPath)) {
        echo "exists: {$lnum}" . PHP_EOL;
        continue;
    }

    @QRcode::png($lnum, $absPath, QR_ECLEVEL_L, 4);
    if (file_exists($absPath)) {
        $esc = $conn->real_escape_string($expected);
        $conn->query("UPDATE laptops SET qr_code_path='$esc' WHERE laptop_id=" . (int)$row['laptop_id']);
        echo "generated: {$lnum} -> {$expected}" . PHP_EOL;
        $count++;
    } else {
        echo "failed: {$lnum}" . PHP_EOL;
    }
}

echo "TOTAL_GENERATED={$count}" . PHP_EOL;
