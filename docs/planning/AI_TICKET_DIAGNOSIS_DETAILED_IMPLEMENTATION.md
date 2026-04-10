# AI Ticket Diagnosis + Auto-Operations Plan (DETAILED - ACTUAL CODE PATTERNS)

**Status:** Foundation Not Yet Started  
**Last Updated:** 2026-04-09 (After Full Codebase Scan)  
**Current Implementation Phase:** 0 (Planning Complete → Ready for Phase 1)

---

## I. Actual Code Patterns Discovered

### A. Ticket Diagnosis Logic (from view_tickets.php lines 31-42)

```php
// ACTUAL PATTERN:
function checkLogMatch($logLines, $needle, $index)
{
  foreach ($logLines as $line) {
    $fields = array_map('trim', explode('|', $line));
    if (isset($fields[$index]) && $fields[$index] === $needle) {
      return true;
    }
  }
  return false;
}

// USAGE:
$today = date('Y-m-d');
$logFile = app_storage_file("logs/{$today}.log");
$logLines = admin_cached_file_lines('support_ticket_today_log', $logFile, 15);

$fpMatch = $fp ? checkLogMatch($logLines, $fp, 3) : false;  // field 3 = fingerprint
$ipMatch = $ip ? checkLogMatch($logLines, $ip, 4) : false;  // field 4 = ip
```

**Key:** Field indices are EXACT: 0=name, 1=matric, 2=action, 3=fingerprint, 4=ip, 5=mac, 6=timestamp, 7=userAgent, 8=course, 9=reason

### B. Ticket Resolution Logic (from view_tickets.php lines 44-61)

```php
function resolve_ticket_atomic($ticketsFile, $resolveTime)
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

  foreach ($tickets as &$ticket) {
    if (($ticket['timestamp'] ?? '') === $resolveTime) {
      $ticket['resolved'] = true;
      break;
    }
  }

  $payload = json_encode($tickets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  rewind($fp);
  ftruncate($fp, 0);
  fwrite($fp, $payload);
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  return true;
}
```

**Pattern:** Atomic write with file locking + rewind/truncate before write

### C. Attendance Log Entry (from view_tickets.php line 181)

```php
// Add attendance entry directly to log file:
$line = "{$name} | {$matric} | {$action} | MANUAL | ::1 | UNKNOWN | {$timestamp} | Web Ticket Panel | {$activeCourse} | {$reason}\n";
file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
```

**Pattern:** Plain append with lock, no JSON parsing needed

### D. Revoked Tokens Structure (actual storage/admin/revoked.json)

```json
{
  "tokens": [],
  "ips": [],
  "macs": []
}
```

**Check Pattern:**
```php
$revokedFile = admin_storage_migrate_file('revoked.json');  // or storage/admin/revoked_tokens.json
$revoked = json_decode(file_get_contents($revokedFile), true);
$isRevoked = in_array($ticket['fingerprint'], $revoked['tokens'] ?? [])
          || in_array($ticket['ip'], $revoked['ips'] ?? []);
```

### E. Announcement Structure (actual storage/admin/announcement.json)

**Current (Single Broadcast):**
```json
{
  "message": "2023011748 Your Matric Number issue has been resolved,",
  "enabled": false,
  "severity": "info",
  "updated_at": "2026-04-08T17:56:37+02:00"
}
```

**Proposed (with target_fingerprint):**
```json
{
  "message": "Your device was not recognized. Please verify...",
  "enabled": true,
  "severity": "info",
  "updated_at": "2026-04-09T14:30:00+01:00",
  "target_fingerprint": "device_abc123xyz...",  // NEW: null = broadcast, set = device-only
  "auto_generated_by": "system_ai_operator",    // NEW: marks AI-created announcements
  "created_for_ticket": "2026-04-09 14:30:00"   // NEW: links to original ticket
}
```

