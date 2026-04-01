<?php
$courseFile = __DIR__ . '/course.json';

// Load courses
$courses = file_exists($courseFile) ? json_decode(file_get_contents($courseFile), true) : [];
if (!is_array($courses)) $courses = [];

// Add course
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['course_name'])) {
        $newCourse = trim($_POST['course_name']);
        if ($newCourse !== '' && !in_array($newCourse, $courses, true)) {
            $courses[] = $newCourse;
            file_put_contents($courseFile, json_encode($courses, JSON_PRETTY_PRINT));
        }
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    if (isset($_POST['remove_course'])) {
        $removeCourse = $_POST['remove_course'];
        $courses = array_values(array_filter($courses, fn($course) => $course !== $removeCourse));
        file_put_contents($courseFile, json_encode($courses, JSON_PRETTY_PRINT));
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}
?>

<div style="max-width:700px;margin:0 auto;">
  <div style="margin-bottom:24px;">
    <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);letter-spacing:-0.02em;margin:0;">
      <span class="material-symbols-outlined" style="vertical-align:middle;margin-right:8px;">add_circle</span>Manage Courses
    </h2>
    <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">Add or remove courses from the attendance system.</p>
  </div>

  <!-- Add Course Form -->
  <div class="st-card" style="margin-bottom:20px;">
    <p style="font-weight:700;color:var(--on-surface);margin:0 0 16px;display:flex;align-items:center;gap:8px;">
      <span class="material-symbols-outlined" style="font-size:1.1rem;">library_add</span> Add New Course
    </p>
    <form method="post" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
      <div style="flex:1;min-width:200px;">
        <label style="display:block;margin-bottom:6px;font-weight:600;color:var(--on-surface-variant);font-size:0.85rem;">Course Name</label>
        <input type="text" id="course_name" name="course_name" placeholder="e.g., CSC101" required>
      </div>
      <button type="submit" class="st-btn st-btn-primary">
        <span class="material-symbols-outlined" style="font-size:1rem;">add</span> Add Course
      </button>
    </form>
  </div>

  <!-- Course List -->
  <div class="st-card">
    <p style="font-weight:700;color:var(--on-surface);margin:0 0 16px;display:flex;align-items:center;gap:8px;">
      <span class="material-symbols-outlined" style="font-size:1.1rem;">menu_book</span> Available Courses
      <span class="st-chip st-chip-info" style="margin-left:auto;"><?= count($courses) ?></span>
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:10px;">
      <?php if (!empty($courses)): ?>
        <?php foreach ($courses as $course): ?>
          <div style="display:inline-flex;align-items:center;gap:8px;background:var(--secondary-fixed);color:var(--on-secondary-fixed-variant);padding:8px 14px;border-radius:999px;font-weight:600;font-size:0.88rem;">
            <span class="material-symbols-outlined" style="font-size:0.95rem;">school</span>
            <?= htmlspecialchars($course) ?>
            <form method="post" class="remove-course-form" style="display:inline;margin:0;">
              <input type="hidden" name="remove_course" value="<?= htmlspecialchars($course) ?>">
              <?php
              $csrfPath = __DIR__ . '/../includes/csrf.php';
              if (file_exists($csrfPath)) { require_once $csrfPath; csrf_field(); }
              ?>
              <button type="submit" style="background:var(--error);color:#fff;border:none;border-radius:50%;width:22px;height:22px;font-size:12px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:all 0.2s;"
                title="Remove Course">&times;</button>
            </form>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div style="color:var(--outline);font-style:italic;padding:12px;">No courses added yet.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    Array.from(document.querySelectorAll('.remove-course-form')).forEach(function(f){
        f.addEventListener('submit', function(e){
            e.preventDefault();
            window.adminConfirm('Remove course', 'Are you sure you want to remove this course?').then(function(ok){ if (!ok) return; f.submit(); });
        });
    });
});
</script>
