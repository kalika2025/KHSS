<?php
// DB connection
$host = "localhost";
$user = "root";
$password = ""; // Change to your database password
$dbname = "school_management"; // Change to your DB name

$conn = new PDO("mysql:host=$host;dbname=$dbname", $user, $password);
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Query stats
$response = [];

$stmt = $conn->query("SELECT COUNT(*) AS total FROM students");
$response['totalStudents'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $conn->query("SELECT COUNT(*) AS boys FROM students WHERE gender = 'Male'");
$response['boys'] = $stmt->fetch(PDO::FETCH_ASSOC)['boys'];

$stmt = $conn->query("SELECT COUNT(*) AS girls FROM students WHERE gender = 'Female'");
$response['girls'] = $stmt->fetch(PDO::FETCH_ASSOC)['girls'];

$stmt = $conn->query("SELECT COUNT(*) AS plusTwo FROM students WHERE class IN ('11', '12')");
$response['plusTwo'] = $stmt->fetch(PDO::FETCH_ASSOC)['plusTwo'];

// Send JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>