Or **as array** if multiple announcements:
```json
[
  {
    "id": "broadcast_001",
    "message": "All students attend 2pm meeting",
    "enabled": true,
    "severity": "info",
    "target_fingerprint": null
  },
  {
    "id": "auto_ai_fp_abc123",
    "message": "Device verification required",
    "enabled": true,
    "severity": "warning",
    "target_fingerprint": "device_abc123xyz...",
    "auto_generated_by": "system_ai_operator"
  }
]
```

### F. Chat Structure (actual storage/admin/chat.json)

```json
[
  {
    "user": "admin_user_id",
    "name": "Admin Name",
    "time": "2026-04-09T14:30:00+01:00",
    "message": "Welcome to support chat"
  },
  {
    "user": "system_ai_operator",
    "name": "System AI Operator",
    "time": "2026-04-09T14:35:00+01:00",
    "message": "I detected you're having attendance issues. Have you tried clearing your browser cache?",
    "auto_replied_by": "system_ai_operator",
    "confidence": 0.92,
    "pattern_matched": "attendance_failure"
  }
]
```

**Max messages:** 1000 (from chat_post.php line 33)

### G. How Public Site Fetches Announcements (index.php lines 686-1010)

**Backend:** `get_announcement.php` (lines 1-20)
```php
<?php
require_once __DIR__ . '/admin/runtime_storage.php';
$announcementFile = admin_storage_migrate_file('announcement.json');

$announcement = [
    'enabled' => false,
    'message' => '',
    'severity' => 'info',
    'updated_at' => null
];

if (file_exists($announcementFile)) {
    $json = json_decode(file_get_contents($announcementFile), true);
    if (is_array($json)) {
        $announcement['enabled'] = $json['enabled'] ?? false;
        $announcement['message'] = $json['message'] ?? '';
        $announcement['severity'] = in_array(($json['severity'] ?? 'info'), ['info', 'warning', 'urgent'], true) ? $json['severity'] : 'info';
        $announcement['updated_at'] = $json['updated_at'] ?? null;
    }
}

header('Content-Type: application/json');
echo json_encode($announcement);
```

**Frontend:** Polls every 10 seconds
```javascript
function fetchAnnouncement() {
  fetch('get_announcement.php', {
    method: 'GET'
  })
  .then(res => res.json())
  .then(data => {
    // Handle announcement update
    // Update banner based on change detection
  })
  .catch(err => {
    console.error("Announcement fetch error:", err);
  });
}

fetchAnnouncement();
setInterval(fetchAnnouncement, 10000);  // Poll every 10 seconds
```

### H. Auto-Send-Logs Pattern (from admin/auto_send_logs.php)

**CLI Arguments Accepted:**
```bash
php admin/auto_send_logs.php [YYYY-MM-DD] [--force] [--dry-run] [--recipient=email] [--format=csv|pdf]
```

**Parsing Logic (lines 9-23):**
```php
$argList = isset($argv) && is_array($argv) ? array_slice($argv, 1) : [];
$date = date('Y-m-d');
$forceRun = false;
$dryRun = false;
$recipientOverride = '';
$formatOverride = '';

foreach ($argList as $arg) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg)) {
        $date = $arg;
        continue;
    }
    if ($arg === '--force') {
        $forceRun = true;
        continue;
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (strpos($arg, '--recipient=') === 0) {
        $recipientOverride = trim(substr($arg, strlen('--recipient=')));
        continue;
    }
    if (strpos($arg, '--format=') === 0) {
        $formatOverride = strtolower(trim(substr($arg, strlen('--format='))));
        continue;
    }
}
```

**Settings Check (lines 30-34):**
```php
$settings = load_settings_file($settingsFile, $keyFile) ?: [];
if (empty($settings['auto_send']['enabled']) && !$forceRun) 
  exit("Auto-send not enabled (use --force for test runs)\n");
$recipient = $recipientOverride !== '' ? $recipientOverride : ($settings['auto_send']['recipient'] ?? '');
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) 
  exit("No valid recipient configured\n");
```

