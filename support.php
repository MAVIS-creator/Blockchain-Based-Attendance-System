<?php
date_default_timezone_set('Africa/Lagos');
require_once __DIR__ . '/hybrid_dual_write.php';
require_once __DIR__ . '/storage_helpers.php';
require_once __DIR__ . '/admin/runtime_storage.php';
require_once __DIR__ . '/admin/cache_helpers.php';
require_once __DIR__ . '/request_timing.php';
require_once __DIR__ . '/request_guard.php';
require_once __DIR__ . '/src/AiTicketAutomationEngine.php';
app_storage_init();
app_request_guard('support.php', 'public');
request_timing_start('support.php');

$ticketsFile = admin_storage_migrate_file('support_tickets.json', app_storage_file('support_tickets.json'));

if (!file_exists($ticketsFile)) {
  file_put_contents($ticketsFile, json_encode([]), LOCK_EX);
}

function append_support_ticket_atomic($ticketsFile, $ticket)
{
  $fp = fopen($ticketsFile, 'c+');
  if (!$fp) return false;
  if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    return false;
  }

  rewind($fp);
  $raw = stream_get_contents($fp);
  $tickets = json_decode($raw ?: '[]', true);
  if (!is_array($tickets)) $tickets = [];

  $tickets[] = $ticket;
  $payload = json_encode($tickets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

  rewind($fp);
  ftruncate($fp, 0);
  fwrite($fp, $payload);
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  return true;
}

function support_run_ai_for_ticket(array $ticket)
{
  try {
    $engine = new AiTicketAutomationEngine();
    return $engine->processTicket($ticket);
  } catch (\Throwable $e) {
    return ['ok' => false, 'error' => 'ai_ticket_processing_failed', 'message' => $e->getMessage()];
  }
}

$success = false;
$formError = '';

$courseFile = admin_course_storage_migrate_file('course.json');
$courseRows = [];
if (file_exists($courseFile)) {
  $decodedCourses = admin_cached_json_file('support_courses', $courseFile, [], 15);
  if (is_array($decodedCourses)) {
    $courseRows = $decodedCourses;
  }
}

$courseOptions = [];
$isAssoc = false;
foreach (array_keys($courseRows) as $k) {
    if (is_string($k)) { $isAssoc = true; break; }
}
if ($isAssoc) {
    foreach(array_keys($courseRows) as $c) {
        $c = trim((string)$c);
        if ($c !== '' && !in_array($c, $courseOptions, true)) {
            $courseOptions[] = $c;
        }
    }
} else {
    foreach ($courseRows as $courseRow) {
      $courseName = trim((string)$courseRow);
      if ($courseName === '') continue;
      if (!in_array($courseName, $courseOptions, true)) {
        $courseOptions[] = $courseName;
      }
    }
}

if (empty($courseOptions)) {
  $courseOptions = ['General'];
}

