<?php
require_once 'includes/db_connect.php';

$limit = 6;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;
$filterCategory = isset($_GET['category']) && $_GET['category'] !== '' ? trim($_GET['category']) : '';

try {
    // Fetch distinct categories from DB
    $categoryStmt = $pdo->query("SELECT DISTINCT category FROM notices ORDER BY category");
    $categories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);

    // Count total notices (with filter)
    $countQuery = "SELECT COUNT(*) FROM notices";
    if ($filterCategory) {
        $countQuery .= " WHERE category = :category";
    }
    $countStmt = $pdo->prepare($countQuery);
    if ($filterCategory) {
        $countStmt->bindValue(':category', $filterCategory);
    }
    $countStmt->execute();
    $totalNotices = $countStmt->fetchColumn();
    $totalPages = ceil($totalNotices / $limit);

    // Fetch notices with pagination and filter
    $query = "SELECT * FROM notices";
    if ($filterCategory) {
        $query .= " WHERE category = :category";
    }
    $query .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($query);
    if ($filterCategory) {
        $stmt->bindValue(':category', $filterCategory);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Notices<?php echo $filterCategory ? " - " . htmlspecialchars($filterCategory) : ""; ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <style>
  /* Background & Typography */
body {
  background-color: #dc3545; fallback color
 /* background-image: url('images/notice.jpg'); */
  background-repeat: no-repeat;
  background-size: cover;       /* makes the image cover the entire background */
  background-position: center;  /* centers the background image */
  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  color: #333;
}
    #notice h2 {
      font-weight: 700;
      color: #0d6efd; /* Bootstrap primary */
      text-shadow: 1px 1px 2px rgba(0,0,0,0.1);
    }

    /* Category Filter */
    select.form-select {
      background-color: #ffffffcc;
      border: 1px solid #0d6efd;
      transition: all 0.3s ease;
    }
    select.form-select:hover, select.form-select:focus {
      background-color: #e7f1ff;
      border-color: #084298;
      box-shadow: 0 0 8px #084298aa;
    }

    /* Cards */
    .card {
      background: #ffffffdd;
      box-shadow: 0 6px 12px rgba(13, 110, 253, 0.15);
      border: none;
      border-radius: 1rem;
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .card:hover {
      transform: translateY(-6px);
      box-shadow: 0 12px 24px rgba(13, 110, 253, 0.3);
    }
    .card-img-top {
      border-top-left-radius: 1rem;
      border-top-right-radius: 1rem;
      height: 200px;
      object-fit: cover;
      filter: brightness(0.95);
      transition: filter 0.3s ease;
    }
    .card-img-top:hover {
      filter: brightness(1);
    }

    .card-body {
      padding: 1rem 1.25rem;
    }
    .card-text {
      font-size: 0.95rem;
      color: #555;
      min-height: 65px;
    }

    .badge {
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
    }
    .badge.bg-primary {
      background-color: #0d6efd !important;
    }
    .badge.bg-danger {
      background-color: #dc3545 !important;
    }

    .card-footer {
      background-color: #f8f9fa;
      font-size: 0.85rem;
      color: #666;
      border-top-left-radius: 0 0;
      border-bottom-left-radius: 1rem;
      border-bottom-right-radius: 1rem;
      text-align: right;
    }

    /* Buttons */
    .btn-outline-primary {
      border-radius: 50px;
      font-size: 0.85rem;
      padding: 0.3rem 1rem;
      transition: background-color 0.3s ease;
    }
    .btn-outline-primary:hover {
      background-color: #0d6efd;
      color: white;
    }
    .btn-outline-secondary {
      border-radius: 50px;
      font-size: 0.85rem;
      padding: 0.3rem 1rem;
    }
    .btn-outline-secondary:hover {
      background-color: #6c757d;
      color: white;
    }

    /* Pagination */
    .pagination .page-item .page-link {
      color: #0d6efd;
      border-radius: 50%;
      width: 40px;
      height: 40px;
      line-height: 36px;
      text-align: center;
      margin: 0 4px;
      transition: all 0.3s ease;
    }
    .pagination .page-item.active .page-link {
      background-color: #0d6efd;
      color: white;
      box-shadow: 0 0 10px #0d6efd88;
    }
    .pagination .page-link:hover {
      background-color: #0d6efd;
      color: white;
      box-shadow: 0 0 8px #0d6efd88;
    }

    /* Modal */
    .modal-content {
      border-radius: 1rem;
      font-size: 1rem;
      color: #333;
    }
    .modal-header {
      border-bottom: none;
      padding-bottom: 0.5rem;
    }
    .modal-footer {
      border-top: none;
      font-size: 0.85rem;
      color: #666;
    }

    /* Responsive adjustments */
    @media (max-width: 576px) {
      .card-text {
        min-height: auto;
      }
    }
  </style>
</head>
<body>

<section id="notice" class="py-5">
  <div class="container">
    <h2 class="mb-5 text-center">📢 Latest Notices</h2>

    <!-- Category Filter -->
    <div class="row mb-4">
      <div class="col-md-6 offset-md-3">
        <form method="GET" action="">
          <select name="category" class="form-select form-select-lg shadow-sm rounded-3" onchange="this.form.submit()">
            <option value="">📁 All Categories</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($filterCategory === $cat) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($cat); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
    </div>

    <?php if ($stmt->rowCount() > 0): ?>
      <div class="row g-4">
        <?php
        $count = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $count++;
            $createdAt = strtotime($row['created_at']);
            $isNew = (time() - $createdAt) < (3 * 24 * 60 * 60);
        ?>
        <div class="col-12 col-md-6 col-lg-4">
          <div class="card h-100 shadow-sm border-0 rounded-4">
            <?php if (!empty($row['photo']) && file_exists("Authentication/admin/uploads/Notices/" . $row['photo'])): ?>
              <img src="Authentication/admin/uploads/Notices/<?php echo htmlspecialchars($row['photo']); ?>" class="card-img-top img-fluid" alt="Notice Image">
            <?php else: ?>
              <img src="assets/images/no-image.jpg" class="card-img-top img-fluid" alt="Default Image">
            <?php endif; ?>

            <div class="card-body">
              <?php if ($isNew): ?>
                <span class="badge bg-danger mb-2">NEW</span>
              <?php endif; ?>
              <span class="badge bg-primary mb-2"><?php echo htmlspecialchars($row['category']); ?></span>

              <?php
                $shortText = substr(strip_tags($row['notice_text']), 0, 100);
                if (strlen(strip_tags($row['notice_text'])) > 100) $shortText .= '...';
              ?>
              <p class="card-text"><?php echo nl2br(htmlspecialchars($shortText)); ?></p>

              <button class="btn btn-sm btn-outline-primary mt-2" data-bs-toggle="modal" data-bs-target="#noticeModal<?php echo $count; ?>">
                📖 Read More
              </button>

              <?php if (!empty($row['link'])):
                $link = htmlspecialchars($row['link']);
                $ext = strtolower(pathinfo($link, PATHINFO_EXTENSION));
                $allowedExt = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', 'jpg', 'jpeg', 'png', 'gif', 'txt', 'mp4', 'mov', 'avi'];
                if (filter_var($link, FILTER_VALIDATE_URL) || in_array($ext, $allowedExt)):
              ?>
                <a href="<?php echo $link; ?>" class="btn btn-sm btn-outline-secondary mt-2 ms-2" target="_blank" rel="noopener noreferrer">
                  📎 Attachment
                </a>
              <?php endif; endif; ?>
            </div>
            <div class="card-footer">
              <small>🕒 <?php echo date('F j, Y', $createdAt); ?></small>
            </div>
          </div>
        </div>

        <!-- Modal -->
        <div class="modal fade" id="noticeModal<?php echo $count; ?>" tabindex="-1" aria-labelledby="noticeModalLabel<?php echo $count; ?>" aria-hidden="true">
          <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="noticeModalLabel<?php echo $count; ?>"><?php echo htmlspecialchars($row['title']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <p><strong>Category:</strong> <?php echo htmlspecialchars($row['category']); ?></p>
                <?php
                  // Show full notice text preserving line breaks
                  echo nl2br(htmlspecialchars($row['notice_text']));
                ?>
                <?php if (!empty($row['link'])): ?>
                  <hr />
                  <p><strong>Attachment:</strong> <a href="<?php echo $link; ?>" target="_blank" rel="noopener noreferrer"><?php echo $link; ?></a></p>
                <?php endif; ?>
              </div>
              <div class="modal-footer">
                <small>Posted on <?php echo date('F j, Y, g:i a', $createdAt); ?></small>
              </div>
            </div>
          </div>
        </div>

        <?php } // end while ?>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
      <nav aria-label="Notice Pagination" class="mt-5">
        <ul class="pagination justify-content-center flex-wrap">
          <!-- Previous -->
          <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $page-1)])); ?>" aria-label="Previous">
              &laquo;
            </a>
          </li>

          <?php
          // Display pages, limit to a window around current page for neatness
          $startPage = max(1, $page - 2);
          $endPage = min($totalPages, $page + 2);
          if ($startPage > 1) {
              echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
          }
          for ($p = $startPage; $p <= $endPage; $p++): ?>
            <li class="page-item <?php echo ($p == $page) ? 'active' : ''; ?>">
              <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>"><?php echo $p; ?></a>
            </li>
          <?php endfor;
          if ($endPage < $totalPages) {
              echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
          }
          ?>

          <!-- Next -->
          <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($totalPages, $page+1)])); ?>" aria-label="Next">
              &raquo;
            </a>
          </li>
        </ul>
      </nav>
      <?php endif; ?>

    <?php else: ?>
      <div class="alert alert-info text-center py-5">
        <h5>No notices found<?php echo $filterCategory ? ' for "' . htmlspecialchars($filterCategory) . '"' : ''; ?>.</h5>
        <p>Check back later or try a different category.</p>
      </div>
    <?php endif; ?>

  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

<?php
} catch (Exception $e) {
    echo "<div class='alert alert-danger m-3'>An error occurred: " . htmlspecialchars($e->getMessage()) . "</div>";
}
?>