---

## II. Implementation Approach (Based on Actual Patterns)

### Phase 1A: Diagnosis Engine Implementation

**File:** `src/AiTicketDiagnoser.php`

```php
<?php
class AiTicketDiagnoser
{
  /**
   * Diagnose a ticket based on fingerprint/IP matching
   */
  public static function diagnose($ticket, $logLines, $revokedData)
  {
    $fp = $ticket['fingerprint'] ?? '';
    $ip = $ticket['ip'] ?? '';
    $message = $ticket['message'] ?? '';
    
    // Step 1: Check if device is known (in logs)
    $fpMatch = self::checkLogMatch($logLines, $fp, 3);
    $ipMatch = self::checkLogMatch($logLines, $ip, 4);
    
    // Step 2: Check if device is revoked
    $isRevoked = self::checkRevoked($fp, $ip, $revokedData);
    
    // Step 3: Analyze message
    $msgAnalysis = self::analyzeMessage($message);
    
    // Step 4: Classify
    $classification = self::classify($fpMatch, $ipMatch, $isRevoked, $msgAnalysis);
    $confidence = self::confidenceScore($classification);
    
    return [
      'classification' => $classification,
      'fpMatch' => $fpMatch,
      'ipMatch' => $ipMatch,
      'isRevoked' => $isRevoked,
      'messageAnalysis' => $msgAnalysis,
      'confidence' => $confidence,
      'autoApprovable' => ($confidence >= 0.85) && ($classification === 'STALE_SESSION')
    ];
  }
  
  private static function checkLogMatch($logLines, $needle, $index)
  {
    if (!$needle) return false;
    foreach ($logLines as $line) {
      $fields = array_map('trim', explode('|', $line));
      if (isset($fields[$index]) && $fields[$index] === $needle) {
        return true;
      }
    }
    return false;
  }
  
  private static function checkRevoked($fp, $ip, $revokedData)
  {
    return in_array($fp, $revokedData['tokens'] ?? [])
        || in_array($ip, $revokedData['ips'] ?? []);
  }
  
  private static function analyzeMessage($message)
  {
    $lower = strtolower($message);
    $hasAttenda = preg_match('/(attend|mark|submit|check)/i', $message);
    $hasFailure = preg_match('/(cant|can\'t|cannot|fail|error|block|won\'t|issue)/i', $message);
    $hasKeyword = $hasAttenda && $hasFailure;
    
    return [
      'text' => $message,
      'hasAttendanceKeyword' => (bool)$hasAttenda,
      'hasFailureKeyword' => (bool)$hasFailure,
      'isAttendanceFailure' => (bool)$hasKeyword,
      'keywordScore' => $hasKeyword ? 1.0 : 0.3
    ];
  }
  
  private static function classify($fpMatch, $ipMatch, $isRevoked, $msgAnalysis)
  {
    if ($isRevoked) {
      return 'BLOCKED';
    }
    
    if ($fpMatch && $ipMatch) {
      return 'STALE_SESSION';
    }
    
    if ($fpMatch && !$ipMatch) {
      return 'IP_ROTATION';
    }
    
    if (!$fpMatch && !$ipMatch) {
      return 'NEW_BROWSER';
    }
    
    return 'UNCLEAR';
  }
  
  private static function confidenceScore($classification)
  {
    return match($classification) {
      'STALE_SESSION' => 0.95,
      'IP_ROTATION' => 0.65,
      'NEW_BROWSER' => 0.50,
      'BLOCKED' => 0.99,
      default => 0.20
    };
  }
}
```

### Phase 1B: Service Identity Model

**File:** `src/AiServiceIdentity.php`