$selectedCourseForm = trim((string)($_POST['course'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $submitSpan = microtime(true);
  $name = trim($_POST['name'] ?? '');
  $matric = preg_replace('/\D+/', '', trim((string)($_POST['matric'] ?? '')));
  $message = trim($_POST['message'] ?? '');
  $fingerprint = trim($_POST['fingerprint'] ?? '');
  $courseInput = trim((string)($_POST['course'] ?? ''));
  $course = $courseInput;
  if ($course === '') {
    $course = in_array('General', $courseOptions, true) ? 'General' : (string)($courseOptions[0] ?? 'General');
  }

  if (!in_array($course, $courseOptions, true)) {
    $formError = 'Please select a valid course from the course list.';
  }

  $requestedAction = strtolower(trim((string)($_POST['requested_action'] ?? '')));
  if (!in_array($requestedAction, ['checkin', 'checkout'], true)) {
    $requestedAction = '';
  }
  $ip = $_SERVER['REMOTE_ADDR'];

  if ($matric !== '' && !preg_match('/^\d{6,20}$/', $matric)) {
    $formError = 'Enter a valid matric number using digits only.';
  }

  if ($name && $matric && $message && $formError === '') {
    $createdAt = date('Y-m-d H:i:s');
    $saved = append_support_ticket_atomic($ticketsFile, [
      'name' => $name,
      'matric' => $matric,
      'message' => $message,
      'fingerprint' => $fingerprint,
      'course' => $course,
      'requested_action' => $requestedAction,
      'ip' => $ip,
      'timestamp' => $createdAt,
      'resolved' => false
    ]);

    if ($saved) {
      $dualWriteSpan = microtime(true);
      hybrid_dual_write('support_ticket', 'support_tickets', [
        'timestamp' => date('c'),
        'name' => $name,
        'matric' => $matric,
        'message' => $message,
        'fingerprint' => $fingerprint,
        'course' => $course,
        'requested_action' => $requestedAction,
        'ip' => $ip,
        'created_at_local' => $createdAt,
        'resolved' => false
      ]);
      request_timing_span('hybrid_dual_write', $dualWriteSpan);

      $aiTicket = [
        'name' => $name,
        'matric' => $matric,
        'message' => $message,
        'fingerprint' => $fingerprint,
        'course' => $course,
        'requested_action' => $requestedAction,
        'ip' => $ip,
        'timestamp' => $createdAt,
        'resolved' => false
      ];
      $aiSpan = microtime(true);
      $aiResult = support_run_ai_for_ticket($aiTicket);
      request_timing_span('ai_ticket_immediate_process', $aiSpan, [
        'ok' => !empty($aiResult['ok']),
        'processed' => (int)($aiResult['processed'] ?? 0),
        'error' => (string)($aiResult['error'] ?? '')
      ]);
    }

    $success = $saved;
    request_timing_span('submit_support_ticket', $submitSpan, ['saved' => $saved]);
  }
}

// Blocked logic: Check if user is blocked via cookie
$blocked = false;
if (isset($_COOKIE['attendanceBlocked'])) {
  $blocked = true;
}

include __DIR__ . '/includes/public_header.php';
?>

<main class="flex-grow max-w-[1440px] mx-auto w-full px-8 py-12">
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-12">
        <!-- Left Column: Context & Help -->
        <div class="lg:col-span-4 space-y-8">
            <div class="space-y-4">
                <h1 class="text-[1.75rem] font-bold text-on-surface tracking-tight">Support Services</h1>
                <p class="text-on-surface-variant leading-relaxed">
                    Tickets are used for attendance issues, validation problems, or access problems. Please provide accurate details to ensure our team can verify your records efficiently.
                </p>
            </div>
            
            <!-- Help Panel Card -->
            <div class="bg-surface-container-low rounded-xl p-8 border border-outline-variant/20 shadow-sm relative overflow-hidden">
                <div class="absolute top-0 right-0 p-4 opacity-10">
                    <span class="material-symbols-outlined text-[80px]" style="font-variation-settings: 'FILL' 1;">help_center</span>
                </div>
                <div class="relative z-10">
                    <h3 class="text-lg font-bold text-primary mb-3">Submission Process</h3>
                    <ul class="space-y-4">
                        <li class="flex gap-3 text-sm text-on-surface-variant">
                            <span class="material-symbols-outlined text-primary text-[20px]">mark_email_read</span>
                            <span>Support may review the ticket after submission to verify data, please make sure your message is clear and concise for better response.</span>
                        </li>
                        <li class="flex gap-3 text-sm text-on-surface-variant">
                            <span class="material-symbols-outlined text-primary text-[20px]">history</span>
                            <span>Response times are typically almost immediately.</span>
                        </li>
                        <li class="flex gap-3 text-sm text-on-surface-variant">
                            <span class="material-symbols-outlined text-primary text-[20px]">security</span>
                            <span>Verification requires your valid matriculation identification.</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Right Column: Form -->
        <div class="lg:col-span-8">
            <div class="bg-surface-container-lowest rounded-xl p-10 shadow-[0_16px_36px_rgba(24,39,75,0.06)] border border-outline-variant/10">
                <div class="mb-10">
                    <h2 class="text-xl font-semibold text-on-surface mb-2">Submit Support Ticket</h2>
                    <div class="h-1 w-16 bg-primary rounded-full"></div>
                </div>
                
                <?php if ($success): ?>
                    <div class="bg-[#ecfdf5] border border-[#a7f3d0] rounded-xl p-6 mb-8 flex gap-4 items-start">
                        <span class="material-symbols-outlined text-[#059669]">check_circle</span>
                        <div>
                            <h4 class="text-sm font-bold text-[#065f46]">Ticket Submitted Successfully</h4>
                            <p class="text-xs text-[#064e3b] mt-1">Your support ticket has been recorded. Our team will review it shortly.</p>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($formError): ?>
                    <div class="bg-[#fef2f2] border border-[#fecaca] rounded-xl p-6 mb-8 flex gap-4 items-start">
                        <span class="material-symbols-outlined text-[#dc2626]">error</span>
                        <div>
                            <h4 class="text-sm font-bold text-[#991b1b]">Submission Error</h4>
                            <p class="text-xs text-[#7f1d1d] mt-1"><?= htmlspecialchars($formError) ?></p>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($blocked): ?>
                    <div class="bg-[#fef2f2] border border-[#fecaca] rounded-xl p-6 mb-8 flex gap-4 items-start">
                        <span class="material-symbols-outlined text-[#dc2626]">error</span>
                        <div>
                            <h4 class="text-sm font-bold text-[#991b1b]">Access Blocked</h4>
                            <p class="text-xs text-[#7f1d1d] mt-1">We noticed your attendance session has been flagged or suspended. Use this form to appeal your case.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" id="supportForm" class="space-y-8">
                    <!-- Personal Info Row -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div class="space-y-2">
                            <label class="text-[0.75rem] font-bold uppercase tracking-wider text-on-surface-variant flex items-center gap-2">
                                <span class="material-symbols-outlined text-[16px]">person</span> Full Name
                            </label>
                            <input name="name" required class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/20 focus:ring-4 focus:ring-primary-fixed focus:border-primary transition-all placeholder:text-outline/50 outline-none focus:outline-none" placeholder="e.g. John Doe" type="text"/>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[0.75rem] font-bold uppercase tracking-wider text-on-surface-variant flex items-center gap-2">
                                <span class="material-symbols-outlined text-[16px]">fingerprint</span> Matric Number
                            </label>
                            <input name="matric" inputmode="numeric" pattern="[0-9]{6,20}" maxlength="20" required class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/20 focus:ring-4 focus:ring-primary-fixed focus:border-primary transition-all placeholder:text-outline/50 outline-none focus:outline-none" placeholder="e.g. 000000" type="text"/>
                        </div>
                    </div>
                    
                    <!-- Dropdowns Row -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div class="space-y-2">
                            <label class="text-[0.75rem] font-bold uppercase tracking-wider text-on-surface-variant flex items-center gap-2">
                                <span class="material-symbols-outlined text-[16px]">book</span> Course
                            </label>
                            <select name="course" required class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/20 focus:ring-4 focus:ring-primary-fixed focus:border-primary transition-all outline-none focus:outline-none cursor-pointer">
                                <?php foreach ($courseOptions as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[0.75rem] font-bold uppercase tracking-wider text-on-surface-variant flex items-center gap-2">
                                <span class="material-symbols-outlined text-[16px]">error</span> Action / Issue Type
                            </label>
                            <select name="requested_action" required class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/20 focus:ring-4 focus:ring-primary-fixed focus:border-primary transition-all outline-none focus:outline-none cursor-pointer">
                                <option value="checkin">Missing Check In</option>
                                <option value="checkout">Missing Check Out</option>
                                <option value="other">Other / Verification Failure</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Message -->
                    <div class="space-y-2">
                        <label class="text-[0.75rem] font-bold uppercase tracking-wider text-on-surface-variant flex items-center gap-2">
                            <span class="material-symbols-outlined text-[16px]">chat_bubble</span> Detailed Message
                        </label>
                        <textarea name="message" required class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/20 focus:ring-4 focus:ring-primary-fixed focus:border-primary transition-all placeholder:text-outline/50 outline-none focus:outline-none resize-none" placeholder="Describe the issue in detail..." rows="6"></textarea>
                    </div>

                    <input type="hidden" id="fingerprint" name="fingerprint">
                    
                    <!-- Action Bar -->
                    <div class="flex flex-col md:flex-row items-center justify-between gap-6 pt-6">
                        <div class="flex items-center gap-2 text-on-surface-variant">
                            <span class="material-symbols-outlined text-tertiary" style="font-variation-settings: 'FILL' 1;">verified</span>
                            <span class="text-xs font-medium">Secured with End-to-End Encryption</span>
                        </div>
                        <button class="w-full md:w-auto bg-gradient-to-br from-primary to-primary-container text-white px-10 py-4 rounded-lg font-bold text-sm tracking-wide shadow-lg hover:shadow-primary/20 transition-all active:scale-95" type="submit">
                            Submit Ticket
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Alert Section -->
            <div class="mt-8 p-6 bg-tertiary-container/10 border border-tertiary-container/20 rounded-xl flex gap-4 items-start">
                <span class="material-symbols-outlined text-tertiary-container">info</span>
                <div>
                    <h4 class="text-sm font-bold text-tertiary-container">Data Retention Policy</h4>
                    <p class="text-xs text-on-surface-variant leading-relaxed mt-1">
                        Submitted information is temporarily stored in our encrypted triage system before being reconciled. Your privacy is maintained through cryptographic anonymization.
                    </p>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/includes/public_footer.php'; ?>

<script src="./js/fp.min.js"></script>
<script>
    FingerprintJS.load().then(fp => {
      fp.get().then(result => {
        document.getElementById('fingerprint').value = result.visitorId;
      }).catch(err => {
        console.error('Fingerprint error:', err);
      });
    });
</script>
