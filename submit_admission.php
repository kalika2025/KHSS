<?php
session_start();

// Database connection
$host = "localhost";
$dbname = "school_management";
$username = "root";
$password = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $_SESSION['error'] = "Database connection failed. Please contact the administrator.";
    header("Location: admission.php");
    exit;
}

// Function to get the next available user ID
function getNextId($pdo, $role) {
    $ranges = ['admin' => [1, 20], 'teacher' => [21, 100], 'student' => [101, 9999]];
    if (!isset($ranges[$role])) return false;
    list($min, $max) = $ranges[$role];

    $stmt = $pdo->prepare("SELECT MAX(id) FROM users WHERE id BETWEEN :min AND :max");
    $stmt->execute(['min' => $min, 'max' => $max]);
    $lastId = $stmt->fetchColumn();
    $nextId = $lastId ? $lastId + 1 : $min;
    return ($nextId > $max) ? false : $nextId;
}

// Function to generate the next roll number for a specific class and year
function generateNextRollNo($pdo, $class_id, $academic_year_id) {
    $stmt = $pdo->prepare("SELECT MAX(roll_no) FROM student_enrollments WHERE class_id = :class_id AND academic_year_id = :academic_year_id");
    $stmt->execute(['class_id' => $class_id, 'academic_year_id' => $academic_year_id]);
    $lastRollNo = $stmt->fetchColumn();
    return $lastRollNo ? $lastRollNo + 1 : 1;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize and retrieve POST data
    $fullname = trim($_POST['fullname']);
    $email = trim($_POST['email']);
    $dob_bs = $_POST['dob_bs'] ?? null;
    $dob_ad = $_POST['dob_ad'] ?? null;
    $class_id = $_POST['class_id'];
    $parent_contact = trim($_POST['parent_contact']);
    $gender = $_POST['gender'];
    $address = trim($_POST['address']);
    $section = trim($_POST['section'] ?? '');

    $_SESSION['form_data'] = $_POST; // Save for repopulation on error

    // --- Validation ---
    if (empty($fullname) || empty($email) || empty($dob_ad) || empty($class_id) || empty($gender) || empty($parent_contact)) {
        $_SESSION['error'] = "❌ Please fill in all required fields.";
        header("Location: admission.php"); exit;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn() > 0) {
        $_SESSION['error'] = "❌ This email address is already registered.";
        header("Location: admission.php"); exit;
    }

    // --- Prepare data for insertion ---
    $stmt_year = $pdo->query("SELECT id FROM academic_years WHERE is_current = 'Y' LIMIT 1");
    $current_academic_year_id = $stmt_year->fetchColumn();
    if (!$current_academic_year_id) {
        $_SESSION['error'] = "⚠️ No current academic year is set. Please contact the administrator.";
        header("Location: admission.php"); exit;
    }
    
    $nextId = getNextId($pdo, 'student');
    if ($nextId === false) {
        $_SESSION['error'] = "⚠️ The student ID range is full. Please contact the administrator.";
        header("Location: admission.php"); exit;
    }

    $username_base = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $fullname));
    $username = $username_base . $nextId;
    $default_password = password_hash($username, PASSWORD_BCRYPT);
    $roll_no = generateNextRollNo($pdo, $class_id, $current_academic_year_id);

    // --- Photo Upload ---
    $photoLocation = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $targetDir = "Authentication/admin/uploads/students/";
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $photoLocation = "student_" . uniqid() . "." . $ext;
        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $targetDir . $photoLocation)) {
            $_SESSION['error'] = "⚠️ There was an error uploading the photo.";
            header("Location: admission.php"); exit;
        }
    }

    // --- Database Transaction ---
    try {
        $pdo->beginTransaction();

        // 1. Insert into users table
        $stmt_users = $pdo->prepare("INSERT INTO users (id, username, password, role, name, email, gender, is_active) VALUES (:id, :username, :password, 'student', :name, :email, :gender, 'Y')");
        $stmt_users->execute(['id' => $nextId, 'username' => $username, 'password' => $default_password, 'name' => $fullname, 'email' => $email, 'gender' => strtolower($gender)]);
        
        // 2. Insert into students table
        $stmt_students = $pdo->prepare("INSERT INTO students (name, email, dob_bs, dob, address, gender, user_id, parent_contact, photo, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Y')");
        $stmt_students->execute([$fullname, $email, $dob_bs, $dob_ad, $address, $gender, $nextId, $parent_contact, $photoLocation]);
        $student_profile_id = $pdo->lastInsertId();

        // 3. Insert into student_enrollments table
        $stmt_enrollment = $pdo->prepare("INSERT INTO student_enrollments (student_id, class_id, academic_year_id, section, roll_no, status) VALUES (?, ?, ?, ?, ?, 'Enrolled')");
        $stmt_enrollment->execute([$student_profile_id, $class_id, $current_academic_year_id, $section, $roll_no]);

        $pdo->commit();

        // Store ONLY the new ID in the session for the confirmation page
        $_SESSION['new_student_id'] = $student_profile_id;

        unset($_SESSION['form_data']);
        header("Location: admission_confirmation.php");
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "⚠️ An error occurred during admission. Please try again. Error: " . $e->getMessage();
        header("Location: admission.php");
        exit;
    }
}
?>