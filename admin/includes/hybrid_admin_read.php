<?php

require_once dirname(__DIR__, 2) . '/hybrid_dual_write.php';

if (!function_exists('hybrid_admin_read_enabled')) {
  function hybrid_admin_read_enabled()
  {
    if (!hybrid_enabled()) return false;
    $flag = strtolower((string)hybrid_env('HYBRID_ADMIN_READ', 'true'));
    return in_array($flag, ['1', 'true', 'yes', 'on'], true);
  }
}

if (!function_exists('hybrid_fetch_attendance_entries')) {
  function hybrid_fetch_attendance_entries($selectedDate, $selectedCourse, $searchName = '', $filterType = 'both', &$source = 'file')
  {
    if (!hybrid_admin_read_enabled()) return null;

    $courseFilter = trim((string)$selectedCourse);
    $courseFilterAll = ($courseFilter === '' || strtolower($courseFilter) === '__all');
    $normalizeCourse = static function ($value) {
      $value = strtolower(trim((string)$value));
      return preg_replace('/\s+/', ' ', $value);
    };
    $selectedCourseNorm = $normalizeCourse($courseFilter);

    $start = $selectedDate . 'T00:00:00Z';

    // Supabase query params can include same key twice; build manually for date range.
    $customQuery = [
      'select' => 'name,matric,action,fingerprint,ip,mac,user_agent,course,reason,timestamp',
      'order' => 'timestamp.asc',
      'timestamp' => 'gte.' . $start,
    ];

    $rows = null;
    $err = null;
    $ok = hybrid_supabase_select('attendance_logs', $customQuery, $rows, $err);
    if (!$ok || !is_array($rows)) return null;

    $entries = [];
    foreach ($rows as $row) {
      $ts = (string)($row['timestamp'] ?? '');
      if ($ts === '') continue;
      $tsDate = substr($ts, 0, 10);
      if ($tsDate !== $selectedDate) continue;

      $entry = [
        'name' => (string)($row['name'] ?? ''),
        'matric' => (string)($row['matric'] ?? ''),
        'action' => (string)($row['action'] ?? ''),
        'fingerprint' => (string)($row['fingerprint'] ?? ''),
        'ip' => (string)($row['ip'] ?? ''),
        'mac' => (string)($row['mac'] ?? 'UNKNOWN'),
        'timestamp' => str_replace('T', ' ', substr($ts, 0, 19)),
        'device' => (string)($row['user_agent'] ?? ''),
        'course' => (string)($row['course'] ?? 'General'),
        'reason' => (string)($row['reason'] ?? '-'),
      ];

      if (!$courseFilterAll) {
        if ($normalizeCourse($entry['course']) !== $selectedCourseNorm) continue;
      }

      if (
        $searchName !== '' &&
        stripos($entry['name'], $searchName) === false &&
        stripos($entry['ip'], $searchName) === false &&
        stripos($entry['mac'], $searchName) === false
      ) {
        continue;
      }

      if ($filterType === 'ip' && ($entry['ip'] === '' || $entry['ip'] === 'UNKNOWN')) continue;
      if ($filterType === 'mac' && ($entry['mac'] === '' || $entry['mac'] === 'UNKNOWN')) continue;

      $entries[] = $entry;
    }

    $source = 'supabase';
    return $entries;
  }
}

if (!function_exists('hybrid_fetch_support_tickets')) {
  function hybrid_fetch_support_tickets(&$source = 'file')
  {
    if (!hybrid_admin_read_enabled()) return null;

    $rows = null;
    $err = null;
    $ok = hybrid_supabase_select('support_tickets', [
      'select' => 'name,matric,message,fingerprint,ip,created_at_local,resolved,timestamp',
      'order' => 'timestamp.asc'
    ], $rows, $err);

    if (!$ok || !is_array($rows)) return null;

    $tickets = [];
    foreach ($rows as $row) {
      $tickets[] = [
        'name' => (string)($row['name'] ?? ''),
        'matric' => (string)($row['matric'] ?? ''),
        'message' => (string)($row['message'] ?? ''),
        'fingerprint' => (string)($row['fingerprint'] ?? ''),
        'ip' => (string)($row['ip'] ?? ''),
        'timestamp' => (string)($row['created_at_local'] ?? substr((string)($row['timestamp'] ?? ''), 0, 19)),
        'resolved' => (bool)($row['resolved'] ?? false),
      ];
    }

    $source = 'supabase';
    return $tickets;
  }
}

if (!function_exists('hybrid_support_ticket_unresolved_count')) {
  function hybrid_support_ticket_unresolved_count(&$source = 'file')
  {
    $tickets = hybrid_fetch_support_tickets($source);
    if (!is_array($tickets)) {
      return null;
    }

    $count = 0;
    foreach ($tickets as $ticket) {
      if (!is_array($ticket)) {
        continue;
      }
      if (empty($ticket['resolved'])) {
        $count++;
      }
    }

    return $count;
  }
}

if (!function_exists('hybrid_mark_support_ticket_resolved')) {
  function hybrid_mark_support_ticket_resolved($ticketTimestamp)
  {
    if (!hybrid_admin_read_enabled()) return false;
    $err = null;
    if (hybrid_supabase_update('support_tickets', ['created_at_local' => 'eq.' . $ticketTimestamp], ['resolved' => true], $err)) {
      return true;
    }
    // Fallback for legacy rows without created_at_local value.
    return hybrid_supabase_update('support_tickets', ['timestamp' => 'eq.' . $ticketTimestamp], ['resolved' => true], $err);
  }
}
