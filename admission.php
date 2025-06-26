<?php
session_start();

// Get old form values if they exist, then clear them
$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);

// --- NEW: Database connection to fetch classes ---
$classes = [];
try {
    $pdo = new PDO("mysql:host=localhost;dbname=school_management", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->query("SELECT id, name FROM classes ORDER BY id");
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If DB fails, the form can't be submitted correctly anyway.
    $_SESSION['error'] = "Could not load class list. Please contact administrator.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Online Admission Form</title>
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/css/nepali.datepicker.v4.0.8.min.css" rel="stylesheet">
  <style>
    body { font-family: 'Poppins', sans-serif; background-color: #f4f7fc; margin: 0; padding: 0; }
    .container { max-width: 800px; margin: 50px auto; padding: 30px; background-color: #fff; border-radius: 12px; box-shadow: 0px 4px 15px rgba(0, 0, 0, 0.1); transition: box-shadow 0.3s ease-in-out; }
    .container:hover { box-shadow: 0px 10px 40px rgba(0, 255, 255, 0.2), 0px 0px 60px rgba(0, 255, 255, 0.3); animation: animateGlow 3s linear infinite; }
    @keyframes animateGlow { 0% { filter: hue-rotate(0deg); } 50% { filter: hue-rotate(180deg); } 100% { filter: hue-rotate(360deg); } }
    h3 { font-size: 1.8rem; color: #003366; font-weight: 600; margin-bottom: 30px; text-align: center; }
    .form-control, .form-select { border-radius: 8px; padding: 12px; font-size: 1rem; transition: box-shadow 0.3s ease, transform 0.3s ease; margin-bottom: 15px; }
    .form-control:focus, .form-select:focus { box-shadow: 0 0 15px rgba(0, 255, 255, 0.5); transform: scale(1.02); }
    .form-control:hover, .form-select:hover { box-shadow: 0 0 12px rgba(0, 255, 255, 0.4); transform: scale(1.02); }
    .btn-submit { background-color: #00509e; color: white; padding: 12px 30px; border-radius: 30px; font-size: 1.1rem; width: 100%; transition: all 0.3s ease, box-shadow 0.3s ease; cursor: pointer; border: none; }
    .btn-submit:hover { background-color: #003366; transform: scale(1.05); box-shadow: 0px 0px 15px rgba(0, 255, 255, 0.5); }
    .form-label { font-weight: bold; font-size: 1rem; margin-bottom: 8px; color: #003366; }
    .file-input { font-size: 1rem; color: #003366; }
    .file-input::-webkit-file-upload-button { background-color: #00509e; color: white; padding: 10px 15px; border-radius: 12px; cursor: pointer; transition: background-color 0.3s ease, transform 0.3s ease; }
    .file-input::-webkit-file-upload-button:hover { background-color: #003366; transform: scale(1.05); }
    .btn-submit:active { box-shadow: 0px 0px 25px rgba(0, 255, 255, 0.7); }
  </style>
</head>
<body style="background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif;">
  <div class="container my-5">
    <div class="card shadow-lg border-0">
      <div class="card-body p-5">
        <h3 class="text-center mb-4 text-primary fw-bold">🎓 Online Admission Form</h3>

        <?php if (isset($_SESSION['error'])): ?>
          <div class="alert alert-danger text-center">
            <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
          </div>
        <?php endif; ?>

        <form action="submit_admission.php" method="POST" enctype="multipart/form-data" class="row g-4">
          <!-- Upload Photo and Preview -->
          <div class="col-12">
            <div class="row align-items-center">
              <div class="col-12 col-md-6 mb-3 mb-md-0">
                <label class="form-label">Upload Photo</label>
                <input type="file" name="photo" id="photoInput" class="form-control file-input" accept="image/*" required>
              </div>
              <div class="col-12 col-md-6 text-center">
                <label class="form-label"></label><br>
                <img id="photoPreview" src="assets/img/default_avatar.png" alt="Photo Preview" style="width: 120px; height: 120px; object-fit: cover;" class="rounded-circle border border-primary shadow">
              </div>
            </div>
          </div>

          <!-- Full Name -->
          <div class="col-md-6"><label class="form-label">Full Name</label><input type="text" name="fullname" class="form-control" required value="<?= htmlspecialchars($form_data['fullname'] ?? '') ?>"></div>
          <!-- Email -->
          <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($form_data['email'] ?? '') ?>"></div>
          <!-- DOB Nepali -->
          <div class="col-md-6"><label class="form-label">Date of Birth (Nepali)</label><input id="nepali-datepicker" class="form-control" name="dob_bs" placeholder="Select Nepali Date" type="text" autocomplete="off" value="<?= htmlspecialchars($form_data['dob_bs'] ?? '') ?>" required></div>
          <!-- DOB English -->
          <div class="col-md-6"><label class="form-label">Date of Birth (English)</label><input type="text" id="english-date" name="dob_ad" class="form-control" placeholder="AD Date" readonly value="<?= htmlspecialchars($form_data['dob_ad'] ?? '') ?>" required></div>

          <!-- Class (Corrected to Dropdown) -->
          <div class="col-md-6">
            <label class="form-label">Class</label>
            <select name="class_id" class="form-select" required>
              <option value="">-- Select Class --</option>
              <?php foreach ($classes as $class): ?>
                <option value="<?= $class['id'] ?>" <?= (isset($form_data['class_id']) && $form_data['class_id'] == $class['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($class['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Parent Contact -->
          <div class="col-md-6"><label class="form-label">Parent Contact</label><input type="text" name="parent_contact" class="form-control" required value="<?= htmlspecialchars($form_data['parent_contact'] ?? '') ?>"></div>
          <!-- Gender -->
          <div class="col-md-6"><label class="form-label">Gender</label><select name="gender" class="form-select" required><option value="">--Select--</option><option value="Male" <?= ($form_data['gender'] ?? '') == 'Male' ? 'selected' : '' ?>>Male</option><option value="Female" <?= ($form_data['gender'] ?? '') == 'Female' ? 'selected' : '' ?>>Female</option><option value="Other" <?= ($form_data['gender'] ?? '') == 'Other' ? 'selected' : '' ?>>Other</option></select></div>
          <!-- Section (Now Optional) -->
          <div class="col-md-6"><label class="form-label">Section (Optional)</label><input type="text" name="section" class="form-control" value="<?= htmlspecialchars($form_data['section'] ?? '') ?>"></div>
          <!-- Address -->
          <div class="col-12"><label class="form-label">Address</label><input type="text" name="address" class="form-control" required value="<?= htmlspecialchars($form_data['address'] ?? '') ?>"></div>
          <!-- Submit -->
          <div class="col-12 text-center"><button type="submit" class="btn btn-submit px-5 py-2 fw-bold shadow-sm">Submit Application</button></div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="assets/js/nepali.datepicker.v4.0.8.min.js"></script>
  <script>
    $(document).ready(function () {
      $('#nepali-datepicker').nepaliDatePicker({ npdMonth: true, npdYear: true, npdYearCount: 70, ndpEnglishInput: 'english-date' });
    });
    document.getElementById('photoInput').addEventListener('change', function (e) {
      const file = e.target.files[0];
      const preview = document.getElementById('photoPreview');
      if (file) {
        const reader = new FileReader();
        reader.onload = function (event) { preview.src = event.target.result; };
        reader.readAsDataURL(file);
      }
    });
  </script>
</body>
</html>