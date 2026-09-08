<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

include('config/config.php');
require_once('tcpdf/tcpdf.php'); // adjust path if needed

// Get filters from GET
$student_id = $_GET['student_id'] ?? '';
$class = $_GET['class'] ?? '';
$stream = $_GET['stream'] ?? '';
$mode = $_GET['mode'] ?? '';

// Build query
$whereClauses = [];
$params = [];
$types = '';

if($student_id) {
    $whereClauses[] = "s.student_id = ?";
    $params[] = $student_id;
    $types .= 'i';
}
if($class) {
    $whereClauses[] = "s.class = ?";
    $params[] = $class;
    $types .= 's';
}
if($stream) {
    $whereClauses[] = "s.stream = ?";
    $params[] = $stream;
    $types .= 's';
}
if($mode) {
    $whereClauses[] = "l.model LIKE ?";
    $params[] = "%$mode%";
    $types .= 's';
}

$whereSQL = $whereClauses ? "WHERE ".implode(" AND ", $whereClauses) : "";

$stmt = $conn->prepare("SELECT s.name, s.class, s.stream, l.laptop_number, l.model, l.serial_number, l.os, l.status 
                        FROM students s 
                        LEFT JOIN laptops l ON s.student_id = l.student_id 
                        $whereSQL 
                        ORDER BY s.name ASC");

if($params) $stmt->bind_param($types, ...$params);

$stmt->execute();
$result = $stmt->get_result();

// Create PDF
$pdf = new TCPDF();
$pdf->SetCreator('Laptop Tracking System');
$pdf->SetAuthor('Admin');
$pdf->SetTitle('Student Laptop Report');
$pdf->SetMargins(15, 20, 15);
$pdf->AddPage();

// Title
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'Student Laptop Report', 0, 1, 'C');
$pdf->Ln(5);

// Table header
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(52, 152, 219);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(35, 10, 'Student', 1, 0, 'C', 1);
$pdf->Cell(20, 10, 'Class', 1, 0, 'C', 1);
$pdf->Cell(20, 10, 'Stream', 1, 0, 'C', 1);
$pdf->Cell(30, 10, 'Laptop #', 1, 0, 'C', 1);
$pdf->Cell(30, 10, 'Model', 1, 0, 'C', 1);
$pdf->Cell(30, 10, 'Serial', 1, 0, 'C', 1);
$pdf->Cell(20, 10, 'OS', 1, 0, 'C', 1);
$pdf->Cell(25, 10, 'Status', 1, 1, 'C', 1);

// Table content
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(0, 0, 0);

if($result->num_rows > 0){
    while($row = $result->fetch_assoc()){
        $pdf->Cell(35, 8, $row['name'], 1);
        $pdf->Cell(20, 8, $row['class'], 1);
        $pdf->Cell(20, 8, $row['stream'], 1);
        $pdf->Cell(30, 8, $row['laptop_number'], 1);
        $pdf->Cell(30, 8, $row['model'], 1);
        $pdf->Cell(30, 8, $row['serial_number'], 1);
        $pdf->Cell(20, 8, $row['os'], 1);
        $pdf->Cell(25, 8, $row['status'], 1);
        $pdf->Ln();
    }
} else {
    $pdf->Cell(210, 8, 'No results found.', 1, 1, 'C');
}

// Output as download
$pdf->Output('student_laptop_report.pdf', 'D');
exit();
?>
