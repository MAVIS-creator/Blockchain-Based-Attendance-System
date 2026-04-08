<?php
require_once __DIR__ . '/../runtime_storage.php';
require_once __DIR__ . '/../cache_helpers.php';
$courseFile = admin_course_storage_migrate_file('course.json');
$activeFile = admin_course_storage_migrate_file('active_course.json');

// Load courses
$courses = admin_cached_json_file('courses_list', $courseFile, [], 30);
if (!is_array($courses)) $courses = [];

// Load current active course
$activeCourse = admin_active_course_name_cached(15);
if ($activeCourse === 'General' && !file_exists($activeFile)) {
    $activeCourse = '';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['disable'])) {
        // Disable active course by saving empty JSON
        if (function_exists('admin_log_action')) { admin_log_action('Courses', 'Active Course Changed', "Set active course to: " . ($course ?? 'unknown')); }
        file_put_contents($activeFile, json_encode(['course' => ''], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    $selected = $_POST['active_course'] ?? '';
    if (in_array($selected, $courses)) {
        if (function_exists('admin_log_action')) { admin_log_action('Courses', 'Active Course Changed', "Set active course to: " . ($course ?? 'unknown')); }
        file_put_contents($activeFile, json_encode(['course' => $selected], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}
?>

<div style="max-width:700px;margin:0 auto;">
  <div style="margin-bottom:24px;">
    <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);letter-spacing:-0.02em;margin:0;">
      <span class="material-symbols-outlined" style="vertical-align:middle;margin-right:8px;">gps_fixed</span>Set Active Course
    </h2>
    <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">Choose which course attendance logs are recorded against.</p>
  </div>

  <!-- Current Status -->
  <?php if ($activeCourse): ?>
    <div class="st-card" style="border-left:4px solid #059669;margin-bottom:20px;">
      <div style="display:flex;align-items:center;gap:12px;">
        <div style="display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:10px;background:#ecfdf5;">
          <span class="material-symbols-outlined" style="color:#059669;font-variation-settings:'FILL' 1;">check_circle</span>
        </div>
        <div>
          <p style="font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.08em;color:var(--on-surface-variant);margin:0;">Current Active Course</p>
          <p style="font-weight:800;color:var(--on-surface);font-size:1.1rem;margin:4px 0 0;"><?= htmlspecialchars($activeCourse) ?></p>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="st-card" style="border-left:4px solid var(--error);margin-bottom:20px;">
      <div style="display:flex;align-items:center;gap:12px;">
        <div style="display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:10px;background:#fef2f2;">
          <span class="material-symbols-outlined" style="color:var(--error);font-variation-settings:'FILL' 1;">error</span>
        </div>
        <div>
          <p style="font-weight:600;color:var(--error);margin:0;">No active course selected — all courses disabled.</p>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Selection Form -->
  <div class="st-card">
    <p style="font-weight:700;color:var(--on-surface);margin:0 0 16px;display:flex;align-items:center;gap:8px;">
      <span class="material-symbols-outlined" style="font-size:1.1rem;">tune</span> Select Course
    </p>
    <form method="post">
      <div style="margin-bottom:16px;">
        <label style="display:block;margin-bottom:6px;font-weight:600;color:var(--on-surface-variant);font-size:0.85rem;">Course</label>
        <select name="active_course" id="active_course" required>
          <option value="">— Choose Course —</option>
          <?php foreach ($courses as $course): ?>
            <option value="<?= htmlspecialchars($course) ?>" <?= $course === $activeCourse ? 'selected' : '' ?>>
              <?= htmlspecialchars($course) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="display:flex;gap:12px;flex-wrap:wrap;">
        <button type="submit" class="st-btn st-btn-primary" style="flex:1;min-width:180px;">
          <span class="material-symbols-outlined" style="font-size:1rem;">check</span> Set Active
        </button>
        <button type="submit" name="disable" value="1" class="st-btn st-btn-danger" style="flex:1;min-width:180px;">
          <span class="material-symbols-outlined" style="font-size:1rem;">block</span> Disable All
        </button>
      </div>
    </form>
  </div>
</div>
