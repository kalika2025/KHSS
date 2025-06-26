<?php
// =================================================================
// 1. INITIALIZATION & CENTRALIZED DATA FETCHING
// =================================================================

session_start([
    'cookie_secure'   => true,
    'cookie_httponly' => true,
    'use_strict_mode' => true
]);

require 'includes/db_connect.php'; 

error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- Initialize all data variables ---
$newsList       = [];
$latest         = []; 
$teachers       = [];
$notices        = [];
$school         = []; 
$quote          = ['quote' => 'No quote available today.', 'author' => 'System'];
$facilities     = [];
$photos         = [];
$allYearStats   = []; // Holds class stats for ALL years
$plusTwoStats   = []; // Holds +2 summary for ALL years
$allTimeSummary = ['total' => 0, 'boys' => 0, 'girls' => 0]; // For the main summary

// --- Helper function to create safe IDs from year names ---
function slugify($text) {
    return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $text)));
}

// --- Fetch school info FIRST ---
try {
    $stmt_school = $pdo->query("SELECT * FROM school_info WHERE is_published ='Y' LIMIT 1");
    $school = $stmt_school->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log("DATABASE SCHOOL FETCH ERROR: " . $e->getMessage());
    $school = []; 
}

// --- If school is published, fetch all other data ---
if (!empty($school)) {
    try {
        // Fetch news, principal's message, teachers, notices, quote, facilities, gallery
        $stmt_news = $pdo->query("SELECT * FROM news ORDER BY id DESC LIMIT 10");
        $newsList = $stmt_news->fetchAll(PDO::FETCH_ASSOC);

        $school_id = $school['id'];
        $stmt_principal = $pdo->prepare("SELECT pm.*, si.school_name FROM principal_messages pm JOIN school_info si ON pm.school_id = si.id WHERE pm.school_id = :school_id ORDER BY pm.created_at DESC LIMIT 1");
        $stmt_principal->execute(['school_id' => $school_id]);
        $latest = $stmt_principal->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmt_teachers = $pdo->query("SELECT t.id, t.name, t.email, t.photo, GROUP_CONCAT(DISTINCT s.subject SEPARATOR ', ') AS subjects, GROUP_CONCAT(DISTINCT c.name SEPARATOR ', ') AS classes FROM teachers t LEFT JOIN teacher_subjects ts ON t.id = ts.teacher_id LEFT JOIN subjects s ON ts.subject_id = s.id LEFT JOIN teacher_classes tc ON t.id = tc.teacher_id LEFT JOIN classes c ON tc.class_id = c.id GROUP BY t.id");
        $teachers = $stmt_teachers->fetchAll(PDO::FETCH_ASSOC);

        $stmt_notices = $pdo->query("SELECT id, notice_text, link, category FROM notices ORDER BY created_at DESC LIMIT 10");
        $notices = $stmt_notices->fetchAll(PDO::FETCH_ASSOC);
        foreach ($notices as &$n) {
            switch (strtolower($n['category'])) { case 'academic': $n['icon'] = '📘'; break; case 'event': $n['icon'] = '🎉'; break; case 'holiday': $n['icon'] = '🏖️'; break; case 'scholarship': $n['icon'] = '🎓'; break; case 'sports': $n['icon'] = '🏆'; break; default: $n['icon'] = '📢'; }
        }

        $totalQuotes = $pdo->query("SELECT COUNT(*) FROM quotes")->fetchColumn();
        if ($totalQuotes > 0) {
            $day = date('z'); $quoteIndex = $day % $totalQuotes;
            $stmt_quote = $pdo->prepare("SELECT quote, author FROM quotes LIMIT 1 OFFSET :index");
            $stmt_quote->bindValue(':index', $quoteIndex, PDO::PARAM_INT);
            $stmt_quote->execute();
            $quote = $stmt_quote->fetch(PDO::FETCH_ASSOC) ?: $quote;
        }

        $stmt_facilities = $pdo->query("SELECT title, icon, description FROM facilities ORDER BY id");
        $facilities = $stmt_facilities->fetchAll(PDO::FETCH_ASSOC);

        $stmt_gallery = $pdo->query("SELECT * FROM photo_gallery ORDER BY id DESC");
        $photos = $stmt_gallery->fetchAll(PDO::FETCH_ASSOC);

        // =====================================================================
        // === **EXPANDED**: FETCH ALL HISTORICAL & CURRENT STATS =============
        // =====================================================================
        
        // 1. Fetch class-by-class stats for ALL academic years
        $stmt_class_stats = $pdo->query("
            SELECT
                ay.year_name, c.name AS class_name, c.id AS class_id,
                COUNT(s.id) AS total_students,
                SUM(CASE WHEN s.gender = 'Male' THEN 1 ELSE 0 END) AS male_students,
                SUM(CASE WHEN s.gender = 'Female' THEN 1 ELSE 0 END) AS female_students
            FROM student_enrollments se
            JOIN students s ON se.student_id = s.id
            JOIN classes c ON se.class_id = c.id
            JOIN academic_years ay ON se.academic_year_id = ay.id
            WHERE s.is_active = 'Y'
            GROUP BY ay.id, ay.year_name, c.id, c.name
            ORDER BY ay.start_date DESC, c.id ASC
        ");
        $rawClassStats = $stmt_class_stats->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($rawClassStats as $stat) {
            $allYearStats[$stat['year_name']][$stat['class_name']] = $stat;
        }

        // 2. Fetch +2 student summaries for ALL academic years
        $stmt_plus_two = $pdo->query("
            SELECT
                ay.year_name, COUNT(DISTINCT s.id) AS total_students,
                SUM(CASE WHEN s.gender = 'Male' THEN 1 ELSE 0 END) AS male_students,
                SUM(CASE WHEN s.gender = 'Female' THEN 1 ELSE 0 END) AS female_students
            FROM student_enrollments se
            JOIN students s ON se.student_id = s.id
            JOIN classes c ON se.class_id = c.id
            JOIN academic_years ay ON se.academic_year_id = ay.id
            WHERE s.is_active = 'Y' AND c.name IN ('Class 11', 'Class 12')
            GROUP BY ay.id, ay.year_name
            ORDER BY ay.start_date DESC
        ");
        $rawPlusTwoStats = $stmt_plus_two->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rawPlusTwoStats as $stat) {
            $plusTwoStats[$stat['year_name']] = $stat;
        }

        // 3. Get the "All Time" summary for currently active students
        $stmt_all_time = $pdo->query("
            SELECT
                COUNT(id) AS total,
                SUM(CASE WHEN gender = 'Male' THEN 1 ELSE 0 END) AS boys,
                SUM(CASE WHEN gender = 'Female' THEN 1 ELSE 0 END) AS girls
            FROM students
            WHERE is_active = 'Y'
        ");
        $allTimeSummary = $stmt_all_time->fetch(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("DATABASE FETCH ERROR (secondary data): " . $e->getMessage());
    }
}

// --- Configuration & Helper Functions ---
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$baseURL = $protocol . $_SERVER['HTTP_HOST'] . "/school_management_system/";
function convertToEmbedUrl($mapsUrl, $fallbackLocation = '') { if (strpos($mapsUrl, '/embed') !== false) return $mapsUrl . '&t=k'; if (preg_match('#@([-0-9.]+),([-0-9.]+)#', $mapsUrl, $match)) return 'https://www.google.com/maps/q=' . $match[1] . ',' . $match[2] . '&output=embed&t=k'; $query = !empty($fallbackLocation) ? $fallbackLocation : $mapsUrl; return 'https://www.google.com/maps?q=' . urlencode($query) . '&output=embed&t=k'; }
function addZoomToMapUrl($url, $zoom = 15) { if (strpos($url, 'zoom=') !== false) return preg_replace('/zoom=\d+/', 'zoom=' . $zoom, $url); return $url . (strpos($url, '?') !== false ? '&' : '?') . 'zoom=' . $zoom; }
$hero_bg_url = $baseURL . 'Authentication/admin/uploads/hero/' . ($school['hero_bg_image'] ?? 'default-hero.jpg');
$logo_url    = $baseURL . 'Authentication/admin/uploads/hero/' . ($school['logo_path'] ?? 'default-logo.png');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($school['school_name'] ?? 'Your School is not Published'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="assets/css/index.css">
</head>
<style>
:root {
    --primary-color: #003366;
    --secondary-color: #00509e;
    --accent-color: #ffc107;
    --text-color: #343a40;
    --light-bg: #f8f9fa;
    --border-radius: 12px;
    --shadow-sm: 0 4px 15px rgba(0, 0, 0, 0.06);
    --shadow-lg: 0 10px 30px rgba(0, 0, 0, 0.1);
}
body { font-family: 'Poppins', 'Segoe UI', sans-serif; background-color: var(--light-bg); color: var(--text-color); scroll-behavior: smooth; line-height: 1.6; }
header.sticky-top { backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); background-color: rgba(0, 51, 102, 0.9); box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); }
.navbar-brand { font-size: 1.5rem; }
.nav-link { transition: color 0.3s ease; }
.nav-link:hover, .nav-link.active { color: var(--accent-color) !important; }
.dropdown-menu { background-color: var(--primary-color); border: 1px solid var(--secondary-color); border-radius: var(--border-radius); padding: 0.5rem 0; }
.dropdown-item { color: white !important; }
.dropdown-item:hover { background-color: var(--secondary-color); color: var(--accent-color) !important; }
#currentDate .nepali { font-size: 0.9rem; line-height: 1.3; }
#currentDate #currentDateAD { font-size: 0.75rem; line-height: 1.1; letter-spacing: 0.5px; }
.notice-bar { background-color: rgba(0, 0, 0, 0.2); }
.notice-scroller { flex-grow: 1; overflow: hidden; -webkit-mask: linear-gradient(90deg, transparent, white 20%, white 80%, transparent); mask: linear-gradient(90deg, transparent, white 20%, white 80%, transparent); }
.notice-scroller-inner { display: flex; flex-wrap: nowrap; list-style: none; padding-left: 0; margin: 0; }
.notice-scroller.scrolling .notice-scroller-inner { animation: scroll-left linear infinite; }
.notice-scroller:hover .notice-scroller-inner { animation-play-state: paused; }
.notice-scroller-inner li { white-space: nowrap; padding: 0 1.5rem; }
.notice-scroller-inner a { color: white; text-decoration: none; transition: color 0.3s; }
.notice-scroller-inner a:hover { color: var(--accent-color); text-decoration: underline; }
@keyframes scroll-left { from { transform: translateX(0%); } to { transform: translateX(-50%); } }
@media (prefers-reduced-motion: reduce) { .notice-scroller-inner { animation-play-state: paused !important; } }
.hero-logo { width: 130px; height: 130px; object-fit: cover; border-radius: 50%; background-color: rgba(255, 255, 255, 0.9); padding: 8px; box-shadow: var(--shadow-lg); }
.hero-title { font-weight: 700; }
.hero-subtitle { opacity: 0.9; }
.section-title { font-size: 2.5rem; color: var(--primary-color); margin-bottom: 3rem; font-weight: 700; text-align: center; position: relative; }
.section-title::after { content: ''; display: block; width: 80px; height: 4px; background-color: var(--accent-color); margin: 10px auto 0; border-radius: 2px; }
.quote-text { font-size: 1.5rem; font-weight: 600; color: var(--primary-color); }
.card { border-radius: var(--border-radius); border: none; box-shadow: var(--shadow-sm); transition: transform 0.3s ease, box-shadow 0.3s ease; }
.card:hover { transform: translateY(-8px); box-shadow: var(--shadow-lg); }
.text-justify { text-align: justify; }
.truncate-2-lines { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.scroller-wrapper { display: flex; overflow-x: auto; padding-bottom: 1rem; scroll-snap-type: x mandatory; scroll-behavior: smooth; -webkit-overflow-scrolling: touch; }
.scroller-wrapper::-webkit-scrollbar { height: 8px; }
.scroller-wrapper::-webkit-scrollbar-track { background: #e0e0e0; }
.scroller-wrapper::-webkit-scrollbar-thumb { background-color: #aaa; border-radius: 10px; }
.scroller-item { flex: 0 0 320px; margin-right: 1.5rem; scroll-snap-align: start; }
.scroller-fade-left, .scroller-fade-right { position: absolute; top: 0; bottom: 1rem; width: 80px; z-index: 2; pointer-events: none; }
.scroller-fade-left { left: 0; background: linear-gradient(to right, var(--light-bg) 20%, transparent 100%); }
.scroller-fade-right { right: 0; background: linear-gradient(to left, var(--light-bg) 20%, transparent 100%); }
.scroller-btn { position: absolute; top: 50%; transform: translateY(-50%); z-index: 3; background-color: white; border: 1px solid #ddd; border-radius: 50%; width: 45px; height: 45px; font-size: 1.5rem; color: var(--primary-color); box-shadow: var(--shadow-sm); transition: all 0.2s ease; }
.scroller-btn:hover { background-color: var(--primary-color); color: white; }
.news-card .card-img-top { height: 180px; object-fit: cover; }
.news-card .card-title { font-weight: 600; }

/* **UPDATED & NEW STYLES** for the new stats section */
#stats .form-select { max-width: 300px; margin: 0 auto 2rem; border-color: var(--primary-color); font-weight: 500; }
.stats-view { display: none; }
.stats-view.active { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.5rem; }
.summary-view { display: none; }
.summary-view.active { display: flex; flex-wrap: wrap; gap: 1.5rem; justify-content: center; }
.class-stat-card { border-left: 4px solid var(--primary-color); background-color: #fff; }
.class-stat-card .card-title { color: var(--primary-color); font-weight: 600; }
.class-stat-item { display: flex; align-items: center; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #eee; }
.class-stat-item:last-child { border-bottom: none; }
.class-stat-item .stat-label { display: flex; align-items: center; color: #555; }
.class-stat-item .bi { font-size: 1.2rem; margin-right: 0.75rem; width: 20px; text-align: center; }
.class-stat-item .stat-value { font-weight: 600; font-size: 1.1rem; }
.bi-people-fill { color: var(--bs-primary); }
.bi-gender-male { color: var(--bs-success); }
.bi-gender-female { color: var(--bs-danger); }
.bi-mortarboard-fill { color: var(--bs-warning); }
.class-stat-card.border-warning .card-title { color: var(--bs-warning-dark); }
.summary-stat-card { border-top: 4px solid; text-align: center; flex: 1; min-width: 220px; max-width: 280px; }
.summary-stat-card .stat-icon { font-size: 3rem; line-height: 1; margin-bottom: 1rem; }

.facility-item { padding: 0.75rem 1rem; margin-bottom: 0.5rem; background-color: #fff; border-radius: var(--border-radius); box-shadow: var(--shadow-sm); cursor: pointer; transition: all 0.3s ease; }
.facility-item:hover { background-color: var(--primary-color); color: white; transform: translateX(10px); }
.facility-icon { font-size: 1.2rem; margin-right: 0.5rem; }
.teacher-card .card-img-top { height: 280px; object-fit: cover; object-position: top; }
.teacher-card .card-title { margin-bottom: 0.25rem; }
.photo-gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
.gallery-item { position: relative; display: block; overflow: hidden; border-radius: var(--border-radius); box-shadow: var(--shadow-sm); aspect-ratio: 4 / 3; cursor: pointer; }
.gallery-thumbnail { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s ease, filter 0.4s ease; }
.gallery-item:hover .gallery-thumbnail { transform: scale(1.1); filter: brightness(0.5); }
.gallery-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; padding: 1rem; color: white; background: rgba(0, 0, 0, 0.4); opacity: 0; transition: opacity 0.4s ease; }
.gallery-item:hover .gallery-overlay { opacity: 1; }
.gallery-overlay .bi { font-size: 2.5rem; transform: translateY(10px); transition: transform 0.4s ease; }
.gallery-item:hover .gallery-overlay .bi { transform: translateY(0); }
.gallery-title-overlay { margin-top: 0.5rem; font-weight: 600; transform: translateY(10px); transition: transform 0.4s 0.1s ease; }
.gallery-item:hover .gallery-title-overlay { transform: translateY(0); }
#galleryModal .modal-dialog { max-width: 95vw; }
#galleryModalImage { max-height: 85vh; object-fit: contain; }
.gallery-modal-caption { position: absolute; bottom: 0; left: 0; width: 100%; padding: 3rem 1.5rem 1.5rem; background: linear-gradient(to top, rgba(0,0,0,0.8), transparent); color: white; text-align: center; }
.gallery-nav-btn { position: absolute; top: 50%; transform: translateY(-50%); background-color: rgba(0, 0, 0, 0.5); color: white; border: none; border-radius: 50%; width: 45px; height: 45px; font-size: 2.5rem; line-height: 1; transition: background-color 0.2s ease; z-index: 1057; }
.gallery-nav-btn:hover { background-color: rgba(0, 0, 0, 0.8); }
.gallery-nav-btn.prev { left: 1rem; }
.gallery-nav-btn.next { right: 1rem; }
.contact-list { list-style: none; padding-left: 0; }
.contact-list li { display: flex; align-items: center; margin-bottom: 1.5rem; font-size: 1.1rem; }
.contact-list .bi { font-size: 1.5rem; color: var(--primary-color); margin-right: 1.5rem; }
.contact-list span { color: #555; }
.map-container { height: 400px; }
.main-footer { background-color: var(--primary-color); color: rgba(255, 255, 255, 0.8); }
.main-footer a { text-decoration: none; transition: all 0.3s ease; }
.main-footer a:hover { color: var(--accent-color) !important; padding-left: 5px; }
.copyright { background-color: rgba(0, 0, 0, 0.2); }
.fade-in-section { opacity: 0; transform: translateY(20px); transition: opacity 0.8s ease-out, transform 0.8s ease-out; }
.fade-in-section.is-visible { opacity: 1; transform: translateY(0); }
@media (max-width: 768px) {
    .section-title { font-size: 2rem; }
    .hero { padding: 4rem 1rem; }
    .scroller-fade-left, .scroller-fade-right, .scroller-btn { display: none; }
    .scroller-item { flex: 0 0 80%; }
}
.alert.alert-light { color: red; }
</style>

<body>
  <!-- HEADER & NAVIGATION -->
  <header class="sticky-top">
    <nav class="navbar navbar-expand-lg navbar-dark">
      <div class="container">
        <a class="navbar-brand fw-bold" href="#"><?= htmlspecialchars($school['school_name'] ?? 'School has not been published yet !!!'); ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
          <ul class="navbar-nav align-items-lg-center">
            <li class="nav-item"><a class="nav-link active" href="#">Home</a></li>
            <li class="nav-item"><a class="nav-link" href="#overview">Overview</a></li>
            <li class="nav-item"><a class="nav-link" href="#facilities">Facilities</a></li>
            <li class="nav-item"><a class="nav-link" href="#gallery">Gallery</a></li>
            <li class="nav-item"><a class="nav-link" href="#teachers">Teachers</a></li>
            <li class="nav-item"><a class="nav-link" href="#contact">Contact</a></li>
            
            <?php if (!empty($school)): ?>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">📢 Notice</a>
              <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="notice_section.php" target="_blank">📁 All Notices</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="notice_section.php?category=Academic" target="_blank">📘 Academic</a></li>
                <li><a class="dropdown-item" href="notice_section.php?category=Event" target="_blank">🎉 Event</a></li>
                <li><a class="dropdown-item" href="notice_section.php?category=Holiday" target="_blank">🏖️ Holiday</a></li>
              </ul>
            </li>
            <?php endif; ?>
            
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-lock-fill"></i> Login</a>
              <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="Authentication/login.php?role=admin">Admin</a></li>
                <li><a class="dropdown-item" href="Authentication/login.php?role=teacher">Teacher</a></li>
                <li><a class="dropdown-item" href="Authentication/login.php?role=student">Student / Parent</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="Authentication/register.php">Register</a></li>
              </ul>
            </li>
            <li class="nav-item d-none d-lg-block ms-3">
              <div id="currentDate" class="d-flex align-items-center">
                <i class="bi bi-calendar-event text-white me-2" style="font-size: 1.8rem;"></i>
                <div class="text-white">
                  <span id="currentDateBS" class="nepali d-block fw-semibold"></span>
                  <span id="currentDateAD" class="d-block small" style="opacity: 0.8;"></span>
                </div>
              </div>
            </li>
          </ul>
        </div>
      </div>
    </nav>
    
    <?php if (!empty($school)): ?>
    <div class="notice-bar py-2">
      <div class="container d-flex align-items-center">
        <strong class="me-3 flex-shrink-0" style="color: rgba(255, 255, 255, 0.75);">Updates:</strong>
        <div class="notice-scroller" data-speed="slow">
          <ul class="notice-scroller-inner">
             <!-- Notices will be populated by JS here -->
          </ul>
        </div>
      </div>
    </div>
    <?php endif; ?>
    
  </header>


  <!-- HERO SECTION IS NOW OUTSIDE AND BEFORE MAIN -->
    <section class="hero" style="background-image: linear-gradient(rgba(0,0,0,0.5), rgba(0,0,0,0.5)), url('<?= htmlspecialchars($hero_bg_url) ?>');">
        <div class="container">
            <div class="row justify-content-center align-items-center flex-column flex-md-row text-center text-md-start">
                <div class="col-12 col-md-auto mb-4 mb-md-0">
                    <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars($school['school_name'] ?? 'School Logo'); ?>" class="hero-logo p-2 bg-white rounded-circle" style="width: 120px; height: 120px; object-fit: cover;">
                </div>
                <div class="col-12 col-md">
                    <h1 class="hero-title display-5 fw-bold"><?= htmlspecialchars($school['school_name'] ?? 'Welcome to Our School'); ?></h1>
                    <p class="hero-subtitle lead"><?= htmlspecialchars($school['hero_subtitle'] ?? 'Excellence in Education'); ?></p>
                    <p class="hero-description mt-2"><?= htmlspecialchars($school['hero_description'] ?? 'Fostering knowledge and character for a brighter future.'); ?></p>
                </div>
            </div>
        </div>
    </section>
<main>
  <!-- DAILY KNOWLEDGE SECTION -->
  <section class="container my-5 text-center fade-in-section">
    <?php if (!empty($school)): ?>
        <h5 class="text-muted">📘 Daily Knowledge</h5>
        <blockquote class="blockquote mb-0">
          <p class="quote-text">"<?= htmlspecialchars($quote['quote']); ?>"</p>
          <footer class="blockquote-footer mt-2"><?= htmlspecialchars($quote['author']); ?></footer>
        </blockquote>
    <?php else: ?>
        <div class="alert alert-light text-center">The "Daily Knowledge" section is unavailable until the school profile is published.</div>
    <?php endif; ?>
  </section>

  <!-- LATEST NEWS SECTION -->
  <section id="news" class="container mt-5 fade-in-section">
    <h2 class="section-title">Latest News</h2>
    <?php if (!empty($school)): ?>
        <?php if(!empty($newsList)): ?>
        <div class="position-relative">
          <div class="scroller-fade-left d-none d-md-block"></div>
          <div class="scroller-fade-right d-none d-md-block"></div>
          <button class="scroller-btn start-0" onclick="scrollNews(-350)" aria-label="Scroll Left">‹</button>
          <button class="scroller-btn end-0" onclick="scrollNews(350)" aria-label="Scroll Right">›</button>
          <div class="scroller-wrapper" id="newsScroll">
            <?php foreach ($newsList as $news): ?>
              <div class="scroller-item">
                <div class="card h-100 news-card">
                  <?php if (!empty($news['image'])): ?>
                    <img src="<?= $baseURL ?>Authentication/admin/uploads/news/<?= htmlspecialchars($news['image']) ?>" class="card-img-top" alt="<?= htmlspecialchars($news['title']) ?>" loading="lazy">
                  <?php endif; ?>
                  <div class="card-body d-flex flex-column">
                    <h5 class="card-title flex-grow-1"><?= htmlspecialchars($news['title']) ?></h5>
                    <p class="card-text small text-muted truncate-2-lines"><?= strip_tags($news['content']) ?></p>
                  </div>
                  <div class="card-footer bg-transparent border-0 pb-3">
                    <small class="text-muted align-middle"><?= date("d M Y", strtotime($news['posted_on'])) ?></small>
                    <a href="<?= $baseURL ?>Authentication/admin/news_detail.php?id=<?= $news['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary float-end">Read More</a>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php else: ?>
        <div class="alert alert-light text-center">No news to display at the moment.</div>
        <?php endif; ?>
    <?php else: ?>
        <div class="alert alert-light text-center">The "Latest News" section is unavailable until the school profile is published.</div>
    <?php endif; ?>
  </section>

   <!-- OVERVIEW SECTION -->
  <section id="overview" class="container mt-5 py-5 fade-in-section">
    <h2 class="section-title">School Overview</h2>
    <div class="row justify-content-center">
      <div class="col-lg-10">
        <?php if (!empty($school['overview'])): ?>
          <p class="text-justify lh-lg"><?= nl2br(htmlspecialchars($school['overview'])) ?></p>
        <?php else: ?>
          <div class="alert alert-light text-center">An overview of the school has not been published yet.</div>
        <?php endif; ?>
      </div>
    </div>
  </section>
  
  <!-- ========================================================= -->
  <!-- ============ **UPDATED** STATS SECTION ================== -->
  <!-- ========================================================= -->
  <section id="stats" class="container mt-5 py-5 fade-in-section">
    <h2 class="section-title">Our School at a Glance</h2>

    <?php if (!empty($allYearStats)): ?>
        <!-- Year Selector Dropdown -->
        <div class="d-flex justify-content-center mb-4">
            <select class="form-select form-select-lg" id="yearSelector" aria-label="Select Academic Year">
                <option value="all-time" selected>All-Time Summary</option>
                <?php foreach (array_keys($allYearStats) as $year): ?>
                    <option value="<?= slugify($year) ?>"><?= htmlspecialchars($year) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="statsContainer">
            <!-- All-Time Summary View -->
            <div class="summary-view active" id="stats-view-all-time">
                <div class="card summary-stat-card border-primary p-4 h-100">
                    <div class="stat-icon text-primary"><i class="bi bi-people-fill"></i></div>
                    <h3 class="display-5 fw-bold"><?= (int)($allTimeSummary['total'] ?? 0) ?></h3>
                    <p class="text-muted mb-0">Total Active Students</p>
                </div>
                 <div class="card summary-stat-card border-success p-4 h-100">
                    <div class="stat-icon text-success"><i class="bi bi-gender-male"></i></div>
                    <h3 class="display-5 fw-bold"><?= (int)($allTimeSummary['boys'] ?? 0) ?></h3>
                    <p class="text-muted mb-0">Boys</p>
                </div>
                <div class="card summary-stat-card border-danger p-4 h-100">
                    <div class="stat-icon text-danger"><i class="bi bi-gender-female"></i></div>
                    <h3 class="display-5 fw-bold"><?= (int)($allTimeSummary['girls'] ?? 0) ?></h3>
                    <p class="text-muted mb-0">Girls</p>
                </div>
            </div>

            <!-- Yearly Detailed Views (Generated by PHP) -->
            <?php foreach ($allYearStats as $year => $classData): ?>
                <div class="stats-view" id="stats-view-<?= slugify($year) ?>">
                    <!-- +2 Summary Card for this year -->
                    <?php if (isset($plusTwoStats[$year])): ?>
                        <div class="card class-stat-card border-warning">
                             <div class="card-body">
                                <h5 class="card-title text-center mb-3"><i class="bi bi-mortarboard-fill"></i> +2 Students</h5>
                                <div class="class-stat-item">
                                    <span class="stat-label"><i class="bi bi-people-fill"></i> Total Students</span>
                                    <span class="stat-value text-primary"><?= htmlspecialchars($plusTwoStats[$year]['total_students']) ?></span>
                                </div>
                                <div class="class-stat-item">
                                    <span class="stat-label"><i class="bi bi-gender-male"></i> Boys</span>
                                    <span class="stat-value text-success"><?= htmlspecialchars($plusTwoStats[$year]['male_students']) ?></span>
                                </div>
                                <div class="class-stat-item">
                                    <span class="stat-label"><i class="bi bi-gender-female"></i> Girls</span>
                                    <span class="stat-value text-danger"><?= htmlspecialchars($plusTwoStats[$year]['female_students']) ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Individual Class Cards for this year -->
                    <?php foreach ($classData as $class): ?>
                        <div class="card class-stat-card">
                            <div class="card-body">
                                <h5 class="card-title text-center mb-3"><?= htmlspecialchars($class['class_name']) ?></h5>
                                <div class="class-stat-item">
                                    <span class="stat-label"><i class="bi bi-people-fill"></i> Total Students</span>
                                    <span class="stat-value text-primary"><?= htmlspecialchars($class['total_students']) ?></span>
                                </div>
                                <div class="class-stat-item">
                                    <span class="stat-label"><i class="bi bi-gender-male"></i> Boys</span>
                                    <span class="stat-value text-success"><?= htmlspecialchars($class['male_students']) ?></span>
                                </div>
                                <div class="class-stat-item">
                                    <span class="stat-label"><i class="bi bi-gender-female"></i> Girls</span>
                                    <span class="stat-value text-danger"><?= htmlspecialchars($class['female_students']) ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-light text-center">
            Our School at a Glance  unavailable until the school profile is published.
        </div>
    <?php endif; ?>
  </section>
  <!-- ========================================================= -->
  <!-- ============ END OF NEW STATS SECTION =================== -->
  <!-- ========================================================= -->

  <!-- PRINCIPAL'S MESSAGE SECTION -->
  <section id="principal" class="container mt-5 fade-in-section">
    <h2 class="section-title">Message from the Principal</h2>
    <?php if (!empty($school)): ?>
        <?php if (!empty($latest) && !empty($latest['message'])):
            $char_limit = 450;
            $full_message = nl2br(htmlspecialchars($latest['message']));
            $trimmed_message = nl2br(htmlspecialchars(mb_strimwidth($latest['message'], 0, $char_limit, '...')));
            $is_long_message = mb_strlen($latest['message']) > $char_limit;
        ?>
          <div class="card shadow-sm border-0">
            <div class="row g-0 align-items-center">
              <div class="col-md-3 text-center p-4">
                <?php 
                $principalPhotoPath = "Authentication/admin/uploads/principal/" . ($latest['photo'] ?? ''); 
                $principalPhotoURL = file_exists($principalPhotoPath) ? $baseURL . $principalPhotoPath : $baseURL . 'assets/img/default.jpg'; 
                ?>
                <img src="<?= htmlspecialchars($principalPhotoURL) ?>" class="img-fluid rounded-circle shadow" alt="Principal Photo" loading="lazy" style="width: 180px; height: 180px; object-fit: cover;">
              </div>
              <div class="col-md-9">
                <div class="card-body p-4">
                  <div class="message-container" style="line-height: 1.8;">
                      <p class="card-text text-justify message-less"><?= $trimmed_message ?></p>
                      <p class="card-text text-justify message-more" style="display: none;"><?= $full_message ?></p>
                  </div>
                  <div class="mt-4">
                    <p class="text-primary mb-0"><strong><?= htmlspecialchars($latest['name'] ?? 'Principal') ?></strong></p>
                    <p><small>Posted on <?= date('F j, Y', strtotime($latest['created_at'])) ?></small></p>
                  </div>
                  <?php if ($is_long_message): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm btn-read-more">Read More</button>
                    <button type="button" class="btn btn-outline-primary btn-sm btn-read-less" style="display: none;">Read Less</button>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        <?php else: ?>
          <div class="alert alert-light text-center">No message from the principal available yet.</div>
        <?php endif; ?>
    <?php else: ?>
        <div class="alert alert-light text-center">The "Principal's Message" section is unavailable until the school profile is published.</div>
    <?php endif; ?>
  </section>

  <!-- FACILITIES SECTION -->
  <section id="facilities" class="container mt-5 py-5 fade-in-section">
    <h2 class="section-title">Our Facilities</h2>
    <?php if (!empty($school)): ?>
        <?php if(!empty($facilities)): $total = count($facilities); $columns = $total > 12 ? 3 : 2; $chunked = array_chunk($facilities, ceil($total / $columns)); ?>
        <div class="row">
          <?php foreach ($chunked as $column): ?>
            <div class="col-md-<?= 12 / $columns ?>">
              <ul class="list-unstyled">
                <?php foreach ($column as $facility): ?>
                  <li class="facility-item" data-bs-toggle="modal" data-bs-target="#facilityModal" data-title="<?= htmlspecialchars($facility['title']) ?>" data-description="<?= htmlspecialchars($facility['description']) ?>">
                    <span class="facility-icon"><?= $facility['icon'] ?></span> <?= htmlspecialchars($facility['title']) ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?><div class="alert alert-light text-center">Facilities information is not available yet.</div><?php endif; ?>
    <?php else: ?>
        <div class="alert alert-light text-center">The "Facilities" section is unavailable until the school profile is published.</div>
    <?php endif; ?>
  </section>

  <!-- TEACHERS SECTION -->
  <section id="teachers" class="container mt-5 fade-in-section">
    <h2 class="section-title">Meet Our Teachers</h2>
    <?php if (!empty($school)): ?>
        <?php if(!empty($teachers)): ?>
        <div class="position-relative">
            <div class="scroller-fade-left d-none d-md-block"></div>
            <div class="scroller-fade-right d-none d-md-block"></div>
            <button class="scroller-btn start-0" onclick="scrollTeachers(-300)" aria-label="Scroll Left">‹</button>
            <button class="scroller-btn end-0" onclick="scrollTeachers(300)" aria-label="Scroll Right">›</button>
            <div class="scroller-wrapper" id="teachersScroll">
            <?php foreach ($teachers as $t):
                $photoFile = $t['photo'] ?? null;
                $photoPath = "Authentication/admin/uploads/teachers/" . $photoFile;
                $photoURL = (!empty($photoFile) && file_exists($photoPath)) ? $baseURL . $photoPath : $baseURL . "assets/img/default.jpg";
            ?>
                <div class="scroller-item" style="width: 260px;">
                    <div class="card teacher-card h-100" data-bs-toggle="modal" data-bs-target="#teacherModal" data-name="<?=htmlspecialchars($t['name'])?>" data-subject="<?=htmlspecialchars($t['subjects'])?>" data-class="<?=htmlspecialchars($t['classes'])?>" data-photo="<?=htmlspecialchars($photoURL)?>" data-email="<?=htmlspecialchars($t['email'])?>">
                        <img src="<?=htmlspecialchars($photoURL)?>" class="card-img-top" alt="<?=htmlspecialchars($t['name'])?>" loading="lazy">
                        <div class="card-body text-center">
                            <h5 class="card-title text-primary fw-semibold mb-1"><?=htmlspecialchars($t['name'])?></h5>
                            <p class="card-text small text-muted text-truncate" title="<?=htmlspecialchars($t['subjects'])?>"><?=htmlspecialchars($t['subjects'])?></p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?><div class="alert alert-light text-center">Teacher profiles are not available at this time.</div><?php endif; ?>
    <?php else: ?>
        <div class="alert alert-light text-center">The "Teachers" section is unavailable until the school profile is published.</div>
    <?php endif; ?>
  </section>
 
  <!-- GALLERY SECTION -->
  <section id="gallery" class="container my-5 py-5 fade-in-section">
    <h2 class="section-title">Photo Gallery</h2>
    <?php if (!empty($photos)): ?>
      <div class="photo-gallery-grid">
        <?php foreach ($photos as $index => $photo): ?>
          <a href="<?= $baseURL ?>Authentication/admin/uploads/gallery/<?= htmlspecialchars($photo['image_path']) ?>" 
             class="gallery-item" 
             data-index="<?= $index ?>"
             data-title="<?= htmlspecialchars($photo['title']) ?>" 
             data-description="<?= htmlspecialchars(nl2br($photo['description'])) ?>">
            <img src="<?= $baseURL ?>Authentication/admin/uploads/gallery/<?= htmlspecialchars($photo['image_path']) ?>" 
                 class="gallery-thumbnail" 
                 alt="<?= htmlspecialchars($photo['title']) ?>" 
                 loading="lazy">
            <div class="gallery-overlay">
              <i class="bi bi-arrows-fullscreen"></i>
              <h5 class="gallery-title-overlay"><?= htmlspecialchars($photo['title']) ?></h5>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="alert alert-light text-center">No gallery images are available at the moment.</p>
    <?php endif; ?>
  </section>

  <!-- CONTACT SECTION -->
  <section id="contact" class="container my-5 fade-in-section">
    <h2 class="section-title">Contact & Location</h2>
    <div class="row">
        <?php if (!empty($school)): ?>
        <div class="col-lg-6">
          <ul class="list-unstyled contact-list">
              <?php
              $contactItems = [
                  'contact_location' => ['icon' => 'bi-geo-alt-fill', 'label' => 'Location'],
                  'contact_phone'    => ['icon' => 'bi-telephone-fill', 'label' => 'Phone'],
                  'contact_email'    => ['icon' => 'bi-envelope-fill', 'label' => 'Email'],
                  'contact_hours'    => ['icon' => 'bi-clock-fill', 'label' => 'Office Hours'],
                  'google_maps_link' => ['icon' => 'bi-map-fill', 'label' => 'Google Maps'],
              ];
              foreach ($contactItems as $key => $details) {
                  $value = $school[$key] ?? 'Not available';
                  if (!empty($value) && $value !== 'Not available') {
                      if ($key === 'google_maps_link') {
                          echo "<li><i class='bi {$details['icon']}'></i><div><strong>{$details['label']}:</strong> <span><a href='" . htmlspecialchars($value) . "' target='_blank' rel='noopener noreferrer'>View on Google Maps</a></span></div></li>";
                      } else {
                          echo "<li><i class='bi {$details['icon']}'></i><div><strong>{$details['label']}:</strong> <span>" . htmlspecialchars($value) . "</span></div></li>";
                      }
                  }
              }
              ?>
          </ul>
        </div>
        <div class="col-lg-6 mt-4 mt-lg-0">
            <?php
            $mapEmbedUrl = '';
            if (!empty($school['google_maps_link']) || !empty($school['contact_location'])) {
                $mapEmbedUrl = convertToEmbedUrl($school['google_maps_link'] ?? '', $school['contact_location'] ?? '');
            }
            if (!empty($mapEmbedUrl)):
            ?>
            <div class="map-container shadow-lg rounded overflow-hidden">
                <iframe src="<?= htmlspecialchars(addZoomToMapUrl($mapEmbedUrl, 16)) ?>" width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="col-12"><div class="alert alert-light text-center">Contact information is not published.</div></div>
        <?php endif; ?>
    </div>
  </section>
</main>

<!-- FOOTER -->
<footer class="main-footer text-white pt-5 pb-4">
    <div class="container text-center text-md-start">
    <div class="row">
      <div class="col-md-4 col-lg-4 mx-auto mb-4">
        <h6 class="text-uppercase fw-bold"><?= htmlspecialchars($school['school_name'] ?? 'Your School'); ?></h6>
        <hr class="mb-4 mt-0 d-inline-block mx-auto" style="width: 60px; background-color: var(--accent-color); height: 2px"/>
        <p><?= htmlspecialchars($school['footer_note'] ?? 'Dedicated to providing quality education and fostering an environment of academic excellence and personal growth.'); ?></p>
      </div>
      <div class="col-md-4 col-lg-2 mx-auto mb-4">
        <h6 class="text-uppercase fw-bold">Quick Links</h6>
        <hr class="mb-4 mt-0 d-inline-block mx-auto" style="width: 60px; background-color: var(--accent-color); height: 2px"/>
        <p><a href="#overview" class="text-white">About Us</a></p>
        <p><a href="#facilities" class="text-white">Facilities</a></p>
        <p><a href="#teachers" class="text-white">Our Team</a></p>
        <p><a href="#contact" class="text-white">Contact</a></p>
      </div>
      <div class="col-md-4 col-lg-4 mx-auto mb-md-0 mb-4">
        <h6 class="text-uppercase fw-bold">Contact</h6>
        <hr class="mb-4 mt-0 d-inline-block mx-auto" style="width: 60px; background-color: var(--accent-color); height: 2px"/>
        <p><i class="bi bi-geo-alt-fill me-3"></i><?= htmlspecialchars($school['contact_location'] ?? 'N/A'); ?></p>
        <p><i class="bi bi-envelope-fill me-3"></i><?= htmlspecialchars($school['contact_email'] ?? 'N/A'); ?></p>
        <p><i class="bi bi-telephone-fill me-3"></i><?= htmlspecialchars($school['contact_phone'] ?? 'N/A'); ?></p>
      </div>
    </div>
  </div>
  <div class="copyright text-center p-3" style="background-color: rgba(0, 0, 0, 0.2);">
    © <?= date('Y') ?> Copyright:
    <a class="text-white fw-bold" href="#"><?= htmlspecialchars($school['school_name'] ?? 'Your School'); ?></a>. All Rights Reserved.
  </div>
</footer>

<!-- MODALS -->
<div class="modal fade" id="facilityModal" tabindex="-1" aria-labelledby="facilityModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="facilityModalLabel"></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="facilityModalBody"></div></div></div></div>
<div class="modal fade" id="teacherModal" tabindex="-1" aria-labelledby="teacherModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="teacherModalLabel">Teacher Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="row align-items-center g-4"><div class="col-md-4 text-center"><img src="" alt="Teacher" id="teacherPhoto" class="img-fluid rounded-circle" style="width:150px;height:150px;object-fit:cover;"></div><div class="col-md-8"><h4 id="teacherName" class="mb-3"></h4><p><strong class="me-2">Subjects:</strong><span id="teacherSubjects"></span></p><p><strong class="me-2">Classes:</strong><span id="teacherClasses"></span></p><p><strong class="me-2">Email:</strong><a href="#" id="teacherEmailLink"><span id="teacherEmail"></span></a></p></div></div></div></div></div></div>
<div class="modal fade" id="galleryModal" tabindex="-1" aria-labelledby="galleryModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content bg-transparent border-0">
      <div class="modal-body p-0 position-relative">
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" style="position:absolute; top:1rem; right:1rem; z-index:1056;"></button>
        <img id="galleryModalImage" class="d-block w-100 rounded" alt="Gallery Image">
        <div class="gallery-modal-caption">
          <h5 id="galleryModalTitle"></h5>
          <p id="galleryModalDescription"></p>
        </div>
        <button class="gallery-nav-btn prev" id="btnGalleryPrev" aria-label="Previous image">‹</button>
        <button class="gallery-nav-btn next" id="btnGalleryNext" aria-label="Next image">›</button>
      </div>
    </div>
  </div>
</div>

<!-- JAVASCRIPT -->
<script>
    const noticesData = <?= json_encode($notices, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="assets/js/nepali.datepicker.v4.0.8.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // --- Stats View Toggler ---
    const yearSelector = document.getElementById('yearSelector');
    if (yearSelector) {
        yearSelector.addEventListener('change', function() {
            const selectedValue = this.value;
            // Hide all views first
            document.querySelectorAll('.stats-view, .summary-view').forEach(view => {
                view.classList.remove('active');
            });
            // Show the selected view
            const targetView = document.getElementById('stats-view-' + selectedValue);
            if (targetView) {
                targetView.classList.add('active');
            }
        });
    }

    // --- Initialize Modern Notice Scroller ---
    function initNoticeScroller() {
        const scroller = document.querySelector(".notice-scroller");
        if (!scroller) return;
        const scrollerInner = scroller.querySelector(".notice-scroller-inner");
        if (!scrollerInner) return;
        if (typeof noticesData !== 'undefined' && noticesData.length > 0) {
            const noticeHTML = noticesData.map(n => `<li><a href="${n.link || '#'}">${n.icon} ${n.notice_text}</a></li>`).join('');
            scrollerInner.innerHTML = noticeHTML;
        } else {
            scrollerInner.innerHTML = '<li><span class="text-muted">No active notices.</span></li>';
            return;
        }
        if (scrollerInner.scrollWidth > scroller.clientWidth) {
            const clonedContent = scrollerInner.cloneNode(true);
            scrollerInner.appendChild(clonedContent);
            const duration = scrollerInner.scrollWidth / 2 / (scroller.dataset.speed === 'fast' ? 50 : 25);
            scrollerInner.style.animationDuration = `${duration}s`;
            scroller.classList.add("scrolling");
        }
    }
    initNoticeScroller();

    // --- Setup Horizontal Scrollers ---
    window.scrollNews = (amount) => document.getElementById('newsScroll')?.scrollBy({ left: amount, behavior: 'smooth' });
    window.scrollTeachers = (amount) => document.getElementById('teachersScroll')?.scrollBy({ left: amount, behavior: 'smooth' });

    // --- Modal Handlers ---
    const facilityModal = document.getElementById('facilityModal');
    if (facilityModal) {
        facilityModal.addEventListener('show.bs.modal', e => {
            facilityModal.querySelector('.modal-title').textContent = e.relatedTarget.dataset.title;
            facilityModal.querySelector('.modal-body').textContent = e.relatedTarget.dataset.description;
        });
    }
    const teacherModal = document.getElementById('teacherModal');
    if (teacherModal) {
        teacherModal.addEventListener('show.bs.modal', e => {
            const card = e.relatedTarget;
            teacherModal.querySelector('#teacherName').textContent = card.dataset.name;
            teacherModal.querySelector('#teacherSubjects').textContent = card.dataset.subject;
            teacherModal.querySelector('#teacherClasses').textContent = card.dataset.class;
            teacherModal.querySelector('#teacherPhoto').src = card.dataset.photo;
            teacherModal.querySelector('#teacherEmail').textContent = card.dataset.email;
            teacherModal.querySelector('#teacherEmailLink').href = `mailto:${card.dataset.email}`;
        });
    } 

    // --- Gallery Lightbox Logic ---
    const galleryItems = document.querySelectorAll('.gallery-item');
    const galleryModalEl = document.getElementById('galleryModal');
    let galleryModal;
    let currentGalleryIndex = 0;
    if (galleryItems.length > 0 && galleryModalEl) {
        galleryModal = new bootstrap.Modal(galleryModalEl);
        const modalImage = document.getElementById('galleryModalImage');
        const modalTitle = document.getElementById('galleryModalTitle');
        const modalDescription = document.getElementById('galleryModalDescription');
        const btnPrev = document.getElementById('btnGalleryPrev');
        const btnNext = document.getElementById('btnGalleryNext');
        function updateGalleryModal(index) {
            const item = galleryItems[index];
            if (!item) return;
            modalImage.src = item.href;
            modalTitle.textContent = item.dataset.title;
            modalDescription.innerHTML = item.dataset.description;
            currentGalleryIndex = index;
            btnPrev.style.display = (index === 0) ? 'none' : 'block';
            btnNext.style.display = (index === galleryItems.length - 1) ? 'none' : 'block';
        }
        galleryItems.forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                const index = parseInt(this.dataset.index, 10);
                updateGalleryModal(index);
                galleryModal.show();
            });
        });
        btnPrev.addEventListener('click', () => { if (currentGalleryIndex > 0) updateGalleryModal(currentGalleryIndex - 1); });
        btnNext.addEventListener('click', () => { if (currentGalleryIndex < galleryItems.length - 1) updateGalleryModal(currentGalleryIndex + 1); });
        document.addEventListener('keydown', function(e) {
            if (galleryModalEl.classList.contains('show')) {
                if (e.key === 'ArrowLeft') btnPrev.click();
                if (e.key === 'ArrowRight') btnNext.click();
            }
        });
    }

    // --- Principal Message Read More/Less ---
    document.querySelectorAll('.btn-read-more').forEach(button => {
        button.addEventListener('click', function() {
            const cardBody = this.closest('.card-body');
            if (cardBody) {
                cardBody.querySelector('.message-less').style.display = 'none';
                cardBody.querySelector('.message-more').style.display = 'block';
                this.style.display = 'none';
                cardBody.querySelector('.btn-read-less').style.display = 'inline-block';
            }
        });
    });
    document.querySelectorAll('.btn-read-less').forEach(button => {
        button.addEventListener('click', function() {
            const cardBody = this.closest('.card-body');
            if (cardBody) {
                cardBody.querySelector('.message-less').style.display = 'block';
                cardBody.querySelector('.message-more').style.display = 'none';
                this.style.display = 'none';
                cardBody.querySelector('.btn-read-more').style.display = 'inline-block';
            }
        });
    });

    // --- Fade-in sections on scroll ---
    const fadeInObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                fadeInObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1 });
    document.querySelectorAll('.fade-in-section').forEach(section => {
        fadeInObserver.observe(section);
    });

    // --- Nepali Date/Time ---
    const nepaliDays = ['आइतवार','सोमवार','मंगलवार','बुधवार','बिहीवार','शुक्रवार','शनिवार'];
    const nepaliMonths = ['बैशाख','जेठ','असार','श्रावण','भदौ','असोज','कार्तिक','मंसिर','पुष','माघ','फाल्गुण','चैत्र'];
    const toNepali = num => String(num).split('').map(d => '०१२३४५६७८९'[d] || d).join('');
    function updateDateTime() {
        const now = new Date();
        document.getElementById('currentDateAD').textContent = `${now.getFullYear()}-${now.toLocaleString('en-GB', {month:'short'})}-${String(now.getDate()).padStart(2,'0')}, ${now.toLocaleString('en-GB', {weekday:'long'})}`;
        const bs = NepaliFunctions.AD2BS({year:now.getFullYear(), month:now.getMonth()+1, day:now.getDate()});
        document.getElementById('currentDateBS').textContent = `${toNepali(bs.year)} ${nepaliMonths[bs.month-1]} ${toNepali(bs.day)}, ${nepaliDays[now.getDay()]}`;
    }
    updateDateTime();
    setInterval(updateDateTime, 30000);
});
</script>
</body>
</html>