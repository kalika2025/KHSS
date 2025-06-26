<?php
session_start();

$student_id = null;

// This page can be accessed in two ways:
// 1. Immediately after a new admission (ID is in the session)
if (isset($_SESSION['new_student_id'])) {
    $student_id = $_SESSION['new_student_id'];
    unset($_SESSION['new_student_id']); // Unset so it's only used once
} 
// 2. By providing an ID in the URL (for re-printing)
elseif (isset($_GET['id']) && is_numeric($_GET['id'])) {
    // Optional: Add a security check here
    // if (!isset($_SESSION['user_id'])) { header("Location: Authentication/login.php"); exit; }
    $student_id = (int)$_GET['id'];
} 
// If no ID is available, we can't proceed
else {
    header("Location: index.php");
    exit;
}

$student_details = null;
$school_info = null; // **NEW**: Variable to hold school data

// --- Database connection and fetching ALL details ---
try {
    $pdo = new PDO("mysql:host=localhost;dbname=school_management", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // **NEW**: Query to fetch active school info
    $stmt_school = $pdo->query("SELECT school_name, logo_path FROM school_info WHERE is_published = 'Y' LIMIT 1");
    $school_info = $stmt_school->fetch(PDO::FETCH_ASSOC);

    // Query to fetch student details
    $stmt_student = $pdo->prepare("
        SELECT 
            s.name, s.email, s.dob_bs, s.dob, s.address, s.gender, s.parent_contact, s.photo,
            se.roll_no,
            c.name AS class_name,
            u.username
        FROM students s
        LEFT JOIN users u ON s.user_id = u.id
        LEFT JOIN student_enrollments se ON s.id = se.student_id
        LEFT JOIN classes c ON se.class_id = c.id
        LEFT JOIN academic_years ay ON se.academic_year_id = ay.id
        WHERE s.id = :student_id
        ORDER BY ay.start_date DESC
        LIMIT 1
    ");
    $stmt_student->execute(['student_id' => $student_id]);
    $student_details = $stmt_student->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("A database error occurred. Please check the system logs. Error: " . $e->getMessage());
}

if (!$student_details) {
    die("Error: No student record found for the provided ID.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Student Admission Card</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body, html { background: #f5f5f5; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; }
    .print-area { width: 277mm; height: 190mm; margin: 20px auto; background: #fff; padding: 15mm; box-sizing: border-box; position: relative; overflow: hidden; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
    .logo { max-height: 80px; display: block; margin: 0 auto 20px; }
    .watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); font-size: 90px; color: #000; opacity: 0.1; z-index: 0; white-space: nowrap; pointer-events: none; font-weight: bold; }
    .student-photo { border: 4px solid #00509e; border-radius: 10px; max-width: 100%; height: 180px; width: 150px; object-fit: cover; box-shadow: 0 4px 6px rgba(0,0,0,0.2); }
    .table { z-index: 1; position: relative; font-size: 14px; }
    h4 { font-size: 24px; font-weight: 600; color: #003366; position: relative; z-index: 1; }
    th { background-color: #00509e; color: #fff; width: 30%; }
    td { background-color: #f7f7f7; }
    .print-btn { text-align: center; margin: 30px auto; }
    .btn-custom { background-color: #00509e; color: white; padding: 12px 30px; border-radius: 30px; font-size: 1.1rem; width: 200px; margin: 10px; transition: all 0.3s ease; text-decoration: none; border: none; display: inline-block; }
    .btn-custom:hover { background-color: #003366; transform: scale(1.05); color: white; }
    @media print {
      body, html { width: 297mm; height: 210mm; margin: 0; padding: 0; }
      .print-area { width: 277mm; height: 190mm; margin: 0; border: none; box-shadow: none; page-break-inside: avoid; }
      .print-btn { display: none; }
      .watermark { display: block; }
    }
  </style>
</head>
<body>
<div class="print-area">
  
  <!-- **NEW**: Dynamic Logo and Watermark -->
  <?php
    $logo_path = 'assets/img/logo.png'; // Default logo
    if ($school_info && !empty($school_info['logo_path'])) {
        $logo_path = "Authentication/admin/uploads/hero/" . $school_info['logo_path'];
    }
    $school_name = $school_info['school_name'] ?? 'Our School';
  ?>
  <img src="<?= htmlspecialchars($logo_path) ?>" alt="School Logo" class="logo rounded-circle">
  <div class="watermark"><?= htmlspecialchars($school_name) ?></div>

  <h4 class="text-center">Student Admission Card</h4>
  <hr>
  <div class="row">
    <div class="col-8">
      <table class="table table-sm table-bordered">
        <tr><th>Full Name</th><td><?= htmlspecialchars($student_details['name'] ?? 'N/A') ?></td></tr>
        <tr><th>Email</th><td><?= htmlspecialchars($student_details['email'] ?? 'N/A') ?></td></tr>
        <tr><th>Date of Birth (B.S.)</th><td><?= htmlspecialchars($student_details['dob_bs'] ?? 'N/A') ?></td></tr>
        <tr><th>Date of Birth (A.D.)</th><td><?= htmlspecialchars($student_details['dob'] ?? 'N/A') ?></td></tr>
        <tr><th>Address</th><td><?= htmlspecialchars($student_details['address'] ?? 'N/A') ?></td></tr>
        <tr><th>Gender</th><td><?= htmlspecialchars($student_details['gender'] ?? 'N/A') ?></td></tr>
        <tr><th>Parent Contact</th><td><?= htmlspecialchars($student_details['parent_contact'] ?? 'N/A') ?></td></tr>
        <tr><th>Class</th><td><?= htmlspecialchars($student_details['class_name'] ?? 'N/A') ?></td></tr>
        <tr><th>Roll Number</th><td><strong><?= htmlspecialchars($student_details['roll_no'] ?? 'N/A') ?></strong></td></tr>
      </table>
        <div class="mt-4">
            <h5 style="color: #003366;">Portal Login Information</h5>
            <table class="table table-sm table-bordered">
                <tr><th>Username</th><td><?= htmlspecialchars($student_details['username'] ?? 'N/A') ?></td></tr>
                <tr><th>Default Password</th><td>(Same as username)</td></tr>
            </table>
        </div>
    </div>
    <div class="col-4 text-center">
        <?php 
            $photoPath = "Authentication/admin/uploads/students/" . ($student_details['photo'] ?? '');
            if (!file_exists($photoPath) || empty($student_details['photo'])) {
                $photoPath = "assets/img/default.jpg";
            }
        ?>
        <img src="<?= htmlspecialchars($photoPath) ?>" alt="Student Photo" class="student-photo mt-2 mb-2">
        <h6 class="mt-2"><?= htmlspecialchars($student_details['name'] ?? 'Student Name') ?></h6>
    </div>
  </div>
</div>
<div class="print-btn">
  <button onclick="window.print()" class="btn btn-custom">Print</button>
  <a href="index.php" class="btn btn-custom">Homepage</a>
</div>
</body>
</html>