```php
<?php
class AiServiceIdentity
{
  public $id;
  public $name;
  public $created_at;
  public $capabilities = [];
  public $can_login = false;  // ALWAYS FALSE FOR AI
  
  private static $loadedIdentities = [];
  
  public function __construct($id, $name, $capabilities = [], $created_at = null)
  {
    $this->id = $id;
    $this->name = $name;
    $this->capabilities = $capabilities;
    $this->created_at = $created_at ?: date('Y-m-d H:i:s');
    $this->can_login = false;  // ENFORCE: AI cannot login
  }
  
  /**
   * Load AI identity from ai_accounts.json
   */
  public static function load($id)
  {
    if (isset(self::$loadedIdentities[$id])) {
      return self::$loadedIdentities[$id];
    }
    
    $file = admin_storage_migrate_file('ai_accounts.json');
    if (!file_exists($file)) {
      return null;
    }
    
    $data = json_decode(file_get_contents($file), true);
    if (!isset($data[$id])) {
      return null;
    }
    
    $rec = $data[$id];
    $identity = new self($id, $rec['name'] ?? $id, $rec['capabilities'] ?? []);
    self::$loadedIdentities[$id] = $identity;
    return $identity;
  }
  
  /**
   * Enforce: AI identities cannot authenticate via login
   */
  public function canLogin()
  {
    return false;
  }
}
```

### Phase 1C: Capability Checker

**File:** `src/AiCapabilityChecker.php`

```php
<?php
class AiCapabilityChecker
{
  private static $permissionsCache = [];
  
  /**
   * Check if AI identity has capability
   */
  public static function can($serviceId, $capability)
  {
    if (!$serviceId) return false;
    
    $perms = self::loadPermissions($serviceId);
    return $perms[$capability] ?? false;
  }
  
  private static function loadPermissions($serviceId)
  {
    if (isset(self::$permissionsCache[$serviceId])) {
      return self::$permissionsCache[$serviceId];
    }
    
    $file = admin_storage_migrate_file('ai_permissions.json');
    if (!file_exists($file)) {
      return [];
    }
    
    $data = json_decode(file_get_contents($file), true);
    $perms = $data[$serviceId] ?? [];
    self::$permissionsCache[$serviceId] = $perms;
    return $perms;
  }
}

/**
 * Helper function: ai_can($serviceId, $capability)
 */
if (!function_exists('ai_can')) {
  function ai_can($serviceId, $capability)
  {
    return AiCapabilityChecker::can($serviceId, $capability);
  }
}
```

### Phase 1D: Storage Files (Seed Data)

**File:** `storage/admin/ai_accounts.json`
```json
{
  "system_ai_operator": {
    "id": "system_ai_operator",
    "name": "System AI Operator",
    "created_at": "2026-04-09",
    "can_login": false,
    "capabilities": [
      "ticket.read",
      "ticket.diagnose",
      "ticket.resolve_stale_session",
      "ticket.add_attendance",
      "announcement.write",
      "chat.reply",
      "logs.export"
    ]
  }
}
```

**File:** `storage/admin/ai_permissions.json`
```json
{
  "system_ai_operator": {
    "ticket.read": true,
    "ticket.diagnose": true,
    "ticket.resolve_stale_session": true,
    "ticket.resolve_new_browser": false,
    "ticket.add_attendance": true,
    "announcement.write": true,
    "chat.reply": true,
    "logs.export": true
  }
}
```

### Phase 1E: Extending Admin Helper

**File:** `admin/state_helpers.php` (add to existing functions)

```php
if (!function_exists('ai_service_account_file')) {
  function ai_service_account_file()
  {
    return admin_storage_migrate_file('ai_accounts.json');
  }
}

if (!function_exists('ai_permissions_file')) {
  function ai_permissions_file()
  {
    return admin_storage_migrate_file('ai_permissions.json');
  }
}

if (!function_exists('ai_action_queue_file')) {
  function ai_action_queue_file()
  {
    return admin_storage_migrate_file('ai_action_queue.jsonl');
  }
}

if (!function_exists('ai_action_results_file')) {
  function ai_action_results_file()
  {
    return admin_storage_migrate_file('ai_action_results.jsonl');
  }
}
```

