<?php
require_once __DIR__ . '/../runtime_storage.php';
require_once __DIR__ . '/../cache_helpers.php';
$courseFile = admin_course_storage_migrate_file('course.json');
$activeFile = admin_course_storage_migrate_file('active_course.json');
$csrfPath = __DIR__ . '/../includes/csrf.php';
if (file_exists($csrfPath)) {
  require_once $csrfPath;
}

// Load courses
$courses = admin_cached_json_file('courses_list', $courseFile, [], 30);
if (!is_array($courses)) $courses = [];

$activeCourse = admin_active_course_name_cached(15);
$message = '';
$errorMessage = '';
if ($activeCourse === 'General' && !file_exists($activeFile)) {
  $activeCourse = '';
}
if (file_exists($activeFile) && $activeCourse === '') {
  $activeData = json_decode(file_get_contents($activeFile), true);
  if (is_array($activeData) && isset($activeData['course'])) {
    $activeCourse = (string)$activeData['course'];
  }
}

// Add course
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (function_exists('csrf_check_request') && !csrf_check_request()) {
    http_response_code(403);
    $errorMessage = 'Invalid CSRF token. Please refresh the page and try again.';
  }

  if ($errorMessage === '' && isset($_POST['course_name'])) {
    $newCourse = trim($_POST['course_name']);
    if ($newCourse !== '' && !in_array($newCourse, $courses, true)) {
      $courses[] = $newCourse;
      file_put_contents($courseFile, json_encode($courses, JSON_PRETTY_PRINT), LOCK_EX);
      $message = 'Course added successfully.';
    } elseif ($newCourse === '') {
      $errorMessage = 'Course name is required.';
    } else {
      $errorMessage = 'That course already exists.';
    }
  }

  if ($errorMessage === '' && isset($_POST['remove_course'])) {
    $removeCourse = $_POST['remove_course'];
    $courses = array_values(array_filter($courses, fn($course) => $course !== $removeCourse));
    file_put_contents($courseFile, json_encode($courses, JSON_PRETTY_PRINT), LOCK_EX);
    $message = 'Course removed successfully.';
  }

  if ($errorMessage === '' && isset($_POST['batch_delete']) && isset($_POST['selected_courses']) && is_array($_POST['selected_courses'])) {
    $selected = array_map('strval', $_POST['selected_courses']);
    $selectedSet = array_flip($selected);
    $courses = array_values(array_filter($courses, function ($course) use ($selectedSet) {
      return !isset($selectedSet[$course]);
    }));

    if ($activeCourse !== '' && isset($selectedSet[$activeCourse])) {
      file_put_contents($activeFile, json_encode(['course' => ''], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    file_put_contents($courseFile, json_encode($courses, JSON_PRETTY_PRINT), LOCK_EX);
    $message = 'Selected courses deleted.';
  }

  if ($errorMessage === '' && isset($_POST['batch_set_active']) && isset($_POST['selected_courses']) && is_array($_POST['selected_courses'])) {
    $selected = array_values(array_filter(array_map('strval', $_POST['selected_courses'])));
    if (count($selected) === 1 && in_array($selected[0], $courses, true)) {
      file_put_contents($activeFile, json_encode(['course' => $selected[0]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
      $activeCourse = $selected[0];
      $message = 'Active course updated.';
    } else {
      $errorMessage = 'Select exactly one valid course to set active.';
    }
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
      <?php if (function_exists('csrf_field')) csrf_field(); ?>
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
      <span class="material-symbols-outlined" style="font-size:1.1rem;">table_view</span> Available Courses
      <span class="st-chip st-chip-info" style="margin-left:auto;"><?= count($courses) ?></span>
    </p>
    <?php if (!empty($courses)): ?>
      <form method="post" id="batch-courses-form">
        <?php if (function_exists('csrf_field')) csrf_field(); ?>
        <div style="overflow:auto;border:1px solid var(--outline-variant);border-radius:10px;">
          <table style="width:100%;border-collapse:collapse;min-width:520px;">
            <thead style="background:var(--surface-container-low);">
              <tr>
                <th style="padding:10px 12px;text-align:left;"><input type="checkbox" id="select_all_courses" aria-label="Select all courses"></th>
                <th style="padding:10px 12px;text-align:left;font-size:0.82rem;color:var(--on-surface-variant);text-transform:uppercase;letter-spacing:0.06em;">Course</th>
                <th style="padding:10px 12px;text-align:left;font-size:0.82rem;color:var(--on-surface-variant);text-transform:uppercase;letter-spacing:0.06em;">Status</th>
                <th style="padding:10px 12px;text-align:right;font-size:0.82rem;color:var(--on-surface-variant);text-transform:uppercase;letter-spacing:0.06em;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($courses as $course): ?>
                <tr style="border-top:1px solid var(--outline-variant);">
                  <td style="padding:10px 12px;"><input class="course-checkbox" type="checkbox" name="selected_courses[]" value="<?= htmlspecialchars($course) ?>"></td>
                  <td style="padding:10px 12px;font-weight:600;color:var(--on-surface);"><?= htmlspecialchars($course) ?></td>
                  <td style="padding:10px 12px;">
                    <?php if ($activeCourse === $course): ?>
                      <span class="st-chip st-chip-success">Active</span>
                    <?php else: ?>
                      <span class="st-chip">Available</span>
                    <?php endif; ?>
                  </td>
                  <td style="padding:10px 12px;text-align:right;">
                    <button type="submit" name="remove_course" value="<?= htmlspecialchars($course) ?>" class="st-btn st-btn-danger single-remove-btn" style="padding:6px 10px;font-size:0.8rem;">Remove</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px;">
          <button type="submit" name="batch_delete" value="1" class="st-btn st-btn-danger" id="batch_delete_btn" disabled>
            <span class="material-symbols-outlined" style="font-size:1rem;">delete</span> Delete Selected
          </button>
          <button type="submit" name="batch_set_active" value="1" class="st-btn st-btn-primary" id="batch_set_active_btn" disabled>
            <span class="material-symbols-outlined" style="font-size:1rem;">check_circle</span> Set Selected as Active
          </button>
          <span id="selection_count" style="color:var(--on-surface-variant);font-size:0.85rem;">0 selected</span>
        </div>
      </form>
    <?php else: ?>
      <div style="color:var(--outline);font-style:italic;padding:12px;">No courses added yet.</div>
    <?php endif; ?>
  </div>
</div>

<script>
  (function() {
    const selectAll = document.getElementById('select_all_courses');
    const checkboxes = Array.from(document.querySelectorAll('.course-checkbox'));
    const count = document.getElementById('selection_count');
    const batchDeleteBtn = document.getElementById('batch_delete_btn');
    const batchActiveBtn = document.getElementById('batch_set_active_btn');
    const singleRemoveButtons = Array.from(document.querySelectorAll('.single-remove-btn'));

    function updateSelectionUI() {
      const selected = checkboxes.filter(c => c.checked).length;
      if (count) count.textContent = selected + ' selected';
      if (batchDeleteBtn) batchDeleteBtn.disabled = selected === 0;
      if (batchActiveBtn) batchActiveBtn.disabled = selected !== 1;
      if (selectAll) {
        selectAll.checked = selected > 0 && selected === checkboxes.length;
        selectAll.indeterminate = selected > 0 && selected < checkboxes.length;
      }
    }

    if (selectAll) {
      selectAll.addEventListener('change', function() {
        checkboxes.forEach(cb => cb.checked = selectAll.checked);
        updateSelectionUI();
      });
    }

    checkboxes.forEach(cb => cb.addEventListener('change', updateSelectionUI));
    updateSelectionUI();

    singleRemoveButtons.forEach(btn => {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        const form = btn.closest('form');
        window.adminConfirm('Remove course', 'Are you sure you want to remove this course?').then(function(ok) {
          if (ok) form.requestSubmit(btn);
        });
      });
    });

    const batchForm = document.getElementById('batch-courses-form');
    if (batchForm) {
      batchForm.addEventListener('submit', function(e) {
        const submitter = e.submitter;
        if (!submitter) return;
        if (submitter.name === 'batch_delete') {
          e.preventDefault();
          window.adminConfirm('Delete selected courses', 'This will remove all selected courses. Continue?').then(function(ok) {
            if (ok) batchForm.requestSubmit(submitter);
          });
        }
      });
    }
    <?php if ($message !== ''): ?>
    window.adminAlert('Success', <?= json_encode($message) ?>, 'success');
    <?php elseif ($errorMessage !== ''): ?>
    window.adminAlert('Action failed', <?= json_encode($errorMessage) ?>, 'error');
    <?php endif; ?>
  })();
</script>