---

## III. How to Send Targeted Announcements to Device

### Pattern: Device-Specific Announcement in JSON

**Option A: Single broadcast + devices array**
```json
{
  "broadcast": {
    "message": "...",
    "enabled": true
  },
  "device_targeted": [
    {
      "target_fingerprint": "fp_abc123",
      "message": "...",
      "enabled": true,
      "auto_generated": true
    }
  ]
}
```

**Option B (Simpler): Announcements array with target field**
```json
[
  {
    "id": "broadcast_1",
    "message": "All students: meeting at 2pm",
    "target_fingerprint": null,
    "enabled": true
  },
  {
    "id": "ai_device_abc123",
    "message": "Device verification needed",
    "target_fingerprint": "device_abc123xyz",
    "enabled": true,
    "auto_generated_by": "system_ai_operator"
  }
]
```

### How Public Site Filters by Device

**Modified `get_announcement.php`:**
```php
<?php
require_once __DIR__ . '/admin/runtime_storage.php';

$fingerprint = trim($_GET['fingerprint'] ?? '');
$announcementFile = admin_storage_migrate_file('announcement.json');

// Load data
$data = file_exists($announcementFile) 
  ? json_decode(file_get_contents($announcementFile), true) 
  : [];

// If single object (broadcast), return it
if (isset($data['message'])) {
  $announcement = [
    'enabled' => $data['enabled'] ?? false,
    'message' => $data['message'] ?? '',
    'severity' => $data['severity'] ?? 'info',
    'updated_at' => $data['updated_at'] ?? null
  ];
  echo json_encode($announcement);
  exit;
}

// If array (mixed), filter by device
$toShow = [];
if (is_array($data)) {
  foreach ($data as $ann) {
    $target = $ann['target_fingerprint'] ?? null;
    // Show if: broadcast (target=null) OR matches this device
    if ($target === null || $target === $fingerprint) {
      $toShow[] = $ann;
    }
  }
}

// Return first active or most recent
$result = [
  'enabled' => false,
  'message' => '',
  'severity' => 'info',
  'updated_at' => null
];

foreach ($toShow as $ann) {
  if ($ann['enabled'] ?? false) {
    $result = $ann;
    break;
  }
}

echo json_encode($result);
```

**Frontend receives fingerprint in form field, passes to api:**
```javascript
const fingerprint = document.getElementById('fingerprint').value;
fetch(`get_announcement.php?fingerprint=${encodeURIComponent(fingerprint)}`)
  .then(res => res.json())
  .then(data => {
    // Update banner based on data
  });
```

---

## IV. How to Reply to Chat Messages

### Pattern: AI Detects Attendance Pattern, Sends Reply

**File:** `src/AiChatResponder.php`

```php
<?php
class AiChatResponder
{
  private static $patterns = [
    'attendance_fail' => [
      'pattern' => '/(cant|can\'t|cannot|fail|block|submit).*?(attend|mark|check)/i',
      'reply' => "I understand you're having trouble with attendance. Try clearing your browser cache (Ctrl+Shift+Del) and refreshing. If that doesn't work, please submit a support ticket.",
      'confidence' => 0.92
    ],
    'device_issue' => [
      'pattern' => '/(new|differnt|different|device|browser|phone|laptop|computer)/i',
      'reply' => "It looks like you're on a new device. Our system may need to verify your identity. Try clearing cache or submitting a support ticket to verify.",
      'confidence' => 0.75
    ],
    'token_expired' => [
      'pattern' => '/(token|session|expired|login|credential)/i',
      'reply' => "Your session may have expired. Try refreshing the page or logging out and back in. If the issue persists, contact support.",
      'confidence' => 0.80
    ]
  ];
  
  /**
   * Analyze user message and generate AI reply if pattern matched
   */
  public static function generateReply($userMessage)
  {
    foreach (self::$patterns as $patternId => $patternData) {
      if (preg_match($patternData['pattern'], $userMessage)) {
        return [
          'pattern_matched' => $patternId,
          'reply' => $patternData['reply'],
          'confidence' => $patternData['confidence'],
          'should_auto_reply' => $patternData['confidence'] >= 0.80
        ];
      }
    }
    
    return null;  // No pattern matched
  }
  
  /**
   * Append AI reply to chat.json
   */
  public static function appendReply($userMessage, $chatFile)
  {
    $result = self::generateReply($userMessage);
    if (!$result || !$result['should_auto_reply']) {
      return null;
    }
    
    $reply = [
      'user' => 'system_ai_operator',
      'name' => 'System AI Operator',
      'time' => date('c'),
      'message' => $result['reply'],
      'auto_replied_by' => 'system_ai_operator',
      'pattern_matched' => $result['pattern_matched'],
      'confidence' => $result['confidence']
    ];
    
    // Append atomically
    $fp = fopen($chatFile, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) {
      fclose($fp);
      return false;
    }
    
    rewind($fp);
    $raw = stream_get_contents($fp);
    $messages = json_decode($raw ?: '[]', true);
    if (!is_array($messages)) $messages = [];
    
    $messages[] = $reply;
    if (count($messages) > 1000) {
      $messages = array_slice($messages, -1000);
    }
    
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    
    return $reply;
  }
}
```

---

## VII. Phase 1 Task Breakdown

```
Phase 1: Foundation (AI Service Identity + Diagnosis Engine)

STEP 1.1: Create AiServiceIdentity.php
  ├─ Class with id, name, capabilities, can_login (false)
  ├─ Static load($id) method
  └─ canLogin() always returns false

STEP 1.2: Create AiTicketDiagnoser.php
  ├─ diagnose($ticket, $logLines, $revokedData) method
  ├─ checkLogMatch() - exact pattern from view_tickets.php
  ├─ checkRevoked() - check tokens/ips arrays
  ├─ analyzeMessage() - keyword matching
  ├─ classify() - 5 classifications (STALE/ROTATION/NEW/BLOCKED/UNCLEAR)
  └─ confidenceScore() - 0.0-1.0

STEP 1.3: Create AiCapabilityChecker.php
  ├─ Static can($serviceId, $capability) method
  ├─ loadPermissions($serviceId)
  └─ Helper function ai_can()

STEP 1.4: Create ai_accounts.json seed
  └─ system_ai_operator with 7 capabilities

STEP 1.5: Create ai_permissions.json seed
  └─ system_ai_operator capability matrix (true/false)

STEP 1.6: Update admin/state_helpers.php
  ├─ ai_service_account_file()
  ├─ ai_permissions_file()
  ├─ ai_action_queue_file()
  └─ ai_action_results_file()

STEP 1.7: Create admin/includes/ticket_helpers.php
  ├─ Extract resolve_ticket_atomic() from view_tickets.php
  ├─ Extract bulk_update_tickets_atomic()
  ├─ Add get_support_tickets()
  ├─ Add ticket_get($timestamp)
  ├─ Add ticket_add_attendance()
  └─ Add ticket_log_entry()

STEP 1.8: Write test script (admin/ai_test_phase1.php)
  ├─ Load diagnos engine
  ├─ Test 5 sample tickets
  ├─ Verify classifications
  ├─ Print diagnosis report
  └─ Verify AI identity cannot login

DELIVERABLES:
  ✅ AI identity cannot sign in
  ✅ 5/5 test tickets classified correctly
  ✅ Confidence scores calculated
  ✅ Capabilities system working
  ✅ Ticket helpers extracted and testable
```

---

## VIII. File Checklist (What Gets Created in Phase 1)

```
NEW FILES (7):
✓ src/AiServiceIdentity.php (150 lines)
✓ src/AiTicketDiagnoser.php (200 lines)
✓ src/AiCapabilityChecker.php (80 lines)
✓ admin/includes/ticket_helpers.php (200 lines)
✓ storage/admin/ai_accounts.json (seed)
✓ storage/admin/ai_permissions.json (seed)
✓ admin/ai_test_phase1.php (test script)

MODIFIED FILES (1):
✓ admin/state_helpers.php (add 4 helper functions)

TOTAL NEW CODE: ~800 lines
TOTAL MODIFIED: ~10 lines
```

---

## IX. Quick Implementation Checklist

- [ ] 1.1. Create `src/AiServiceIdentity.php` with load(), canLogin()
- [ ] 1.2. Create `src/AiTicketDiagnoser.php` with diagnose(), all helpers
- [ ] 1.3. Create `src/AiCapabilityChecker.php` with can(), ai_can()
- [ ] 1.4. Create `storage/admin/ai_accounts.json` seed (system_ai_operator)
- [ ] 1.5. Create `storage/admin/ai_permissions.json` seed (capability matrix)
- [ ] 1.6. Update `admin/state_helpers.php` with 4 new helpers
- [ ] 1.7. Extract ticket logic to `admin/includes/ticket_helpers.php`
- [ ] 1.8. Create test script and verify all 5 classifications work
- [ ] Syntax check: `php -l src/AiServiceIdentity.php` (etc)
- [ ] Run test: `php admin/ai_test_phase1.php` → all green ✅

---

## X. Integration Points for Later Phases

**Phase 2** will wire these into:
- `src/AiActionRouter.php` → uses diagnoser output
- `src/AiPolicyGuard.php` → uses capability checker
- `src/AiActionExecutor.php` → uses ticket_helpers

**Phase 3** will create:
- `admin/ai_ticket_processor.php` → runs diagnosis on unresolved tickets
- `ticket_status_api.php` → device-isolated query

**Phase 4** will extend:
- `admin/announcement.php` → add target_fingerprint UI
- `get_announcement.php` → filter by device fingerprint

**Phase 5** will use:
- `src/AiChatResponder.php` → auto-reply to chats

---

## IX. Key Acceptance Criteria (Phase 1)

1. ✅ `AiServiceIdentity::load('system_ai_operator')` returns object
2. ✅ `$identity->canLogin()` returns false (enforced)
3. ✅ `ai_can('system_ai_operator', 'ticket.resolve_stale_session')` returns true
4. ✅ `ai_can('system_ai_operator', 'ticket.resolve_new_browser')` returns false
5. ✅ Diagnose STALE_SESSION ticket → classification='STALE_SESSION', confidence=0.95
6. ✅ Diagnose NEW_BROWSER ticket → classification='NEW_BROWSER', confidence=0.50
7. ✅ Diagnose BLOCKED ticket → classification='BLOCKED', confidence=0.99
8. ✅ Diagnose IP_ROTATION ticket → classification='IP_ROTATION', confidence=0.65
9. ✅ All files pass PHP syntax check (`php -l`)
10. ✅ Test script produces clear output with 5/5 classifications correct

---

## XII. Appendix: Code Snippets Ready to Use

### Template: Atomic File Write Pattern
```php
$fp = fopen($file, 'c+');
if (!$fp || !flock($fp, LOCK_EX)) {
    fclose($fp);
    return false;
}

rewind($fp);
$raw = stream_get_contents($fp);
$data = json_decode($raw ?: '[]', true);
// ... modify data ...

rewind($fp);
ftruncate($fp, 0);
fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);
return true;
```

### Template: Log Entry Append (Simple)
```php
$line = "{$name} | {$matric} | {$action} | {$fp} | {$ip} | {$mac} | {$ts} | {$ua} | {$course} | {$reason}\n";
file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
```

### Template: Field Extraction from Log
```php
$fields = array_map('trim', explode('|', $line));
// fields[0]=name, [1]=matric, [2]=action, [3]=fingerprint, [4]=ip, [5]=mac, [6]=ts, [7]=ua, [8]=course, [9]=reason
```

